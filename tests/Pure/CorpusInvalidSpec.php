<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * invalid/ (90 fixtures × both backends): loads() must throw
 * KtavException with exactly the oracle's expected_error category.
 * Files are read as raw bytes (the 9 raw_bytes-flagged fixtures are
 * deliberately invalid UTF-8). InvalidUtf8 must fire BEFORE grammar
 * with a nonempty BYTE span over the malformed bytes.
 */
describe('corpus :: invalid (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest();
    });

    $fixtures = Corpus::fixtures('invalid');

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend . '/invalid';

        foreach ($fixtures as $rel => $stem) {
            it("$prefix :: $rel", function () use ($backend, $stem, $rel) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                expect(Corpus::hasOracle($stem))->toBe(true);
                $oracle = Corpus::oracle($stem);
                expect(isset($oracle['expected_error']) && is_string($oracle['expected_error']))->toBe(true);

                $caught = null;
                try {
                    Corpus::call($backend, 'loads', Corpus::source($stem));
                } catch (KtavException $e) {
                    $caught = $e;
                }
                expect($caught)->not->toBeNull();
                expect($caught->getError())->toBe($oracle['expected_error']);

                if ($oracle['expected_error'] === 'InvalidUtf8') {
                    // § 6.15: detected before any grammar error; the
                    // span is a nonempty UTF-8 BYTE range.
                    $span = $caught->getSpan();
                    expect($span)->not->toBeNull();
                    expect($span['end'] > $span['start'])->toBe(true);
                }
            });
        }
    }
});
