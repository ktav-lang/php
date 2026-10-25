<?php

declare(strict_types=1);

namespace Ktav\Pure\Edit;

use Ktav\KtavException;
use Ktav\Pure\PureFloat;
use Ktav\Pure\PureParser;
use Ktav\Pure\PureScanner;
use Ktav\Pure\PureText;
use Ktav\Pure\PureWriter;

/**
 * Source-splice engine for the pure backend — a PHP port of the Rust
 * core's `document/parser/edit/splice.rs` + `splice/layout.rs`.
 *
 * Invariants (mirror the Rust engine; see the contract):
 * - only bytes of the target (and of the inline wrappers the layout
 *   criterion forces to expand) change; comments, key spelling, key
 *   order, BOM, CR/LF/CRLF and EOF bytes outside the edit survive;
 * - replace-existing only; missing key / out-of-bounds index / scalar
 *   descent / empty segments => EditNotFound; malformed textual path
 *   => EditInvalidPath with the UTF-8 byte offset;
 * - dotted/reopened noncontiguous targets and structural replacement
 *   of a container holding comment lines => EditUnsupported BEFORE the
 *   splice; a replacement String containing CR or a line `))` also has
 *   no lossless form;
 * - byte-identical no-op even for noncanonical source spelling;
 * - every patch is re-parsed and semantically verified: exactly the
 *   target replaced, the rest of the tree equal — fail closed.
 */
final class EditSplice
{
    /** Inline-line growth limit (Unicode scalar values). */
    const LINE_LIMIT = 100;

    // -----------------------------------------------------------------
    // Public entry points
    // -----------------------------------------------------------------

    /**
     * edit_value(source, textualPath, replacement): the textual path
     * `key1.key2.0.key4` with `\.` / `\\` escapes.
     *
     * ORDER mirrors the C ABI: UTF-8 validate + parse the SOURCE
     * first (a broken source wins with the ordinary 0.9 parser
     * envelope), THEN decode the path/replacement transport, then
     * edit. Invalid path bytes are a Message transport diagnostic,
     * never a guessed NotFound.
     *
     * @param mixed $replacement public PHP value (or `['$i'=>…]` /
     *   `['$f'=>…]` tagged scalars)
     * @throws KtavException the edit envelope, a 0.9 parser envelope,
     *   or a Message transport envelope
     */
    public static function editValue(string $src, string $path, $replacement): string
    {
        return self::apply(
            $src,
            static function () use ($path): array {
                if (PureScanner::validateUtf8($path) !== null) {
                    // Transport decode failure: the path argument is
                    // not valid UTF-8 (source-less Message envelope).
                    throw EditEnvelope::message('edit path is not valid UTF-8');
                }
                return self::parsePath($path);
            },
            $replacement
        );
    }

    /**
     * edit_value_segments(source, decodedSegments, replacement): the
     * segments are literal, already-decoded names — no extra unescape;
     * a numeric segment is a key in an Object and a zero-based
     * ASCII-digits index in an Array. Source-first like the C ABI: an
     * empty segments list is EditNotFound only AFTER the source
     * parsed; a non-string segment is a Message transport failure.
     *
     * @param string[] $segments
     * @param mixed $replacement
     */
    public static function editValueSegments(string $src, array $segments, $replacement): string
    {
        return self::apply(
            $src,
            static function () use ($segments): array {
                if ($segments === []) {
                    // Contract: empty segments list -> NotFound (after
                    // the source parse, like the Rust entry).
                    throw EditEnvelope::notFound();
                }
                foreach ($segments as $seg) {
                    if (!is_string($seg)) {
                        throw EditEnvelope::message(
                            'edit segments transport must be a list of strings'
                        );
                    }
                }
                return $segments;
            },
            $replacement
        );
    }

    /**
     * Textual path grammar: '.' separates; `\.` and `\\` are literals;
     * any other escape or a trailing lone backslash, an empty path and
     * empty segments are EditInvalidPath with the byte offset.
     *
     * @return string[]
     */
    public static function parsePath(string $path): array
    {
        $n = strlen($path);
        if ($n === 0) {
            throw EditEnvelope::invalidPath(0);
        }
        $segments = [];
        $segStart = 0;
        $cur = null;
        $i = 0;
        while ($i < $n) {
            $b = ord($path[$i]);
            if ($b === 0x2E) { // '.'
                if ($segStart === $i && $cur === null) {
                    throw EditEnvelope::invalidPath($i);
                }
                $segments[] = $cur !== null ? $cur : substr($path, $segStart, $i - $segStart);
                $cur = null;
                $segStart = $i + 1;
                ++$i;
                continue;
            }
            if ($b === 0x5C) { // '\'
                if ($i + 1 < $n && (ord($path[$i + 1]) === 0x2E || ord($path[$i + 1]) === 0x5C)) {
                    if ($cur === null) {
                        $cur = substr($path, $segStart, $i - $segStart);
                    }
                    $cur .= $path[$i + 1] === '.' ? '.' : '\\';
                    $i += 2;
                    continue;
                }
                throw EditEnvelope::invalidPath($i);
            }
            // UTF-8 lead byte length (input is valid UTF-8; continuation
            // bytes are copied inside their sequence).
            $len = 1;
            if ($b >= 0xC2 && $b <= 0xDF) {
                $len = 2;
            } elseif ($b >= 0xE0 && $b <= 0xEF) {
                $len = 3;
            } elseif ($b >= 0xF0 && $b <= 0xF7) {
                $len = 4;
            }
            if ($cur !== null) {
                $cur .= substr($path, $i, $len);
            }
            $i += $len;
        }
        if ($segStart === $n && $cur === null) {
            throw EditEnvelope::invalidPath($n); // trailing '.'
        }
        $segments[] = $cur !== null ? $cur : substr($path, $segStart);
        return $segments;
    }

