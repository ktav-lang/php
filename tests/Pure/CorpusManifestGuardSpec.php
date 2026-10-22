<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\Tests\TestPaths;

/**
 * Fail-closed corpus guard (schema_version 1). Runs FIRST in this
 * suite directory: every deviation — missing corpus directory, wrong
 * schema_version, unknown category, count drift off 228/90/5/4/13,
 * unknown fixture_flags or a count other than 9 — FAILS, never skips.
 * The historical population-guard problem (a runner that walked
 * directories executed zero fixtures while green) is closed here by
 * the manifest's exact counts instead of directory heuristics.
 */
describe('corpus manifest guard (schema_version 1, fail-closed)', function () {

    it('resolves the corpus and validates the manifest completely', function () {
        // Throws with a precise message on ANY deviation; the
        // returned array is the validated manifest.
        $manifest = Corpus::manifest();

        expect($manifest['schema_version'])->toBe(1);
        expect(array_keys($manifest['categories']))->toBe(array_keys(Corpus::CATEGORIES));
        expect(count($manifest['fixture_flags']))->toBe(Corpus::EXPECTED_FLAG_COUNT);
    });

    foreach (Corpus::CATEGORIES as $category => $count) {
        it("category `$category` holds exactly $count fixtures on disk", function () use ($category, $count) {
            expect(count(Corpus::fixtures($category)))->toBe($count);
        });
    }

    it('every fixture_flags entry is `raw_bytes` on an existing invalid/ fixture', function () {
        $dir = TestPaths::spec();
        foreach (Corpus::manifest()['fixture_flags'] as $entry) {
            expect($entry['flags'])->toBe(['raw_bytes']);
            expect(is_file($dir . '/' . $entry['category'] . '/' . $entry['fixture'] . '.ktav'))->toBe(true);
        }
    });

    it('valid/ carries exactly one `.canonical.ktav` companion per fixture', function () {
        $fixtures = Corpus::fixtures('valid');
        $withCanonical = 0;
        foreach ($fixtures as $stem) {
            if (Corpus::hasCanonical($stem)) {
                $withCanonical++;
            }
        }
        expect($withCanonical)->toBe(count($fixtures));
    });
});
