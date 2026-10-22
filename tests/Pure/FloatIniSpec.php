<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\Pure\PureFloat;
use Ktav\PureBackend;

/**
 * serialize_precision independence (§ 5.9.8 / round-5 src FIX):
 * PureFloat::shortestDigits() forces serialize_precision=-1 around the
 * json_encode-based shortest round-trip and restores it in a finally,
 * so canonical float text must be IDENTICAL no matter what the host
 * ini says — and the ini must never be left modified.
 *
 * Checked contract (round-5 review P2): when the ini cannot be forced
 * (ini_set disabled / locked), formatFloatText must either return
 * round-trip-exact digits or throw KtavException whose message
 * mentions serialize_precision — never a wrong silent output, never a
 * raw \Error. Every $collect() call is immediately followed by an
 * ini restoration assertion.
 *
 * Ground truth (IEEE-754 shortest round-trip + § 5.9.8 relayout
 * thresholds; spec § 5.9.8): 0.1 → `0.1`; 1/3 →
 * `0.3333333333333333` (decimal, 16 digits); 1e-7 → `1e-7` (E ≤ −3 →
 * scientific); 5e-324 → `5e-324` (shortest subnormal); −0.0 →
 * `-0.0` (§ 5.9.8 signed zero); 0.0 → `0.0`; 2.5e10 → `2.5e10`
 * (E ≥ 7 → scientific); 1e10 → `1e10` (digits `1`, E=10 ≥ 7 — NOT
 * `10000000000.0`).
 */
describe('float output is serialize_precision independent (§ 5.9.8)', function () {

    $values = [
        '0.1' => 0.1,
        '1/3' => 1 / 3,
        '1e-7' => 1.0e-7,
        '5e-324' => 5.0e-324,
        '-0.0' => -0.0,
        '0.0' => 0.0,
        '2.5e10' => 2.5e10,
        '1e10' => 1.0e10,
    ];

    // Exact § 5.9.8 relayout oracles, pinned INDEPENDENTLY of any
    // cross-setting agreement (round-5: no cross-setting result may
    // stand in for a wrong canonical literal).
    $pinned = [
        '1/3' => '0.3333333333333333',
        '5e-324' => '5e-324',
        '-0.0' => '-0.0',
        '0.0' => '0.0',
    ];

    $canSet = function_exists('ini_set')
        && @ini_set('serialize_precision', ini_get('serialize_precision')) !== false;

    $collect = function (array $values): array {
        $out = [];
        foreach ($values as $k => $v) {
            $out[$k] = PureFloat::formatFloatText($v);
        }
        return $out;
    };

    $settings = ['-1', '3', '17'];
    $snapshots = [];
    $canon = [];

    foreach ($settings as $p) {
        it("produces identical float text at serialize_precision=$p", function () use ($p, $values, $pinned, $collect, $canSet, &$snapshots, &$canon) {
            $prev = ini_get('serialize_precision');
            if (!$canSet) {
                // Contract instead of skip: without ini_set control the
                // helper must still be correct — round-trip-exact digits
                // or an explicit KtavException naming serialize_precision.
                try {
                    foreach ($values as $k => $v) {
                        $got = PureFloat::formatFloatText($v);
                        expect((float) $got === $v || $got === '-0.0' || $got === '0.0')->toBe(true);
                    }
                } catch (KtavException $e) {
                    expect(strpos($e->getMessage(), 'serialize_precision') !== false)->toBe(true);
                }
                return;
            }
            expect(ini_set('serialize_precision', $p) !== false)->toBe(true);
            try {
                $snapshots[$p] = $collect($values);
                // Restoration proof, checked IMMEDIATELY after use.
                expect(ini_get('serialize_precision'))->toBe($p);
                // Pinned § 5.9.8 relayout oracles.
                expect($snapshots[$p]['2.5e10'])->toBe('2.5e10');
                expect($snapshots[$p]['1e10'])->toBe('1e10');
                expect($snapshots[$p]['0.1'])->toBe('0.1');
                expect($snapshots[$p]['1e-7'])->toBe('1e-7');
                foreach ($pinned as $k => $want) {
                    expect($snapshots[$p][$k])->toBe($want);
                }
                $canon[$p] = PureBackend::emitCanonical(['v' => 2.5e10]);
                expect($canon[$p])->toBe("v: 2.5e10\n");
            } finally {
                ini_set('serialize_precision', (string) $prev);
                expect(ini_get('serialize_precision'))->toBe($prev);
            }
        });
    }

    it('emits byte-identical output across all three settings', function () use ($settings, &$snapshots, &$canon) {
        // Skipped (not failed) only when the host allows no setting at
        // all — the contract branch above still ran on such hosts.
        if (count($snapshots) < 2) {
            skipIf(true, 'fewer than two serialize_precision settings were settable');
        }
        foreach ($settings as $p) {
            if (!isset($snapshots[$p])) {
                continue;
            }
            expect($snapshots[$p])->toBe($snapshots['-1']);
            expect($canon[$p])->toBe($canon['-1']);
        }
    });
});
