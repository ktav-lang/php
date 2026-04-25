<?php

declare(strict_types=1);

namespace Ktav\Tests;

use Ktav\NativeLoader;

/**
 * Hardcoded paths for the test suite. We implement one specific spec
 * version and the cabi build lives in the same workspace.
 */
final class TestPaths
{
    private const REPO = __DIR__ . '/..';

    public static function cabi(): string
    {
        $name = PHP_OS_FAMILY === 'Windows'
            ? 'ktav_cabi.dll'
            : (PHP_OS_FAMILY === 'Darwin' ? 'libktav_cabi.dylib' : 'libktav_cabi.so');
        return self::REPO . '/target/release/' . $name;
    }

    public static function spec(): string
    {
        return self::REPO . '/spec/versions/0.1/tests';
    }

    public static function cabiBuilt(): bool
    {
        return is_file(self::cabi());
    }

    public static function specPresent(): bool
    {
        return is_dir(self::spec());
    }

    /**
     * Idempotent — first call wires the test cabi into the loader; the
     * spec presence is queried directly by tests, no plumbing needed.
     */
    public static function init(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (self::cabiBuilt()) {
            NativeLoader::setLibraryPath(self::cabi());
        }
    }
}
