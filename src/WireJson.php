<?php

declare(strict_types=1);

namespace Ktav;

/**
 * JSON ⇄ PHP value bridge. The cabi side speaks JSON with
 * `{"$i":"<digits>"}` / `{"$f":"<text>"}` tagged wrappers around
 * typed integers / floats — same wire format used by the Java / Go /
 * .NET / JS bindings.
 *
 * Read path: cabi → JSON bytes → PHP value. `$i` becomes an `int`
 * when it fits in PHP_INT_*, otherwise a `string` (PHP has no native
 * arbitrary-precision integer; users wanting GMP can post-process).
 * `$f` becomes a `float`.
 *
 * Write path: PHP value → JSON bytes → cabi. `int` (any width) gets
 * the `$i` envelope; `float` (finite) gets `$f` with a forced
 * decimal point; bigint-as-string requires the user to wrap it
 * themselves as `['$i' => '999...']`.
 */
final class WireJson
{
    /** @return mixed */
    public static function decode(string $json)
    {
        if ($json === '') {
            throw new KtavException('empty JSON from native');
        }
        $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::revive($parsed);
    }

    /** @param mixed $value */
    public static function encode($value): string
    {
        $wrapped = self::wrap($value);
        return json_encode(
            $wrapped,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function revive($v)
    {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        if (!is_array($v)) {
            return $v;
        }
        // Tagged-wrapper detection: assoc array with a single key.
        if (count($v) === 1) {
            if (isset($v['$i']) && is_string($v['$i'])) {
                return self::reviveInteger($v['$i']);
            }
            if (isset($v['$f']) && is_string($v['$f'])) {
                return (float) $v['$f'];
            }
        }
        // Recurse into array (assoc or list).
        foreach ($v as $k => $child) {
            $v[$k] = self::revive($child);
        }
        return $v;
    }

    /** @return int|string */
    private static function reviveInteger(string $digits)
    {
        // Try plain int first.
        $i = (int) $digits;
        if ((string) $i === $digits) {
            return $i;
        }
        // Out of PHP int range — return as string (callers can use
        // GMP / BCMath if they need arithmetic on it).
        return $digits;
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function wrap($v)
    {
        if ($v === null || is_bool($v) || is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return ['$i' => (string) $v];
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                throw new KtavException('Ktav floats must be finite (got ' . $v . ')');
            }
            // PHP's default float-to-string is locale-independent and
            // round-trip-safe via `serialize_precision = -1` (default
            // since PHP 7.1). Force a decimal point if missing.
            $s = (string) $v;
            if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) {
                $s .= '.0';
            }
            return ['$f' => $s];
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $child) {
                $out[$k] = self::wrap($child);
            }
            return $out;
        }
        throw new KtavException('unsupported value type for Ktav::dumps: ' . gettype($v));
    }
}
