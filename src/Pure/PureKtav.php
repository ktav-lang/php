<?php

declare(strict_types=1);

namespace Ktav\Pure;

use Ktav\KtavException;

/**
 * Pure-PHP backend facade — a full parser AND writer implementation
 * of Ktav 0.8.1 that loads no native library, WASM or subprocess.
 * Same nine-operation surface as the native `Ktav` facade, same
 * nine-field error envelopes (built directly, never over the JSON
 * wire). Explicitly opted into per call; no global backend state, no
 * fallback to (or from) the native backend.
 *
 * Version marker: {@see VERSION} — the pure backend never reports a
 * native version; use `Ktav::nativeVersion()` for that.
 */
final class PureKtav
{
    /** Pure-backend version marker (never a native version). */
    const VERSION = 'pure-0.8.1';

    /** Declared inline-nesting limit, shared with the Rust core. */
    const MAX_DEPTH = PureScanner::MAX_INLINE_DEPTH;

    /**
     * Parse a Ktav document into a native PHP value. Object → assoc
     * array; Array → sequential list; empty / comments-only document
     * → empty assoc array. Bigints beyond the PHP int range arrive as
     * decimal digit strings.
     *
     * @throws KtavException on any parse error.
     * @return mixed
     */
    public static function loads(string $src)
    {
        return self::toPublic(PureParser::parse($src, false));
    }

    /**
     * Parse with strict numeric spelling checks (§ 8.1): a scalar
     * whose lexical form differs from the canonical form of the
     * number it would be inferred as is rejected with `LossyScalar`.
     *
     * @throws KtavException on any strict parse error.
     * @return mixed
     */
    public static function loadsStrict(string $src)
    {
        return self::toPublic(PureParser::parse($src, true));
    }

    /**
     * Render a PHP value to Ktav text. Top-level value must be an
     * array (assoc → Object, list → Array); bare scalars are
     * rejected. `['$i' => '<digits>']` carries a big Integer without
     * float conversion.
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function dumps($value): string
    {
        return self::renderChecked($value, false, false);
    }

    /**
     * Render with every leaf scalar coerced to a String via the raw
     * marker `::`, so the output round-trips as the same Strings.
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function dumpsForceStrings($value): string
    {
        return self::renderChecked($value, false, true);
    }

    /**
     * Render the deterministic canonical form (spec § 5.9):
     * insertion order, no trivia, empty-compound identities.
     *
     * @param mixed $value
     * @throws KtavException on any render error.
     */
    public static function emitCanonical($value): string
    {
        return self::renderChecked($value, true, false);
    }

    /**
     * Parse Ktav source and re-emit it in canonical form, preserving
     * key order AND empty Object/Array identity (the value model
     * never leaves the internal representation on this path).
     *
     * @throws KtavException on any parse or render error.
     */
    public static function canonicalFromSource(string $src): string
    {
        $root = PureParser::parse($src, false);
        return PureWriter::renderDocument($root, true, false);
    }

    /** See {@see PureFormatter::format()}. */
    public static function format(string $src): string
    {
        return PureFormatter::format($src);
    }

    /** Pure-backend version marker — never a native version. */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Always true: the pure backend needs no artifact, download or
     * extension beyond ext-json (float shortest digits only).
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    // -----------------------------------------------------------------

    /** @param mixed $value */
    private static function renderChecked($value, bool $canonical, bool $force): string
    {
        if (!is_array($value)) {
            // Same envelope the native-backed Ktav::dumps builds.
            throw KtavException::fromEnvelope([
                'error' => 'Unrepresentable',
                'reason' => 'ScalarRoot',
                'spec_section' => '§5.9.0',
                'message' => 'ScalarRoot: the document root is not an Object or an Array',
            ]);
        }
        return PureWriter::renderDocument($value, $canonical, $force);
    }

    /**
     * Internal value → public PHP value: PureObject → assoc array,
     * PureList → list, PureBigInt → decimal digit string.
     *
     * @param mixed $v
     * @return mixed
     */
    private static function toPublic($v)
    {
        if ($v instanceof PureObject) {
            $out = [];
            foreach ($v->pairs as $k => $child) {
                $out[$k] = self::toPublic($child);
            }
            return $out;
        }
        if ($v instanceof PureList) {
            $out = [];
            foreach ($v->items as $child) {
                $out[] = self::toPublic($child);
            }
            return $out;
        }
        if ($v instanceof PureBigInt) {
            return $v->digits;
        }
        return $v;
    }

    /**
     * Throw a KtavException carrying the given nine-field envelope
     * verbatim (all fields optional; `error` defaults to `Unknown`
     * inside fromEnvelope).
     *
     * @param array<string, mixed> $fields
     * @return void
     * @throws KtavException
     */
    public static function failEnvelope(array $fields)
    {
        throw KtavException::fromEnvelope($fields);
    }
}
