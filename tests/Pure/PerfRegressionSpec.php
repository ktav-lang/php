<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\Pure\PureWorkMeter;
use Ktav\PureBackend;

/**
 * Deterministic, TIMING-FREE algorithmic regression checks (brief:
 * genuine work on wide objects, dotted merges, long Strings, nested
 * compounds — never wall-clock gates on shared-CI milliseconds).
 *
 * Method — PureWorkMeter tick accounting (instrumented sites, round-3
 * src repair FIX E / round-4 src report):
 *
 *   - PureParser::run(): 1 tick per input byte (line-terminator scan)
 *     and 1 per processed line;
 *   - PureInlineScan::scan(): 1 tick per scanned byte, including the
 *     opaque quoted-key skip loop;
 *   - PureScanner::decRun(): 1 tick per digit-run byte;
 *   - PureParser::walkKey(): 1 tick per decoded dotted-segment descent
 *     step (O(segments) in a correct implementation);
 *   - PureParser::insertLeaf(): 1 tick per call (O(1) leaf placement);
 *   - PureWriter::emitPair() / emitItem(): 1 tick per emitted
 *     pair/item line.
 *
 * HONEST SCOPE (round-4/round-6 review): the meter is NOT a
 * universal complexity proof. NOT counted: PHP's own hash-table
 * lookup cost (unmeterable and trusted), key-escape work, output-byte
 * appends, dotted-path descent inside the writer, and formatter
 * trivia recursion. Consequently an UNINSTRUMENTED linear rescan of
 * the accumulated table (e.g. a quadratic lookup inside walkKey or
 * insertLeaf) would NOT be caught by the tick bound — the pair
 * lookups are plain PHP hash lookups. What the 2.5x doubling bound
 * DOES catch is extra INSTRUMENTED passes: extra per-segment descent
 * steps, extra per-byte/per-line scans, or extra emitted lines per
 * input line. Correctness-at-scale assertions (deep-equal reparse,
 * leaf walks, merge counts) guard the rest.
 *
 * The tick bound applies ONLY where the instrumented sites dominate
 * AND both the input AND the spec-mandated output are linear in the
 * varied dimension: wide object, reopen-merge (100/200), long Strings
 * (parse window only), nested chains (32/64, depth fixed at 64, so
 * output bytes are linear in count).
 *
 * The dotted-path workload is EXCLUDED from any tick bound: § 5.9.10
 * mandates one nested level per dotted segment, so its nested output
 * grows QUADRATICALLY in path length while PureParser::run() ticks
 * per re-parsed output byte — ticks(2N) ~ 4x ticks(N) there even for
 * a perfectly linear implementation. It is kept as a bounded
 * correctness check (48 vs 96 segments: full leaf walk + reparse
 * equality) with no tick assertion at all.
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

    /** Correctness-only variant: same double-run harness, NO tick
     *  bound — for workloads whose spec-mandated output is not linear
     *  in the varied dimension (dotted paths). */
    $twice = function (int $n, callable $run): array {
        PureWorkMeter::reset();
        $small = $run($n);
        expect(PureWorkMeter::$ticks > 0)->toBe(true);
        PureWorkMeter::reset();
        $big = $run(2 * $n);
        expect(PureWorkMeter::$ticks > 0)->toBe(true);
        return [$small, $big];
    };

    /** Walks $segments-1 nested levels from the root and asserts the
     *  leaf value — the § 5.9.10 dotted expansion shape. */
    $walkLeaf = function (array $root, int $segments): void {
        $cur = $root;
        for ($i = 0; $i < $segments - 1; $i++) {
            expect(isset($cur['seg' . $i]))->toBe(true);
            $cur = $cur['seg' . $i];
        }
        expect(isset($cur['seg' . ($segments - 1)]))->toBe(true);
        expect($cur['seg' . ($segments - 1)])->toBe(1);
        expect(isset($root['seg0']))->toBe(true);
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

    it('long strings: parse stays linear in bytes (dump correctness outside the window)', function () use ($scale) {
        // Only the PARSE window is metered: emitPair/emitItem tick per
        // line (not per byte), so a dump window would add no scaling
        // signal — it is asserted for correctness outside the meter.
        $run = function (int $units): array {
            $long = str_repeat('abcdefghij', $units); // 10 * $units chars
            $src = "s: $long\nt: $long\n";
            return PureBackend::loads($src);
        };

        list($small, $big) = $scale(25000, $run);
        expect($small['s'])->toBe(str_repeat('abcdefghij', 25000));
        expect($big['s'])->toBe(str_repeat('abcdefghij', 50000));
        // Dump correctness OUTSIDE the measured window.
        $text = PureBackend::dumps($big);
        expect(strpos($text, str_repeat('abcdefghij', 50000)) !== false)->toBe(true);
    });

    it('nested compounds: parse + dump stay linear in chains', function () use ($scale) {
        $run = function (int $count): array {
            // $count independent depth-64 chains inside an Array root.
            // Depth is FIXED, so input AND output bytes are linear in
            // $count — the tick bound is meaningful here.
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

    it('dotted path (48 vs 96 segments): correctness only, no tick bound', function () use ($twice, $walkLeaf) {
        // NO tick bound, deliberately: § 5.9.10 mandates one nested
        // `{` level per dotted segment, so dumps() output (and hence
        // the re-parsed byte stream that PureParser::run() ticks per
        // byte) grows quadratically in path length. A 2.5x doubling
        // bound would fail a perfectly linear implementation. The
        // workload stays as bounded CORRECTNESS at 48 vs 96 segments:
        // full leaf walk at both sizes + reparse equality — it still
        // guards the dotted emitter and merge bookkeeping against
        // wrong-output regressions at scale.
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

        list($small, $big) = $twice(48, $run);
        $walkLeaf($small, 48);
        $walkLeaf($big, 96);
    });

    it('duplicate-heavy merge: per-segment descent + leaf insert stay linear', function () use ($scale) {
        // Narrow meter guard (round-6 honest scope): the metered sites
        // are OUR walkKey per-segment descent steps and insertLeaf
        // calls (O(segments)/O(1) in a correct implementation). The
        // pair lookups are PHP hash lookups — unmeterable — so an
        // uninstrumented linear rescan of the accumulated merge table
        // would NOT be caught here; what the 2.5x bound catches is
        // extra per-segment descent steps, extra line scans, or extra
        // emitted lines per input line (see the HONEST SCOPE header).
        // N stays small (200 vs 400 pairs) — a deterministic counter,
        // not a CI load.
        $run = function (int $n): array {
            $lines = [];
            for ($i = 0; $i < $n; $i++) {
                $lines[] = "obj.leaf{$i}: {$i}";
            }
            return PureBackend::loads(implode("\n", $lines) . "\n");
        };

        list($small, $big) = $scale(200, $run);
        expect(count($small['obj']))->toBe(200);
        expect(count($big['obj']))->toBe(400);
        expect($small['obj']['leaf0'])->toBe(0);
        expect($big['obj']['leaf399'])->toBe(399);

        // The failing half of the workload, outside the metered window:
        // a duplicate dotted pair must throw DuplicateKey on the FIRST
        // duplicate (insertLeaf lookup), never silently overwrite.
        $dup = null;
        try {
            PureBackend::loads("obj.leaf0: 0\nobj.leaf0: 1\n");
        } catch (KtavException $e) {
            $dup = $e;
        }
        expect($dup)->toBeAnInstanceOf(KtavException::class);
        expect($dup->getError())->toBe('DuplicateKey');
    });
});
