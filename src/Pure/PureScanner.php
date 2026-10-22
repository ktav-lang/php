<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * Byte-level lexical helpers for the pure-PHP backend. Every function
 * operates on (string, int-offset) pairs — no substring-of-tail copies,
 * no regex — so scanning is O(bytes) with O(1) auxiliary state.
 *
 * Whitespace is the closed 25-code-point § 3.3 set (23 for the
 * line-bounded inline view), never a host mb_* primitive. UTF-8
 * validation is a hand-rolled byte DFA (§ 6.15), no ext-mbstring.
 */
final class PureScanner
{
    /** Same declared inline-compound depth limit as the Rust core. */
    const MAX_INLINE_DEPTH = 128;

    /**
     * Byte span [start, end) of the first invalid UTF-8 sequence, or
     * null when the input is valid UTF-8. Strict RFC 3624 (surrogates
     * and > U+10FFFF rejected). Mirrors the Rust core's raw-envelope
     * span rules: an illegal lead byte swallows any following
     * continuation bytes into the same ill-formed subsequence, and a
     * sequence truncated at EOF runs to end-of-input.
     */
    public static function validateUtf8(string $s): ?array
    {
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                ++$i;
                continue;
            }
            if ($b >= 0xC2 && $b <= 0xDF) {
                if ($i + 1 >= $n) {
                    return [$i, $n]; // truncated at EOF
                }
                if ((ord($s[$i + 1]) & 0xC0) !== 0x80) {
                    return self::invalidLeadSpan($s, $i, $i + 1);
                }
                $i += 2;
                continue;
            }
            if ($b >= 0xE0 && $b <= 0xEF) {
                if ($i + 2 >= $n) {
                    return [$i, $n];
                }
                $b1 = ord($s[$i + 1]);
                $b2 = ord($s[$i + 2]);
                if ($b === 0xE0) {
                    $ok = $b1 >= 0xA0 && $b1 <= 0xBF && ($b2 & 0xC0) === 0x80;
                } elseif ($b === 0xED) {
                    $ok = $b1 >= 0x80 && $b1 <= 0x9F && ($b2 & 0xC0) === 0x80;
                } else {
                    $ok = $b1 >= 0x80 && $b1 <= 0xBF && ($b2 & 0xC0) === 0x80;
                }
                if (!$ok) {
                    return self::invalidLeadSpan($s, $i, $i + 1);
                }
                $i += 3;
                continue;
            }
            if ($b >= 0xF0 && $b <= 0xF4) {
                if ($i + 3 >= $n) {
                    return [$i, $n];
                }
                $b1 = ord($s[$i + 1]);
                $b2 = ord($s[$i + 2]);
                $b3 = ord($s[$i + 3]);
                if ($b === 0xF0) {
                    $ok = $b1 >= 0x90 && $b1 <= 0xBF;
                } elseif ($b === 0xF4) {
                    $ok = $b1 >= 0x80 && $b1 <= 0x8F;
                } else {
                    $ok = $b1 >= 0x80 && $b1 <= 0xBF;
                }
                if ($ok) {
                    $ok = ($b2 & 0xC0) === 0x80 && ($b3 & 0xC0) === 0x80;
                }
                if (!$ok) {
                    return self::invalidLeadSpan($s, $i, $i + 1);
                }
                $i += 4;
                continue;
            }
            // Illegal lead byte (continuation stray, 0xC0/0xC1, > 0xF4):
            // one-byte ill-formed subsequence, swallowing continuations.
            return self::invalidLeadSpan($s, $i, $i + 1);
        }
        return null;
    }

    /** Span over an ill-formed lead byte plus any following continuations. */
    private static function invalidLeadSpan(string $s, int $start, int $end): array
    {
        $n = strlen($s);
        while ($end < $n && (ord($s[$end]) & 0xC0) === 0x80) {
            ++$end;
        }
        return [$start, $end];
    }

    /**
     * Byte length of the § 3.3 whitespace code point at $i (0 when not
     * whitespace). $inline excludes LF/CR (the line-bounded view).
     */
    public static function wsLenAt(string $s, int $i, int $end, bool $inline): int
    {
        if ($i >= $end) {
            return 0;
        }
        $b = ord($s[$i]);
        if ($b === 0x20 || $b === 0x09 || $b === 0x0B || $b === 0x0C) {
            return 1;
        }
        if (!$inline && ($b === 0x0A || $b === 0x0D)) {
            return 1;
        }
        if ($b < 0x80) {
            return 0;
        }
        // Multibyte members: U+0085, U+00A0, U+1680, U+2000–U+200A,
        // U+2028, U+2029, U+202F, U+205F, U+3000.
        $b1 = $i + 1 < $end ? ord($s[$i + 1]) : -1;
        if ($b === 0xC2) {
            if ($b1 === 0x85 || $b1 === 0xA0) {
                return 2;
            }
            return 0;
        }
        if ($b === 0xE1 && $b1 === 0x9A && $i + 2 < $end && ord($s[$i + 2]) === 0x80) {
            return 3;
        }
        if ($b === 0xE2 && $b1 === 0x80 && $i + 2 < $end) {
            $b2 = ord($s[$i + 2]);
            if (($b2 >= 0x80 && $b2 <= 0x8A) || $b2 === 0xA8 || $b2 === 0xA9 || $b2 === 0xAF) {
                return 3;
            }
            return 0;
        }
        if ($b === 0xE2 && $b1 === 0x81 && $i + 2 < $end && ord($s[$i + 2]) === 0x9F) {
            return 3;
        }
        if ($b === 0xE3 && $b1 === 0x80 && $i + 2 < $end && ord($s[$i + 2]) === 0x80) {
            return 3;
        }
        return 0;
    }

    /** @return array{int,int} trimmed [start, end) bounds, inline view */
    public static function trimBounds(string $s, int $start, int $end): array
    {
        while ($start < $end) {
            $l = self::wsLenAt($s, $start, $end, true);
            if ($l === 0) {
                break;
            }
            $start += $l;
        }
        while ($end > $start) {
            // A trailing whitespace code point ends exactly at $end;
            // scan back over the (≤3-byte) lead byte candidates.
            $found = false;
            for ($k = max($start, $end - 3); $k < $end; ++$k) {
                $l = self::wsLenAt($s, $k, $end, true);
                if ($l > 0 && $k + $l === $end) {
                    $end = $k;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
        }
        return [$start, $end];
    }

    /** Trim § 3.3 inline whitespace from both ends of a short body. */
    public static function trim(string $s): string
    {
        $n = strlen($s);
        $b = self::trimBounds($s, 0, $n);
        return $b[0] === 0 && $b[1] === $n ? $s : substr($s, $b[0], $b[1] - $b[0]);
    }

    /** True when the byte is one of the four ASCII inline-ws members. */
    public static function isAsciiWs(int $b): bool
    {
        return $b === 0x20 || $b === 0x09 || $b === 0x0B || $b === 0x0C;
    }

    /** Any raw control byte (< 0x20) or DEL — forbidden unescaped in keys. */
    public static function isForbiddenControl(int $b): bool
    {
        return ($b < 0x20 && $b !== 0x09 && $b !== 0x0B && $b !== 0x0C) || $b === 0x7F;
    }

    /**
     * Decode the escape sequence whose lead backslash sits at $i.
     * Returns [decoded, next] on success or null on a malformed
     * escape (§ 6.13). Handles § 3.7.1 surrogate pairing strictly.
     *
     * @return array{string,int}|null
     */
    public static function decodeEscape(string $s, int $i, int $end): ?array
    {
        if ($i + 1 >= $end) {
            return null; // backslash at end of line
        }
        $c = $s[$i + 1];
        switch ($c) {
            case '\\': return ['\\', $i + 2];
            case ',': return [',', $i + 2];
            case '}': return ['}', $i + 2];
            case ']': return [']', $i + 2];
            case '{': return ['{', $i + 2];
            case '[': return ['[', $i + 2];
            case 'n': return ["\n", $i + 2];
            case 'r': return ["\r", $i + 2];
            case '.': return ['.', $i + 2];
            case ':': return [':', $i + 2];
            case '"': return ['"', $i + 2];
            case "'": return ["'", $i + 2];
            case '`': return ['`', $i + 2];
            case 'u':
                $cp = self::hex4($s, $i + 2, $end);
                if ($cp < 0) {
                    return null;
                }
                $next = $i + 6;
                if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                    // High surrogate: must be followed by a low one.
                    if ($next + 1 >= $end || $s[$next] !== '\\' || $s[$next + 1] !== 'u') {
                        return null;
                    }
                    $lo = self::hex4($s, $next + 2, $end);
                    if ($lo < 0xDC00 || $lo > 0xDFFF) {
                        return null;
                    }
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                    $next += 6;
                } elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) {
                    return null; // lone low surrogate
                }
                return [self::codePoint($cp), $next];
            default:
                return null;
        }
    }

    /** @internal */
    private static function hex4(string $s, int $i, int $end): int
    {
        if ($i + 4 > $end) {
            return -1;
        }
        $v = 0;
        for ($k = $i; $k < $i + 4; ++$k) {
            $b = ord($s[$k]);
            if ($b >= 0x30 && $b <= 0x39) {
                $v = ($v << 4) | ($b - 0x30);
            } elseif ($b >= 0x41 && $b <= 0x46) {
                $v = ($v << 4) | ($b - 0x37);
            } elseif ($b >= 0x61 && $b <= 0x66) {
                $v = ($v << 4) | ($b - 0x57);
            } else {
                return -1;
            }
        }
        return $v;
    }

    /** UTF-8 encode one code point (no surrogates by the time we get here). */
    public static function codePoint(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /** True when the byte opens a quoted key segment. */
    public static function isQuoteByte(int $b): bool
    {
        return $b === 0x22 || $b === 0x27 || $b === 0x60;
    }

    /**
     * Quote-aware, escape-aware § 4 separator scan over [start, end).
     * Returns [sepPos|-1, markerLen, unterminatedQuote, badEscapeAt|-1].
     * A quote opens a segment only at a segment-start position (§ 5.3.3
     * positional rule); an unterminated one swallows the rest of the
     * range, so no separator is found.
     *
     * @return array{int,int,bool,int}
     */
    public static function findSeparator(string $s, int $start, int $end): array
    {
        $i = $start;
        $segStart = true;
        while ($i < $end) {
            $b = ord($s[$i]);
            if ($b === 0x5C) { // backslash: escape lead, next byte opaque
                $i += 2;
                $segStart = false;
                continue;
            }
            if ($segStart && self::isQuoteByte($b)) {
                // Skip ws before the segment's first code point is
                // already done by the caller's trimming; a quote after
                // trimming IS the segment start. Scan to its closer.
                $q = $b;
                ++$i;
                $closed = false;
                while ($i < $end) {
                    $c = ord($s[$i]);
                    if ($c === 0x5C) {
                        $i += 2;
                        continue;
                    }
                    if ($c === $q) {
                        $closed = true;
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                if (!$closed) {
                    return [-1, 0, true, -1];
                }
                // After the closer: only ws before '.' / ':' / EOL.
                while ($i < $end && self::isAsciiWs(ord($s[$i]))) {
                    ++$i;
                }
                $segStart = false;
                continue;
            }
            if ($b === 0x3A) { // ':'
                $marker = ($i + 1 < $end && ord($s[$i + 1]) === 0x3A) ? 2 : 1;
                return [$i, $marker, false, -1];
            }
            if ($b === 0x2E) { // '.' — next segment starts (skipping ws)
                ++$i;
                while ($i < $end && ($l = self::wsLenAt($s, $i, $end, true)) > 0) {
                    // Advance by the RETURNED width: multibyte ws
                    // members are 2–3 bytes, ++ would split them.
                    $i += $l;
                }
                $segStart = true;
                continue;
            }
            $segStart = false;
            ++$i;
        }
        return [-1, 0, false, -1];
    }

    /**
     * § 5.0.1 rule 6 phase-1 pair-candidate test on a TRIMMED line:
     * unescaped separator with a non-empty raw prefix, where a plain
     * ':' must be satisfied by whitespace or end-of-line.
     */
    public static function isPairShape(string $line): bool
    {
        $end = strlen($line);
        list($sep, $marker, $unterm) = self::findSeparator($line, 0, $end);
        if ($sep < 0 || $unterm) {
            return false;
        }
        list($ps, $pe) = self::trimBounds($line, 0, $sep);
        if ($ps >= $pe) {
            return false;
        }
        if ($marker === 2) {
            return true;
        }
        return $sep + 1 >= $end || self::wsLenAt($line, $sep + 1, $end, true) > 0;
    }

    /**
     * Split a raw (undecoded, untrimmed) key prefix into segments on
     * unescaped dots, quote-aware (a dot inside a quoted segment never
     * splits, § 4). Returns the raw segment substrings.
     *
     * @return string[]
     */
    public static function splitKeyPath(string $s, int $start, int $end): array
    {
        $segs = [];
        $segStart = $start;
        $i = $start;
        $inQuote = 0;
        while ($i < $end) {
            $b = ord($s[$i]);
            if ($b === 0x5C) {
                $i += 2;
                continue;
            }
            if ($inQuote === 0 && self::isQuoteByte($b) && $i === self::segEdge($s, $segStart, $i)) {
                $inQuote = $b;
                ++$i;
                continue;
            }
            if ($inQuote !== 0) {
                if ($b === $inQuote) {
                    $inQuote = 0;
                }
                ++$i;
                continue;
            }
            if ($b === 0x2E) {
                $segs[] = substr($s, $segStart, $i - $segStart);
                ++$i;
                $segStart = $i;
                continue;
            }
            ++$i;
        }
        $segs[] = substr($s, $segStart, $end - $segStart);
        return $segs;
    }

    /**
     * A quote only opens a segment when it is the first code point of
     * the segment (positional rule): everything between $segStart and
     * $i must be inline whitespace.
     *
     * @internal
     */
    private static function segEdge(string $s, int $segStart, int $i): int
    {
        $j = $segStart;
        while ($j < $i && self::wsLenAt($s, $j, $i, true) > 0) {
            $j += self::wsLenAt($s, $j, $i, true);
        }
        return $j;
    }

    /**
     * Validate and decode one raw key segment (already trimmed). On
     * failure returns ['err' => category, 'at' => offset-within-raw,
     * 'seq' => escape-text]; on success ['val' => decoded].
     *
     * @return array
     */
    public static function decodeKeySegment(string $raw): array
    {
        $n = strlen($raw);
        if ($n === 0) {
            return ['err' => 'EmptyKey', 'at' => 0, 'seq' => ''];
        }
        $b0 = ord($raw[0]);
        if (self::isQuoteByte($b0)) {
            // Quoted segment: content as-is, only the own delimiter is
            // structural (§ 5.3.3).
            $out = '';
            $i = 1;
            while ($i < $n) {
                $c = ord($raw[$i]);
                if ($c === $b0) {
                    // Nothing but whitespace may follow the closer.
                    list($ts, $te) = self::trimBounds($raw, $i + 1, $n);
                    if ($ts < $te) {
                        return ['err' => 'InvalidKey', 'at' => $ts, 'seq' => ''];
                    }
                    return ['val' => $out];
                }
                if ($c === 0x5C) {
                    $d = self::decodeEscape($raw, $i, $n);
                    if ($d === null) {
                        return ['err' => 'BadEscapeSequence', 'at' => $i, 'seq' => substr($raw, $i, min(6, $n - $i))];
                    }
                    $out .= $d[0];
                    $i = $d[1];
                    continue;
                }
                if (self::isForbiddenControl($c)) {
                    return ['err' => 'InvalidKey', 'at' => $i, 'seq' => ''];
                }
                $out .= $raw[$i];
                ++$i;
            }
            return ['err' => 'UnterminatedQuotedKey', 'at' => 0, 'seq' => ''];
        }
        // Bare segment.
        $out = '';
        $i = 0;
        while ($i < $n) {
            $c = ord($raw[$i]);
            if ($c === 0x5C) {
                $d = self::decodeEscape($raw, $i, $n);
                if ($d === null) {
                    return ['err' => 'BadEscapeSequence', 'at' => $i, 'seq' => substr($raw, $i, min(6, $n - $i))];
                }
                $out .= $d[0];
                $i = $d[1];
                continue;
            }
            // Raw <key-char> exclusions (§ 4): controls/DEL and the
            // structural bytes — a raw ':' cannot appear (the scan
            // would have stopped there), the rest is InvalidKey.
            if (self::isForbiddenControl($c)) {
                return ['err' => 'InvalidKey', 'at' => $i, 'seq' => ''];
            }
            if ($c === 0x5B || $c === 0x5D || $c === 0x7B || $c === 0x7D
                || $c === 0x28 || $c === 0x29 || $c === 0x2C || $c === 0x2E) {
                return ['err' => 'InvalidKey', 'at' => $i, 'seq' => ''];
            }
            $out .= $raw[$i];
            ++$i;
        }
        return ['val' => $out];
    }

    /**
     * § 3.6 integer literal grammar. Returns [value, negative] where
     * value is int, PureBigInt (in-i64 magnitude on a host whose int
     * is narrower than 64 bits — same domain, digit-string carrier),
     * or null when the body overflows i64 (String per § 5.2 rule 13)
     * or is not an integer literal at all. Domain: i64.
     *
     * @return array|null
     */
    public static function matchInteger(string $s): ?array
    {
        $n = strlen($s);
        $i = 0;
        $neg = false;
        if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) {
            $neg = $s[$i] === '-';
            ++$i;
        }
        // Prefixes are lowercase-only (0.8.0/0.8.1); the bounds guard
        // makes '' and sign-only bodies ('+', '-') fall through to the
        // decRun null path instead of an out-of-range offset read.
        if ($i < $n && $s[$i] === '0' && ($i + 1 < $n)) {
            // Prefixes are lowercase-only (0.8.0/0.8.1).
            $p = $s[$i + 1];
            if ($p === 'x' || $p === 'o' || $p === 'b') {
                return self::matchBased($s, $i + 2, $neg, $p);
            }
        }
        // Decimal: digit (('_')? digit)* — an underscore only between
        // two digits (leading / trailing / doubled disqualify, § 3.6).
        $digits = self::decRun($s, $i, $n);
        if ($digits === null || $i + strlen($digits) !== $n) {
            return null; // the WHOLE body must match the grammar
        }
        // Redundant leading zero (§ 5.2 rule 13): never an Integer.
        $t = ltrim($digits, '_');
        if (strlen($t) > 1 && $t[0] === '0') {
            return null;
        }
        $t = str_replace('_', '', $t);
        if ($t === '') {
            return null;
        }
        $mag = $neg ? '9223372036854775808' : '9223372036854775807';
        $c = self::digitCmp($t, $mag);
        if ($c > 0) {
            return [null, $neg]; // literal, but outside i64 → String
        }
        // Magnitudes stay DECIMAL STRINGS until the range is proven:
        // (int)'9223372036854775808' would saturate before negation.
        if ($neg) {
            if ($c === 0) {
                // Exactly i64::MIN — int on a 64-bit host; on a host
                // whose int is narrower the magnitude is still within
                // i64 (string-compare proven), so it keeps its Integer
                // identity via the existing PureBigInt digit-string
                // carrier (no domain shrink; NOT a new exemption).
                if (PHP_INT_SIZE === 8) {
                    return [PHP_INT_MIN, $neg];
                }
                return [new PureBigInt('-9223372036854775808'), $neg];
            }
            // Host-width honesty (§ 5.2 rule 13): the cast is only
            // trusted when the decimal round-trips; on a host too
            // narrow for the value the literal becomes a String.
            // '-0' is Integer 0 (no sign on zero), so skip the check.
            if ($t !== '0') {
                $val = -(int) $t;
                if ((string) $val !== '-' . $t) {
                    // Host too narrow for the in-i64 magnitude: keep
                    // the Integer as its exact digit string.
                    return [new PureBigInt('-' . $t), $neg];
                }
                return [$val, $neg];
            }
            return [0, $neg];
        }
        $val = (int) $t;
        if ((string) $val !== $t) {
            return [null, $neg]; // host too narrow (§ 5.2 rule 13)
        }
        return [$val, $neg];
    }

    /** @return array|null [decimalString|null, neg] */
    private static function matchBased(string $s, int $i, bool $neg, string $base): ?array
    {
        $n = strlen($s);
        $max = $base === 'x' ? 16 : ($base === 'o' ? 8 : 2);
        $digits = '';
        $prevDigit = false;
        while ($i < $n) {
            $v = self::digitVal(ord($s[$i]), $max);
            if ($v >= 0) {
                $digits .= dechex($v); // normalize per-digit
                $prevDigit = true;
                ++$i;
                continue;
            }
            if ($s[$i] === '_') {
                // Underscore only between two digits; a leading /
                // trailing / doubled underscore disqualifies (§ 3.6).
                if (!$prevDigit || $i + 1 >= $n || self::digitVal(ord($s[$i + 1]), $max) < 0) {
                    return null;
                }
                $prevDigit = false;
                ++$i;
                continue;
            }
            return null;
        }
        if ($digits === '') {
            return null;
        }
        // Accumulate in decimal, guarding i64 overflow by digit compare.
        $dec = '0';
        $big = false;
        $mag = $neg ? '9223372036854775808' : '9223372036854775807';
        foreach (str_split($digits) as $d) {
            if (!$big) {
                $dec = self::mulAddSmall($dec, $max, (int) hexdec($d));
                if (self::digitCmp($dec, $mag) > 0) {
                    $big = true;
                }
            }
        }
        if ($big) {
            return [null, $neg];
        }
        // Signed-zero literals normalise to 0 (spec.md:673-675, like
        // decimal `-0` in matchInteger) — the cast round-trip below
        // would expect '-0' and misroute the value to String.
        if ($dec === '0') {
            return [0, $neg];
        }
        // Same string-compare discipline as matchInteger: the negative
        // minimum magnitude must never be cast before the range check.
        // Sign-conditional: a POSITIVE equal-to-max literal is simply
        // i64::MAX and falls through to the cast round-trip (which on
        // 64-bit returns PHP_INT_MAX, never PHP_INT_MIN).
        $c = self::digitCmp($dec, $mag);
        if ($neg && $c === 0) {
            // Exactly i64::MIN — int on a 64-bit host, PureBigInt
            // digit-string carrier on a narrower one (same i64 domain,
            // no shrink; § 5.2 rule 13 unaffected).
            if (PHP_INT_SIZE === 8) {
                return [PHP_INT_MIN, $neg];
            }
            return [new PureBigInt('-9223372036854775808'), $neg];
        }
        if ($c > 0) {
            return [null, $neg];
        }
        // Same host-width round-trip discipline as matchInteger: when
        // the cast cannot hold the in-i64 magnitude (PHP_INT_SIZE < 8)
        // the Integer keeps its identity as the exact digit string.
        $val = $neg ? -(int) $dec : (int) $dec;
        if ((string) $val !== ($neg ? '-' . $dec : $dec)) {
            return [new PureBigInt($neg ? '-' . $dec : $dec), $neg];
        }
        return [$val, $neg];
    }

    /**
     * Bounded digit run: consume digits/underscores and STOP at the
     * first byte that is neither (not consumed — callers inspect the
     * rest, e.g. '.' / 'e' for floats). An underscore only between two
     * digits: leading / trailing (just before the stop byte) / doubled
     * underscore disqualify the literal (§ 3.6).
     *
     * @return string|null the digit run (with underscores) or null
     */
    private static function decRun(string $s, int $i, int $n): ?string
    {
        $digits = '';
        $prevDigit = false;
        while ($i < $n) {
            PureWorkMeter::tick();
            $c = ord($s[$i]);
            if ($c >= 0x30 && $c <= 0x39) {
                $digits .= $s[$i];
                $prevDigit = true;
                ++$i;
                continue;
            }
            if ($c === 0x5F) {
                if (!$prevDigit || $i + 1 >= $n || ord($s[$i + 1]) < 0x30 || ord($s[$i + 1]) > 0x39) {
                    return null;
                }
                $digits .= '_';
                $prevDigit = false;
                ++$i;
                continue;
            }
            break; // stop byte: not consumed
        }
        return $digits === '' ? null : $digits;
    }

    /** Compare two unsigned decimal digit strings. */
    public static function digitCmp(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '' && $b === '') {
            return 0;
        }
        if (strlen($a) !== strlen($b)) {
            return strlen($a) < strlen($b) ? -1 : 1;
        }
        return strcmp($a, $b);
    }

    /** @internal decimal string × base + digit, arbitrary length */
    private static function mulAddSmall(string $dec, int $base, int $digit): string
    {
        $carry = $digit;
        $out = '';
        for ($i = strlen($dec) - 1; $i >= 0; --$i) {
            $v = ((int) $dec[$i]) * $base + $carry;
            $out = ($v % 10) . $out;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $out = ($carry % 10) . $out;
            $carry = intdiv($carry, 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    /** @internal */
    private static function digitVal(int $b, int $max): int
    {
        if ($b >= 0x30 && $b <= 0x39) {
            $v = $b - 0x30;
        } elseif ($b >= 0x61 && $b <= 0x66) {
            $v = $b - 0x57;
        } elseif ($b >= 0x41 && $b <= 0x46) {
            $v = $b - 0x37;
        } else {
            return -1;
        }
        return $v < $max ? $v : -1;
    }

    /**
     * § 3.6 float literal grammar (§ 5.2 rule 14). Returns the parsed
     * float, or null when the body is not a float literal / has a
     * redundant leading zero / overflows to non-finite (all fall
     * through to String). Underflow to ±0.0 is an ordinary Float.
     *
     * @return float|null
     */
    public static function matchFloat(string $s): ?float
    {
        $n = strlen($s);
        $i = 0;
        $sign = '';
        if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) {
            $sign = $s[$i];
            ++$i;
        }
        // Alternative 1: dec_part '.' dec_part exponent?
        // Alternative 2: dec_part exponent
        $intPart = self::decRun($s, $i, $n); // stops at '.' / 'e' / 'E'
        if ($intPart === null) {
            return null;
        }
        $i += strlen($intPart);
        $fracPart = '';
        $hasPoint = false;
        if ($i < $n && $s[$i] === '.') {
            $fracPart = self::decRun($s, $i + 1, $n);
            if ($fracPart === null) {
                return null;
            }
            $hasPoint = true;
            $i += 1 + strlen($fracPart);
        }
        $expText = '';
        if ($i < $n && ($s[$i] === 'e' || $s[$i] === 'E')) {
            $j = $i + 1;
            if ($j < $n && ($s[$j] === '+' || $s[$j] === '-')) {
                ++$j;
            }
            $expRun = self::decRun($s, $j, $n);
            if ($expRun === null) {
                return null;
            }
            $expText = substr($s, $i + 1, $j - $i - 1) . $expRun;
            $i = $j + strlen($expRun);
        }
        if ($i !== $n || (!$hasPoint && $expText === '')) {
            return null;
        }
        // Redundant leading zero on the digits before the point /
        // exponent (§ 5.2 rule 14): never a Float.
        $t = ltrim($intPart, '_');
        if (strlen($t) > 1 && $t[0] === '0') {
            return null;
        }
        // Build the literal WITHOUT a trailing bare dot when there is
        // no fractional part (the exponent-only alternative) — a
        // "5.e3" spelling is not a guaranteed-portable numeric literal
        // in every PHP runtime.
        $lit = str_replace('_', '', $intPart);
        if ($hasPoint) {
            $lit .= '.' . str_replace('_', '', $fracPart);
        }
        if ($expText !== '') {
            // Underscores are grammar-valid between exponent digits
            // (decRun) but not valid in a PHP numeric literal.
            $lit .= 'e' . str_replace('_', '', $expText);
        }
        $num = (float) ($sign . $lit); // sign preserved
        if (!is_finite($num)) {
            return null;
        }
        return $num;
    }

    /**
     * True when the body matches the § 3.6 integer / float grammar at
     * all — the writer's raw-marker trigger is keyed to the GRAMMAR,
     * not to classification outcome (§ 5.9.5 / § 5.9.6).
     */
    public static function matchesNumberGrammar(string $s): bool
    {
        if (self::matchInteger($s) !== null) {
            return true;
        }
        return self::matchFloatLoose($s);
    }

    /**
     * Grammar-only float match (no domain / leading-zero filtering) —
     * the writer emits `::` for any body shaped like a float literal.
     */
    public static function matchFloatLoose(string $s): bool
    {
        $n = strlen($s);
        $i = 0;
        if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) {
            ++$i;
        }
        $intPart = self::decRun($s, $i, $n);
        if ($intPart === null) {
            return false;
        }
        $i += strlen($intPart);
        if ($i < $n && $s[$i] === '.') {
            $frac = self::decRun($s, $i + 1, $n);
            if ($frac === null) {
                return false;
            }
            $i += 1 + strlen($frac);
        }
        if ($i < $n && ($s[$i] === 'e' || $s[$i] === 'E')) {
            $j = $i + 1;
            if ($j < $n && ($s[$j] === '+' || $s[$j] === '-')) {
                ++$j;
            }
            $exp = self::decRun($s, $j, $n);
            if ($exp === null) {
                return false;
            }
            $i = $j + strlen($exp);
        }
        return $i === $n && ($i > 0);
    }

    /** 1-based line number + line text for a byte offset (§ 6.15). */
    public static function locate(string $s, int $pos): array
    {
        $n = strlen($s);
        $pos = min($pos, $n);
        $line = 1;
        $i = 0;
        $lineStart = 0;
        while ($i < $pos) {
            $b = ord($s[$i]);
            if ($b === 0x0A) {
                ++$line;
                ++$i;
                $lineStart = $i;
            } elseif ($b === 0x0D) {
                if ($i + 1 < $n && ord($s[$i + 1]) === 0x0A) {
                    // CRLF is ONE terminator and counts only for offsets
                    // strictly after its LF byte.
                    if ($i + 2 <= $pos) {
                        ++$line;
                    }
                    $i += 2;
                } else {
                    ++$line;
                    ++$i;
                }
                $lineStart = $i;
            } else {
                ++$i;
            }
        }
        // Line text: to the next terminator, trailing CR stripped.
        $j = $pos;
        while ($j < $n && ord($s[$j]) !== 0x0A) {
            if (ord($s[$j]) === 0x0D) {
                break;
            }
            ++$j;
        }
        $text = substr($s, $lineStart, $j - $lineStart);
        if (substr($text, -1) === "\r") {
            $text = substr($text, 0, -1);
        }
        return [$line, $text];
    }
}
