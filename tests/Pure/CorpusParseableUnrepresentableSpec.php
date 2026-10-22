<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * parseable-unrepresentable/ (4 fixtures × both backends): the value
 * PARSES fine and matches the oracle's `value`, but the writer cannot
 * reproduce it — emitCanonical (and dumps) must throw with the
 * documented unrepresentable_reason.
 */
describe('corpus :: parseable-unrepresentable (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest();
    });

    $fixtures = Corpus::fixtures('parseable-unrepresentable');

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend . '/parseable-unrepresentable';

        foreach ($fixtures as $rel => $stem) {
            it("$prefix :: $rel", function () use ($backend, $stem) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                expect(Corpus::hasOracle($stem))->toBe(true);
                $oracle = Corpus::oracle($stem);
                expect(isset($oracle['value'], $oracle['unrepresentable_reason']))->toBe(true);

                $got = Corpus::call($backend, 'loads', Corpus::source($stem));
                expect(Corpus::equals($oracle['value'], $got))->toBe(true);

                foreach (['dumps', 'emitCanonical'] as $operation) {
                    $caught = null;
                    try {
                        Corpus::call($backend, $operation, $got);
                    } catch (KtavException $e) {
                        $caught = $e;
                    }
                    expect($caught)->not->toBeNull();
                    expect($caught->getReason())->toBe($oracle['unrepresentable_reason']);
                }
            });
        }
    }
});
