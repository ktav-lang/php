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
        $target = getenv('CARGO_TARGET_DIR');
        $base = $target !== false && $target !== ''
            ? rtrim($target, '/\\')
            : self::REPO . '/target';
        return $base . '/release/' . $name;
    }

    /**
     * Conformance corpus root (tests/ of spec v0.8).
     *
     * Resolution order: `KTAV_SPEC_DIR` env override (CI / parent
     * checkouts where the spec submodule sits elsewhere or is pinned
     * independently) > the worktree's `spec` submodule. Parent CI pins
     * the submodule to
     * 388982118c0d8f767bf283356ac9c9bb50fdc3f5, so the default path
     * works there unchanged.
     */
    public static function spec(): string
    {
        $override = getenv('KTAV_SPEC_DIR');
        if ($override !== false && $override !== '') {
            return rtrim($override, '/\\');
        }
        return self::REPO . '/spec/versions/0.8/tests';
    }

    /** The in-repo submodule default (ignores the env override). */
    public static function specSubmodulePath(): string
    {
        return self::REPO . '/spec/versions/0.8/tests';
    }

    /**
     * Spec 0.9 corpus root (diagnostics09: 30 EOF + 42 malformed-UTF8
     * new invalid fixtures; total 412 = 228 valid / 162 invalid /
     * 5 unrepresentable / 4 parseable-unrepresentable / 13 strict-lossy).
     * Parent pins the spec submodule to a45275688557dc9ecc24a82afb491dd46e615d4d.
     * `KTAV_SPEC09_DIR` overrides (CI / parent checkouts).
     */
    public static function spec09(): string
    {
        $override = getenv('KTAV_SPEC09_DIR');
        if ($override !== false && $override !== '') {
            return rtrim($override, '/\\');
        }
        return self::REPO . '/spec/versions/0.9/tests';
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
        $override = getenv('KTAV_LIB_PATH');
        if ($override !== false && $override !== '') {
            NativeLoader::setLibraryPath($override);
        } elseif (self::cabiBuilt()) {
            NativeLoader::setLibraryPath(self::cabi());
        }
    }
}
