<?php

declare(strict_types=1);

namespace Ktav;

/**
 * FFI binding to the `ktav_cabi` shared library. Nine functions, all
 * using the canonical "caller-owned input pointer, callee-owned output
 * buffer" pattern. The output buffer is freed via `ktav_free` after
 * the PHP side has copied the bytes out.
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
        // Provenance check: an override (KTAV_LIB_PATH / setLibraryPath)
        // or a stale cache entry may carry an OLD binary; a LIB_VERSION
        // bump alone would not reject it. Verify the loaded library
        // reports the expected version before it serves any call.
        $actual = self::versionFromFfi();
        if ($actual !== self::LIB_VERSION) {
            self::$ffi = null;
            throw new KtavException(
                'ktav native library version mismatch in ' . $path
                . ': expected ' . self::LIB_VERSION . ', got ' . $actual
            );
        }
        return self::$ffi;
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
        $bytes = \FFI::string($ptr, $len);
        self::ffi()->ktav_free($ptr, $len);
        return $bytes !== false ? $bytes : '';
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
