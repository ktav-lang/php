<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * unrepresentable/ (5 fixtures × both backends): the oracle's Value
 * is level-checked, not parsed — dumps() AND emitCanonical() must
 * reject it with the documented reason.
 */
describe('corpus :: unrepresentable (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest();
    });

    $fixtures = Corpus::fixtures('unrepresentable');

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend . '/unrepresentable';

        foreach ($fixtures as $rel => $stem) {
            it("$prefix :: $rel", function () use ($backend, $stem) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                $oracle = Corpus::oracle($stem);
                expect(isset($oracle['value'], $oracle['unrepresentable_reason']))->toBe(true);

                $v = Corpus::translateFixtureValue($oracle['value']);
                $reason = $oracle['unrepresentable_reason'];

                foreach (['dumps', 'emitCanonical'] as $operation) {
                    $caught = null;
                    try {
                        Corpus::call($backend, $operation, $v);
                    } catch (KtavException $e) {
                        $caught = $e;
                    }
                    expect($caught)->not->toBeNull();
                    expect($caught->getReason())->toBe($reason);
                }
            });
        }
    }
});
