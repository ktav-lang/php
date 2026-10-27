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
     * Replace an EXISTING value in Ktav SOURCE TEXT at a textual path
     * (spec 0.9 source edit). Only the bytes of the target (and of the
     * inline wrappers the layout criterion forces to expand) change —
     * never parse-mutate-serialize of the whole document: comments,
     * key spelling/order, dotted/inline/block forms, BOM, CR/LF/CRLF
     * and EOF bytes outside the edit are preserved. Byte-identical
     * no-op when the replacement equals the current value.
     *
     * Path: `key1.key2.0.key4`, separator '.', literal dot `\.`,
     * literal backslash `\\`; other escapes, dangling escapes and
     * empty segments are EditInvalidPath (body = UTF-8 byte offset).
     * Numeric segments are keys in Objects and zero-based ASCII-digit
     * indexes (leading zeros allowed, no signs) in Arrays; missing
     * key / overflow / scalar descent / empty path is EditNotFound.
     *
     * $replacement is a PHP value; int/float/string/bool/null keep
     * their type (arbitrary integers pass `['$i' => '<digits>']`,
     * exact float lexemes `['$f' => '<lexeme>']`). Errors:
     * EditInvalidPath / EditNotFound / EditUnsupported envelopes; the
     * SOURCE itself failing to parse raises the ordinary 0.9 parser
     * envelope. Requires a ktav_cabi that exports the source-edit
     * symbols — an older binary raises a clear unsupported error, no
     * pure fallback.
     *
     * @param mixed $replacement
     * @throws KtavException
     */
    public static function editValue(string $source, string $path, $replacement): string
    {
        return NativeLib::callEditBytes(
            'ktav_edit_value',
            $source,
            $path,
            WireJson::encodeEditReplacement($replacement)
        );
    }

    /**
     * {@see editValue} with pre-decoded literal segments (no extra
     * unescaping — a segment containing '.' is that literal key).
     *
     * @param string[] $segments
     * @param mixed $replacement
     * @throws KtavException
     */
    public static function editValueSegments(string $source, array $segments, $replacement): string
    {
        // The segments transport is a JSON array of literal strings.
        // A json_encode failure is surfaced as a typed transport error
        // BEFORE any native call — the cause is never swallowed by a
        // silent (string) cast to empty path bytes, and no partial
        // edit can happen.
        $pathJson = json_encode(array_values($segments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($pathJson === false) {
            throw KtavException::fromEnvelope([
                'error' => 'Message',
                'reason' => null,
                'line' => null,
                'line_text' => null,
                'span' => null,
                'path' => null,
                'canonical' => null,
                'spec_section' => null,
                'body' => null,
                'message' => 'edit segments transport is not JSON-encodable: '
                    . json_last_error_msg(),
            ]);
        }
        return NativeLib::callEditBytes(
            'ktav_edit_value_segments',
            $source,
            $pathJson,
            WireJson::encodeEditReplacement($replacement)
        );
    }

    /** Append a new final Object key; existing keys are never overwritten. */
    public static function insertValue(string $source, string $path, $replacement): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditBytes('ktav_insert_value', $source, $path, WireJson::encodeEditReplacement($replacement));
    }

    /** Segment-path variant of {@see insertValue}. */
    public static function insertValueSegments(string $source, array $segments, $replacement): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditMutationBytes('ktav_insert_value_segments', [
            $source, self::encodeEditSegments($segments), WireJson::encodeEditReplacement($replacement),
        ]);
    }

    /** Remove the addressed value without pruning empty ancestors. */
    public static function removeValue(string $source, string $path): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditMutationBytes('ktav_remove_value', [$source, $path]);
    }

    /** Segment-path variant of {@see removeValue}. */
    public static function removeValueSegments(string $source, array $segments): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditMutationBytes('ktav_remove_value_segments', [$source, self::encodeEditSegments($segments)]);
    }

    /** Rename the final key using a literal decoded UTF-8 key. */
    public static function renameKey(string $source, string $path, string $newKey): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditMutationBytes('ktav_rename_key', [$source, $path, $newKey]);
    }

    /** Segment-path variant of {@see renameKey}. */
    public static function renameKeySegments(string $source, array $segments, string $newKey): string
    {
        self::validateEditSource($source);
        return NativeLib::callEditMutationBytes('ktav_rename_key_segments', [$source, self::encodeEditSegments($segments), $newKey]);
    }

    private static function validateEditSource(string $source): void
    {
        // Parse with the native implementation before preparing mutation
        // transport, so parser errors take precedence without rewriting source.
        NativeLib::callBytes('ktav_loads', $source);
    }

    private static function encodeEditSegments(array $segments): string
    {
        $json = json_encode(array_values($segments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw KtavException::fromEnvelope([
                'error' => 'Message', 'reason' => null, 'line' => null, 'line_text' => null,
                'span' => null, 'path' => null, 'canonical' => null, 'spec_section' => null,
                'body' => null, 'message' => 'edit segments transport is not JSON-encodable: ' . json_last_error_msg(),
            ]);
        }
        return $json;
    }


    /**
     * Version string reported by the loaded `ktav_cabi`. With the
     * immutable git-core pin the string is informational; the
     * AUTHORITATIVE compatibility gate is the C ABI version (see
     * NativeLib::EXPECTED_ABI_VERSION — every native call is served
     * only by an ABI-2 binary).
     */
    public static function nativeVersion(): string
    {
        return NativeLib::version();
    }

    /**
     * Release-tag version this build's release artifacts are cut from
     * (download cache path / release URL). NOT a runtime gate: since
     * the ABI-2 git-core pin, compatibility is enforced by the C ABI
     * version check at load time, not by this string.
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
