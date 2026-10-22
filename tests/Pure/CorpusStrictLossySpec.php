<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * strict-lossy/ (13 fixtures × both backends, spec § 8.1): lax
 * loads() accepts and yields exactly `lax_value`; loadsStrict()
 * refuses with the LossyScalar payload — expected_error, body and
 * canonical asserted field-by-field from the oracle.
 */
describe('corpus :: strict-lossy (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest();
    });

    $fixtures = Corpus::fixtures('strict-lossy');

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend . '/strict-lossy';

        foreach ($fixtures as $rel => $stem) {
            it("$prefix :: $rel", function () use ($backend, $stem) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                $oracle = Corpus::oracle($stem);
                foreach (['lax_value', 'expected_error', 'body', 'canonical'] as $field) {
                    expect(array_key_exists($field, $oracle))->toBe(true);
                }

                // Lax: accepted, exact payload.
                $lax = Corpus::call($backend, 'loads', Corpus::source($stem));
                expect(Corpus::equals($oracle['lax_value'], $lax))->toBe(true);

                // Strict: refused with the exact lossy-scalar payload.
                $caught = null;
                try {
                    Corpus::call($backend, 'loadsStrict', Corpus::source($stem));
                } catch (KtavException $e) {
                    $caught = $e;
                }
                expect($caught)->not->toBeNull();
                expect($caught->getError())->toBe($oracle['expected_error']);
                expect($caught->getBody())->toBe($oracle['body']);
                expect($caught->getCanonical())->toBe($oracle['canonical']);
            });
        }
    }
});