    /**
     * Public PHP replacement value -> cval. Int/float/string/bool/null
     * keep their type; tagged payloads go through the SAME strict
     * wire-ingest as the native codec (cabi/wire.rs canonical_integer /
     * canonical_float): the payload must be a STRING, `$i` is
     * canonicalized (explicit `+` stripped, redundant leading zeros
     * trimmed, signed zero normalized to "0", bignums pass through
     * digit-exactly), `$f` must be a finite-decimal lexical form
     * ('.' or exponent) parsed to f64 and re-emitted shortest
     * round-trip — a non-finite result (e.g. "1e400") passes through
     * verbatim as a wire-only value. ANY invalid payload is a
     * source-less Message transport failure, never a silently coerced
     * scalar (a bogus `$f` must not collapse to 0.0 and produce a
     * false no-op). An empty PHP array follows the nested EMPTY LIST
     * convention (documented like the writer's); a sequential array is
     * an Array, an associative one an Object.
     *
     * @param mixed $v
     * @return mixed cval
     */
    public static function publicToCval($v)
    {
        if (is_array($v) && count($v) === 1
            && (array_key_exists('$i', $v) || array_key_exists('$f', $v))) {
            // Single-key tagged payload: the tag is recognized even for
            // a null value (array_key_exists, not isset).
            if (array_key_exists('$i', $v)) {
                $p = $v['$i'];
                if (!is_string($p)) {
                    throw EditEnvelope::message(
                        'edit replacement transport: $i payload must be a string'
                    );
                }
                return ['t' => 'int', 'text' => self::canonicalWireInteger($p)];
            }
            $p = $v['$f'];
            if (!is_string($p)) {
                throw EditEnvelope::message(
                    'edit replacement transport: $f payload must be a string'
                );
            }
            return self::canonicalWireFloat($p);
        }
        if ($v === null) {
            return ['t' => 'null'];
        }
        if (is_bool($v)) {
            return ['t' => 'bool', 'v' => $v];
        }
        if (is_int($v)) {
            return ['t' => 'int', 'text' => (string) $v];
        }
        if (is_float($v)) {
            // Direct PHP non-finite floats never reach the native codec
            // (the wire wrapper rejects them pre-call) — mirror that as
            // a transport Message instead of rendering garbage.
            if (!is_finite($v)) {
                throw EditEnvelope::message(
                    'edit replacement transport: non-finite Float'
                );
            }
            return ['t' => 'float', 'v' => $v, 'text' => self::floatText($v)];
        }
        if (is_string($v)) {
            return ['t' => 'string', 'v' => $v];
        }
        if (is_array($v)) {
            if ($v === [] || EditSplice::isList($v)) {
                $items = [];
                foreach ($v as $child) {
                    $items[] = self::publicToCval($child);
                }
                return ['t' => 'array', 'items' => $items];
            }
            $pairs = [];
            foreach ($v as $k => $child) {
                $pairs[] = [(string) $k, self::publicToCval($child)];
            }
            return ['t' => 'object', 'pairs' => $pairs];
        }
        // Wire/transport failure: no invented source location, same
        // Message category as the native JSON transport.
        throw EditEnvelope::message(
            'edit replacement transport: unsupported PHP type ' . gettype($v)
        );
    }

    /** @param mixed $v */
    private static function isList($v): bool
    {
        $i = 0;
        foreach ($v as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    /** Pretty float text (shortest round-trip, forced decimal point). */
    private static function floatText(float $f): string
    {
        $s = PureFloat::shortestDigits($f);
        if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) {
            $s .= '.0';
        }
        return $s;
    }

    /**
     * cabi/wire.rs canonical_integer: optional sign, ASCII digits
     * only, explicit `+` stripped, redundant leading zeros trimmed,
     * signed zero normalized to "0", i64-overflow bignums pass through
     * digit-exactly. Anything else is a wire-contract Message.
     */
    private static function canonicalWireInteger(string $s): string
    {
        $sign = '';
        $digits = $s;
        $b0 = $s === '' ? 0 : ord($s[0]);
        if ($b0 === 0x2D) { // '-'
            $sign = '-';
            $digits = substr($s, 1);
        } elseif ($b0 === 0x2B) { // '+'
            $digits = substr($s, 1);
        }
        if ($digits === '' || !ctype_digit($digits)) {
            throw EditEnvelope::message(
                'edit replacement transport: $i payload not an integer literal'
            );
        }
        $trimmed = ltrim($digits, '0');
        if ($trimmed === '') {
            return '0'; // "-0", "+0", "000" -> "0"
        }
        return $sign . $trimmed;
    }

    /**
     * cabi/wire.rs canonical_float: the payload must be a decimal
     * lexical form ('.' or exponent), parse to f64; finite results are
     * re-emitted shortest round-trip, a non-finite parse (extreme
     * exponent, e.g. "1e400") passes through VERBATIM as a wire-only
     * value the writers reject at render time. Invalid payloads are a
     * wire-contract Message — never a silent 0.0 coercion.
     */
    private static function canonicalWireFloat(string $s): array
    {
        if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) {
            throw EditEnvelope::message(
                'edit replacement transport: $f payload must contain \'.\' or exponent'
            );
        }
        // Strict decimal parse (no leading/trailing whitespace, no PHP
        // hex strings — mirroring f64::from_str).
        $t = $s;
        if ($t !== '' && (ord($t[0]) === 0x2B || ord($t[0]) === 0x2D)) {
            $t = substr($t, 1);
        }
        $valid = $t !== '' && preg_match('/^\d*\.?\d+([eE][+-]?\d+)?$|^\d+\.([eE][+-]?\d+)?$/', $t) === 1;
        if (!$valid) {
            throw EditEnvelope::message(
                'edit replacement transport: $f payload not a finite decimal'
            );
        }
        $val = (float) $s;
        if (!is_finite($val)) {
            // Wire-only non-finite: verbatim text (never normalized).
            return ['t' => 'float', 'v' => $val, 'text' => $s];
        }
        return ['t' => 'float', 'v' => $val, 'text' => self::floatText($val)];
    }

    // -----------------------------------------------------------------
    // Core apply
    // -----------------------------------------------------------------

    /**
     * @param string[] $segments
     * @param mixed $c replacement cval
     */
    private static function apply(string $src, callable $decodePath, $c): string
    {
        // (1) SOURCE FIRST (C ABI order): UTF-8 validate + parse; its
        // envelope propagates VERBATIM (0.9 parser diagnostics, never
        // an edit code). The parsed original feeds no-op detection and
        // verification.
        $original = EditEnvelope::toCval(PureParser::parse($src, false));
        if (is_array($c)) {
            \Ktav\Internal\ArrayGraph::assertAcyclic($c);
        }
        try {
            // (2) THEN the path/replacement transport decode.
            $segments = $decodePath();
            return self::applyInner($src, $segments, self::publicToCval($c), $original);
        } catch (KtavException $e) {
            $code = $e->getError();
            if ($code === 'EditInvalidPath' || $code === 'EditNotFound'
                || $code === 'EditUnsupported' || $code === 'Message') {
                throw $e;
            }
            // A parser envelope surfaced mid-edit (e.g. an invalid
            // standalone render during layout): typed Unsupported,
            // keeping the original message text.
            throw EditEnvelope::unsupported($e->getMessage());
        } catch (\RuntimeException $e) {
            throw EditEnvelope::unsupported('internal: ' . $e->getMessage());
        }
    }

