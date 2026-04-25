<?php

declare(strict_types=1);

namespace Ktav;

/**
 * FFI binding to the `ktav_cabi` shared library. Four functions, all
 * using the canonical "caller-owned input pointer, callee-owned output
 * buffer" pattern. The output buffer is freed via `ktav_free` after
 * the PHP side has copied the bytes out.
 *
 * Internal — do not import directly. Use the {@see Ktav} facade.
 */
final class NativeLib
{
    /** Version of `ktav_cabi` this build expects. Bump per release. */
    public const LIB_VERSION = '0.1.0';

    private const CDEF = <<<'C'
        typedef int int32_t;
        typedef unsigned char uint8_t;
        typedef unsigned long long size_t;

        int ktav_loads(
            const uint8_t *src, size_t src_len,
            uint8_t **out_buf, size_t *out_len,
            uint8_t **out_err, size_t *out_err_len);

        int ktav_dumps(
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
        return self::$ffi;
    }

    /**
     * Calls `ktav_loads` or `ktav_dumps`, copies the success buffer
     * into a PHP string, frees the native buffer, and returns the
     * copy. Throws {@see KtavException} on non-zero return code with
     * the native error string as the message.
     */
    public static function callBytes(string $fn, string $input): string
    {
        $ffi = self::ffi();
        $len = strlen($input);

        // Allocate input buffer in native land and copy into it.
        $srcBuf = $len > 0 ? $ffi->new("uint8_t[$len]", false) : null;
        if ($srcBuf !== null) {
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
                $msg = self::copyAndFree($outErr, (int) $outErrLen->cdata)
                    ?: 'native call failed with code ' . $rc;
                // Drain success buffer too (defence in depth).
                self::freeIfPresent($outBuf, (int) $outLen->cdata);
                throw new KtavException($msg);
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

    public static function version(): string
    {
        $ffi = self::ffi();
        $ret = $ffi->ktav_version();
        if ($ret === null) {
            return '';
        }
        // PHP FFI auto-converts `const char *` returns to a PHP string
        // when the value is non-null. Older versions handed back a CData
        // pointer that needed `\FFI::string`; modern versions don't.
        if (is_string($ret)) {
            return $ret;
        }
        $s = \FFI::string($ret);
        return $s === false ? '' : $s;
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
