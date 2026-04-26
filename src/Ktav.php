<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Public facade for the Ktav configuration format. Thin wrapper around
 * the native `ktav_cabi` library — same C ABI used by the Java / Go /
 * .NET / JS bindings, same `{"$i":"…"}` / `{"$f":"…"}` JSON wire
 * format with lossless typed-integer / typed-float round-trip.
 *
 * Usage:
 *
 *     $cfg = \Ktav\Ktav::loads("port:i 8080\n");
 *     echo \Ktav\Ktav::dumps(['name' => 'demo', 'count' => 42]);
 *
 * Requires PHP 7.4+ with the FFI extension enabled (`ffi.enable=1` in
 * php.ini, or the default in CLI).
 */
final class Ktav
{
    /**
     * Parse a Ktav document into a native PHP value.
     *
     * @throws KtavException on any parse error.
     * @return mixed null|bool|int|float|string|array
     */
    public static function loads(string $src)
    {
        $bytes = NativeLib::callBytes('ktav_loads', $src);
        return WireJson::decode($bytes);
    }

    /**
     * Render a native PHP value back to Ktav text. Top-level value
     * must be an associative array — sequential arrays and scalars
     * at the root are rejected.
     *
     * @param array<string, mixed> $value
     * @throws KtavException on any render error.
     */
    public static function dumps($value): string
    {
        if (!is_array($value)) {
            throw new KtavException('top-level Ktav document must be an object');
        }
        // Empty PHP array is ambiguous (list or object). Treat it as
        // an empty object at the root, since cabi accepts that — and
        // force the JSON encoder to emit `{}` rather than `[]`.
        if ($value === []) {
            return NativeLib::callBytes('ktav_dumps', '{}');
        }
        if (self::isList($value)) {
            throw new KtavException('top-level Ktav document must be an object');
        }
        $json = WireJson::encode($value);
        return NativeLib::callBytes('ktav_dumps', $json);
    }

    /**
     * Version string reported by the loaded `ktav_cabi`. Useful for
     * sanity checks against {@see ExpectedNativeVersion}.
     */
    public static function nativeVersion(): string
    {
        return NativeLib::version();
    }

    /**
     * Native library version this build was compiled against. If
     * {@see nativeVersion()} differs at runtime, the loaded binary
     * was not the one we expected.
     */
    public const ExpectedNativeVersion = NativeLib::LIB_VERSION;

    /** @param array<mixed,mixed> $arr */
    private static function isList(array $arr): bool
    {
        // `array_is_list` is PHP 8.1+. Manual fallback for 7.4 / 8.0.
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }
        if ($arr === []) {
            return true; // empty list / empty object — both render fine, treat as list to reject root
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