    /**
     * @param string[] $segments
     * @param mixed $c
     * @param mixed $original parsed original cval (target included)
     */
    private static function applyInner(string $src, array $segments, $c, $original): string
    {
        // (2) The target must exist in the parsed original.
        $target = self::lookupValue($original, $segments);
        if ($target === null) {
            throw EditEnvelope::notFound();
        }
        // (3) Byte-identical no-op (even noncanonical source spelling).
        if (self::replacementEqual($target, $c)) {
            return $src;
        }

        // (4) Positional tree + descent.
        list($root, $rootExplicit) = EditTree::build($src);
        list($node, $inlineLeaf, $rootFirst) = self::descend($root, $segments, $rootExplicit);

        $slot = $node['slot'];
        if ($slot === null) {
            throw EditEnvelope::unsupported(
                'target is a noncontiguous value (dotted-key/reopen intermediate);'
                . ' no lossless single-range splice exists'
            );
        }
        $slotKind = $slot['r']; // 'pair' | 'item' | 'block'

        list($b0, $b1, $a) = self::slotRanges($slot);
        if ($b0 > $b1 || $a > $b1 || !($a <= strlen($src)) || !($b1 <= strlen($src))) {
            throw EditEnvelope::unsupported('internal: invalid marker/body offsets');
        }

        $inlineItem = $inlineLeaf && $slotKind === 'item';
        $existingRaw = false;
        $wsRaw = '';
        $wsPlain = '';
        if ($slotKind !== 'block' && !$inlineItem) {
            $existingRaw = $a + 1 < strlen($src) && ord($src[$a + 1]) === 0x3A;
            if ($existingRaw) {
                $wsRaw = substr($src, $a + 2, $b0 - ($a + 2));
                if ($wsRaw === false) {
                    throw EditEnvelope::unsupported('internal: invalid raw marker range');
                }
            }
            if ($slotKind === 'pair') {
                $wsPlain = substr($src, $a + 1, $b0 - ($a + 1));
                if ($wsPlain === false) {
                    throw EditEnvelope::unsupported('internal: invalid plain marker range');
                }
            }
        }

        // Structural replacement of a container holding comment lines:
        // typed refusal BEFORE the splice. Multiline scalar blocks are
        // data and excluded from the scan.
        if ($node['kind'] !== 'scalar') {
            $skip = [];
            self::collectMultilineScalarRanges($node, $skip);
            if (self::hasCommentLine($src, $b0, $b1, $skip)) {
                throw EditEnvelope::unsupported(
                    'target container contains comment lines; structural'
                    . ' replacement cannot preserve them losslessly'
                );
            }
        }

        $indent = EditTree::lineIndent($src, $a);
        $eol = EditTree::detectEol($src, $b1);
        $isCompound = $node['kind'] !== 'scalar';
        $roleItemBlock = $slotKind === 'block' && $slot['anchor'] === null;
        $wsPairBlock = '';
        if ($slotKind === 'block' && !$roleItemBlock) {
            $wsPairBlock = substr($src, $a + 1, $b0 - ($a + 1));
            if ($wsPairBlock === false) {
                throw EditEnvelope::unsupported('internal: invalid block pair marker range');
            }
        }

        // ---- Build the splice plan -------------------------------------
        if ($inlineLeaf) {
            list($planFrom, $prefix0, $prefix1, $body) = self::planInline(
                $slotKind, $c, $existingRaw, $src, $a, $b0, $b1, $wsRaw
            );
        } elseif ($slotKind === 'block') {
            list($planFrom, $prefix0, $prefix1, $body) = self::planBlock(
                $c, $roleItemBlock, $existingRaw, $rootFirst, $indent, $eol,
                $isCompound, $a, $b0, $wsPairBlock
            );
        } else {
            list($planFrom, $prefix0, $prefix1, $body) = self::planLinear(
                $c, $slotKind, $existingRaw, $rootFirst, $indent, $eol,
                $isCompound, $a, $b0, $b1, $wsRaw, $wsPlain
            );
        }

        $insertion = strlen($prefix0) + strlen($prefix1) + strlen($body);
        $candidate = substr($src, 0, $planFrom) . $prefix0 . $prefix1 . $body . substr($src, $b1);

        // ---- Layout: minimal inline-wrapper expansion -------------------
        $candidate = self::layoutAdjust(
            $src, $candidate, $root, $segments, $c,
            $planFrom, $b1, $insertion, $inlineLeaf
        );

        // ---- Mandatory semantic verification (fail closed) --------------
        $patched = EditEnvelope::toCval(PureParser::parse($candidate, false));
        $actual = self::takeTarget($patched, $segments);
        if ($actual === null || !self::replacementEqual($actual, $c)) {
            throw EditEnvelope::unsupported('semantic mismatch after splice; no lossless patch produced');
        }
        $expected = $original;
        if (self::takeTarget($expected, $segments) === null) {
            throw EditEnvelope::notFound();
        }
        if (!EditEnvelope::cvalEqual($patched, $expected)) {
            throw EditEnvelope::unsupported('semantic mismatch after splice; no lossless patch produced');
        }
        return $candidate;
    }

    /** @return array{int,int,int} [bodyStart, bodyEnd, markerAnchor] */
    private static function slotRanges(array $slot): array
    {
        switch ($slot['r']) {
            case 'pair':
                return [$slot['start'], $slot['end'], $slot['colon']];
            case 'item':
                return [$slot['body0'], $slot['end'], $slot['start']];
            default: // block
                return [$slot['start'], $slot['end'], $slot['anchor'] !== null ? $slot['anchor'] : $slot['start']];
        }
    }

    /**
     * Descend the positional tree by decoded segments. Returns
     * [node, physicallyInline, rootFirst].
     *
     * @param string[] $segments
     * @return array{array,bool,bool}
     */
    private static function descend(array $root, array $segments, bool $rootExplicit): array
    {
        $containerIsRoot = true;
        $cur = $root;
        $rootFirst = false;
        foreach ($segments as $seg) {
            if ($cur['kind'] === 'object') {
                $rootFirst = false;
                $cur = self::childOf($cur, $seg);
                if ($cur === null) {
                    throw EditEnvelope::notFound();
                }
            } elseif ($cur['kind'] === 'array') {
                $idx = self::parseIndex($seg);
                $rootFirst = $containerIsRoot && !$rootExplicit && $idx === 0;
                if (!isset($cur['items'][$idx])) {
                    throw EditEnvelope::notFound();
                }
                $cur = $cur['items'][$idx];
            } else {
                throw EditEnvelope::notFound(); // scalar descent
            }
            $containerIsRoot = false;
        }
        return [$cur, $cur['inline'], $rootFirst];
    }

    /** @return array|null */
    private static function childOf(array $node, string $key)
    {
        foreach ($node['pairs'] as $pair) {
            if ($pair[0] === $key) {
                return $pair[1];
            }
        }
        return null;
    }

    /** ASCII digits only; leading zeros allowed; signs are not indexes. */
    private static function parseIndex(string $seg): int
    {
        $n = strlen($seg);
        if ($n === 0) {
            throw EditEnvelope::notFound();
        }
        $v = 0;
        for ($i = 0; $i < $n; ++$i) {
            $b = ord($seg[$i]);
            if ($b < 0x30 || $b > 0x39) {
                throw EditEnvelope::notFound();
            }
            $v = $v * 10 + ($b - 0x30);
            if ($v > PHP_INT_MAX / 2 - 1) {
                // Overflow guard: definitely out of bounds.
                $v = PHP_INT_MAX;
            }
        }
        return (int) $v;
    }

