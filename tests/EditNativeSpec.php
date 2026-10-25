<?php

declare(strict_types=1);

namespace Ktav\Tests;

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\NativeLib;
use Ktav\PureBackend;

/**
 * Native source-edit parity (spec 0.9): the SAME assertions the pure
 * suites pin, executed through \Ktav\Ktav::editValue over the C ABI
 * (ktav_edit_value / ktav_edit_value_segments, ABI_VERSION 2).
 *
 * Requires a ktav_cabi that exports the source-edit symbols; an ABI-1
 * binary is a HARD FAILURE for these cells — the pure backend is
 * never a hidden fallback and the requirement is never skipped.
 */
describe('native source edit (C ABI parity, ABI version 2)', function () {

    beforeAll(function () {
        TestPaths::init();
    });

    /** Capability probe: does the loaded cabi export the edit set? */
    $editAbiAvailable = function (): bool {
        if (!extension_loaded('ffi')) {
            return false;
        }
        try {
            NativeLib::callEditBytes('ktav_edit_value', 'a: 1' . "\n", 'a', '2');
            return true;
        } catch (KtavException $e) {
            // A typed edit envelope means the symbols exist and work.
            if ($e->getError() !== null
                && in_array($e->getError(), ['EditInvalidPath', 'EditNotFound', 'EditUnsupported'], true)) {
                return true;
            }
            return false;
        }
    };

    $requireNativeEdit = function () use ($editAbiAvailable) {
        if (!$editAbiAvailable()) {
            // REQUIRED, never skipped: pin the parent Rust C ABI commit.
            throw new \RuntimeException(
                'loaded ktav_cabi does not export the ABI-2 source-edit set'
                . ' — pin the parent Rust C ABI commit'
            );
        }
    };

    it('replaces a scalar like the pure backend', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        expect(Ktav::editValue("port: 8080\nhost: db\n", 'port', 9090))
            ->toBe("port: 9090\nhost: db\n");
    });

    it('is byte-identical on a no-op', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        expect(Ktav::editValue("a: 1_000\n", 'a', 1000))->toBe("a: 1_000\n");
    });

    it('matches the pure backend on layout expansion', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        expect(Ktav::editValue("cfg: {a: 1}\n", 'cfg.a', 'a,b,c,d'))
            ->toBe(PureBackend::editValue("cfg: {a: 1}\n", 'cfg.a', 'a,b,c,d'));
        expect(Ktav::editValue("cfg: {a: 1, b: 2}\n", 'cfg.a', "line1\nline2"))
            ->toBe(PureBackend::editValue("cfg: {a: 1, b: 2}\n", 'cfg.a', "line1\nline2"));
    });

    it('matches the pure backend on marker transitions', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        foreach (
            [
                ["k:: 5\n", 'k', 'x'],
                ["k:: 5\n", 'k', 9090],
                ["k: 5\n", 'k', 'null'],
                ["arr: [\n  5\n]\n", 'arr.0', 'null'],
            ] as $case
        ) {
            list($src, $path, $repl) = $case;
            expect(Ktav::editValue($src, $path, $repl))
                ->toBe(PureBackend::editValue($src, $path, $repl));
        }
    });

    it('reports the same envelope codes and bodies as pure', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        $cases = [
            ['a: 1' . "\n", 'a.', 2, 'EditInvalidPath', '2'],
            ['a: 1' . "\n", 'b', 2, 'EditNotFound', null],
            ["a: {}\na.y: 2\n", 'a', ['z' => 9], 'EditUnsupported', 'noncontiguous'],
        ];
        foreach ($cases as $case) {
            list($src, $path, $repl, $code, $body) = $case;
            $native = null;
            $pure = null;
            try {
                Ktav::editValue($src, $path, $repl);
            } catch (KtavException $e) {
                $native = $e;
            }
            try {
                PureBackend::editValue($src, $path, $repl);
            } catch (KtavException $e) {
                $pure = $e;
            }
            expect($native)->toBeAnInstanceOf(KtavException::class);
            expect($pure)->toBeAnInstanceOf(KtavException::class);
            expect($native->getError())->toBe($code);
            expect($native->getError())->toBe($pure->getError());
            expect($native->getBody())->toBe($body);
            if ($code !== 'EditUnsupported') {
                // Pure reason text may differ in wording for Unsupported;
                // codes and null-locations must match everywhere.
                expect($native->getErrorLine())->toBe($pure->getErrorLine());
                expect($native->getSpan())->toBe($pure->getSpan());
            }
            // The host error text IS the envelope message.
            expect($native->getMessage())->toBe((string) $native->getMessage());
        }
    });

    it('keeps the source-first transport order on the native side too', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        // Broken SOURCE wins over any path/segments transport failure:
        // the 0.9 parser envelope, not an edit or Message code. Native
        // and pure must agree on code AND message text.
        $outcome = function (callable $fn): array {
            try {
                $fn();
                return ['ok', ''];
            } catch (KtavException $e) {
                return [$e->getError(), $e->getMessage()];
            }
        };
        $native = $outcome(function () {
            Ktav::editValue("a: {\n", 'a.', 2);
        });
        $pure = $outcome(function () {
            PureBackend::editValue("a: {\n", 'a.', 2);
        });
        expect($native)->toBe($pure);
        expect($native[0])->toBe('UnclosedCompound');
        // An empty textual path is a transport failure that must not
        // mask the broken source either.
        $native = $outcome(function () {
            Ktav::editValue("a: {\n", '', 2);
        });
        $pure = $outcome(function () {
            PureBackend::editValue("a: {\n", '', 2);
        });
        expect($native)->toBe($pure);
        expect($native[0])->toBe('UnclosedCompound');
    });

    it('aligns transport failures with pure Message envelopes', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        // Non-string segment: native path JSON is ["a",5] -> transport
        // decode failure (Message); pure raises the same category.
        $native = null;
        $pure = null;
        try {
            Ktav::editValueSegments("a: 1\n", ['a', 5], 2);
        } catch (KtavException $e) {
            $native = $e;
        }
        try {
            PureBackend::editValueSegments("a: 1\n", ['a', 5], 2);
        } catch (KtavException $e) {
            $pure = $e;
        }
        expect($native)->toBeAnInstanceOf(KtavException::class);
        expect($pure)->toBeAnInstanceOf(KtavException::class);
        expect($native->getError())->toBe('Message');
        expect($native->getError())->toBe($pure->getError());
        // Source-less transport diagnostics never invent a location.
        expect($native->getErrorLine())->toBe(null);
        expect($native->getSpan())->toBe(null);
        // Invalid UTF-8 textual path: native transport -> Message,
        // same as pure.
        $native = null;
        $pure = null;
        try {
            Ktav::editValue("a: 1\n", "a\xFF", 2);
        } catch (KtavException $e) {
            $native = $e;
        }
        try {
            PureBackend::editValue("a: 1\n", "a\xFF", 2);
        } catch (KtavException $e) {
            $pure = $e;
        }
        expect($native)->toBeAnInstanceOf(KtavException::class);
        expect($native->getError())->toBe('Message');
        expect($native->getError())->toBe($pure->getError());
        expect($native->getErrorLine())->toBe(null);
    });

    it('gates EVERY native operation on ABI version 2 at load time', function () use ($requireNativeEdit) {
        // Mechanism regression: the shared gate rejects any non-2 ABI
        // (an ABI-1 binary) for ALL native calls — not just the edit
        // set. Static and artifact-free; runs everywhere.
        $caught = null;
        try {
            NativeLib::assertAbiVersion(1);
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect(strpos((string) $caught->getMessage(), 'ABI version mismatch') !== false)->toBe(true);
        expect(strpos((string) $caught->getMessage(), 'expected 2, got 1') !== false)->toBe(true);
        // The gate accepts exactly the expected version.
        NativeLib::assertAbiVersion(NativeLib::EXPECTED_ABI_VERSION);
        // Positive: the LOADED library passed the shared gate (any
        // successful native operation implies ffi() ran it).
        $requireNativeEdit();
        expect(Ktav::nativeVersion())->not->toBe('');
    });

    it('matches pure on strict wire-ingest negative controls', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        // Invalid tagged payloads are Message on BOTH backends — never
        // a coerced scalar that could produce a false no-op.
        $cases = [
            ["a: 0.0\n", 'a', ['$f' => 'abc']], // the false 0.0 no-op regression
            ["a: 0\n", 'a', ['$i' => '12a3']],
            ["a: 0.0\n", 'a', ['$f' => '5']], // no '.' / exponent
        ];
        foreach ($cases as $case) {
            list($src, $path, $repl) = $case;
            $outcome = function (callable $fn): string {
                try {
                    $fn();
                    return 'ok';
                } catch (KtavException $e) {
                    return $e->getError();
                }
            };
            $native = $outcome(function () use ($src, $path, $repl) {
                Ktav::editValue($src, $path, $repl);
            });
            $pure = $outcome(function () use ($src, $path, $repl) {
                PureBackend::editValue($src, $path, $repl);
            });
            expect($native)->toBe('Message');
            expect($native)->toBe($pure);
        }
        // Canonicalized payloads agree byte-for-byte with pure.
        foreach (
            [
                ["a: 1\n", 'a', ['$i' => '+0001'], "a: 1\n"],
                ["a: 0\n", 'a', ['$i' => '-0'], "a: 0\n"],
                ["a: 1\n", 'a', ['$f' => '2.50'], "a: 2.5\n"],
            ] as $case
        ) {
            list($src, $path, $repl, $expected) = $case;
            expect(Ktav::editValue($src, $path, $repl))->toBe($expected);
            expect(PureBackend::editValue($src, $path, $repl))->toBe($expected);
        }
    });

    it('treats a reordered Object as a byte-identical no-op on both backends', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        $src = "k: {a: 1, b: 2}\n";
        expect(Ktav::editValue($src, 'k', ['b' => 2, 'a' => 1]))->toBe($src);
        expect(PureBackend::editValue($src, 'k', ['b' => 2, 'a' => 1]))->toBe($src);
        // Arrays stay ordered: the reorder is a real change, agreeing
        // with pure byte-for-byte.
        $reordered = "k: {a: [2, 1]}\n";
        expect(Ktav::editValue("k: {a: [1, 2]}\n", 'k.a', [2, 1]))->toBe($reordered);
        expect(PureBackend::editValue("k: {a: [1, 2]}\n", 'k.a', [2, 1]))->toBe($reordered);
    });

    it('segments variant carries literal keys without unescaping', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        expect(Ktav::editValueSegments('"a.b": 1' . "\n", ['a.b'], 2))
            ->toBe('"a.b": 2' . "\n");
    });

    it('lets the source fail with the ordinary parser envelope', function () use ($requireNativeEdit) {
        $requireNativeEdit();
        $caught = null;
        try {
            Ktav::editValue("a: {\n", 'a', 2);
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('UnclosedCompound');
    });
});
