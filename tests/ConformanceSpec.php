<?php

declare(strict_types=1);

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Tests\TestPaths;

describe('Ktav (conformance)', function () {

    /**
     * @return array<string, string>  relative-name → absolute-path
     */
    $walkKtav = function (string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $items = [];
        foreach ($iter as $file) {
            $path = $file->getPathname();
            if (substr($path, -5) === '.ktav') {
                $rel = substr($path, strlen($dir) + 1);
                $rel = str_replace('\\', '/', $rel);
                $items[$rel] = $path;
            }
        }
        ksort($items);
        return $items;
    };

    /** Structural equality with float tolerance (tests/JS conformance does the same). */
    $equals = function ($a, $b) use (&$equals) {
        if (is_float($a) && is_float($b)) {
            return $a === $b || abs($a - $b) < 1e-12;
        }
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!$equals($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    };

    beforeAll(function () {
        TestPaths::init();
        if (!TestPaths::cabiBuilt()) {
            skipIf(true, 'cabi not built (' . TestPaths::cabi() . ') — run `cargo build --release -p ktav-cabi`');
        }
        if (!TestPaths::specPresent()) {
            skipIf(true, 'spec submodule missing (' . TestPaths::spec() . ') — run `git submodule update --init`');
        }
    });

    describe('valid fixtures', function () use ($walkKtav, $equals) {
        $cases = $walkKtav(TestPaths::spec() . '/valid');

        foreach ($cases as $rel => $abs) {
            it($rel, function () use ($abs, $equals) {
                $oraclePath = substr($abs, 0, -5) . '.json';
                expect(file_exists($oraclePath))->toBe(true);

                $src = (string) file_get_contents($abs);
                $oracleText = (string) file_get_contents($oraclePath);

                $got = Ktav::loads($src);
                $want = json_decode(
                    $oracleText === '' ? '{}' : $oracleText,
                    true,
                    512,
                    JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                );

                expect($equals($want, $got))->toBe(true);
            });
        }
    });

    describe('invalid fixtures', function () use ($walkKtav) {
        $cases = $walkKtav(TestPaths::spec() . '/invalid');

        foreach ($cases as $rel => $abs) {
            it($rel, function () use ($abs) {
                $src = (string) file_get_contents($abs);
                $closure = function () use ($src) {
                    Ktav::loads($src);
                };
                expect($closure)->toThrow(new KtavException());
            });
        }
    });

});