    /**
     * Current value at the path in a parsed cval (null when absent).
     *
     * @param mixed $v
     * @param string[] $segments
     * @return mixed
     */
    private static function lookupValue($v, array $segments)
    {
        $cur = $v;
        foreach ($segments as $seg) {
            if (!is_array($cur)) {
                return null;
            }
            if ($cur['t'] === 'object') {
                $cur = self::cvalChild($cur, $seg);
            } elseif ($cur['t'] === 'array') {
                $idx = self::parseIndexSoft($seg);
                if ($idx === null || !isset($cur['items'][$idx])) {
                    return null;
                }
                $cur = $cur['items'][$idx];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** @return mixed|null */
    private static function cvalChild(array $v, string $key)
    {
        foreach ($v['pairs'] as $pair) {
            if ($pair[0] === $key) {
                return $pair[1];
            }
        }
        return null;
    }

    /** Like parseIndex but null instead of an envelope. */
    private static function parseIndexSoft(string $seg): ?int
    {
        try {
            return self::parseIndex($seg);
        } catch (KtavException $e) {
            return null;
        }
    }

    /**
     * Remove (and return) the target from a mutable cval tree by
     * replacing it with the ['t'=>'removed'] sentinel.
     *
     * @param mixed $v
     * @param string[] $segments
     * @return mixed|null
     */
    private static function takeTarget(&$v, array $segments)
    {
        $last = count($segments) - 1;
        $cur = &$v;
        foreach ($segments as $i => $seg) {
            if (!is_array($cur) || ($cur['t'] !== 'object' && $cur['t'] !== 'array')) {
                return null;
            }
            if ($i === $last) {
                if ($cur['t'] === 'object') {
                    foreach ($cur['pairs'] as $j => $pair) {
                        if ($pair[0] === $seg) {
                            $taken = $cur['pairs'][$j][1];
                            $cur['pairs'][$j][1] = ['t' => 'removed'];
                            return $taken;
                        }
                    }
                    return null;
                }
                $idx = self::parseIndexSoft($seg);
                if ($idx === null || !isset($cur['items'][$idx])) {
                    return null;
                }
                $taken = $cur['items'][$idx];
                $cur['items'][$idx] = ['t' => 'removed'];
                return $taken;
            }
            if ($cur['t'] === 'object') {
                $next = null;
                foreach ($cur['pairs'] as $j => $pair) {
                    if ($pair[0] === $seg) {
                        $next = $j;
                        break;
                    }
                }
                if ($next === null) {
                    return null;
                }
                $cur = &$cur['pairs'][$next][1];
            } else {
                $idx = self::parseIndexSoft($seg);
                if ($idx === null || !isset($cur['items'][$idx])) {
                    return null;
                }
                $cur = &$cur['items'][$idx];
            }
        }
        return null;
    }

    /**
     * No-op / verification equality: ints compare as digit strings,
     * floats numerically (so a noncanonical source spelling of the
     * SAME value stays byte-identical), strings exactly.
     *
     * @param mixed $parsed parsed-side cval
     * @param mixed $c replacement cval
     */
    private static function replacementEqual($parsed, $c): bool
    {
        if (!is_array($parsed) || !is_array($c)) {
            return false;
        }
        $pt = $parsed['t'];
        $ct = $c['t'];
        if ($pt !== $ct) {
            return false;
        }
        switch ($ct) {
            case 'object':
                // Rust IndexMap PartialEq IGNORES pair order (map
                // equality by keys) — an equal object with a different
                // key order is a byte-identical no-op. Arrays stay
                // ordered; only Object is a map.
                $pc = $c['pairs'];
                if (count($parsed['pairs']) !== count($pc)) {
                    return false;
                }
                foreach ($parsed['pairs'] as $pair) {
                    $matched = false;
                    foreach ($pc as $other) {
                        if ($other[0] === $pair[0]) {
                            if (!self::replacementEqual($pair[1], $other[1])) {
                                return false;
                            }
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false;
                    }
                }
                return true;
            case 'array':
                $ia = $parsed['items'];
                $ic = $c['items'];
                if (count($ia) !== count($ic)) {
                    return false;
                }
                foreach ($ia as $i => $item) {
                    if (!self::replacementEqual($item, $ic[$i])) {
                        return false;
                    }
                }
                return true;
            case 'int':
                return PureScanner::digitCmp(ltrim($parsed['text'], '+'), ltrim($c['text'], '+')) === 0
                    && (strpos($parsed['text'], '-') === 0) === (strpos($c['text'], '-') === 0);
            case 'float':
                // Exact f64 identity via the ini-independent shortest
                // round-trip digits (injective on f64, sign of zero
                // kept) — never a lossy (string) cast under the
                // `precision` ini, and -0.0 !== 0.0 semantically.
                // Wire-only non-finite payloads (e.g. "1e400" tagged)
                // compare by their verbatim text; a parsed source value
                // is always finite, so they never false-match.
                $pf = (float) $parsed['v'];
                $cf = (float) $c['v'];
                if (!is_finite($pf) || !is_finite($cf)) {
                    return isset($parsed['text'], $c['text'])
                        && $parsed['text'] === $c['text'];
                }
                return PureFloat::shortestDigits($pf)
                    === PureFloat::shortestDigits($cf);
            default:
                return $parsed === $c;
        }
    }

    // -----------------------------------------------------------------
    // Comment-line scan for structural replacement
    // -----------------------------------------------------------------

    /** @param array[] $node
     * @param array[] $out */
    private static function collectMultilineScalarRanges(array $node, array &$out)
    {
        if ($node['kind'] === 'scalar') {
            if (is_array($node['slot']) && $node['slot']['r'] === 'block') {
                $out[] = [$node['slot']['start'], $node['slot']['end']];
            }
            return;
        }
        if ($node['kind'] === 'object') {
            foreach ($node['pairs'] as $pair) {
                self::collectMultilineScalarRanges($pair[1], $out);
            }
            return;
        }
        foreach ($node['items'] as $item) {
            self::collectMultilineScalarRanges($item, $out);
        }
    }

    /**
     * True when [from, to) contains a line whose first non-§3.3-whitespace
     * code point starts `##`, outside the (sorted) multiline-scalar data
     * ranges. Monotonic cursor — O(lines + ranges).
     *
     * @param array[] $skip
     */
    private static function hasCommentLine(string $text, int $from, int $to, array &$skip): bool
    {
        usort($skip, static function (array $x, array $y): int {
            return $x[0] <=> $y[0];
        });
        $skipI = 0;
        $n = strlen($text);
        $i = $from;
        while ($i < $to && $i < $n) {
            $lineStart = $i;
            $j = $i;
            while ($j < $to && $j < $n && ord($text[$j]) !== 0x0A && ord($text[$j]) !== 0x0D) {
                ++$j;
            }
            // First non-whitespace byte of the line.
            $k = $lineStart;
            while ($k < $j && ($l = PureScanner::wsLenAt($text, $k, $j, true)) > 0) {
                $k += $l;
            }
            while ($skipI < count($skip) && $skip[$skipI][1] <= $k) {
                ++$skipI;
            }
            $inData = $skipI < count($skip) && $skip[$skipI][0] <= $k && $k < $skip[$skipI][1];
            if (!$inData && $k + 2 <= $n && substr($text, $k, 2) === '##') {
                return true;
            }
            $i = $j;
            while ($i < $to && $i < $n && (ord($text[$i]) === 0x0A || ord($text[$i]) === 0x0D)) {
                ++$i;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // Inline bodies (§ 3.7 encoding)
    // -----------------------------------------------------------------

    /** Scalar body text (non-string scalars only). */
    private static function scalarBodyText(array $c): string
    {
        switch ($c['t']) {
            case 'null': return 'null';
            case 'bool': return $c['v'] ? 'true' : 'false';
            case 'int':
            case 'float':
                return $c['text'];
        }
        throw EditEnvelope::unsupported('internal: scalar body requested for a compound');
    }

    /** Body of a PHYSICALLY inline value (marker rules differ). */
    private static function inlineBody(array $c, bool $forceString): string
    {
        if ($c['t'] === 'string') {
            return EditEnvelope::encodeInline($c['v'], $forceString && PureText::needsRawMarker($c['v']));
        }
        if ($c['t'] === 'object' || $c['t'] === 'array') {
            $out = '';
            self::pushInlineValue($c, 0, $out);
            return $out;
        }
        return self::scalarBodyText($c);
    }

    /** @param mixed $c */
    private static function pushInlineValue($c, int $depth, string &$out)
    {
        if ($depth > 128) {
            throw EditEnvelope::unsupported('replacement nesting exceeds inline depth limit (128)');
        }
        if ($c['t'] === 'object') {
            $out .= '{';
            $first = true;
            foreach ($c['pairs'] as $pair) {
                if (!$first) {
                    $out .= ', ';
                }
                $first = false;
                $out .= self::escapedKeySegment($pair[0]);
                $out .= ': ';
                $child = $pair[1];
                if ($child['t'] === 'string') {
                    $out .= EditEnvelope::encodeInline($child['v'], PureText::needsRawMarker($child['v']));
                } else {
                    self::pushInlineValue($child, $depth + 1, $out);
                }
            }
            $out .= '}';
            return;
        }
        if ($c['t'] === 'array') {
            $out .= '[';
            $first = true;
            foreach ($c['items'] as $child) {
                if (!$first) {
                    $out .= ', ';
                }
                $first = false;
                if ($child['t'] === 'string' && $child['v'] === '') {
                    $out .= '()';
                } elseif ($child['t'] === 'string') {
                    $out .= EditEnvelope::encodeInline($child['v'], PureText::needsRawMarker($child['v']));
                } else {
                    self::pushInlineValue($child, $depth + 1, $out);
                }
            }
            $out .= ']';
            return;
        }
        if ($c['t'] === 'string') {
            $out .= EditEnvelope::encodeInline($c['v'], PureText::needsRawMarker($c['v']));
            return;
        }
        $out .= self::scalarBodyText($c);
    }

    /**
     * § 5.9.10 key segment emitter for inline compound bodies: bare
     * with re-escaping, or a quoted `"` form for structural / edge
     * code points (matches the pure writer's rules).
     */
    private static function escapedKeySegment(string $key): string
    {
        $n = strlen($key);
        if ($n > 0) {
            $b0 = ord($key[0]);
            if (PureScanner::isQuoteByte($b0)
                || substr($key, 0, 2) === '##'
                || substr($key, 0, 3) === "\xEF\xBB\xBF") {
                return self::quotedKey($key);
            }
            list($cp0,) = PureText::cpAt($key, 0);
            if (PureText::isInlineWsCp($cp0)) {
                return self::quotedKey($key);
            }
            $j = $n - 1;
            while ($j > 0 && (ord($key[$j]) & 0xC0) === 0x80) {
                --$j;
            }
            list($cpL,) = PureText::cpAt($key, $j);
            if (PureText::isInlineWsCp($cpL)) {
                return self::quotedKey($key);
            }
        }
        $out = '';
        $i = 0;
        while ($i < $n) {
            $b = ord($key[$i]);
            switch ($b) {
                case 0x2E: case 0x3A: case 0x2C:
                case 0x7B: case 0x7D: case 0x5B: case 0x5D:
                case 0x28: case 0x29:
                    return self::quotedKey($key);
                case 0x5C:
                    $out .= '\\\\';
                    break;
                case 0x0A:
                    $out .= '\n';
                    break;
                case 0x0D:
                    $out .= '\r';
                    break;
                case 0x09: case 0x0B: case 0x0C:
                    $out .= chr($b);
                    break;
                default:
                    if ($b < 0x20 || $b === 0x7F) {
                        $out .= EditEnvelope::uEscape($b);
                    } else {
                        $out .= chr($b);
                    }
            }
            ++$i;
        }
        return $out;
    }

    /** @return string */
    private static function quotedKey(string $key): string
    {
        $out = '"';
        $n = strlen($key);
        $i = 0;
        while ($i < $n) {
            $b = ord($key[$i]);
            if ($b < 0x80) {
                switch ($b) {
                    case 0x22: $out .= '\\"'; break;
                    case 0x5C: $out .= '\\\\'; break;
                    case 0x0A: $out .= '\n'; break;
                    case 0x0D: $out .= '\r'; break;
                    default:
                        if (($b < 0x20 && $b !== 0x09 && $b !== 0x0B && $b !== 0x0C) || $b === 0x7F) {
                            $out .= EditEnvelope::uEscape($b);
                        } else {
                            $out .= chr($b);
                        }
                }
                ++$i;
                continue;
            }
            list($cp, $len) = PureText::cpAt($key, $i);
            if (($cp < 0x20 && !($cp === 0x09 || $cp === 0x0B || $cp === 0x0C)) || $cp === 0x7F) {
                $out .= EditEnvelope::uEscape($cp);
            } else {
                $out .= substr($key, $i, $len);
            }
            $i += $len;
        }
        return $out . '"';
    }

    // -----------------------------------------------------------------
    // Plans
    // -----------------------------------------------------------------

    /**
     * Physically inline target. Returns [from, prefix0, prefix1, body].
     *
     * @return array{int,string,string,string}
     */
    private static function planInline(
        string $slotKind,
        array $c,
        bool $existingRaw,
        string $src,
        int $a,
        int $b0,
        int $b1,
        string $wsRaw
    ): array {
        if ($slotKind === 'item') {
            // Inline array item: `::` is literal data — no marker.
            $body = ($c['t'] === 'string' && $c['v'] === '') ? '()' : self::inlineBody($c, true);
            return [$b0, '', '', $body];
        }
        if ($existingRaw && $c['t'] !== 'string') {
            // Raw body is literal: strip only the SECOND colon, keep the
            // original whitespace.
            return [$a + 1, $wsRaw, '', self::inlineBody($c, false)];
        }
        return [$b0, '', '', self::inlineBody($c, !$existingRaw)];
    }

    /**
     * Linear (pair/item line) slots. Returns [from, prefix0, prefix1, body].
     *
     * @return array{int,string,string,string}
     */
    private static function planLinear(
        array $c,
        string $slotKind,
        bool $existingRaw,
        bool $rootFirst,
        string $indent,
        string $eol,
        bool $isCompound,
        int $a,
        int $b0,
        int $b1,
        string $wsRaw,
        string $wsPlain
    ): array {
        list($body, $desiredRaw) = self::planValue(
            $c, $slotKind, $existingRaw, $rootFirst, $indent, $eol,
            $isCompound, false
        );
        if ($existingRaw === $desiredRaw) {
            $prefix = ($b0 === $b1 && $wsPlain === '' && $wsRaw === '') ? ' ' : '';
            return [$b0, $prefix, '', $body];
        }
        if ($existingRaw && !$desiredRaw) {
            if ($slotKind === 'pair') {
                // 'k:: x' -> 'k: x': strip only the second colon.
                return [$a + 1, self::sepWs($b0, $b1, $wsRaw), '', $body];
            }
            // Item: remove '::' but keep the original whitespace.
            return [$a, $wsRaw, '', $body];
        }
        // plain -> raw
        if ($slotKind === 'pair') {
            return [$a + 1, ':', self::sepWs($b0, $b1, $wsPlain), $body];
        }
        return [$a, ':: ', '', $body];
    }

    /** Marker-zone whitespace helper: one space on an empty body. */
    private static function sepWs(int $b0, int $b1, string $ws): string
    {
        return ($b0 === $b1 && $ws === '') ? ' ' : $ws;
    }

    /**
     * plan_value: (body, desiredRaw) for a linear slot.
     *
     * @return array{string,bool}
     */
    private static function planValue(
        array $c,
        string $slotKind,
        bool $existingRaw,
        bool $rootFirst,
        string $indent,
        string $eol,
        bool $isCompoundInline,
        bool $isCompoundBlock
    ): array {
        if ($c['t'] === 'string') {
            return self::planString($c['v'], $slotKind, $existingRaw, $rootFirst, $indent, $eol);
        }
        if ($c['t'] !== 'object' && $c['t'] !== 'array') {
            return [self::scalarBodyText($c), false];
        }
        if ($isCompoundInline) {
            $out = '';
            self::pushInlineValue($c, 0, $out);
            return [$out, false];
        }
        $isEmpty = $c['t'] === 'object' ? $c['pairs'] === [] : $c['items'] === [];
        if ($isEmpty) {
            if ($isCompoundBlock) {
                $opener = $c['t'] === 'object' ? '{' : '[';
                return [$opener . $eol . $indent . ($c['t'] === 'object' ? '}' : ']'), false];
            }
            return [$c['t'] === 'object' ? '{}' : '[]', false];
        }
        $rendered = self::renderCompoundBlock($c, $indent, $eol);
        return [$rendered, false];
    }

    /**
     * String replacement plan for linear slots (outside inline).
     *
     * @return array{string,bool}
     */
    private static function planString(
        string $s,
        string $slotKind,
        bool $existingRaw,
        bool $rootFirst,
        string $indent,
        string $eol
    ): array {
        if (strpos($s, "\r") !== false) {
            throw EditEnvelope::unsupported('replacement string contains CR (U+000D)');
        }
        $edgeWs = self::edgeWs($s);
        if (strpos($s, "\n") !== false || $edgeWs) {
            // Plain marker is mandatory: raw does not open multiline
            // blocks and trims edges.
            return [self::verbatimBlockBody($s, $indent, $eol), false];
        }
        $desiredRaw = $slotKind === 'item'
            ? (PureText::itemNeedsRawMarker($s) || $s === '')
            : PureText::needsRawMarker($s);
        if ($slotKind === 'item' && $rootFirst) {
            // § 5.0.1 root dispatch: pair-shaped / BOM bodies of the
            // first item of an IMPLICIT root array must be raw.
            $desiredRaw = $desiredRaw || PureScanner::isPairShape($s)
                || strpos($s, "\xEF\xBB\xBF") === 0;
        }
        if ($existingRaw) {
            // Raw preservation: compatible single-line bodies keep the
            // marker (raw unnecessary != raw forbidden).
            $desiredRaw = true;
        }
        return [$s, $desiredRaw];
    }

    /** @return bool first/last code point is § 3.3 whitespace */
    private static function edgeWs(string $s): bool
    {
        $n = strlen($s);
        if ($n === 0) {
            return false;
        }
        list($cp0,) = PureText::cpAt($s, 0);
        if (PureText::isKtavWsCp($cp0)) {
            return true;
        }
        $j = $n - 1;
        while ($j > 0 && (ord($s[$j]) & 0xC0) === 0x80) {
            --$j;
        }
        list($cpL,) = PureText::cpAt($s, $j);
        return PureText::isKtavWsCp($cpL);
    }

    /**
     * Verbatim block `(( ... ))`: content verbatim, '\n' -> document
     * EOL; a line equal to `))` has no lossless verbatim form.
     */
    private static function verbatimBlockBody(string $s, string $indent, string $eol): string
    {
        foreach (explode("\n", $s) as $line) {
            $t = trim($line, " \t");
            if ($t === '))') {
                throw EditEnvelope::unsupported(
                    "multiline string contains a line equal to '))'; no lossless verbatim form exists"
                );
            }
        }
        $content = $eol === "\n" ? $s : str_replace("\n", $eol, $s);
        return '((' . $eol . $content . $eol . $indent . '))';
    }

    /**
     * Multiline-BLOCK target. Returns [from, prefix0, prefix1, body].
     *
     * @return array{int,string,string,string}
     */
    private static function planBlock(
        array $c,
        bool $roleItemBlock,
        bool $existingRaw,
        bool $rootFirst,
        string $indent,
        string $eol,
        bool $isCompound,
        int $a,
        int $b0,
        string $wsPairBlock
    ): array {
        if ($c['t'] === 'string') {
            $s = $c['v'];
            if (strpos($s, "\r") !== false) {
                throw EditEnvelope::unsupported('replacement string contains CR (U+000D)');
            }
            $multiline = strpos($s, "\n") !== false || self::edgeWs($s);
            if ($multiline) {
                return [$b0, '', '', self::verbatimBlockBody($s, $indent, $eol)];
            }
            if ($roleItemBlock && $s === '') {
                // `()` parses as the empty String; a bare empty line
                // would be blank.
                return [$b0, '', '', '()'];
            }
            $itemRaw = $roleItemBlock
                && (PureText::itemNeedsRawMarker($s)
                    || ($rootFirst && (PureScanner::isPairShape($s) || strpos($s, "\xEF\xBB\xBF") === 0)));
            $pairRaw = !$roleItemBlock && PureText::needsRawMarker($s);
            if ($itemRaw) {
                return [$b0, ':: ', '', $s];
            }
            if ($pairRaw) {
                // Switch the pair marker to raw: second colon + the
                // original whitespace + body.
                return [$a + 1, ':', $wsPairBlock, $s];
            }
            return [$b0, '', '', $s];
        }
        list($body, ) = self::planValue(
            $c, 'block', $existingRaw, $rootFirst, $indent, $eol, false, true
        );
        return [$b0, '', '', $body];
    }

    /**
     * Pretty block render of a non-empty compound at the target line
     * indent (four spaces per new level, source EOL). Strings follow
     * the pure writer's § 5.9.7 form selection via renderDocument.
     */
    private static function renderCompoundBlock(array $c, string $indent, string $eol): string
    {
        $public = self::cvalToPublic($c);
        $doc = PureWriter::renderDocument($public, false, false);
        $doc = substr($doc, 0, -1); // trailing newline
        $opener = $c['t'] === 'object' ? '{' : '[';
        $closer = $c['t'] === 'object' ? '}' : ']';
        $childIndent = $indent . '    ';
        return $opener . $eol . self::indentLines($doc, $childIndent, $eol) . $eol . $indent . $closer;
    }

    /** @param mixed $c */
    private static function cvalToPublic($c)
    {
        if (!is_array($c)) {
            return $c;
        }
        switch ($c['t']) {
            case 'object':
                $out = [];
                foreach ($c['pairs'] as $pair) {
                    $out[$pair[0]] = self::cvalToPublic($pair[1]);
                }
                return $out;
            case 'array':
                $out = [];
                foreach ($c['items'] as $item) {
                    $out[] = self::cvalToPublic($item);
                }
                return $out;
            case 'int':
                $t = ltrim($c['text'], '+');
                if (preg_match('/^-?\d+$/', $t) === 1
                    && PureScanner::digitCmp(ltrim($t, '-'), '9223372036854775807') <= 0
                    && ($t[0] !== '-' || PureScanner::digitCmp(substr($t, 1), '9223372036854775808') <= 0)) {
                    return (int) $t;
                }
                return ['$i' => $c['text']];
            case 'float':
                return (float) $c['text'];
            case 'bool':
                return $c['v'];
            case 'null':
                return null;
            default:
                return $c['v'];
        }
    }

    /**
     * Prefix every non-empty line with $childIndent, keeping verbatim
     * multiline-string content verbatim (context heuristic, fail-closed
     * under the semantic verification).
     */
    private static function indentLines(string $doc, string $childIndent, string $eol): string
    {
        $lines = explode("\n", $doc);
        $out = [];
        $inVerbatim = false;
        foreach ($lines as $line) {
            if ($line !== '' && !$inVerbatim) {
                $out[] = $childIndent . $line;
            } else {
                $out[] = $line;
            }
            $t = trim($line, " \t");
            if (substr($line, -2) === '((') {
                $inVerbatim = true;
            } elseif ($t === '))') {
                $inVerbatim = false;
            }
        }
        return implode($eol, $out);
    }

    // -----------------------------------------------------------------
    // Layout: minimal inline-wrapper expansion (port of layout.rs)
    // -----------------------------------------------------------------

    /**
     * @param string[] $segments
     * @param mixed $c
     */
    private static function layoutAdjust(
        string $source,
        string $candidate,
        array $root,
        array $segments,
        array $c,
        int $from,
        int $to,
        int $inserted,
        bool $inlineLeaf
    ): string {
        $owner = self::inlineOwner($source, $root, $segments, $from, $inlineLeaf);
        if ($owner === null || $from < $owner[0]) {
            return $candidate;
        }
        list($start, $end) = $owner;
        $newEnd = $end - ($to - $from) + $inserted;
        if ($newEnd < $start || $newEnd > strlen($candidate)) {
            throw EditEnvelope::unsupported('invalid layout range');
        }
        $body = substr($candidate, $start, $newEnd - $start);
        if ($body === false || !self::isCompoundText($body)) {
            return $candidate;
        }
        $oldWidth = EditEnvelope::countScalars(EditTree::physicalLine($source, $from));
        $limit = max($oldWidth, self::LINE_LIMIT);
        $force = EditEnvelope::requiresBlock($c);
        if (!$force && EditEnvelope::countScalars(EditTree::physicalLine($candidate, $from)) <= $limit) {
            return $candidate;
        }
        $indent = EditTree::lineIndent($source, $start);
        $eol = EditTree::detectEol($source, $end);
        $expanded = self::expand(
            $body, $indent, $eol, $limit,
            [$from - $start, $from - $start + $inserted], $force, 0
        );
        if ($expanded === null) {
            return $candidate; // lossless standalone impossible: keep inline
        }
        return substr($candidate, 0, $start) . $expanded . substr($candidate, $newEnd);
    }

    /** Leading § 3.3 inline-whitespace trim (width-correct). */
    private static function trimStartInline(string $s): string
    {
        $i = 0;
        $n = strlen($s);
        while ($i < $n && ($l = PureScanner::wsLenAt($s, $i, $n, true)) > 0) {
            $i += $l;
        }
        return $i === 0 ? $s : substr($s, $i);
    }

    /** @return bool */
    private static function isCompoundText(string $body): bool
    {
        return (substr($body, 0, 1) === '{' && substr($body, -1) === '}')
            || (substr($body, 0, 1) === '[' && substr($body, -1) === ']');
    }

    /**
     * Innermost…outermost inline compound owning the splice position:
     * the first single-line compound slot along the path, or the whole
     * trimmed physical line for a physically-inline root/leaf chain.
     *
     * @param string[] $segments
     * @return array{int,int}|null
     */
    private static function inlineOwner(
        string $source,
        array $root,
        array $segments,
        int $pos,
        bool $inlineLeaf
    ): ?array {
        if ($root['inline']) {
            return self::lineOwner($source, $pos);
        }
        $node = $root;
        foreach ($segments as $seg) {
            if ($node['kind'] === 'object') {
                $node = self::childOf($node, $seg);
            } elseif ($node['kind'] === 'array') {
                $idx = self::parseIndexSoft($seg);
                $node = $idx !== null ? ($node['items'][$idx] ?? null) : null;
            } else {
                $node = null;
            }
            if ($node === null) {
                throw EditEnvelope::notFound();
            }
            $slot = $node['slot'];
            if ($slot !== null) {
                list($start, $end) = self::slotRanges($slot);
                $body = substr($source, $start, $end - $start);
                if ($node['kind'] !== 'scalar' && $body !== false
                    && self::isCompoundText($body)
                    && strpos($body, "\n") === false && strpos($body, "\r") === false) {
                    return [$start, $end];
                }
            }
        }
        if ($node['inline']) {
            return self::lineOwner($source, $pos);
        }
        return null;
    }

    /** Trimmed (BOM-stripped) whole-line owner for inline contexts. */
    private static function lineOwner(string $source, int $pos): array
    {
        $line = EditTree::physicalLine($source, $pos);
        $start = strpos($source, $line, max(0, $pos - strlen($line)));
        list($ts, $te) = PureScanner::trimBounds($line, 0, strlen($line));
        $body = substr($line, $ts, $te - $ts);
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
            $ts += 3;
        }
        return [$start + $ts, $start + $ts + strlen($body)];
    }

    /**
     * Expand one inline compound body into a block. Returns null when
     * a moved part has no lossless standalone form (the caller keeps
     * the inline candidate).
     *
     * @param int[] $changed [from, to) relative to $body
     */
    private static function expand(
        string $body,
        string $indent,
        string $eol,
        int $limit,
        array $changed,
        bool $force,
        int $depth
    ): ?string {
        if ($depth > 128) {
            throw EditEnvelope::unsupported('layout nesting exceeds inline depth limit');
        }
        $object = substr($body, 0, 1) === '{';
        $kind = $object ? 'o' : 'a';
        $scan = \Ktav\Pure\PureInlineScan::scan($body, $kind, 1, strlen($body) - 1);
        if ($scan['badEscape'] !== null || $scan['closer'] === null) {
            throw EditEnvelope::unsupported('cannot split inline layout');
        }
        $childIndent = $indent . '    ';
        $out = $object ? '{' : '[';
        foreach ($scan['segs'] as $seg) {
            list($ss, $se) = PureScanner::trimBounds($body, $seg[0], $seg[1]);
            if ($ss >= $se) {
                continue; // trailing comma
            }
            $part = substr($body, $ss, $se - $ss);
            $offset = $ss;
            $touched = $offset < $changed[1] && $offset + strlen($part) > $changed[0];
            if ($object) {
                list($sep, $marker, $unterm) = PureScanner::findSeparator($part, 0, strlen($part));
                if ($sep < 0 || $unterm) {
                    throw EditEnvelope::unsupported('inline pair has no separator');
                }
                $raw = $marker === 2;
                $vs = $sep + $marker;
                while ($vs < strlen($part) && ($l = PureScanner::wsLenAt($part, $vs, strlen($part), true)) > 0) {
                    $vs += $l;
                }
                $value = substr($part, $vs);
                $prefix = substr($part, 0, strlen($part) - strlen($value));
            } else {
                $raw = false;
                $value = self::trimStartInline($part);
                $prefix = '';
            }
            $out .= $eol . $childIndent;
            $trimmedValue = self::trimStartInline($value);
            $isCompoundValue = self::isCompoundText($trimmedValue);
            if (!$raw && $isCompoundValue && $touched
                && ($force || EditEnvelope::countScalars($childIndent . $part) > $limit)) {
                $valueOffset = $offset + strlen($part) - strlen($value);
                $local = [$changed[0] - $valueOffset, $changed[1] - $valueOffset];
                $out .= $prefix;
                $child = self::expand($trimmedValue, $childIndent, $eol, $limit, $local, $force, $depth + 1);
                if ($child === null) {
                    return null;
                }
                $out .= $child;
            } else {
                $moved = self::standalone($part, $prefix, $value, $raw, $object, $childIndent, $eol);
                if ($moved === null) {
                    return null;
                }
                $out .= $moved;
            }
        }
        $out .= $eol . $indent . ($object ? '}' : ']');
        return $out;
    }

    /**
     * One untouched (or leaf) inline part moved onto its own line:
     * keep the bytes when the standalone form parses to the same value;
     * otherwise decode and re-render. Null when no lossless standalone
     * form exists.
     */
    private static function standalone(
        string $part,
        string $prefix,
        string $body,
        bool $raw,
        bool $object,
        string $indent,
        string $eol
    ): ?string {
        // When inline escapes stop being escapes, semantics must hold.
        $inlineText = $object ? '{' . $part . '}' : '[' . $part . ']';
        $expected = EditEnvelope::toCval(PureParser::parse($inlineText, false));
        $lineText = $object ? $part : "[\n" . $part . "\n]";
        $standaloneOk = false;
        try {
            $moved = EditEnvelope::toCval(PureParser::parse($lineText, false));
            $standaloneOk = EditEnvelope::cvalEqual($moved, $expected);
        } catch (KtavException $e) {
            $standaloneOk = false;
        }
        if ($standaloneOk) {
            return $part;
        }
        // Decode the moved value.
        if ($raw) {
            $d = EditEnvelope::decodeInlineScalar($body);
            if ($d === null) {
                throw EditEnvelope::unsupported('invalid inline string');
            }
            $c = ['t' => 'string', 'v' => $d[0]];
        } else {
            try {
                $parsed = PureParser::parse('[' . $body . ']', false);
            } catch (KtavException $e) {
                throw EditEnvelope::unsupported('cannot decode moved inline value');
            }
            if (!($parsed instanceof \Ktav\Pure\PureList) || count($parsed->items) !== 1) {
                throw EditEnvelope::unsupported('moved inline value is not a single value');
            }
            $c = EditEnvelope::toCval($parsed->items[0]);
        }
        if ($c['t'] === 'string') {
            if (strpos($c['v'], "\r") !== false) {
                return null; // CR: no lossless standalone string form
            }
            foreach (explode("\n", $c['v']) as $l) {
                if (trim($l, " \t") === '))') {
                    return null;
                }
            }
        }
        $slotKind = $object ? 'pair' : 'item';
        list($newBody, $desiredRaw) = self::planValue(
            $c, $slotKind, $raw, false, $indent, $eol, true, false
        );
        $out = '';
        if ($object) {
            list($sep, $marker, $unterm) = PureScanner::findSeparator($part, 0, strlen($part));
            if ($sep < 0 || $unterm) {
                throw EditEnvelope::unsupported('moved pair has no separator');
            }
            $out .= substr($part, 0, $sep + 1);
            if ($desiredRaw) {
                $out .= ':';
            }
            $ws = substr($prefix, $sep + ($raw ? 2 : 1));
            $out .= $ws === '' ? ' ' : $ws;
        } elseif ($desiredRaw) {
            $out .= ':: ';
        }
        $out .= $newBody;
        return $out;
    }
}
