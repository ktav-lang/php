<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Public facade for the Ktav configuration format. Thin wrapper around
 * the native `ktav_cabi` library — same C ABI used by the Java / Go /
 * .NET / JS bindings, same `{"$i":"…"}` / `{"$f":"…"}` JSON wire
 * format with lossless typed-integer / typed-float round-trip.
 *
 * Since spec 0.5.0: typed markers `:i` / `:f` are inferred from the
 * lexical form (`port: 8080` yields an Integer, not a String). Use the
 * raw-marker form (`port:: 8080`) to keep a value as a String. Comments
 * now require `##` (a single `#` is content).
 *
 * Usage:
 *
 *     $cfg = \Ktav\Ktav::loads("port: 8080\n");
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
     * Top-level Object documents come back as an associative array;
     * top-level Array documents (spec § 5.0.1, since 0.1.1) come back
     * as a sequential PHP list. Empty / comments-only documents
     * default to an empty associative array.
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
     * must be an associative array (Object) or a sequential array
     * (top-level Array, spec § 5.0.1, since 0.1.1) — bare scalars at
     * the root are rejected.
     *
     * @param array<mixed, mixed> $value
     * @throws KtavException on any render error.
     */
    public static function dumps($value): string
    {
        return self::dumpsImpl('ktav_dumps', $value);
    }

    /**
     * Render with **every scalar coerced to a String**: typed
     * integers, typed floats, booleans, and null are flattened to
     * their textual form (e.g. `42`, `3.14`, `true`, `null`) and
     * emitted via the raw-marker `::` so the output round-trips back
     * through the parser as the same string scalars.
     *
     * Compounds (associative / sequential arrays) preserve their
     * structure; only leaf scalars are coerced. Useful for dumping
     * configuration in a "everything is a string" shape — e.g. for
     * environments or downstream consumers that don't understand the
     * `:i` / `:f` typed markers.
     *
     * @param array<mixed, mixed> $value
     * @throws KtavException on any render error.
     */
    public static function dumpsForceStrings($value): string
    {
        return self::dumpsImpl('ktav_dumps_force_strings', $value);
    }

    /**
     * Render a native PHP value to the deterministic **canonical** Ktav
     * form (spec § 7). The output is stable across platforms, sorts
     * object keys, and round-trips unchanged through `loads` / `dumps`.
     *
     * Useful for diffing, hashing, and golden-file tests.
     *
     * @param array<mixed, mixed> $value
     * @throws KtavException on any render error.
     */
    public static function emitCanonical($value): string
    {
        return self::dumpsImpl('ktav_emit_canonical', $value);
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

    /**
     * @param mixed $value
     */
    private static function dumpsImpl(string $fn, $value): string
    {
        if (!is_array($value)) {
            throw new KtavException('top-level Ktav document must be an object or array');
        }
        // Empty PHP array is ambiguous (list or object). The cabi side
        // accepts both `{}` and `[]` at the root — pick `{}` for
        // backward compatibility (pre-0.3.1 always emitted Object).
        if ($value === []) {
            return NativeLib::callBytes($fn, '{}');
        }
        $json = WireJson::encode($value);
        return NativeLib::callBytes($fn, $json);
    }
}
