<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\Pure\PureWorkMeter;
use Ktav\PureBackend;

/**
 * Deterministic, TIMING-FREE algorithmic regression checks (brief:
 * genuine work on wide objects, dotted merges, long Strings, nested
 * compounds — never wall-clock gates on shared-CI milliseconds).
 *
 * Method — PureWorkMeter tick accounting (src/Pure/PureWorkMeter.php,
 * instrumented by the round-3 src repair, FIX E): the meter counts
 * GENUINE work units at the parser/scanner/writer's linear hot sites —
 *
 *   - PureParser::run(): 1 tick per input byte (line-terminator scan,
 *     src/Pure/PureParser.php:109) and 1 per processed line
 *     (src/Pure/PureParser.php:122);
 *   - PureInlineScan::scan(): 1 tick per scanned byte
 *     (src/Pure/PureInlineScan.php:54);
 *   - PureScanner::decRun(): 1 tick per digit-run byte
 *     (src/Pure/PureScanner.php:659);
 *   - PureWriter::emitPair() / emitItem(): 1 tick per emitted
 *     pair/item line (src/Pure/PureWriter.php:248, :371).
 *
 * Every site is linear in input bytes, lines, or emitted lines and
 * independent of indentation depth, so total ticks are a deterministic
 * proxy for O(n) work: if an algorithm were quadratic on the same
 * input, doubling the input size would roughly QUADRUPLE the ticks and
 * blow the bound below — with no clock involved, the check is stable
 * on any CI hardware and immune to machine noise.
 *
 * Bound: ticks(2N) <= 2 * ticks(N) + ticks(N)/2, i.e. at most 2.5x per
 * doubling. A strictly linear algorithm sits at 2.0x; the extra 0.5x
 * headroom is deliberately generous for genuine super-linear constant
 * factors (hash-table growth/rehashing, allocator behaviour), while
 * any quadratic component (4.0x) still fails decisively.
 *
 * Correctness at BOTH sizes is asserted unconditionally inside every
 * workload — deep-equal reparse, insertion order, reopen-merge counts,
 * leaf walks. A tick bound never replaces a value assertion.
 */
