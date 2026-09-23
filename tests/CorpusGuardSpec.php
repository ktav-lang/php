<?php

declare(strict_types=1);

use Ktav\Tests\TestPaths;

/**
 * The conformance runner (ConformanceSpec) parametrizes test cases
 * from directory walks. A missing or renamed category directory
 * yields zero cases, not a failure — the category just silently
 * vanishes from the run while the suite stays green. Not hypothetical:
 * the C# runner executed zero fixtures in CI while green, and the
 * Python runner ran the stale 0.6 corpus. This guards against both by
 * asserting every category directory exists and is non-empty, and
 * that `valid/` has exactly as many `.canonical.ktav` companions as
 * fixtures.
 *
 * A shared corpus manifest is being designed in the spec repo to
 * replace hand-rolled guards like this one across every binding. Once
 * it lands, this should read expected categories/counts from that
 * manifest instead of hardcoding them here.
 */
describe('spec corpus (population guard)', function () {

    beforeAll(function () {
        if (!TestPaths::specPresent()) {
            skipIf(true, 'spec submodule missing (' . TestPaths::spec() . ') — run `git submodule update --init`');
        }
    });

    /** Recursively counts files under $dir whose path ends with $suffix. */
    $countBySuffix = function (string $dir, string $suffix) : int {
        if (!is_dir($dir)) {
            return 0;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $n = 0;
        foreach ($iter as $file) {
            $path = $file->getPathname();
            if (substr($path, -strlen($suffix)) === $suffix) {
                $n++;
            }
        }
        return $n;
    };

    $categories = ['valid', 'invalid', 'unrepresentable', 'parseable-unrepresentable', 'strict-lossy'];

    it('has no fixture category the conformance runner does not execute', function () use ($categories) {
        $unknown = [];
        foreach (new \DirectoryIterator(TestPaths::spec()) as $entry) {
            if ($entry->isDir() && !$entry->isDot() && !in_array($entry->getFilename(), $categories, true)) {
                $unknown[] = $entry->getFilename();
            }
        }
        expect($unknown)->toBe([]);
    });

    foreach ($categories as $category) {
        it("`$category/` exists and has at least one fixture", function () use ($category, $countBySuffix) {
            $dir = TestPaths::spec() . '/' . $category;
            expect(is_dir($dir))->toBe(true);

            // Fixtures are either `.ktav` (valid/invalid/parseable-
            // unrepresentable) or `.json` (unrepresentable) — count
            // both, excluding `.canonical.ktav` companions which
            // aren't fixtures in their own right.
            $ktavCount = $countBySuffix($dir, '.ktav') - $countBySuffix($dir, '.canonical.ktav');
            $jsonCount = $countBySuffix($dir, '.json');

            expect($ktavCount + $jsonCount)->toBeGreaterThan(0);
        });
    }

    it('`valid/` has exactly one `.canonical.ktav` companion per fixture', function () use ($countBySuffix) {
        $dir = TestPaths::spec() . '/valid';
        $canonicalCount = $countBySuffix($dir, '.canonical.ktav');
        $fixtureCount = $countBySuffix($dir, '.ktav') - $canonicalCount;

        expect($fixtureCount)->toBeGreaterThan(0);
        expect($canonicalCount)->toBe($fixtureCount);
    });

});
