<?php

declare(strict_types=1);

namespace Ktav\Pure\Edit;

use Ktav\KtavException;
use Ktav\Pure\PureBigInt;
use Ktav\Pure\PureFloat;
use Ktav\Pure\PureList;
use Ktav\Pure\PureObject;
use Ktav\Pure\PureScanner;
use Ktav\Pure\PureText;

/**
 * Shared plumbing for the pure source-edit engine (0.9):
 *
 * - the three edit error envelopes (EditInvalidPath / EditNotFound /
 *   EditUnsupported) in the exact ten-field shape — reason, line,
 *   line_text, span, path and canonical stay null; body carries the
 *   decimal UTF-8 byte offset (InvalidPath) or the reason text
 *   (Unsupported); messages are the Rust Display texts verbatim so the
 *   native and pure backends print identical sentences;
 * - the comparable value model ("cval"): an order-preserving,
 *   numeric-string-safe representation used for no-op detection and
 *   post-splice semantic verification (PHP array keys coerce "0" to
 *   int, so pairs live in ordered [key, value] lists);
 * - inline § 3.7 scalar encoding / decoding helpers shared by the
 *   splice and the layout expander.
 */
final class EditEnvelope
{
    /** EditInvalidPath with the UTF-8 byte offset in the path argument. */
    public static function invalidPath(int $offset): KtavException
    {
        return self::envelope(
            'EditInvalidPath',
            (string) $offset,
            'invalid edit path (bad escape or empty segment) at byte ' . $offset
        );
    }

    /** EditNotFound — the path does not address an existing value. */
    public static function notFound(): KtavException
    {
        return self::envelope(
            'EditNotFound',
            null,
            'edit path does not address an existing value'
        );
    }

    /** EditAlreadyExists — insertion never overwrites an Object member. */
    public static function alreadyExists(): KtavException
    {
        return self::envelope(
            'EditAlreadyExists',
            null,
            'edit target already exists; insertion never overwrites'
        );
    }

    /** EditUnsupported with the human-readable limitation text. */
    public static function unsupported(string $reason): KtavException
    {
        return self::envelope(
            'EditUnsupported',
            $reason,
            'no lossless source edit: ' . $reason
        );
    }

    /**
     * Transport/wire decode failure (invalid path UTF-8, non-string
     * segments, unrepresentable replacement type): the existing
     * Message envelope WITHOUT an invented source location — the same
     * category the native transport layer reports.
     */
    public static function message(string $text): KtavException
    {
        return self::envelope('Message', $text, $text);
    }

    private static function envelope(string $code, ?string $body, string $message): KtavException
    {
        return KtavException::fromEnvelope([
            'error' => $code,
            'reason' => null,
            'line' => null,
            'line_text' => null,
            'span' => null,
            'path' => null,
            'body' => $body,
            'canonical' => null,
            'spec_section' => null,
            'message' => $message,
        ]);
    }

    // -----------------------------------------------------------------
    // Comparable value model (cval)
    // -----------------------------------------------------------------

    /**
     * Convert a public PHP value (as produced by the pure parser's
     * toPublic, bigints as digit strings) into the order-preserving
     * cval form:
     *   scalar  -> ['t'=>'null'|'bool'|'int'|'float'|'string', ...]
     *   object  -> ['t'=>'object', 'pairs'=>[[key, cval], ...]]
     *   array   -> ['t'=>'array', 'items'=>[cval, ...]]
     *
     * @param mixed $v
     * @return mixed
     */
    public static function toCval($v)
    {
        if ($v instanceof PureObject) {
            $pairs = [];
            foreach ($v->pairs as $k => $child) {
                $pairs[] = [(string) $k, self::toCval($child)];
            }
            return ['t' => 'object', 'pairs' => $pairs];
        }
        if ($v instanceof PureList) {
            $items = [];
            foreach ($v->items as $child) {
                $items[] = self::toCval($child);
            }
            return ['t' => 'array', 'items' => $items];
        }
        if ($v instanceof PureBigInt) {
            return ['t' => 'int', 'text' => $v->digits];
        }
        if (is_int($v)) {
            return ['t' => 'int', 'text' => (string) $v];
        }
        if (is_float($v)) {
            return ['t' => 'float', 'v' => $v];
        }
        if (is_bool($v)) {
            return ['t' => 'bool', 'v' => $v];
        }
        if ($v === null) {
            return ['t' => 'null'];
        }
        return ['t' => 'string', 'v' => (string) $v];
    }