describe('pure backend scaling (deterministic PureWorkMeter tick bound)', function () {

    /**
     * Runs $run($n) at $n and 2*$n with a PureWorkMeter::reset() around
     * each run; asserts ticks(2N) <= 2.5 * ticks(N) and returns both
     * results for the caller's correctness assertions.
     *
     * @return array [resultAtN, resultAt2N]
     */
    $scale = function (int $n, callable $run): array {
        PureWorkMeter::reset();
        $small = $run($n);
        $tN = PureWorkMeter::$ticks;

        PureWorkMeter::reset();
        $big = $run(2 * $n);
        $t2N = PureWorkMeter::$ticks;

        expect($tN > 0)->toBe(true); // the meter must have observed real work
        expect($t2N <= 2 * $tN + intdiv($tN, 2))->toBe(true);
        return [$small, $big];
    };

    it('wide object: parse + dump + canonical stay linear in pairs', function () use ($scale) {
        $run = function (int $n): array {
            $pairs = [];
            for ($i = 0; $i < $n; $i++) {
                $pairs['key_' . $i] = 'value_' . $i;
            }
            $parsed = PureBackend::loads(PureBackend::dumps($pairs));
            $canonical = PureBackend::emitCanonical($pairs);
            // Insertion order survives canonical emission (§ 5.9.11:
            // no sorting) and the canonical text reparses deep-equal.
            expect(strpos($canonical, 'key_0') < strpos($canonical, 'key_' . ($n - 1)))->toBe(true);
            expect(PureBackend::loads($canonical))->toBe($pairs);
            return $parsed;
        };

        list($small, $big) = $scale(1000, $run);
        // Genuine correctness at BOTH sizes: deep-equal reparse shape.
        expect(count($small))->toBe(1000);
        expect($small['key_0'])->toBe('value_0');
        expect(count($big))->toBe(2000);
        expect($big['key_1999'])->toBe('value_1999');
    });

    it('dotted reopen-merge: linear across 100 vs 200 objects x 10 leaves', function () use ($scale) {
        $run = function (int $objects): array {
            $lines = [];
            for ($o = 0; $o < $objects; $o++) {
                for ($k = 0; $k < 10; $k++) {
                    $lines[] = "obj{$o}.leaf{$k}: {$k}";
                }
            }
            $parsed = PureBackend::loads(implode("\n", $lines) . "\n");
            // Genuine reopen-merge (§ 5.3.2): extend every object a
            // second time through dump -> append -> reparse.
            $reopen = [];
            for ($o = 0; $o < $objects; $o++) {
                $reopen[] = "obj{$o}.extra: x";
            }
            $merged = PureBackend::loads(PureBackend::dumps($parsed) . implode("\n", $reopen) . "\n");
            // Reopen-merge counts asserted INSIDE the measured run at
            // both sizes: every object gained exactly one leaf.
            for ($o = 0; $o < $objects; $o++) {
                expect(count($merged['obj' . $o]))->toBe(11);
                expect($merged['obj' . $o]['extra'])->toBe('x');
            }
            return $merged;
        };

        list($small, $big) = $scale(100, $run);
        expect(count($small))->toBe(100);
        expect(count($big))->toBe(200);
    });

    it('long strings: round trip stays linear in bytes', function () use ($scale) {
        $run = function (int $units): array {
            $long = str_repeat('abcdefghij', $units); // 10 * $units chars
            $src = "s: $long\nt: $long\n";
            $parsed = PureBackend::loads($src);
            expect($parsed['s'])->toBe($long);
            expect($parsed['t'])->toBe($long);
            $text = PureBackend::dumps($parsed);
            expect(strpos($text, $long) !== false)->toBe(true);
            return $parsed;
        };

        list($small, $big) = $scale(25000, $run);
        expect(strlen($small['s']))->toBe(250000);
        expect(strlen($big['s']))->toBe(500000);
    });

    it('nested compounds: parse + dump stay linear in chains', function () use ($scale) {
        $run = function (int $count): array {
            // $count independent depth-64 chains inside an Array root.
            $items = [];
            for ($c = 0; $c < $count; $c++) {
                $value = 'leaf';
                for ($d = 0; $d < 64; $d++) {
                    $value = [$value];
                }
                $items[] = $value;
            }
            $parsed = PureBackend::loads(PureBackend::dumps($items));
            expect($parsed)->toBe($items); // deep-equal reparse
            return $parsed;
        };

        list($small, $big) = $scale(32, $run);
        expect(count($small))->toBe(32);
        expect(count($big))->toBe(64);
    });

    it('dotted path (48 vs 96 segments): nested render + reparse stay linear', function () use ($scale) {
        // Kept BOUNDED deliberately (round-3 review P2): § 5.9.10
        // mandates one nested `{` level per dotted segment, so the
        // nested render is spec-required; the workload stays modest
        // and correctness-focused — it guards the offset-based dotted
        // emitter (PureWriter::emitDottedPairFrom, no per-level
        // array_slice), not giant-indent output volume.
        $run = function (int $segments): array {
            $path = [];
            for ($i = 0; $i < $segments; $i++) {
                $path[] = 'seg' . $i;
            }
            $src = implode('.', $path) . ": 1\n";
            $parsed = PureBackend::loads($src);
            // Leaf walk: every level exists, leaf value intact.
            $cur = $parsed;
            for ($i = 0; $i < $segments - 1; $i++) {
                expect(isset($cur['seg' . $i]))->toBe(true);
                $cur = $cur['seg' . $i];
            }
            expect($cur['seg' . ($segments - 1)])->toBe(1);
            // Full rewrite + reparse deep-equal at both sizes.
            $reparsed = PureBackend::loads(PureBackend::dumps($parsed));
            expect($reparsed)->toBe($parsed);
            return $parsed;
        };

        list($small, $big) = $scale(48, $run);
        expect(isset($small['seg0']))->toBe(true);
        expect(isset($big['seg0']))->toBe(true);
        expect(isset($big['seg95']))->toBe(true);
    });
});
