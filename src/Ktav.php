<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Public facade for the Ktav configuration format. Thin wrapper around
 * the native `ktav_cabi` library — same C ABI used by the Java / Go /
 * .NET / JS bindings, same `{"$i":"…"}` / `{"$f":"…"}` JSON wire
 * format with lossless typed-integer / typed-float round-trip.
 *
 * Since spec 0.5.0, numeric types are inferred from lexical form
 * (`port: 8080` yields an Integer, not a String). Use the
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
     * Parse a Ktav document with strict numeric spelling checks.
     *
     * @throws KtavException on any strict parse error.
     * @return mixed null|bool|int|float|string|array
     */
    public static function loadsStrict(string $src)
    {
        $bytes = NativeLib::callBytes('ktav_loads_strict', $src);
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
     * environments or downstream consumers that need string-only values.
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
     * form (spec § 5.9). The output is stable across platforms and
     * preserves object key order. Empty Object/Array identity is lost
     * when source text passes through PHP arrays.
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
     * Comment-preserving formatter over Ktav SOURCE TEXT (not a
     * value-to-text renderer). Every comment is preserved verbatim
     * (Ktav has no trailing comments — spec § 3.4, a comment owns a
     * whole line, so attachment is unambiguous). Blank lines survive
     * as a grouping hint but a run of two or more collapses to
     * exactly one, and blank padding immediately inside a bracket is
     * dropped, which makes the transform a fixed point
     * (`format(format($src)) === format($src)`). Key order is never
     * changed (canonical form has no sorting rule, spec § 5.9). For a
     * document with no comments AND no blank lines the result equals
     * `self::canonicalFromSource($src)`.
     *
     * @throws KtavException on any format error.
     */
    public static function format(string $src): string
    {
        return NativeLib::callBytes('ktav_format', $src);
    }

    /**
     * Parse Ktav source text and immediately re-emit it in canonical
     * form (spec § 5.9), preserving object key order and empty
     * Object/Array identity without a PHP value round-trip.
     *
     * Comments and blank lines do NOT survive — canonical form carries
     * no trivia; use {@see format} for that.
     *
     * @throws KtavException on any parse or render error.
     */
    public static function canonicalFromSource(string $src): string
    {
        return NativeLib::callBytes('ktav_canonical_from_source', $src);
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
            throw KtavException::fromEnvelope([
                'error' => 'Unrepresentable',
                'reason' => 'ScalarRoot',
                'spec_section' => '§5.9.0',
                'message' => 'ScalarRoot: the document root is not an Object or an Array',
            ]);
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
