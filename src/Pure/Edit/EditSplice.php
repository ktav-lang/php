<?php

declare(strict_types=1);

namespace Ktav\Pure\Edit;

use Ktav\KtavException;
use Ktav\Pure\PureFloat;
use Ktav\Pure\PureInlineScan;
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
                    throw EditEnvelope::message('path is not valid UTF-8: ' . self::utf8Error($path));
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

    /** Remove an existing value while preserving untouched source bytes. */
    public static function removeValue(string $src, string $path): string
    {
        PureParser::parse($src, false);
        if (PureScanner::validateUtf8($path) !== null) {
            throw EditEnvelope::message('path is not valid UTF-8: ' . self::utf8Error($path));
        }
        return self::removeValueSegments($src, self::parsePath($path));
    }

    /** @param string[] $segments */
    public static function removeValueSegments(string $src, array $segments): string
    {
        $original = EditEnvelope::toCval(PureParser::parse($src, false));
        self::validateMutationSegments($segments);
        $target = self::lookupValue($original, $segments);
        if ($target === null) { throw EditEnvelope::notFound(); }
        list($root,) = EditTree::build($src);
        $node = self::nodeAt($root, $segments);
        list($from, $to, $strippedLead) = self::mutationRange($src, $root, $segments, $node);
        if ($node['kind'] !== 'scalar') {
            $skip = [];
            self::collectMultilineScalarRanges($node, $skip);
            if (self::hasCommentLine($src, $from, $to, $skip)) {
                throw EditEnvelope::unsupported('target contains comment lines; removal cannot preserve them losslessly');
            }
        }
        $expected = $original;
        if (self::removeCvalTarget($expected, $segments) === null) { throw EditEnvelope::notFound(); }
        $parents = array_slice($segments, 0, -1);
        $materialized = null;
        if ($parents !== []) {
            $emptiedFrom = null;
            for ($start = 0; $start < count($parents); ++$start) {
                $allEmpty = true;
                for ($k = $start; $k < count($parents); ++$k) {
                    $ancestor = self::lookupValue($expected, array_slice($parents, 0, $k + 1));
                    if ($ancestor === null || $ancestor['t'] !== 'object' || $ancestor['pairs'] !== []) { $allEmpty = false; break; }
                }
                if ($allEmpty) { $emptiedFrom = $start; break; }
            }
            if ($emptiedFrom !== null) {
                $synthetic = self::nodeAt($root, array_slice($parents, 0, $emptiedFrom + 1))['slot'] === null;
                $containerDepth = 0;
                for ($i = 0; $i < count($parents); ++$i) {
                    if (self::nodeAt($root, array_slice($parents, 0, $i + 1))['slot'] !== null) { $containerDepth = $i + 1; }
                }
                if ($synthetic && $emptiedFrom >= $containerDepth) {
                    $leadLen = $strippedLead ? (substr($src, $from, 2) === "\r\n" ? 2 : 1) : 0;
                    $lineStart = $from + $leadLen;
                    $indentEnd = $lineStart;
                    while ($indentEnd < strlen($src) && ($wsLen = PureScanner::wsLenAt($src, $indentEnd, strlen($src), false)) > 0) { $indentEnd += $wsLen; }
                    $indent = substr($src, $lineStart, $indentEnd - $lineStart);
                    $key = implode('.', array_map([self::class, 'escapedKeySegment'], array_slice($parents, $containerDepth)));
                    $materialized = substr($src, $from, $leadLen) . $indent . $key . ': {}';
                    if ($to > $from && ($src[$to - 1] === "\n" || $src[$to - 1] === "\r")) {
                        $eolStart = $src[$to - 1] === "\n" && $to >= 2 && $src[$to - 2] === "\r" ? $to - 2 : $to - 1;
                        $materialized .= substr($src, $eolStart, $to - $eolStart);
                    }
                }
            }
        }
        $cut = static function (?string $replacement) use ($src, $from, $to): string {
            return substr($src, 0, $from) . ($replacement ?? '') . substr($src, $to);
        };
        $candidate = $cut(null);
        try {
            $actual = EditEnvelope::toCval(PureParser::parse($candidate, false));
            if (EditEnvelope::cvalEqual($actual, $expected)) { return $candidate; }
        } catch (KtavException $ignored) {
            // A plain cut can be unparseable when it prunes a synthetic dotted ancestor.
        }
        $candidate = $cut($materialized);
        try {
            $actual = EditEnvelope::toCval(PureParser::parse($candidate, false));
        } catch (KtavException $ignored) {
            throw EditEnvelope::unsupported('candidate source does not parse; no lossless edit produced');
        }
        if (!EditEnvelope::cvalEqual($actual, $expected)) {
            throw EditEnvelope::unsupported('semantic mismatch after splice; no lossless edit produced');
        }
        return $candidate;
    }

    /** Rename an existing Object member key without moving its value. */
    public static function renameKey(string $src, string $path, string $newKey): string
    {
        PureParser::parse($src, false);
        if (PureScanner::validateUtf8($path) !== null) { throw EditEnvelope::message('path is not valid UTF-8: ' . self::utf8Error($path)); }
        return self::renameKeySegments($src, self::parsePath($path), $newKey);
    }

    /** @param string[] $segments */
    public static function renameKeySegments(string $src, array $segments, string $newKey): string
    {
        $original = EditEnvelope::toCval(PureParser::parse($src, false));
        self::validateMutationSegments($segments);
        if (PureScanner::validateUtf8($newKey) !== null) { throw EditEnvelope::message('new key is not valid UTF-8: ' . self::utf8Error($newKey)); }
        if ($newKey === '') { throw EditEnvelope::unsupported('empty key name is not representable'); }
        $last = $segments[count($segments)-1];
        $parentPath = array_slice($segments, 0, -1);
        $parent = self::lookupValue($original, $parentPath);
        if ($parent === null) { throw EditEnvelope::notFound(); }
        if ($parent['t'] === 'array') {
            throw EditEnvelope::unsupported('rename of an Array element is not supported; only Object keys can be renamed');
        }
        if ($parent['t'] !== 'object') { throw EditEnvelope::notFound(); }
        if (self::cvalChild($parent, $last) === null) { throw EditEnvelope::notFound(); }
        if ($last === $newKey) { return $src; }
        if (self::cvalChild($parent, $newKey) !== null) { throw EditEnvelope::alreadyExists(); }
        list($root,) = EditTree::build($src);
        $parentNode = self::nodeAt($root, $parentPath);
        $targetNode = self::nodeAt($parentNode, [$last]);
        $colon = null;
        if ($targetNode['slot'] !== null) {
            if (isset($targetNode['slot']['colon'])) {
                $colon = $targetNode['slot']['colon'];
            } elseif (($targetNode['slot']['r'] ?? null) === 'block'
                && isset($targetNode['slot']['anchor'])) {
                $colon = $targetNode['slot']['anchor'];
            }
        }
        if ($colon === null) {
            throw EditEnvelope::unsupported('target is a noncontiguous value (dotted-key/reopen intermediate); no lossless rename range exists');
        }
        list($ks, $ke) = self::mutationKeyRange($src, $colon);
        $encoded = self::escapedKeySegment($newKey);
        $candidate = substr($src, 0, $ks) . $encoded . substr($src, $ke);
        $expected = self::renameCval($original, $segments, $newKey);
        try {
            $actual = EditEnvelope::toCval(PureParser::parse($candidate, false));
        } catch (KtavException $ignored) {
            throw EditEnvelope::unsupported('candidate source does not parse; no lossless edit produced');
        }
        if (!EditEnvelope::cvalEqual($actual, $expected)) { throw EditEnvelope::unsupported('semantic mismatch after splice; no lossless edit produced'); }
        return $candidate;
    }

    private static function utf8Error(string $value): string
    {
        $span = PureScanner::validateUtf8($value);
        $start = $span[0];
        $end = $span[1];
        $length = $end - $start;
        $first = ord($value[$start]);

        // Rust's Utf8Error reports error_len as the number of bytes up to
        // (but not including) the first byte that cannot continue the
        // sequence. Only a genuinely truncated, still-valid prefix at EOF
        // has error_len == None. Constrained second-byte failures (e.g.
        // E0 80) are invalid immediately, even when EOF follows.
        $expected = $first >= 0xC2 && $first <= 0xDF ? 2
            : ($first >= 0xE0 && $first <= 0xEF ? 3
                : ($first >= 0xF0 && $first <= 0xF4 ? 4 : 1));
        if ($expected === 1) {
            // Illegal lead bytes are their own error, regardless of any
            // continuation bytes that the scanner groups into its span.
            $length = 1;
        } elseif ($expected > 1) {
            $validPrefix = 1;
            while ($validPrefix < $expected && $start + $validPrefix < strlen($value)) {
                $byte = ord($value[$start + $validPrefix]);
                $position = $validPrefix;
                $valid = $byte >= 0x80 && $byte <= 0xBF;
                if ($position === 1 && $first === 0xE0) { $valid = $byte >= 0xA0 && $byte <= 0xBF; }
                elseif ($position === 1 && $first === 0xED) { $valid = $byte >= 0x80 && $byte <= 0x9F; }
                elseif ($position === 1 && $first === 0xF0) { $valid = $byte >= 0x90 && $byte <= 0xBF; }
                elseif ($position === 1 && $first === 0xF4) { $valid = $byte >= 0x80 && $byte <= 0x8F; }
                if (!$valid) { break; }
                ++$validPrefix;
            }
            if ($start + $validPrefix === strlen($value) && $validPrefix < $expected) {
                return 'incomplete utf-8 byte sequence from index ' . $start;
            }
            if ($validPrefix > 1) {
                $length = $validPrefix;
            } elseif ($length > 1 && $first >= 0xC2 && $first <= 0xF4) {
                $length = 1;
            }
        }
        return 'invalid utf-8 sequence of ' . $length . ' bytes from index ' . $start;
    }

    private static function validateMutationSegments(array $segments): void
    {
        if ($segments === []) { throw EditEnvelope::notFound(); }
        foreach ($segments as $segment) {
            if (!is_string($segment)) { throw EditEnvelope::message('edit segments transport must be a list of strings'); }
            if (PureScanner::validateUtf8($segment) !== null) { throw EditEnvelope::message('edit segments transport contains invalid UTF-8'); }
        }
    }

    private static function removeCvalTarget(array &$value, array $segments)
    {
        $last = array_pop($segments);
        $parent = &self::cvalAtRef($value, $segments);
        if ($parent['t'] === 'object') {
            foreach ($parent['pairs'] as $i => $pair) if ($pair[0] === $last) { array_splice($parent['pairs'], $i, 1); return $pair[1]; }
        } elseif ($parent['t'] === 'array') {
            $i = self::parseIndex($last); if (isset($parent['items'][$i])) { $v=$parent['items'][$i]; array_splice($parent['items'], $i, 1); return $v; }
        }
        return null;
    }

    private static function &cvalAtRef(array &$value, array $segments): array
    {
        $cur = &$value;
        foreach ($segments as $seg) {
            if ($cur['t'] === 'object') { foreach ($cur['pairs'] as $i => $p) if ($p[0] === $seg) { $cur = &$cur['pairs'][$i][1]; continue 2; } }
            elseif ($cur['t'] === 'array') { $i=self::parseIndex($seg); if (isset($cur['items'][$i])) { $cur = &$cur['items'][$i]; continue; } }
            throw EditEnvelope::notFound();
        }
        return $cur;
    }

    private static function renameCval(array $value, array $segments, string $newKey): array
    {
        $last = array_pop($segments);
        $parent = &self::cvalAtRef($value, $segments);
        foreach ($parent['pairs'] as &$pair) { if ($pair[0] === $last) { $pair[0] = $newKey; break; } }
        unset($pair);
        return $value;
    }

    private static function mutationRange(string $src, array $root, array $segments, array $node): array
    {
        $slot=$node['slot'];
        $strippedLead = false;
        if ($slot === null) { throw EditEnvelope::unsupported('target is a noncontiguous value (dotted-key/reopen intermediate); no lossless removal range exists'); }
        if (!empty($node['inline'])) {
            $range = self::inlineRemovalRange($src, $root, $segments, $slot);
            return [$range[0], $range[1], false];
        }
        $anchor=isset($slot['colon']) ? $slot['colon'] : $slot['start'];
        $from=$anchor; while ($from>0 && $src[$from-1] !== "\n" && $src[$from-1] !== "\r") --$from;
        if ($from === 0 && substr($src, 0, 3) === "\xEF\xBB\xBF") { $from=3; }
        $to=$slot['end']; while ($to<strlen($src) && $src[$to] !== "\n" && $src[$to] !== "\r") ++$to;
        if ($to<strlen($src)) { if ($src[$to] === "\r" && isset($src[$to+1]) && $src[$to+1] === "\n") $to+=2; else ++$to; }
        if ($to === strlen($src) && !($src[$to - 1] === "\n" || $src[$to - 1] === "\r") && $from < $to && $from > 0) {
            if ($src[$from - 1] === "\n") { --$from; if ($from > 0 && $src[$from - 1] === "\r") { --$from; } $strippedLead = true; }
            elseif ($src[$from - 1] === "\r") { --$from; $strippedLead = true; }
        }
        return [$from, $to, $strippedLead];
    }

    private static function inlineRemovalRange(string $src, array $root, array $segments, array $slot): array
    {
        $parents = array_slice($segments, 0, -1);
        $parent = self::nodeAt($root, $parents);
        $bounds = self::inlineRange($root, $parent, $parents, $src);
        if ($bounds === null && $parent['kind'] === 'array' && is_array($parent['slot'])) {
            $parentSlot = $parent['slot'];
            $bodyStart = $parentSlot['body0'] ?? $parentSlot['start'] ?? null;
            if (($parentSlot['r'] === 'pair' || $parentSlot['r'] === 'item')
                && $bodyStart !== null
                && $bodyStart < $parentSlot['end']
                && $src[$bodyStart] === '['
                && $src[$parentSlot['end'] - 1] === ']') {
                $bounds = [$bodyStart, $parentSlot['end']];
            }
        }
        if ($bounds === null) { throw EditEnvelope::unsupported('internal: inline target without inline parent'); }
        list($p0, $p1) = $bounds;
        $body = substr($src, $p0, $p1 - $p0);
        $object = $body[0] === '{';
        $scan = PureInlineScan::scan($body, $object ? 'o' : 'a', 1, strlen($body));
        if ($scan['badEscape'] !== null || $scan['closer'] === null) { throw EditEnvelope::unsupported('cannot split inline parent for removal'); }
        $spans = [];
        foreach ($scan['segs'] as $seg) {
            list($s, $e) = PureScanner::trimBounds($body, $seg[0], $seg[1]);
            if ($s < $e) { $spans[] = [$p0 + $s, $p0 + $e]; }
        }
        $anchor = isset($slot['colon']) ? $slot['colon'] : $slot['start'];
        $pos = null;
        foreach ($spans as $i => $span) { if ($span[0] <= $anchor && $anchor < $span[1]) { $pos = $i; break; } }
        if ($pos === null) { throw EditEnvelope::unsupported('internal: target segment not found in inline parent'); }
        if (count($spans) === 1) { return [$p0 + 1, $p1 - 1]; }
        return $pos + 1 < count($spans) ? [$spans[$pos][0], $spans[$pos + 1][0]] : [$spans[$pos - 1][1], $spans[$pos][1]];
    }

    private static function mutationKeyRange(string $src, int $colon): array
    {
        $end = $colon;
        while ($end > 0) {
            $wsStart = max(0, $end - 3);
            $wsLen = 0;
            for ($k = $wsStart; $k < $end; ++$k) {
                $len = PureScanner::wsLenAt($src, $k, $end, false);
                if ($len > 0 && $k + $len === $end) { $wsLen = $len; break; }
            }
            if ($wsLen === 0) { break; }
            $end -= $wsLen;
        }
        $start = $colon;
        while ($start > 0 && $src[$start - 1] !== "\n" && $src[$start - 1] !== "\r") { --$start; }
        if ($start === 0 && substr($src, 0, 3) === "\xEF\xBB\xBF") { $start = 3; }
        $bytes = $src;
        $boundary = $start;
        $i = $start;
        $segStart = true;
        while ($i < $colon) {
            if ($segStart) {
                while ($i < $colon && ($wsLen = PureScanner::wsLenAt($bytes, $i, $colon, true)) > 0) { $i += $wsLen; }
                if ($i < $colon && ($bytes[$i] === '"' || $bytes[$i] === "'" || $bytes[$i] === '`')) {
                    $quote = $bytes[$i++];
                    while ($i < $colon) {
                        if ($bytes[$i] === '\\') { $i += 2; continue; }
                        if ($bytes[$i++] === $quote) { break; }
                    }
                    $segStart = false;
                    continue;
                }
                $segStart = false;
            }
            if ($bytes[$i] === '\\') { $i += 2; continue; }
            if ($bytes[$i] === '.') { $segStart = true; $boundary = $i + 1; }
            elseif ($bytes[$i] === ',' || $bytes[$i] === '{' || $bytes[$i] === '[') { $boundary = $i + 1; $segStart = true; }
            ++$i;
        }
        while ($boundary < $end && ($wsLen = PureScanner::wsLenAt($bytes, $boundary, $end, true)) > 0) { $boundary += $wsLen; }
        return [$boundary, $end];
    }

    /** Insert a new final Object key without ever overwriting. */
    public static function insertValue(string $src, string $path, $replacement): string
    {
        // Source parse precedes path UTF-8/path grammar diagnostics.
        PureParser::parse($src, false);
        if (PureScanner::validateUtf8($path) !== null) {
            throw EditEnvelope::message('path is not valid UTF-8: ' . self::utf8Error($path));
        }
        return self::insertValueSegments($src, self::parsePath($path), $replacement);
    }

    /** @param string[] $segments */
    public static function insertValueSegments(string $src, array $segments, $replacement): string
    {
        $original = EditEnvelope::toCval(PureParser::parse($src, false));
        if ($segments === []) {
            throw EditEnvelope::notFound();
        }
        foreach ($segments as $segment) {
            if (!is_string($segment)) {
                throw EditEnvelope::message('edit segments transport must be a list of strings');
            }
            if (PureScanner::validateUtf8($segment) !== null) {
                throw EditEnvelope::message('edit segments transport contains invalid UTF-8');
            }
        }
        $c = self::publicToCval($replacement);
        $parentSegments = array_slice($segments, 0, -1);
        $key = $segments[count($segments) - 1];
        $parent = self::lookupValue($original, $parentSegments);
        if ($parent === null) {
            throw EditEnvelope::notFound();
        }
        if ($parent['t'] === 'array') {
            throw EditEnvelope::unsupported('insertion into an Array is not supported; only Object members can be created');
        }
        if ($parent['t'] !== 'object') {
            throw EditEnvelope::notFound();
        }
        if (self::cvalChild($parent, $key) !== null) {
            throw EditEnvelope::alreadyExists();
        }
        return self::insertSplice($src, $original, $parentSegments, $key, $c);
    }

    /** @param string[] $parentSegments */
    private static function insertSplice(string $src, $original, array $parentSegments, string $key, array $c): string
    {
        list($positionRoot, $rootExplicit) = EditTree::build($src);
        $parentNode = self::nodeAt($positionRoot, $parentSegments);
        $inlineRange = self::inlineRange($positionRoot, $parentNode, $parentSegments, $src);
        if ($inlineRange !== null) {
            list($from, $to) = $inlineRange;
            $inner = substr($src, $from + 1, $to - $from - 2);
            if ($inner === false) {
                throw EditEnvelope::unsupported('internal: invalid inline parent range');
            }
            $pair = self::insertInlinePairText($key, $c);
            $forceLayout = (EditEnvelope::requiresBlock($c) || (
                $c['t'] === 'string'
                && (strpos($c['v'], "\n") !== false || strpos($c['v'], "\r") !== false
                    || PureText::stringNeedsMultiline($c['v']))
            )) && self::blockEncodable($c);
            if (PureScanner::trimBounds($inner, 0, strlen($inner))[0] === PureScanner::trimBounds($inner, 0, strlen($inner))[1]) {
                $spliceFrom = $from + 1;
                $spliceTo = $to - 1;
                $insert = $pair;
                $candidate = substr($src, 0, $spliceFrom) . $insert . substr($src, $spliceTo);
            } else {
                // Insert immediately before the closer; preserve whitespace
                // preceding the closer and account for comma/space in range.
                $spliceFrom = $to - 1;
                $spliceTo = $spliceFrom;
                $inlineText = '{' . $inner . '}';
                $inlineScan = PureInlineScan::scan($inlineText, 'o', 1, strlen($inlineText));
                if ($inlineScan['badEscape'] !== null || $inlineScan['closer'] === null) {
                    throw EditEnvelope::unsupported('cannot split inline parent for insertion');
                }
                // Insert after a structural trailing comma, before trailing whitespace.
                $lastSegment = $inlineScan['segs'][count($inlineScan['segs']) - 1];
                list($lastStart, $lastEnd) = PureScanner::trimBounds(
                    $inlineText,
                    $lastSegment[0],
                    $lastSegment[1]
                );
                $hasTrailingComma = count($inlineScan['segs']) > 1
                    && $lastStart === $lastEnd;
                if ($hasTrailingComma) {
                    $comma = $inlineScan['segs'][count($inlineScan['segs']) - 2][1];
                    $spliceFrom = $from + $comma + 1;
                    $spliceTo = $spliceFrom;
                    $insert = ' ' . $pair . ',';
                } else {
                    $insert = ', ' . $pair;
                }
                $candidate = substr($src, 0, $spliceFrom) . $insert . substr($src, $spliceTo);
            }
            $candidate = self::layoutInsertCandidate(
                $src,
                $candidate,
                $positionRoot,
                $parentSegments,
                $c,
                $spliceFrom,
                $spliceTo,
                strlen($insert),
                true,
                $forceLayout
            );
            return self::verifyInsertion($candidate, $original, $parentSegments, $key, $c);
        }
        if ($parentSegments !== []) {
            // A block Object has a positional Block slot whose start/end
            // delimit the opener through the closing brace. Insert before
            // the closer, using the last sibling's indentation (or one
            // level below the opener for an empty Object).
            if ($parentNode['kind'] !== 'object') {
                throw EditEnvelope::unsupported('internal: parent node is not an object');
            }
            $slot = $parentNode['slot'];
            if (!is_array($slot) || $slot['r'] !== 'block') {
                throw EditEnvelope::unsupported('parent is a noncontiguous value (dotted-key/reopen intermediate); no lossless insertion point exists');
            }
            $openerIndent = EditTree::lineIndent($src, $slot['start']);
            $childIndent = $openerIndent . '    ';
            if ($parentNode['pairs'] !== []) {
                $last = $parentNode['pairs'][count($parentNode['pairs']) - 1][1];
                if (is_array($last['slot'])) {
                    $lastPos = $last['slot']['r'] === 'pair' ? $last['slot']['colon'] : $last['slot']['start'];
                    $childIndent = EditTree::lineIndent($src, $lastPos);
                }
            }
            $at = self::physicalLineRange($src, $slot['end'])[0];
            $eol = EditTree::detectEol($src, $slot['end']);
            $pair = self::insertBlockPair($key, $c, $childIndent, $eol);
            $candidate = substr($src, 0, $at) . $childIndent . $pair . $eol . substr($src, $at);
            $candidate = self::layoutInsertCandidate($src, $candidate, $positionRoot, $parentSegments, $c, $at, $at, strlen($childIndent . $pair . $eol), false);
            return self::verifyInsertion($candidate, $original, $parentSegments, $key, $c);
        }
        if ($rootExplicit) {
            $closer = self::rootCloserLineStart($src);
            if ($closer === null) {
                throw EditEnvelope::unsupported('internal: explicit root without closer');
            }
            $childIndent = $positionRoot['pairs'] === [] ? '    ' : '';
            if ($positionRoot['pairs'] !== []) {
                $last = $positionRoot['pairs'][count($positionRoot['pairs']) - 1][1];
                if (is_array($last['slot'])) {
                    $lastPos = $last['slot']['r'] === 'pair' ? $last['slot']['colon'] : $last['slot']['start'];
                    $childIndent = EditTree::lineIndent($src, $lastPos);
                }
            }
            $eol = EditTree::detectEol($src, $closer);
            $pair = self::insertBlockPair($key, $c, $childIndent, $eol);
            $candidate = substr($src, 0, $closer) . $childIndent . $pair . $eol . substr($src, $closer);
            $candidate = self::layoutInsertCandidate($src, $candidate, $positionRoot, [], $c, $closer, $closer, strlen($childIndent . $pair . $eol), false);
            return self::verifyInsertion($candidate, $original, [], $key, $c);
        }
        if ($positionRoot['inline']) {
            throw EditEnvelope::unsupported('pure root insertion requires an Object');
        }
        if ($original['t'] !== 'object') {
            throw EditEnvelope::unsupported('insertion parent is not an Object');
        }
        $eol = self::detectEol($src, strlen($src));
        $pair = self::insertBlockPair($key, $c, '', $eol);
        $endsWithEol = $src !== '' && ($src[strlen($src) - 1] === "\n" || $src[strlen($src) - 1] === "\r");
        if ($src === '') {
            $insert = $pair;
        } elseif ($endsWithEol) {
            $insert = $pair . $eol;
        } else {
            $insert = $eol . $pair;
        }
        $candidate = $src . $insert;
        $candidate = self::layoutInsertCandidate($src, $candidate, $positionRoot, [], $c, strlen($src), strlen($src), strlen($insert), false);
        return self::verifyInsertion($candidate, $original, [], $key, $c);
    }

    /**
     * Render a new pair in a block slot using the same value planner as
     * replacement. Compound values use block rendering; strings retain the
     * planner's raw-marker/multiline decisions and source EOL/indent.
     */
    private static function insertBlockPair(string $key, array $c, string $indent, string $eol): string
    {
        if ($key === '') {
            throw EditEnvelope::unsupported('empty key name is not representable');
        }
        list($body, $raw) = self::planValue(
            $c,
            'pair',
            false,
            false,
            $indent,
            $eol,
            false,
            true
        );
        return self::escapedKeySegment($key) . ':' . ($raw ? ':' : '') . ' ' . $body;
    }

    private static function nodeAt(array $root, array $segments): array
    {
        $node = $root;
        foreach ($segments as $segment) {
            if ($node['kind'] === 'object') {
                $node = self::childOf($node, $segment);
            } elseif ($node['kind'] === 'array') {
                $idx = self::parseIndex($segment);
                $node = $node['items'][$idx] ?? null;
            } else {
                $node = null;
            }
            if ($node === null) {
                throw EditEnvelope::notFound();
            }
        }
        return $node;
    }

    private static function inlineRange(array $root, array $node, array $path, string $src): ?array
    {
        if (isset($node['slot']) && is_array($node['slot'])) {
            $slot = $node['slot'];
            if ($slot['r'] !== 'pair' && $slot['r'] !== 'item') {
                return null;
            }
            if (isset($slot['body0']) && $src[$slot['body0']] === '{') {
                $start = $slot['body0'];
                $end = $slot['end'];
                if ($start < $end && $src[$end - 1] === '}') {
                    return [$start, $end];
                }
            }
            if (isset($slot['colon'])) {
                $start = $slot['start'];
                $end = $slot['end'];
                if ($start < $end && $src[$start] === '{' && $src[$end - 1] === '}') {
                    return [$start, $end];
                }
            }
        }
        if ($path === [] && !empty($root['inline'])) {
            $metadata = $root['rootRange'] ?? null;
            if (is_array($metadata)) {
                $start = $metadata['start'] ?? null;
                $end = $metadata['end'] ?? null;
                if (is_int($start) && is_int($end) && $start < $end
                    && (($src[$start] === '{' && $src[$end - 1] === '}')
                        || ($src[$start] === '[' && $src[$end - 1] === ']'))) {
                    return [$start, $end];
                }
            }
        }
        return null;
    }

    /** Locate the explicit root Object's closing-brace line. */
    private static function rootCloserLineStart(string $src): ?int
    {
        $end = strlen($src);
        while ($end > 0) {
            // Skip a terminal EOL before locating the preceding content
            // line; using the LF byte itself would return the same line
            // start forever.
            if ($src[$end - 1] === "\n") {
                --$end;
                if ($end > 0 && $src[$end - 1] === "\r") { --$end; }
                continue;
            }
            if ($src[$end - 1] === "\r") {
                --$end;
                continue;
            }
            list($start, $lineEnd) = self::physicalLineRange($src, $end - 1);
            list($ts, $te) = PureScanner::trimBounds($src, $start, $lineEnd);
            if (substr($src, $ts, $te - $ts) === '}') {
                return $start;
            }
            $end = $start;
        }
        return null;
    }

    /** @return int[] */
    private static function physicalLineRange(string $src, int $pos): array
    {
        $start = $pos;
        while ($start > 0 && $src[$start - 1] !== "\n" && $src[$start - 1] !== "\r") { --$start; }
        $end = $pos;
        $len = strlen($src);
        while ($end < $len && $src[$end] !== "\n" && $src[$end] !== "\r") { ++$end; }
        return [$start, $end];
    }

    private static function blockEncodable(array $c): bool
    {
        if ($c['t'] === 'string') {
            if (strpos($c['v'], "\r") !== false) {
                return false;
            }
            foreach (explode("\n", $c['v']) as $line) {
                if (trim($line, " \t") === '))') {
                    return false;
                }
            }
            return true;
        }
        if ($c['t'] === 'object') {
            foreach ($c['pairs'] as $pair) {
                if (!self::blockEncodable($pair[1])) { return false; }
            }
        } elseif ($c['t'] === 'array') {
            foreach ($c['items'] as $item) {
                if (!self::blockEncodable($item)) { return false; }
            }
        }
        return true;
    }

    private static function insertInlinePairText(string $key, array $c): string
    {
        if ($key === '') {
            throw EditEnvelope::unsupported('empty key name is not representable');
        }
        $body = $c['t'] === 'string'
            ? EditEnvelope::encodeInline($c['v'], PureText::needsRawMarker($c['v']))
            : self::inlineBody($c, true);
        return self::escapedKeySegment($key) . ': ' . $body;
    }

    private static function verifyInsertion(string $candidate, $original, array $parentSegments, string $key, array $c): string
    {
        try {
            $patched = EditEnvelope::toCval(PureParser::parse($candidate, false));
        } catch (KtavException $e) {
            throw EditEnvelope::unsupported('patch produced an invalid document: ' . $e->getMessage());
        }
        $expected = $original;
        $parents = &$expected;
        foreach ($parentSegments as $segment) {
            if (!is_array($parents)) {
                throw EditEnvelope::notFound();
            }
            if (($parents['t'] ?? null) === 'object') {
                $idx = null;
                foreach ($parents['pairs'] as $i => $pair) {
                    if ($pair[0] === $segment) { $idx = $i; break; }
                }
                if ($idx === null) { throw EditEnvelope::notFound(); }
                $parents = &$parents['pairs'][$idx][1];
            } elseif (($parents['t'] ?? null) === 'array') {
                $idx = self::parseIndex($segment);
                if (!array_key_exists($idx, $parents['items'])) { throw EditEnvelope::notFound(); }
                $parents = &$parents['items'][$idx];
            } else {
                throw EditEnvelope::notFound();
            }
        }
        if (!is_array($parents) || ($parents['t'] ?? null) !== 'object') {
            throw EditEnvelope::notFound();
        }
        $parents['pairs'][] = [$key, $c];
        unset($parents);
        if (!EditEnvelope::cvalEqual($patched, $expected)) {
            throw EditEnvelope::unsupported('semantic mismatch after splice; no lossless patch produced');
        }
        return $candidate;
    }

    /** Encode a root-level pair with the pure inline/compound renderer. */
    private static function insertRootPair(string $key, array $c): string
    {
        $keyText = self::escapedKeySegment($key);
        if ($c['t'] === 'string' && $c['v'] === '') {
            $body = '()';
        } elseif ($c['t'] === 'object' || $c['t'] === 'array') {
            $body = self::inlineBody($c, true);
        } elseif ($c['t'] === 'string') {
            $body = EditEnvelope::encodeInline($c['v'], PureText::needsRawMarker($c['v']));
        } else {
            $body = self::scalarBodyText($c);
        }
        return $keyText . ': ' . $body;
    }

    /** Source EOL policy shared with existing edit planners. */
    private static function detectEol(string $text, int $at): string
    {
        for ($i = max(0, $at); $i < strlen($text); ++$i) {
            if ($text[$i] === "\n") {
                return "\n";
            }
            if ($text[$i] === "\r") {
                return isset($text[$i + 1]) && $text[$i + 1] === "\n" ? "\r\n" : "\r";
            }
        }
        $prefix = substr($text, 0, max(0, $at));
        $cr = strrpos($prefix, "\r");
        $lf = strrpos($prefix, "\n");
        if ($cr !== false && ($lf === false || $cr > $lf)) {
            return "\r";
        }
        if ($lf !== false && $lf > 0 && $prefix[$lf - 1] === "\r") {
            return "\r\n";
        }
        return "\n";
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
        // EMPTY stdClass only: the documented escape hatch for a
        // replacement empty Object (PHP [] is the empty LIST). A
        // NON-empty stdClass — including a self-referencing one — is a
        // typed Message transport failure BEFORE any recursion, so a
        // cycle can never hang the conversion.
        if ($v instanceof \stdClass) {
            if ((array) $v !== []) {
                throw EditEnvelope::message(
                    'edit replacement transport: only an EMPTY stdClass'
                    . ' (an explicit empty Object) is supported'
                );
            }
            return ['t' => 'object', 'pairs' => []];
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
        bool $inlineLeaf,
        bool $forceLayout = false
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
            return self::inlineRange($root, $root, [], $source);
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

    /** Trimmed owner for inline contexts: the whole trimmed line when
     * it is itself a compound, otherwise the value body after the
     * pair's unescaped colon (core inline_owner final branch). */
    private static function lineOwner(string $source, int $pos): ?array
    {
        $line = EditTree::physicalLine($source, $pos);
        $start = strpos($source, $line, max(0, $pos - strlen($line)));
        list($ts, $te) = PureScanner::trimBounds($line, 0, strlen($line));
        $body = substr($line, $ts, $te - $ts);
        $bodyStart = $start + $ts;
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
            $bodyStart += 3;
        }
        if (self::isCompoundText($body)) {
            return [$bodyStart, $bodyStart + strlen($body)];
        }
        // Unescaped colon on the line: the pair value is the owner.
        list($sep, $marker, $unterm) = PureScanner::findSeparator($line, $ts, $te);
        if ($sep < 0 || $unterm) {
            return null;
        }
        $vs = $sep + $marker;
        while ($vs < $te && ($l = PureScanner::wsLenAt($line, $vs, $te, true)) > 0) {
            $vs += $l;
        }
        $value = substr($line, $vs, $te - $vs);
        if (!self::isCompoundText($value)) {
            return null;
        }
        return [$start + $vs, $start + $vs + strlen($value)];
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
        // COORDINATE BASIS: PureInlineScan::scan REQUIRES its region to
        // include this compound's own closing delimiter (it stops
        // there and reports its offset) — unlike the Rust
        // split_top_level, which works on the inner slice. The region
        // [1, strlen(body)) covers inner + closer; [1, strlen-1) would
        // never see the closer and report "positioning failed" for
        // every valid inline body.
        $scan = \Ktav\Pure\PureInlineScan::scan($body, $kind, 1, strlen($body));
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

    /**
     * Apply insertion-specific layout policy to an already-built candidate.
     * Integration point for insertSplice; insertion planning can call this
     * without coupling its splice construction to replacement planning.
     *
     * @param string[] $parentSegments path to the containing Object
     * @param array $replacement inserted cval
     */
    public static function layoutInsertCandidate(
        string $source,
        string $candidate,
        array $root,
        array $parentSegments,
        array $replacement,
        int $from,
        int $to,
        int $inserted,
        bool $inlineParent,
        bool $forceLayout = false
    ): string {
        $insertForce = $forceLayout;
        $lineTooWide = EditEnvelope::countScalars(EditTree::physicalLine($candidate, $from))
            > max(EditEnvelope::countScalars(EditTree::physicalLine($source, $from)), self::LINE_LIMIT);
        if (!$insertForce && !$lineTooWide) {
            return $candidate;
        }
        return self::layoutAdjust(
            $source, $candidate, $root, $parentSegments, $replacement,
            $from, $to, $inserted, $inlineParent
        );
    }
}
