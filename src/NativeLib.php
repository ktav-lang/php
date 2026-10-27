<?php

declare(strict_types=1);

namespace Ktav;

/**
 * FFI binding to the `ktav_cabi` shared library. Document functions and
 * source-edit/mutation functions use caller-owned input pointers and
 * callee-owned output buffers. Returned buffers are freed via `ktav_free`.
 *
 * Internal — do not import directly. Use the {@see Ktav} facade.
 */
final class NativeLib
{
    /** Version of `ktav_cabi` this build expects. Bump per release. */
    public const LIB_VERSION = '0.8.1';

    // PHP-FFI knows uint8_t / size_t as built-in types — no typedefs
    // needed. The previous `typedef unsigned long long size_t` was
    // also wrong on 32-bit platforms.
    private const CDEF = <<<'C'
        int ktav_loads(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_loads_strict(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_dumps(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_dumps_force_strings(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_emit_canonical(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_format(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_canonical_from_source(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        void ktav_free(uint8_t *ptr, size_t len);
        const char *ktav_version();
        int ktav_abi_version();
        C;

    private static ?\FFI $ffi = null;

    private static function ffi(): \FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (!extension_loaded('ffi')) {
            throw new KtavException(
                'PHP FFI extension is required (php.ini: ffi.enable=1).'
            );
        }
        $path = NativeLoader::resolve();
        try {
            self::$ffi = \FFI::cdef(self::CDEF, $path);
        } catch (\FFI\Exception $e) {
            throw new KtavException(
                'failed to load ' . $path . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }
        // Provenance: a stale override / cache entry may carry an OLD
        // binary. Since the immutable git-core pin (8b6b45f) the
        // version STRING is no longer statically knowable, so the
        // AUTHORITATIVE load gate is the C ABI version: an ABI-1 (or
        // any non-2) binary is rejected for EVERY native operation.
        // The exact 8b6b45f rev is provenance-checked in CI against
        // Cargo.lock instead of here.
        self::assertAbiVersion((int) self::$ffi->ktav_abi_version(), $path);
        return self::$ffi;
    }

    /**
     * Shared ABI gate: since the user-approved ABI 1 -> 2 bump, EVERY
     * native call (document functions and mutation operations) is served only by a
     * binary reporting ktav_abi_version() == 2; an ABI-1 binary is
     * rejected at load time with a clear version error — never a
     * silent pure fallback. Static so the rejection mechanism itself
     * is testable without an ABI-1 artifact on disk.
     */
    public static function assertAbiVersion(int $actual, string $path = 'ktav_cabi'): void
    {
        if ($actual !== self::EXPECTED_ABI_VERSION) {
            throw new KtavException(
                'ktav_cabi ABI version mismatch in ' . $path
                . ': expected ' . self::EXPECTED_ABI_VERSION . ', got ' . $actual
                . ' — the loaded binary predates the user-approved ABI-2'
                . ' mutation set; pin the matching native library'
            );
        }
    }

    /**
     * Source-edit C ABI (0.9): two extra exports sharing the standard
     * return-0/1 + output-buffer + ktav_free pattern. Declared in a
     * SEPARATE \FFI instance ONLY so that symbol resolution of the
     * edit exports happens at a known point with a precise error; the
     * ABI gate itself is SHARED (see ffi/assertAbiVersion) — an ABI-1
     * binary is rejected for every native call, never a silent pure
     * fallback.
     */
    private const EDIT_CDEF = <<<'C'
        int ktav_edit_value(
            const uint8_t *src, size_t src_len,
            const uint8_t *path, size_t path_len,
            const uint8_t *replacement, size_t replacement_len,
            uint8_t **out_buf, size_t *out_len,
            char **out_err, size_t *out_err_len);

        int ktav_edit_value_segments(
            const uint8_t *src, size_t src_len,
            const uint8_t *path, size_t path_len,
            const uint8_t *replacement, size_t replacement_len,
            uint8_t **out_buf, size_t *out_len,
            char **out_err, size_t *out_err_len);
        int ktav_abi_version();
        void ktav_free(uint8_t *ptr, size_t len);
        C;

    private const MUTATION_CDEF = <<<'C'
        int ktav_insert_value(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            const uint8_t *replacement, size_t replacement_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_insert_value_segments(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            const uint8_t *replacement, size_t replacement_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_remove_value(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_remove_value_segments(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_rename_key(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            const uint8_t *new_key, size_t new_key_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_rename_key_segments(
            const uint8_t *src, size_t src_len, const uint8_t *path, size_t path_len,
            const uint8_t *new_key, size_t new_key_len,
            uint8_t **out_buf, size_t *out_len, char **out_err, size_t *out_err_len);
        int ktav_abi_version();
        void ktav_free(uint8_t *ptr, size_t len);
        C;

    private static ?\FFI $mutationFfi = null;

    private static function mutationFfi(): \FFI
    {
        if (self::$mutationFfi !== null) {
            return self::$mutationFfi;
        }
        if (!extension_loaded('ffi')) {
            throw new KtavException('PHP FFI extension is required (php.ini: ffi.enable=1).');
        }
        $path = NativeLoader::resolve();
        try {
            self::$mutationFfi = \FFI::cdef(self::MUTATION_CDEF, $path);
        } catch (\FFI\Exception $e) {
            throw new KtavException(
                'ktav insert/remove/rename symbols are not available in ' . $path
                . ' — the loaded ktav_cabi does not export the ABI-'
                . self::EXPECTED_ABI_VERSION . ' mutation set. Pin a matching native library.'
                . ' Detail: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        self::assertAbiVersion((int) self::$mutationFfi->ktav_abi_version(), $path);
        return self::$mutationFfi;
    }

    private static ?\FFI $editFfi = null;

    /**
     * Expected C ABI version. Bumped 1 -> 2 by explicit user approval
     * (2026-10-25): EVERY native operation rejects an ABI-1 binary at
     * load time (see {@see assertAbiVersion}), not only the edit set.
     */
    public const EXPECTED_ABI_VERSION = 2;

    /**
     * FFI handle for the edit symbols. Symbol resolution happens at
     * cdef() time, so a library without the edit exports fails HERE
     * with a typed, actionable message; the ABI gate is the shared
     * {@see assertAbiVersion} (same for all native calls).
     */
    private static function editFfi(): \FFI
    {
        if (self::$editFfi !== null) {
            return self::$editFfi;
        }
        if (!extension_loaded('ffi')) {
            throw new KtavException(
                'PHP FFI extension is required (php.ini: ffi.enable=1).'
            );
        }
        $path = NativeLoader::resolve();
        try {
            self::$editFfi = \FFI::cdef(self::EDIT_CDEF, $path);
        } catch (\FFI\Exception $e) {
            throw new KtavException(
                'ktav_edit_value / ktav_edit_value_segments are not available in ' . $path
                . ' — the loaded ktav_cabi does not export the ABI-'
                . self::EXPECTED_ABI_VERSION . ' source-edit/mutation set.'
                . ' Pin the native library that exports these symbols; no pure fallback is performed.'
                . ' Detail: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        self::assertAbiVersion((int) self::$editFfi->ktav_abi_version(), $path);
        return self::$editFfi;
    }

    /**
     * Call `ktav_edit_value` / `ktav_edit_value_segments`: three UTF-8
     * byte inputs (source; textual path OR a JSON array of literal
     * string segments; replacement on the tagged-numeric JSON wire),
     * Ktav-source bytes out. Errors are the structured envelope with
     * error = EditInvalidPath / EditNotFound / EditUnsupported.
     *
     * @throws KtavException carrying the structured envelope
     */
    public static function callEditBytes(string $fn, string $src, string $path, string $replacement): string
    {
        return self::callEditMutationBytes($fn, [$src, $path, $replacement]);
    }

    /** Call any mutation export using its ordered UTF-8 byte input buffers. */
    public static function callEditMutationBytes(string $fn, array $inputs): string
    {
        if (!in_array($fn, [
            'ktav_edit_value', 'ktav_edit_value_segments',
            'ktav_insert_value', 'ktav_insert_value_segments',
            'ktav_remove_value', 'ktav_remove_value_segments',
            'ktav_rename_key', 'ktav_rename_key_segments',
        ], true)) {
            throw new KtavException('unsupported native edit operation: ' . $fn);
        }
        $ffi = in_array($fn, ['ktav_edit_value', 'ktav_edit_value_segments'], true)
            ? self::editFfi()
            : self::mutationFfi();
        $lens = array_map('strlen', $inputs);

        // One live byte per input (see callBytes for the NULL-with-len-0
        // rationale); the pad byte is never read.
        $buffers = [];
        foreach ($inputs as $k => $input) {
            $alloc = max(1, $lens[$k]);
            $buf = $ffi->new("uint8_t[$alloc]", false);
            if ($lens[$k] > 0) {
                \FFI::memcpy($buf, $input, $lens[$k]);
            }
            $buffers[] = $buf;
        }

        $outBuf    = $ffi->new('uint8_t*');
        $outLen    = $ffi->new('size_t');
        $outErr    = $ffi->new('char*');
        $outErrLen = $ffi->new('size_t');

        try {
            $args = [];
            foreach ($buffers as $i => $buffer) {
                $args[] = $buffer;
                $args[] = $lens[$i];
            }
            $args[] = \FFI::addr($outBuf);
            $args[] = \FFI::addr($outLen);
            $args[] = \FFI::addr($outErr);
            $args[] = \FFI::addr($outErrLen);
            $rc = $ffi->{$fn}(...$args);
            if ($rc !== 0) {
                // Free through the SAME FFI instance that produced the
                // pointers: CData types are per-instance, and the base
                // instance's ktav_free(uint8_t*) rejects a char* from
                // this one (CI: "Passing incompatible argument 1 of
                // C function 'ktav_free'"). char* is cast to uint8_t*
                // — same pointer, FFI-visible type only.
                $payload = self::copyAndFreeEdit($ffi, $outErr, (int) $outErrLen->cdata);
                self::freeIfPresentEdit($ffi, $outBuf, (int) $outLen->cdata);
                $fields = $payload !== '' ? json_decode($payload, true) : null;
                if (is_array($fields) && isset($fields['error']) && is_string($fields['error'])) {
                    throw KtavException::fromEnvelope($fields);
                }
                throw new KtavException($payload !== '' ? $payload : 'native call failed with code ' . $rc);
            }
            self::freeIfPresentEdit($ffi, $outErr, (int) $outErrLen->cdata);
            return self::copyAndFreeEdit($ffi, $outBuf, (int) $outLen->cdata);
        } finally {
            foreach ($buffers as $buf) {
                if ($buf !== null) {
                    \FFI::free($buf);
                }
            }
        }
    }

    /**
     * copyAndFree counterpart bound to the EDIT FFI instance (the
     * output pointers come from its cdef). $ptr may be a char* (the
     * contract's out_err) — cast to the declared uint8_t*.
     */
    private static function copyAndFreeEdit(\FFI $ffi, $ptr, int $len): string
    {
        if ($len <= 0 || $ptr === null) {
            return '';
        }
        try {
            $bytes = \FFI::string($ptr, $len);
            return $bytes !== false ? $bytes : '';
        } finally {
            $ffi->ktav_free($ffi->cast('uint8_t*', $ptr), $len);
        }
    }

    /** freeIfPresent counterpart bound to the EDIT FFI instance. */
    private static function freeIfPresentEdit(\FFI $ffi, $ptr, int $len): void
    {
        if ($len <= 0 || $ptr === null) {
            return;
        }
        $ffi->ktav_free($ffi->cast('uint8_t*', $ptr), $len);
    }

    /**
     * `ktav_version()` with the dual string / CData return handling —
     * used by both {@see version()} and the {@see ffi()} provenance
     * check (which must not recurse through {@see ffi()} again).
     */
    private static function versionFromFfi(): string
    {
        $ret = self::$ffi->ktav_version();
        if ($ret === null) {
            return '';
        }
        // Some PHP-FFI versions auto-convert `const char *` returns to a
        // PHP string, others hand back a CData pointer that needs
        // \FFI::string. Handle both — the test matrix covers 7.4 / 8.2 /
        // 8.3 and the behavior diverges across them.
        if (is_string($ret)) {
            return $ret;
        }
        $s = \FFI::string($ret);
        return $s === false ? '' : $s;
    }

    public static function version(): string
    {
        self::ffi();
        return self::versionFromFfi();
    }

    /**
     * Calls `ktav_loads` or `ktav_dumps`, copies the success buffer
     * into a PHP string, frees the native buffer, and returns the
     * copy. On non-zero return code the native error bytes are
     * decoded as the structured nine-field error envelope (see
     * {@see KtavException::fromEnvelope()}); since 0.7.1 the envelope
     * contract is: native errors are always this JSON object.
     *
     * @throws KtavException carrying the structured envelope
     */
    public static function callBytes(string $fn, string $input): string
    {
        $ffi = self::ffi();
        $len = strlen($input);

        // Allocate a LIVE input buffer of at least one byte. Rust's
        // `slice::from_raw_parts(src, src_len)` (rust/src/cabi/declare.rs:76,110)
        // requires a non-null, aligned pointer EVEN WHEN src_len is 0 —
        // debug builds abort on NULL-with-len-0 (release builds only
        // hide it). The pad byte is never copied into or out of; the
        // logical length passed to the callee stays the ORIGINAL $len.
        $alloc = max(1, $len);
        $srcBuf = $ffi->new("uint8_t[$alloc]", false);
        if ($len > 0) {
            \FFI::memcpy($srcBuf, $input, $len);
        }

        $outBuf    = $ffi->new('uint8_t*');
        $outLen    = $ffi->new('size_t');
        $outErr    = $ffi->new('uint8_t*');
        $outErrLen = $ffi->new('size_t');

        try {
            $rc = $ffi->{$fn}(
                $srcBuf,
                $len,
                \FFI::addr($outBuf),
                \FFI::addr($outLen),
                \FFI::addr($outErr),
                \FFI::addr($outErrLen),
            );

            if ($rc !== 0) {
                $payload = self::copyAndFree($outErr, (int) $outErrLen->cdata);
                // Drain success buffer too (defence in depth).
                self::freeIfPresent($outBuf, (int) $outLen->cdata);
                // The envelope contract is: native errors are always
                // this JSON object since 0.7.1. The fallback branch
                // keeps old binaries / edge cases working.
                $fields = $payload !== '' ? json_decode($payload, true) : null;
                if (is_array($fields) && isset($fields['error']) && is_string($fields['error'])) {
                    if ($fields['error'] === 'Message'
                        && in_array($fn, ['ktav_loads', 'ktav_loads_strict', 'ktav_format', 'ktav_canonical_from_source'], true)
                        && preg_match('//u', $input) !== 1) {
                        $fields['error'] = 'InvalidUtf8';
                        $fields['spec_section'] = '§6.15';
                        $fields['message'] = 'InvalidUtf8: input is not valid UTF-8';
                    }
                    throw KtavException::fromEnvelope($fields);
                }
                throw new KtavException($payload !== '' ? $payload : 'native call failed with code ' . $rc);
            }

            // Drain error buffer (defence in depth).
            self::freeIfPresent($outErr, (int) $outErrLen->cdata);
            return self::copyAndFree($outBuf, (int) $outLen->cdata);
        } finally {
            if ($srcBuf !== null) {
                \FFI::free($srcBuf);
            }
        }
    }

    /**
     * @param \FFI\CData $ptrField  Reference to a `uint8_t *` field.
     */
    private static function copyAndFree($ptrField, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $ptr = $ptrField;
        if ($ptr === null) {
            return '';
        }
        try {
            $bytes = \FFI::string($ptr, $len);
            return $bytes !== false ? $bytes : '';
        } finally {
            self::ffi()->ktav_free($ptr, $len);
        }
    }

    /** @param \FFI\CData $ptrField */
    private static function freeIfPresent($ptrField, int $len): void
    {
        if ($len <= 0 || $ptrField === null) {
            return;
        }
        self::ffi()->ktav_free($ptrField, $len);
    }
}
