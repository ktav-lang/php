<?php

declare(strict_types=1);

namespace Ktav\Pure;

use Ktav\KtavException;

/**
 * § 5.9.8 canonical Float rendering and the § 5.9.0 reason-code
 * Display strings (split out of PureWriter to keep each file under
 * the 1000-line limit).
 */
final class PureFloat
{
    /** Rust `ReasonCode` Display strings, verbatim. */
    public static function reasonDisplay(string $code): string
    {
        switch ($code) {
            case 'ScalarRoot':
                return 'ScalarRoot: the document root is not an Object or an Array (spec § 5.9.0)';
            case 'EmptyKeyName':
                return 'EmptyKeyName: an Object pair\'s name is the empty string (spec § 5.9.0)';
            case 'NonFiniteFloat':
                return 'NonFiniteFloat: a Float is NaN or ±Infinity (spec § 5.9.0)';
            case 'CRByte':
                return 'CRByte: a String contains a CR byte (spec § 5.9.0 / § 5.9.7)';
            case 'BothFormsRequired':
                return 'BothFormsRequired: the multi-line String needs both forms, a segment trimming to \'))\' and a segment trimming to \')\' (spec § 5.9.7)';
            case 'TrailingWhitespaceCollision':
                return 'TrailingWhitespaceCollision: a segment trims to \'))\' and some content line has trailing whitespace (spec § 5.9.7)';
            default:
                return 'LeadingWhitespaceCollision: a segment trims to \'))\' and every non-blank segment shares leading whitespace at the same position (spec § 5.9.7)';
        }
    }

    /**
     * Shortest round-trip decimal digits for $v, independent of the
     * serialize_precision ini (forced to -1 and restored in a
     * finally). Checked contract: if the ini could not be forced
     * (ini_set disabled or locked) AND the ambient precision truncates
     * the digits, there is no spec-conforming answer — throw
     * KtavException instead of silently emitting wrong canonical
     * bytes. The ini is never left modified.
     *
     * @return string
     */
    public static function shortestDigits(float $v): string
    {
        $prev = ini_get('serialize_precision');
        $canSet = function_exists('ini_set');
        if ($canSet) {
            // @: some hosts disable ini_set with a warning; on PHP 8
            // a disabled function raises \Error, caught below.
            $set = @ini_set('serialize_precision', '-1');
            $canSet = $set !== false;
        }
        $s = false;
        try {
            $s = json_encode($v);
        } catch (\Error $e) {
            $canSet = false;
        } finally {
            if ($canSet) {
                ini_set('serialize_precision', (string) $prev);
            }
        }
        $s = is_string($s) ? $s : '';
        // Round-trip proof: the digits MUST cast back to exactly $v
        // (json digits like '1e-7' cast-compare exactly; NaN/Inf never
        // reach here — formatFloatText guards is_finite upstream).
        if ($s === '' || (float) $s !== $v) {
            throw new KtavException(
                'cannot produce shortest round-trip Float digits for canonical output:'
                . ' serialize_precision is not settable and the ambient precision is lossy'
            );
        }
        return $s;
    }

    /**
     * Canonical Float text (§ 5.9.8): shortest round-trip digits
     * (configuration-independent shortestDigits — a Ryu-equivalent),
     * re-laid-out by the exact 1e-2 / 1e7 thresholds. `-0.0` keeps
     * its sign; scientific form has no `+` and no exponent zeroes.
     *
     * @return string
     */
    public static function formatFloatText(float $v): string
    {
        if (!is_finite($v)) {
            PureKtav::failEnvelope([
                'error' => 'UnrepresentableAt',
                'reason' => 'NonFiniteFloat',
                'spec_section' => '§5.9.0',
                'message' => self::reasonDisplay('NonFiniteFloat'),
            ]);
        }
        if ($v == 0.0) {
            // § 5.9.8: Float preserves the sign of zero. Sign-bit test
            // over the IEEE-754 bytes — pack('e', -0.0) preserves the
            // sign bit (little-endian binary64, PHP >= 7.2); the high
            // dword's MSB is the sign. NO division, so ±0.0 never
            // raises DivisionByZeroError.
            $hi = unpack('V', substr(pack('e', $v), 4, 4))[1];
            return (($hi >> 31) === 1) ? '-0.0' : '0.0';
        }
        $s = self::shortestDigits($v);
        $neg = $s[0] === '-';
        if ($neg) {
            $s = substr($s, 1);
        }
        $exp = 0;
        $e = strpos($s, 'e');
        if ($e === false) {
            $e = strpos($s, 'E');
        }
        if ($e !== false) {
            $exp = (int) substr($s, $e + 1);
            $s = substr($s, 0, $e);
        }
        $dot = strpos($s, '.');
        if ($dot !== false) {
            $exp -= strlen($s) - $dot - 1;
            $s = substr($s, 0, $dot) . substr($s, $dot + 1);
        }
        $s = ltrim($s, '0'); // leading zeros are value-neutral at fixed k
        if ($s === '') {
            $s = '0';
        }
        // Defensive shortest-digits normalization: each trimmed
        // trailing zero compensates k ("120"x10^k == "12"x10^(k+1)).
        $t = rtrim($s, '0');
        if ($t !== '') {
            $exp += strlen($s) - strlen($t);
            $s = $t;
        }
        $D = $s;
        $k = $exp;
        $n = strlen($D);
        $E = $k + $n - 1; // adjusted exponent
        $sign = $neg ? '-' : '';
        if ($E <= -3 || $E >= 7) {
            // Scientific: first digit [. rest] e E.
            $mantissa = $D[0];
            if ($n > 1) {
                $mantissa .= '.' . substr($D, 1);
            }
            return $sign . $mantissa . 'e' . $E;
        }
        // Decimal form; p = position of the point in D.
        $p = $n + $k;
        if ($p <= 0) {
            return $sign . '0.' . str_repeat('0', -$p) . $D;
        }
        if ($p >= $n) {
            return $sign . $D . str_repeat('0', $p - $n) . '.0';
        }
        return $sign . substr($D, 0, $p) . '.' . substr($D, $p);
    }
}
