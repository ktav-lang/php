<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Resolves the on-disk path to the `ktav_cabi` shared library.
 * Resolution order mirrors the Java / Go / .NET bindings:
 *
 *   1. `KTAV_LIB_PATH` env var, if set.
 *   2. User cache: `<userCache>/ktav-php/v<VERSION>/<asset>`.
 *   3. Downloaded from the matching GitHub Release into (2).
 *
 * Internal — used by {@see NativeLib}.
 */
final class NativeLoader
{
    private const RELEASE_BASE = 'https://github.com/ktav-lang/php/releases/download/v';

    private static ?string $override = null;

    /**
     * Test hook — pins the on-disk path the loader will dlopen.
     * Production users override via `$KTAV_LIB_PATH`.
     */
    public static function setLibraryPath(string $path): void
    {
        self::$override = $path;
    }

    public static function resolve(): string
    {
        if (self::$override !== null) {
            if (!is_file(self::$override)) {
                throw new KtavException(
                    'NativeLoader::setLibraryPath("' . self::$override . '"): file not found',
                );
            }
            return self::$override;
        }
        $env = getenv('KTAV_LIB_PATH');
        if ($env !== false && $env !== '') {
            if (!is_file($env)) {
                throw new KtavException('KTAV_LIB_PATH="' . $env . '": file not found');
            }
            return $env;
        }

        $asset = self::assetName();
        $dir = self::userCacheDir() . DIRECTORY_SEPARATOR . 'ktav-php'
            . DIRECTORY_SEPARATOR . 'v' . NativeLib::LIB_VERSION;
        $target = $dir . DIRECTORY_SEPARATOR . $asset;

        if (is_file($target)) {
            return $target;
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new KtavException('cannot create cache dir ' . $dir);
        }

        $url = self::RELEASE_BASE . NativeLib::LIB_VERSION . '/' . $asset;
        try {
            self::download($url, $target);
        } catch (\Throwable $e) {
            throw new KtavException('fetch ' . $url . ': ' . $e->getMessage(), 0, $e);
        }
        return $target;
    }

    private static function userCacheDir(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            if ($local !== false && $local !== '') {
                return $local;
            }
            return ($_SERVER['USERPROFILE'] ?? 'C:') . '\\AppData\\Local';
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $home = getenv('HOME') ?: '/tmp';
            return $home . '/Library/Caches';
        }
        $xdg = getenv('XDG_CACHE_HOME');
        if ($xdg !== false && $xdg !== '') {
            return $xdg;
        }
        $home = getenv('HOME') ?: '/tmp';
        return $home . '/.cache';
    }

    private static function assetName(): string
    {
        $arch = self::arch();
        if (PHP_OS_FAMILY === 'Windows') {
            return 'ktav_cabi-windows-' . $arch . '.dll';
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'libktav_cabi-darwin-' . $arch . '.dylib';
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return 'libktav_cabi-linux-' . $arch . '.so';
        }
        throw new KtavException('unsupported OS: ' . PHP_OS_FAMILY);
    }

    private static function arch(): string
    {
        $machine = strtolower(php_uname('m'));
        if ($machine === 'x86_64' || $machine === 'amd64') {
            return 'amd64';
        }
        if ($machine === 'aarch64' || $machine === 'arm64') {
            return 'arm64';
        }
        throw new KtavException('unsupported arch: ' . $machine);
    }

    private static function download(string $url, string $target): void
    {
        $tmp = $target . '.' . getmypid() . '.tmp';

        // 30s connect / 5min read timeout. Default php_stream wrapper
        // honours these via a context.
        $ctx = stream_context_create([
            'http'  => ['follow_location' => 1, 'timeout' => 300],
            'https' => ['follow_location' => 1, 'timeout' => 300],
        ]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            $hint = ini_get('allow_url_fopen')
                ? ''
                : ' (php.ini: allow_url_fopen is disabled)';
            throw new KtavException('cannot open ' . $url . $hint);
        }
        try {
            // Check HTTP status — fopen returns a stream even on 404.
            $meta = stream_get_meta_data($fp);
            $headers = $meta['wrapper_data'] ?? [];
            $statusOk = false;
            foreach ($headers as $h) {
                if (preg_match('#^HTTP/\S+ 200\b#', $h)) {
                    $statusOk = true;
                    break;
                }
            }
            if (!$statusOk) {
                $first = $headers[0] ?? 'unknown status';
                throw new KtavException('HTTP not 200 for ' . $url . ': ' . $first);
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new KtavException('cannot create ' . $tmp);
            }
            try {
                stream_copy_to_stream($fp, $out);
                // Push to disk before rename so a crash mid-rename
                // can't leave a truncated/empty file at $target.
                fflush($out);
                if (function_exists('fsync')) {
                    @fsync($out);
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($fp);
        }

        if (file_exists($target) && !@unlink($target)) {
            @unlink($tmp);
            throw new KtavException('cannot replace existing ' . $target);
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new KtavException('cannot rename ' . $tmp . ' -> ' . $target);
        }
    }
}
