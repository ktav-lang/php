<?php

declare(strict_types=1);

namespace Ktav\Pure;

/**
 * § 5.9.7 string-form selection and § 3.3 whitespace/code-point
 * helpers shared by the pure writer, parser and formatter. Static and
 * stateless; all byte offsets are UTF-8 byte offsets.
 */
final class PureText
{
    /**
     * True when a one-line body would be re-interpreted by § 5.2:
     * keyword, number-grammar shape, compound prefix, multi-line
     * opener, or § 5.7 shortcut (§ 5.9.5 / § 5.9.6).
     *
     * @return bool
     */
    public static function needsRawMarker(string $s): bool
    {
        $n = strlen($s);
        if ($n === 0) {
            return false;
        }
        $b0 = ord($s[0]);
        if ($b0 === 0x7B || $b0 === 0x5B) {
            return true;
        }
        list($cp0) = self::cpAt($s, 0);
        if (self::isInlineWsCp($cp0)) {
            $t = self::trimKtav($s);
            if ($t === '') {
                return false;
            }
            $b0 = ord($t[0]);
            if ($b0 === 0x7B || $b0 === 0x5B) {
                return true;
            }
            return self::needsRawMarkerContent($t);
        }
        return self::needsRawMarkerContent($s);
    }

    /** @return bool */
    private static function needsRawMarkerContent(string $s): bool
    {
        if ($s === 'null' || $s === 'true' || $s === 'false'
            || $s === '(' || $s === '((' || $s === '()' || $s === '(())') {
            return true;
        }
        return PureScanner::matchesNumberGrammar($s);
    }

    /**
     * Item-context raw-marker trigger: adds `##` / `::` prefixes and
     * sole `}` / `]` bodies (§ 5.9.6).
     *
     * @return bool
     */
    public static function itemNeedsRawMarker(string $s): bool
    {
        if (self::needsRawMarker($s)) {
            return true;
        }
        return strpos($s, '##') === 0 || strpos($s, '::') === 0
            || $s === ']' || $s === '}';
    }

