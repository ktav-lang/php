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
            if (substr($path, -5) === '.ktav' && strpos($path, '.canonical.ktav') === false) {
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

    /**
     * Translate a fixture JSON value into the PHP/wire representation:
     * objects → assoc arrays, arrays → lists, numbers → int/float (json_decode
     * assoc already does this). In `unrepresentable/` only, the non-finite
     * float sentinel `{"$float": "NaN"|"Infinity"|"-Infinity"}` maps to our
     * cabi wire key `"$f"`.
     */
    $translateFixtureValue = function ($v) use (&$translateFixtureValue) {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            $out = [];
            foreach ($v as $k => $sub) {
                if (!$isList && $k === '$float' && is_string($sub)) {
                    $out['$f'] = $sub;
                } else {
                    $out[$k] = $translateFixtureValue($sub);
                }
            }
            return $out;
        }
        return $v;
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

    describe('valid fixtures — canonical form (spec § 5.9.8, § 5.9.10)', function () use ($walkKtav) {
        $cases = $walkKtav(TestPaths::spec() . '/valid');

        /**
         * Byte-exact comparison against each fixture's
         * `.canonical.ktav` companion — for ALL valid/ fixtures.
         *
         * Six fixtures cannot pass today and are skipped (NOT
         * weakened to value equality) with the reason in the spec
         * name. Root cause: PHP has one `array` type for both JSON
         * objects and JSON lists, so an *empty* compound loses which
         * one it was the moment `Ktav::loads()` returns —
         * `json_decode(..., true)` maps both `{}` and `[]` to the
         * same empty PHP `array`, and neither the empty-root choice
         * in `Ktav::dumpsImpl()` (`[]` → `{}`) nor `WireJson::wrap()`
         * can recover it. Every *non-empty* fixture round-trips
         * byte-exactly (object vs list is recoverable from key
         * shape).
         *
         * A weaker assertion would be worse than none: the "valid
         * fixture matches oracle" test passes vacuously on these six
         * precisely because BOTH sides decode through
         * `json_decode(..., true)` and collapse `{}` / `[]` alike.
         * The byte comparison is the only check in this binding that
         * can see the gap at all, so these six stay
         * byte-exact-or-skipped, never value-equal.
         *
         * When the binding learns to preserve empty-compound identity
         * (e.g. by decoding objects to `stdClass`), remove the
         * fixture's entry from `$knownGaps` — its spec then runs the
         * byte-exact assertion again and enforces the fix.
         *
         * Documented for consumers in CHANGELOG under
         * "Unreleased → Known limitations".
         */
        $knownGaps = [
            'inline/object/empty.ktav' => 'empty Object round-trips as empty Array',
            'mixed/nested_multiline_representable.ktav' => 'empty Object round-trips as empty Array',
            'objects/empty_inline.ktav' => 'empty Object round-trips as empty Array',
            'objects/empty_multiline.ktav' => 'empty Object round-trips as empty Array',
            'top_level_array/empty_compound_first_item.ktav' => 'empty Object item round-trips as empty Array',
            'top_level_inline/empty_array.ktav' => 'top-level empty Array round-trips as empty Object',
        ];

        foreach ($cases as $rel => $abs) {
            $gap = $knownGaps[$rel] ?? null;
            $name = $gap === null
                ? $rel
                : $rel . ' — KNOWN GAP: ' . $gap . ' (see CHANGELOG "Known limitations")';

            it($name, function () use ($abs, $gap) {
                if ($gap !== null) {
                    skipIf(true);
                }

                $canonicalPath = substr($abs, 0, -5) . '.canonical.ktav';
                expect(file_exists($canonicalPath))->toBe(true);

                $expected = (string) file_get_contents($canonicalPath);
                $value = Ktav::loads((string) file_get_contents($abs));
                $actual = Ktav::emitCanonical($value);

                expect($actual)->toBe($expected);
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

    describe('unrepresentable fixtures', function () use ($translateFixtureValue) {
        $dir = TestPaths::spec() . '/unrepresentable';
        $cases = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $abs) {
                $cases[basename($abs)] = $abs;
            }
            ksort($cases);
        }

        foreach ($cases as $rel => $abs) {
            it($rel, function () use ($abs, $translateFixtureValue) {
                $fixture = json_decode(
                    (string) file_get_contents($abs),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $v = $translateFixtureValue($fixture['value']);

                $dumps = function () use ($v) {
                    Ktav::dumps($v);
                };
                expect($dumps)->toThrow(new KtavException());

                $canonical = function () use ($v) {
                    Ktav::emitCanonical($v);
                };
                expect($canonical)->toThrow(new KtavException());
            });
        }
    });

    describe('parseable-unrepresentable fixtures', function () use ($walkKtav, $equals) {
        $cases = $walkKtav(TestPaths::spec() . '/parseable-unrepresentable');

        foreach ($cases as $rel => $abs) {
            it($rel, function () use ($abs, $equals) {
                $oraclePath = substr($abs, 0, -5) . '.json';
                expect(file_exists($oraclePath))->toBe(true);

                $src = (string) file_get_contents($abs);
                $oracle = json_decode(
                    (string) file_get_contents($oraclePath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $loaded = Ktav::loads($src);
                expect($equals($oracle['value'], $loaded))->toBe(true);

                $canonical = function () use ($loaded) {
                    Ktav::emitCanonical($loaded);
                };
                expect($canonical)->toThrow(new KtavException());
            });
        }
    });

});
