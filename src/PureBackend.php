<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Explicit opt-in entry point for the pure-PHP backend — a full
 * parser AND writer implementation of Ktav 0.8.1 with no native
 * library, WASM module, download or subprocess. Static selection per
 * call (`PureBackend::loads` vs `Ktav::loads`); there is no global
 * backend flag and no fallback in either direction, so choosing pure
 * never touches FFI and choosing native never loads pure code.
 *
 * Errors are the same `KtavException` nine-field envelopes as the
 * native backend, with byte-offset spans and 1-based line numbers.
 */
final class PureBackend
{
    /**
     * Parse a Ktav document into a native PHP value.
     *
     * @throws KtavException on any parse error.
     * @return mixed
     */
    public static function loads(string $src)
    {
        return Pure\PureKtav::loads($src);
    }

    /**
     * Parse with strict numeric spelling checks (§ 8.1).
     *
     * @throws KtavException on any strict parse error.
     * @return mixed
     */
    public static function loadsStrict(string $src)
    {
        return Pure\PureKtav::loadsStrict($src);
    }

    /**
     * Render a PHP value to Ktav text (dumps semantics).
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function dumps($value): string
    {
        return Pure\PureKtav::dumps($value);
    }

    /**
     * Render with every leaf scalar coerced to a String via `::`.
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function dumpsForceStrings($value): string
    {
        return Pure\PureKtav::dumpsForceStrings($value);
    }

    /**
     * Render the deterministic canonical form (spec § 5.9).
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function emitCanonical($value): string
    {
        return Pure\PureKtav::emitCanonical($value);
    }

    /**
     * Comment-preserving source formatter (verbatim comments, blank
     * runs collapse to one, fixed point, key order unchanged).
     *
     * @throws KtavException on any format error.
     */
    public static function format(string $src): string
    {
        return Pure\PureKtav::format($src);
    }

    /**
     * Parse and re-emit canonical form, preserving key order and
     * empty Object/Array identity.
     *
     * @throws KtavException on any parse or render error.
     */
    public static function canonicalFromSource(string $src): string
    {
        return Pure\PureKtav::canonicalFromSource($src);
    }

    /**
     * Pure source edit: replace an existing value at a textual path
     * (see \Ktav\Ktav::editValue for the full contract — same rules,
     * same envelopes, no native artifact). The source is spliced by
     * byte ranges from a positional tree; every patch is re-parsed and
     * semantically verified.
     *
     * @param mixed $replacement
     * @throws KtavException
     */
    public static function editValue(string $src, string $path, $replacement): string
    {
        return Pure\Edit\EditSplice::editValue($src, $path, $replacement);
    }

    /**
     * Pure source edit with pre-decoded literal segments.
     *
     * @param string[] $segments
     * @param mixed $replacement
     * @throws KtavException
     */
    public static function editValueSegments(string $src, array $segments, $replacement): string
    {
        return Pure\Edit\EditSplice::editValueSegments($src, $segments, $replacement);
    }

    /** Pure insertion of a new final Object key. */
    public static function insertValue(string $src, string $path, $replacement): string
    {
        return Pure\Edit\EditSplice::insertValue($src, $path, $replacement);
    }

    /** @param string[] $segments */
    public static function insertValueSegments(string $src, array $segments, $replacement): string
    {
        return Pure\Edit\EditSplice::insertValueSegments($src, array_values($segments), $replacement);
    }

    /** Pure removal of an existing value. */
    public static function removeValue(string $src, string $path): string
    {
        return Pure\Edit\EditSplice::removeValue($src, $path);
    }

    /** @param string[] $segments */
    public static function removeValueSegments(string $src, array $segments): string
    {
        return Pure\Edit\EditSplice::removeValueSegments($src, array_values($segments));
    }

    /** Pure rename of an existing Object key. */
    public static function renameKey(string $src, string $path, string $newKey): string
    {
        return Pure\Edit\EditSplice::renameKey($src, $path, $newKey);
    }

    /** @param string[] $segments */
    public static function renameKeySegments(string $src, array $segments, string $newKey): string
    {
        return Pure\Edit\EditSplice::renameKeySegments($src, array_values($segments), $newKey);
    }

    /**
     * Pure-backend version marker (`pure-0.8.1` style) — never a
     * native version; the native one stays `Ktav::nativeVersion()`.
     */
    public static function version(): string
    {
        return Pure\PureKtav::version();
    }

    /**
     * Always true: the pure backend requires no native artifact.
     */
    public static function isAvailable(): bool
    {
        return Pure\PureKtav::isAvailable();
    }
}
