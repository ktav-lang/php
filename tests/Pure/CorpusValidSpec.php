<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * valid/ (228 fixtures × both backends): lax loads must deep-equal
 * the immutable .json oracle, and canonical bytes must equal the
 * .canonical.ktav companion when present — asserted through
 * canonicalFromSource for ALL fixtures (that path preserves empty
 * Object/Array identity) and through emitCanonical(loads(x)) for all
 * but the six documented empty-compound gaps shared by both backends
 * (PHP's single array type collapses {} and []; see CHANGELOG
 * "Known limitations"). Expected values come from the corpus only,
 * never from an implementation.
 */
describe('corpus :: valid (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest(); // fail closed on any corpus problem
    });

    /**
     * The six known gaps: loads() loses empty-compound identity, so
     * emitCanonical(loads(x)) cannot reproduce the canonical bytes.
     * Documented, not new: identical to tests/ConformanceSpec.php's
     * `$knownGaps` for the native backend; the pure backend has the
     * same PHP value-model limitation. canonicalFromSource has no
     * such gap and is asserted for all 228 below.
     */
    $knownGaps = [
        'inline/object/empty',
        'mixed/nested_multiline_representable',
        'objects/empty_inline',
        'objects/empty_multiline',
        'top_level_array/empty_compound_first_item',
        'top_level_inline/empty_array',
    ];

    $fixtures = Corpus::fixtures('valid');

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend . '/valid';

        foreach ($fixtures as $rel => $stem) {
            $isGap = in_array($rel, $knownGaps, true);

            it("$prefix :: value oracle :: $rel", function () use ($backend, $stem, $rel) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                expect(Corpus::hasOracle($stem))->toBe(true);
                $want = Corpus::oracle($stem);
                $got = Corpus::call($backend, 'loads', Corpus::source($stem));
                expect(Corpus::equals($want, $got))->toBe(true);
            });

            // Byte-exact canonical oracle for every fixture, via the
            // identity-preserving path.
            it("$prefix :: canonicalFromSource bytes :: $rel", function () use ($backend, $stem, $rel) {
                if ($backend === 'native') {
                    Corpus::requireNative();
                }
                if (!Corpus::hasCanonical($stem)) {
                    skipIf(true, 'no .canonical.ktav companion in corpus');
                }
                $expected = Corpus::canonicalBytes($stem);
                $actual = Corpus::call($backend, 'canonicalFromSource', Corpus::source($stem));
                expect($actual)->toBe($expected);
            });

            if (!$isGap) {
                it("$prefix :: emitCanonical(loads) bytes :: $rel", function () use ($backend, $stem, $rel) {
                    if ($backend === 'native') {
                        Corpus::requireNative();
                    }
                    if (!Corpus::hasCanonical($stem)) {
                        skipIf(true, 'no .canonical.ktav companion in corpus');
                    }
                    $expected = Corpus::canonicalBytes($stem);
                    $value = Corpus::call($backend, 'loads', Corpus::source($stem));
                    $actual = Corpus::call($backend, 'emitCanonical', $value);
                    expect($actual)->toBe($expected);
                });
            }
        }
    }

    it('every valid fixture produced exactly one triple of cells per backend', function () use ($fixtures) {
        // Belt-and-braces against the silent-vanish failure mode: the
        // runner above derives its cases from the same call, so this
        // only holds while the corpus guard holds.
        expect(count($fixtures))->toBe(228);
    });
});