    /**
     * Multi-line rerouting test (§ 5.9.7): leading/trailing § 3.3
     * whitespace or a control byte outside TAB.
     *
     * @return bool
     */
    public static function stringNeedsMultiline(string $s): bool
    {
        $n = strlen($s);
        if ($n === 0) {
            return false;
        }
        $first = self::cpAt($s, 0);
        if (self::isKtavWsCp($first[0])) {
            return true;
        }
        // Last code point.
        $j = $n - 1;
        while ($j > 0 && (ord($s[$j]) & 0xC0) === 0x80) {
            --$j;
        }
        $last = self::cpAt($s, $j);
        if (self::isKtavWsCp($last[0])) {
            return true;
        }
        $m = strcspn($s, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F");
        return $m !== $n;
    }

    /**
     * § 5.6.1 multi-line form chooser. Returns 'stripped' | 'verbatim'
     * or throws the matching § 5.9.0 collision reason. Ported from the
     * Rust core's choose_multiline_form, including the shared
     * common-leading-whitespace scan on the fallback path only.
     *
     * @return string
     */
    public static function chooseMultilineForm(string $s, bool $preferStripped): string
    {
        $soleSingle = false;
        $soleDouble = false;
        $wsOnlyLine = false;
        $indentedLine = false;
        $trailingWsLine = false;
        $lines = explode("\n", $s);
        foreach ($lines as $line) {
            list($ts, $te) = PureScanner::trimBounds($line, 0, strlen($line));
            $trimmed = substr($line, $ts, $te - $ts);
            if ($trimmed === ')') {
                $soleSingle = true;
            } elseif ($trimmed === '))') {
                $soleDouble = true;
            }
            if ($line === '') {
                continue;
            }
            if ($ts >= $te) {
                $wsOnlyLine = true;
            } elseif ($ts > 0) {
                $indentedLine = true;
            }
            if (self::endsWithInlineWs($line)) {
                $trailingWsLine = true;
            }
        }
        $strippedSafe = !$soleSingle && !$wsOnlyLine && !$indentedLine && !$trailingWsLine;
        $verbatimOk = !$soleDouble;
        if ($preferStripped && $strippedSafe) {
            return 'stripped';
        }
        if ($verbatimOk) {
            return 'verbatim';
        }
        // Both forms blocked by the cheap checks: run the § 5.6
        // common-leading-whitespace scan once.
        $hasCommonIndent = self::commonLeadingWsPrefixLen($lines) !== 0;
        $strippedLossless = !$soleSingle && !$wsOnlyLine && !$hasCommonIndent && !$trailingWsLine;
        if ($strippedLossless) {
            return 'stripped';
        }
        if ($soleSingle) {
            return self::failForm('BothFormsRequired');
        }
        if ($trailingWsLine) {
            return self::failForm('TrailingWhitespaceCollision');
        }
        return self::failForm('LeadingWhitespaceCollision');
    }

    /** @return string */
    private static function failForm(string $code)
    {
        PureKtav::failEnvelope([
            'error' => 'UnrepresentableAt',
            'reason' => $code,
            'spec_section' => '§5.9.7',
            'message' => PureFloat::reasonDisplay($code),
        ]);
        return '';
    }

    /** True when the line's last code point is § 3.3 inline whitespace. */
    public static function endsWithInlineWs(string $line): bool
    {
        $n = strlen($line);
        if ($n === 0) {
            return false;
        }
        $j = $n - 1;
        while ($j > 0 && (ord($line[$j]) & 0xC0) === 0x80) {
            --$j;
        }
        list($cp) = self::cpAt($line, $j);
        return self::isInlineWsCp($cp);
    }

    /**
     * Byte length of the § 5.6 common leading-whitespace prefix
     * (code-point-identical across every non-blank line).
     *
     * @param string[] $lines
     * @return int
     */
    public static function commonLeadingWsPrefixLen(array $lines): int
    {
        $runs = [];
        $probe = null;
        foreach ($lines as $line) {
            list($ts, $te) = PureScanner::trimBounds($line, 0, strlen($line));
            if ($ts >= $te) {
                continue; // blank lines do not participate
            }
            $runs[] = substr($line, 0, $ts);
            if ($probe === null) {
                $probe = $line;
            }
        }
        if ($runs === [] || $probe === null) {
            return 0;
        }
        $shared = strlen($runs[0]);
        foreach ($runs as $run) {
            if (strlen($run) < $shared) {
                $shared = strlen($run);
            }
        }
        if ($shared === 0) {
            return 0;
        }
        // Code-point-identical: the shared byte prefix must match in
        // every run and not end mid-sequence.
        foreach ($runs as $run) {
            if (substr($run, 0, $shared) !== substr($probe, 0, $shared)) {
                // Divergence inside the shared length: shrink to the
                // first differing byte.
                $k = 0;
                while ($k < $shared && $run[$k] === $probe[$k]) {
                    ++$k;
                }
                $shared = $k;
                if ($shared === 0) {
                    return 0;
                }
            }
        }
        while ($shared > 0 && (ord($probe[$shared]) & 0xC0) === 0x80) {
            --$shared;
        }
        return $shared;
    }

    /** @return array{int,int} [codePoint, byteLen] at a lead byte */
    public static function cpAt(string $s, int $i): array
    {
        $b = ord($s[$i]);
        if ($b < 0x80) {
            return [$b, 1];
        }
        if ($b < 0xE0) {
            return [(($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F), 2];
        }
        if ($b < 0xF0) {
            return [(($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F), 3];
        }
        return [(($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12)
            | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F), 4];
    }

    /** @return bool */
    public static function isKtavWsCp(int $cp): bool
    {
        if ($cp === 0x09 || $cp === 0x0A || $cp === 0x0B || $cp === 0x0C
            || $cp === 0x0D || $cp === 0x20) {
            return true;
        }
        return $cp === 0x85 || $cp === 0xA0 || $cp === 0x1680
            || ($cp >= 0x2000 && $cp <= 0x200A)
            || $cp === 0x2028 || $cp === 0x2029 || $cp === 0x202F
            || $cp === 0x205F || $cp === 0x3000;
    }

    /** @return bool */
    public static function isInlineWsCp(int $cp): bool
    {
        return $cp !== 0x0A && $cp !== 0x0D && self::isKtavWsCp($cp);
    }

    /** Full § 3.3 inline trim on a short body. */
    public static function trimKtav(string $s): string
    {
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $l = PureScanner::wsLenAt($s, $i, $n, true);
            if ($l === 0) {
                break;
            }
            $i += $l;
        }
        $j = $n;
        while ($j > $i) {
            $k = $j - 1;
            $found = false;
            while ($k >= $i && $k >= $j - 4) {
                $l = PureScanner::wsLenAt($s, $k, $j, true);
                if ($l > 0 && $k + $l === $j) {
                    $j = $k;
                    $found = true;
                    break;
                }
                --$k;
            }
            if (!$found) {
                break;
            }
        }
        return substr($s, $i, $j - $i);
    }
}