    /** Structural equality of two cvals (int and float never equal). */
    public static function cvalEqual($a, $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (($a['t'] ?? null) !== ($b['t'] ?? null)) {
            return false;
        }
        switch ($a['t']) {
            case 'object':
                // Rust IndexMap PartialEq IGNORES pair order — Object
                // equality is key-map equality. Arrays stay ordered.
                $pb = $b['pairs'];
                if (count($a['pairs']) !== count($pb)) {
                    return false;
                }
                foreach ($a['pairs'] as $pair) {
                    $matched = false;
                    foreach ($pb as $other) {
                        if ($other[0] === $pair[0]) {
                            if (!self::cvalEqual($pair[1], $other[1])) {
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
                $ia = $a['items'];
                $ib = $b['items'];
                if (count($ia) !== count($ib)) {
                    return false;
                }
                foreach ($ia as $i => $item) {
                    if (!self::cvalEqual($item, $ib[$i])) {
                        return false;
                    }
                }
                return true;
            case 'int':
                return PureScanner::digitCmp(ltrim($a['text'], '+'), ltrim($b['text'], '+')) === 0
                    && (strpos($a['text'], '-') === 0) === (strpos($b['text'], '-') === 0);
            case 'float':
                // Exact f64 identity via shortest round-trip digits
                // (injective on f64, -0.0 keeps its sign) — never the
                // lossy `precision`-governed (string) cast. Wire-only
                // non-finite payloads compare by verbatim text.
                $fa = (float) $a['v'];
                $fb = (float) $b['v'];
                if (!is_finite($fa) || !is_finite($fb)) {
                    return isset($a['text'], $b['text'])
                        && $a['text'] === $b['text'];
                }
                return PureFloat::shortestDigits($fa)
                    === PureFloat::shortestDigits($fb);
            default:
                return $a === $b;
        }
    }

    // -----------------------------------------------------------------
    // § 3.7 inline scalar encoding / decoding
    // -----------------------------------------------------------------

    /**
     * Encode a String body for an inline context: every byte that
     * would break the inline split/decode (`\\` `\,` `\}` `\]` `\{`
     * `\[`) and LF/CR are escaped; edge § 3.3 whitespace becomes
     * `\uXXXX` (physical edges are trimmed on re-parse). With
     * $forceString the FIRST code point is emitted as `\uXXXX` so the
     * body re-parses as a String, not as a number/keyword.
     */
    public static function encodeInline(string $s, bool $forceString): string
    {
        $out = '';
        $n = strlen($s);
        $i = 0;
        $first = true;
        while ($i < $n) {
            list($cp, $len) = PureText::cpAt($s, $i);
            $last = $i + $len >= $n;
            if ($forceString && $first) {
                $out .= self::uEscape($cp);
            } else {
                $out .= self::encodeInlineCp($cp, $s, $i, $len, $first, $last);
            }
            $first = false;
            $i += $len;
        }
        return $out;
    }

    /** @return string */
    private static function encodeInlineCp(int $cp, string $s, int $i, int $len, bool $first, bool $last): string
    {
        switch ($cp) {
            case 0x5C: return '\\\\';
            case 0x2C: return '\\,';
            case 0x7D: return '\\}';
            case 0x5D: return '\\]';
            case 0x7B: return '\\{';
            case 0x5B: return '\\[';
            case 0x0A: return '\\n';
            case 0x0D: return '\\r';
        }
        if (($first || $last) && PureText::isKtavWsCp($cp)) {
            return self::uEscape($cp);
        }
        return substr($s, $i, $len);
    }

    /** \uXXXX, surrogate pair for > U+FFFF (§ 3.7.1, uppercase hex). */
    public static function uEscape(int $cp): string
    {
        if ($cp > 0xFFFF) {
            $v = $cp - 0x10000;
            $hi = 0xD800 | ($v >> 10);
            $lo = 0xDC00 | ($v & 0x3FF);
            return sprintf('\u%04X\u%04X', $hi, $lo);
        }
        return sprintf('\u%04X', $cp);
    }

    /**
     * Decode every escape in an inline scalar body (§ 3.7). Returns
     * [decodedText, hadEscape] or null on a malformed escape.
     *
     * @return array{string,bool}|null
     */
    public static function decodeInlineScalar(string $raw): ?array
    {
        $n = strlen($raw);
        $out = '';
        $had = false;
        $i = 0;
        while ($i < $n) {
            if (ord($raw[$i]) === 0x5C) {
                $d = PureScanner::decodeEscape($raw, $i, $n);
                if ($d === null) {
                    return null;
                }
                $out .= $d[0];
                $i = $d[1];
                $had = true;
                continue;
            }
            $out .= $raw[$i];
            ++$i;
        }
        return [$out, $had];
    }

    /** Unicode scalar value count (byte walk, no preg / mbstring).
     * A scalar is any byte that is NOT a continuation byte (10xxxxxx)
     * — valid UTF-8 input makes this exact. */
    public static function countScalars(string $s): int
    {
        $n = strlen($s);
        $count = 0;
        for ($i = 0; $i < $n; ++$i) {
            if ((ord($s[$i]) & 0xC0) !== 0x80) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * The requires_block layout criterion for a replacement: a String
     * containing LF/CR, or a String whose § 3.7 inline encoding needs
     * >= 4 escape sequences with overhead >= 25% of the decoded scalar
     * count (per contract, per-string, not per-array sum). Compounds
     * recurse; a hit anywhere forces the containing wrapper to expand.
     *
     * @param mixed $c replacement cval
     * @throws KtavException (EditUnsupported) beyond the inline depth limit
     */
    public static function requiresBlock($c, int $depth = 0): bool
    {
        if ($depth > 128) {
            throw self::unsupported('replacement nesting exceeds inline depth limit (128)');
        }
        if (!is_array($c)) {
            return false;
        }
        switch ($c['t']) {
            case 'string':
                $s = $c['v'];
                if (strpos($s, "\n") !== false || strpos($s, "\r") !== false) {
                    return true;
                }
                $encoded = self::encodeInline($s, false);
                $escapes = 0;
                $i = 0;
                $n = strlen($encoded);
                while ($i < $n) {
                    if (ord($encoded[$i]) === 0x5C) {
                        ++$escapes;
                        $i += 2;
                        continue;
                    }
                    ++$i;
                }
                $plain = self::countScalars($s);
                $overhead = self::countScalars($encoded) - $plain;
                $threshold = intdiv($plain, 4) + ($plain % 4 !== 0 ? 1 : 0);
                return $escapes >= 4 && $overhead >= $threshold;
            case 'object':
                foreach ($c['pairs'] as $pair) {
                    if (self::requiresBlock($pair[1], $depth + 1)) {
                        return true;
                    }
                }
                return false;
            case 'array':
                foreach ($c['items'] as $item) {
                    if (self::requiresBlock($item, $depth + 1)) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }
}
