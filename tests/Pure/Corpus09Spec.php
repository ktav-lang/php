<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\Tests\TestPaths;

/**
 * Spec 0.9 corpus runner (see Corpus09 for the REAL schema contract):
 * every fixture is executed through the pure parser over the RAW BYTES
 * and, when the native backend is enabled (KTAV_ENABLED_BACKENDS),
 * through the native bytes API too. ALL 72 diagnostics09 fixtures run
 * on both backends.
 *
 * The corpus is REQUIRED — a missing directory FAILS this spec, it
 * never skips. A missing native artifact while the native backend is
 * enabled ALSO FAILS (Corpus::requireNative), it never skips.
 */
describe('spec 0.9 corpus (fail-closed, raw bytes, dual backend)', function () {

    beforeAll(function () {
        TestPaths::init();
    });

    // The guard itself runs FIRST: any count/category/flag drift fails.
    it('guards the closed 0.9 category set, counts and the 72 diagnostics09', function () {
        $fixtures = Corpus09::fixtures();
        expect(array_keys($fixtures))->toBe(array_keys(Corpus09::CATEGORIES));
        $total = 0;
        foreach ($fixtures as $files) {
            $total += count($files);
        }
        expect($total)->toBe(Corpus09::TOTAL);
    });

    $runAll = function (bool $native) {
        $backends = Corpus::backends();
        if ($native && !in_array('native', $backends, true)) {
            // An explicit pure-only RUN (KTAV_ENABLED_BACKENDS=pure,
            // the FFI-absence CI job) opts out by scope; this is a
            // declared backend list, not a missing artifact.
            skipIf(true, 'native backend not enabled (KTAV_ENABLED_BACKENDS) — declared pure-only run');
        }
        if (!$native && !in_array('pure', $backends, true)) {
            throw new \RuntimeException(
                'KTAV_ENABLED_BACKENDS must include "pure" for the spec 0.9'
                . ' corpus run — got "' . implode(',', $backends) . '"'
            );
        }
        if ($native) {
            // Enabled but missing artifact: FAIL, never skip.
            Corpus::requireNative();
        }
        foreach (Corpus09::fixtures() as $category => $files) {
            foreach ($files as $path) {
                Corpus09::run($path, $category, $native);
            }
        }
    };

    it('executes every fixture through the pure parser over raw bytes', function () use ($runAll) {
        $runAll(false);
    });

    it('executes every fixture through the native bytes API', function () use ($runAll) {
        $runAll(true);
    });
});
