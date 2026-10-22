<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Pure-backend value model: Integer/Float domains, big integers as
 * digit strings (the `$i` wire convention), Float shortest round-trip
 * incl. signed zero, force-strings mode, root kinds, and the
 * no-native-dependency guarantees.
 */
describe('PureBackend value model', function () {

    // -- Integers -----------------------------------------------------

    it('keeps in-range integers as PHP ints', function () {
        $got = PureBackend::loads("small: -3\nbig: " . PHP_INT_MAX . "\n");
        expect($got['small'])->toBe(-3);
        expect($got['big'])->toBe(PHP_INT_MAX);
    });

    it('delivers integers beyond PHP_INT_MAX as decimal digit strings', function () {
        $huge = '99999999999999999999999999999';
        $got = PureBackend::loads('value: ' . $huge);
        expect($got['value'])->toBe($huge);

        $tiny = '-99999999999999999999999999999';
        $got = PureBackend::loads('value: ' . $tiny);
        expect($got['value'])->toBe($tiny);
    });

    it('writes `["$i" => digits]` without float conversion, both directions', function () {
        $huge = '123456789012345678901234567890';
        $text = PureBackend::dumps(['v' => ['$i' => $huge]]);
        expect($text)->toContain($huge);

        // Cross-backend agreement runs only where the native backend is
        // enabled AND its artifact is present; the pure-only CI job
        // (KTAV_ENABLED_BACKENDS=pure) must execute every pure cell
        // with zero native references. The same `['$i' => digits]`
        // shape is cross-checked unconditionally in
        // CrossBackendRoundtripSpec (which requires native in beforeAll).
        if (Corpus::nativeEnabled()) {
            Corpus::requireNative();
            expect(Corpus::call('native', 'loads', $text))->toBe(['v' => $huge]);
        }
    });

    it('negative Integer canonicalises to 0 (unlike Float -0.0)', function () {
        $got = PureBackend::loads('x: -0');
        expect($got['x'])->toBe(0);
    });

    it('parses based i64::MAX as PHP_INT_MAX on a 64-bit host', function () {
        // Corpus i64-max fixtures + spec.md:659-665 (Integer domain is
        // i64): a POSITIVE equal-to-max based literal falls through the
        // sign-conditional bound (PureScanner::matchBased) to the cast
        // round-trip — never PHP_INT_MIN.
        if (PHP_INT_SIZE < 8) {
            return; // 32-bit host: PHP_INT_MAX < i64::MAX (PureBigInt carrier)
        }
        expect(PureBackend::loads('m: 0x7fffffffffffffff')['m'])->toBe(PHP_INT_MAX);
    });

    it('normalises negative based zero to Integer 0', function () {
        // spec.md:673-675: signed-zero literals (decimal AND based)
        // normalise to 0 — `-0x0` must not be misrouted to String by a
        // '-0' round-trip expectation.
        expect(PureBackend::loads('z: -0x0')['z'])->toBe(0);
        expect(PureBackend::loads('z: -0b0')['z'])->toBe(0);
        expect(PureBackend::loads('z: -0o0')['z'])->toBe(0);
    });

    // -- Floats --------------------------------------------------------

    it('round-trips floats through shortest digits', function () {
        foreach ([0.5, 0.75, 1e-3, 1e10, 3.14159265358979, 1e-7, 123456789.0] as $f) {
            $text = PureBackend::dumps(['v' => $f]);
            $back = PureBackend::loads($text);
            expect($back['v'])->toBe($f);
        }
    });

    it('preserves negative zero as -0.0 (§ 5.9.8)', function () {
        $text = PureBackend::dumps(['v' => -0.0]);
        expect($text)->toContain('-0.0');

        // Sign preservation WITHOUT dividing (1.0 / -0.0 throws
        // DivisionByZeroError on PHP 8): the sign survives a full
        // dumps -> loads round trip, proven by re-emission — PHP's
        // === cannot distinguish -0.0 from 0.0, the emitted text can.
        $back = PureBackend::loads('x: -0.0');
        expect(PureBackend::dumps($back))->toContain('-0.0');

        // Canonical emission carries the IEEE-754 sign bit through
        // PureFloat::formatFloatText's sign-bit test, and the writer
        // appends "\n" after every pair line (PureWriter::emitPair),
        // byte-identical to Rust render.rs emit_root.
        expect(PureBackend::emitCanonical(['x' => -0.0]))->toBe("x: -0.0\n");
        expect(PureBackend::emitCanonical(['x' => 0.0]))->toBe("x: 0.0\n");
    });

    it('rejects non-finite floats', function () {
        foreach ([NAN, INF, -INF] as $v) {
            $caught = null;
            try {
                PureBackend::dumps(['v' => $v]);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            expect($caught->getReason())->toBe('NonFiniteFloat');
        }
    });

    // -- Root kinds / compounds ----------------------------------------

    it('emits an empty PHP array as an empty Object root: ZERO bytes', function () {
        // Grounding: rust/src/document/render/emitters/render.rs:68-79
        // emit_root(): an empty Object root renders the (empty) object
        // body — ZERO bytes; an empty Array root renders "[]\n".
        // PureWriter::emitRoot matches byte-for-byte, and the facade
        // passes '{}' JSON for [] on the native path too (Ktav.php
        // dumpsImpl), so both backends agree: dumps([]) === ''.
        expect(PureBackend::dumps([]))->toBe('');
        expect(PureBackend::emitCanonical([]))->toBe('');
        expect(PureBackend::loads('{}'))->toBe([]);
        // `[[]]` is NOT an empty Array root: toInternal converts the
        // inner empty PHP array to a PureObject (PureWriter.php:84-86,
        // documented {} convention), so the root is a LIST whose one
        // item is an EMPTY COMPOUND. emitRoot takes the § 5.9.3 wrap
        // branch (items[0] is a compound, PureWriter.php:172-178):
        // "[\n" + emitItem(item, 1, false) + "]\n"; emitItem's empty
        // PureObject branch (PureWriter.php:405-407) indents one level
        // and emits "{}\n" — bytes "[\n    {}\n]\n", which reparses
        // to [ [] ]-shaped (one empty compound item).
        expect(PureBackend::emitCanonical([[]]))->toBe("[\n    {}\n]\n");
        expect(PureBackend::loads(PureBackend::emitCanonical([[]])))->toBe([[]]);
    });

    it('round-trips a list root', function () {
        $text = PureBackend::dumps(['alpha', 'beta']);
        expect(PureBackend::loads($text))->toBe(['alpha', 'beta']);
    });

    it('keeps insertion order in canonical output (no sorting, § 5.9.11)', function () {
        $canon = PureBackend::emitCanonical(['z' => 1, 'm' => 2, 'a' => 3]);
        $positions = [strpos($canon, 'z'), strpos($canon, 'm'), strpos($canon, 'a')];
        expect($positions[0] < $positions[1])->toBe(true);
        expect($positions[1] < $positions[2])->toBe(true);
    });

    it('force-strings round-trips through the raw marker', function () {
        $text = PureBackend::dumpsForceStrings(['n' => 7, 'f' => 2.5, 'b' => false, 'nil' => null]);
        $back = PureBackend::loads($text);
        expect($back)->toBe(['n' => '7', 'f' => '2.5', 'b' => 'false', 'nil' => 'null']);
    });

    // -- No-native-dependency guarantees --------------------------------

    it('pure classes never reference the FFI loader', function () {
        // The pure source tree must not touch NativeLib/NativeLoader —
        // proven statically here; the CI pure job additionally proves
        // it at runtime by running with the ffi extension disabled.
        // tests/Pure -> dirname(__DIR__, 2) is the repo root, so the
        // glob resolves to <repo>/src/Pure/*.php (the old
        // __DIR__ . '/../../src/Pure' landed under tests/src and
        // matched nothing).
        $srcDir = dirname(__DIR__, 2) . '/src/Pure';
        $files = glob($srcDir . '/*.php');
        // FAIL CLOSED: a scan that finds zero files proves nothing.
        if ($files === [] || $files === false) {
            throw new \RuntimeException('pure isolation scan found no files under ' . $srcDir);
        }
        expect(count($files) >= 10)->toBe(true); // the real Pure/ tree, not an empty dir
        // TOKEN-based code check (round-3 review P2: the raw substring
        // scan rejected harmless words inside comments/docs). Strip
        // T_COMMENT / T_DOC_COMMENT / T_WHITESPACE tokens, then assert
        // the remaining CODE contains none of the native-loading
        // identifiers — a code reference, not a word in a comment.
        $banned = ['NativeLib', 'NativeLoader', 'FFI', 'extension_loaded'];
        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $code = '';
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_WHITESPACE) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }
            foreach ($banned as $needle) {
                expect(strpos($code, $needle) === false)->toBe(true);
            }
        }
        expect(PureBackend::isAvailable())->toBe(true);
    });
});
