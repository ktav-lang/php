<?php

declare(strict_types=1);

// Executes the claims README.md and its two translations make, so a doc
// edit cannot drift from the library silently.
//
// Each expectation corresponds to a sentence in the docs. The API-table
// rows for dumpsForceStrings and emitCanonical were missing from all
// three languages until they were measured against the real Ktav
// surface, which is why this file exists rather than a prose review.

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Tests\TestPaths;

describe('README claims', function () {

    beforeAll(function () {
        TestPaths::init();
        if (!TestPaths::cabiBuilt()) {
            skipIf(true, 'cabi not built (' . TestPaths::cabi() . ') — run `cargo build --release -p ktav-cabi`');
        }
    });

    describe('the API table', function () {

        it('format keeps comments and expands an inline compound', function () {
            // The comma is a real separator; two spaces are NOT — see the
            // guard below. A laxer assertion would pass without expanding.
            expect(Ktav::format("## the server\nserver: {host: a, port: 80}\n"))
                ->toBe("## the server\nserver: {\n    host: a\n    port: 80\n}\n");
        });

        it('does not treat two spaces as a compound separator', function () {
            // Guards the distinction the expectation above relies on.
            expect(Ktav::loads("server: {host: a  port: 80}\n"))
                ->toBe(['server' => ['host' => 'a  port: 80']]);
        });

        it('collapses blank-line runs to exactly one', function () {
            expect(Ktav::format("a: 1\n\n\n\nb: 2\n"))->toBe("a: 1\n\nb: 2\n");
        });

        it('is a fixed point', function () {
            $once = Ktav::format("## c\na :  1\n\n\n\nb : 2\n");
            expect(Ktav::format($once))->toBe($once);
        });

        it('equals emitCanonical of the parse when no trivia is present', function () {
            $doc = "server: {host: a, port: 80}\nratio: 0.5\n";
            expect(Ktav::format($doc))->toBe(Ktav::emitCanonical(Ktav::loads($doc)));
        });

        it('diverges from emitCanonical when trivia is present', function () {
            // README: format keeps what the canonical writer drops.
            $doc = "## why\na: 1\n\nb: 2\n";
            expect(Ktav::format($doc))->not->toBe(Ktav::emitCanonical(Ktav::loads($doc)));
        });

        it('canonicalFromSource agrees with emitCanonical(loads(x)), and drops trivia', function () {
            // Task #311: this symbol was already exported by the native
            // library — every cabi crate carries it — but this binding
            // never surfaced it. It has to agree with the two-step path
            // it replaces, and it must NOT keep comments/blank lines the
            // way format() does.
            foreach ([
                "x: 1.0\n",
                "x: 1e400\n",
                "a.b: 1\nc: [1, 2]\n",
                "x: 1.23456789012345678901\n",
            ] as $src) {
                expect(Ktav::canonicalFromSource($src))
                    ->toBe(Ktav::emitCanonical(Ktav::loads($src)));
            }
            $out = Ktav::canonicalFromSource("## why\na: 1\n\n\nb: 2\n");
            expect($out)->not->toContain('##');
            expect($out)->not->toContain("\n\n");
        });

        it('coerces every leaf scalar and keeps compounds in dumpsForceStrings', function () {
            $out = Ktav::dumpsForceStrings([
                'p' => 8080,
                'r' => 0.5,
                't' => true,
                'n' => null,
                'o' => ['k' => 1],
            ]);
            expect($out)->toContain('p:: 8080');
            expect($out)->toContain('r:: 0.5');
            expect($out)->toContain('t:: true');
            expect($out)->toContain('n:: null');
            // README: "only leaves are coerced" — a nested leaf too.
            expect($out)->toContain('k:: 1');
            expect($out)->toContain("o: {");
        });

        it('reparses a dumpsForceStrings result as all String scalars', function () {
            $out = Ktav::dumpsForceStrings(['p' => 8080, 't' => true, 'o' => ['k' => 1]]);
            expect(Ktav::loads($out))->toBe([
                'p' => '8080',
                't' => 'true',
                'o' => ['k' => '1'],
            ]);
        });

        it('reports a non-empty nativeVersion', function () {
            expect(Ktav::nativeVersion())->not->toBe('');
        });

        it('rejects with loadsStrict what loads silently canonicalises', function () {
            // This IS the reason loadsStrict exists: lax loads infers a
            // number and the written "1.10" becomes 1.1 with no warning.
            expect(Ktav::loads("version: 1.10\n"))->toBe(['version' => 1.1]);
            expect(function () {
                Ktav::loadsStrict("version: 1.10\n");
            })->toThrow(new KtavException(''));
        });
    });

    describe('the error envelope', function () {

        it('carries the worked example the README prints', function () {
            $thrown = null;
            try {
                Ktav::loadsStrict("version: 1.10\n");
            } catch (KtavException $e) {
                $thrown = $e;
            }
            expect($thrown)->toBeAnInstanceOf(KtavException::class);
            expect($thrown->getError())->toBe('LossyScalar');
            expect($thrown->getErrorLine())->toBe(1);
            expect($thrown->getLineText())->toBe('version: 1.10');
            expect($thrown->getBody())->toBe('1.10');
            expect($thrown->getCanonical())->toBe('1.1');
            expect($thrown->getSpecSection())->toBe('§3.6/§5.2');
        });

        it('reports absent information as null, not a missing accessor', function () {
            // README: any field can be read without first checking the
            // error class. A writer-time error has no source position.
            $thrown = null;
            try {
                Ktav::emitCanonical(['srv' => ['port' => ['$f' => '1e999']]]);
            } catch (KtavException $e) {
                $thrown = $e;
            }
            expect($thrown)->toBeAnInstanceOf(KtavException::class);
            expect($thrown->getErrorLine())->toBeNull();
            expect($thrown->getLineText())->toBeNull();
            expect($thrown->getSpan())->toBeNull();
            expect($thrown->getMessage())->not->toBe('');
        });

        it('measures span in UTF-8 bytes, not UTF-16 code units', function () {
            // Cyrillic makes the two disagree: 14 bytes vs 10 characters.
            $thrown = null;
            try {
                Ktav::loadsStrict("ключ: 1.10\n");
            } catch (KtavException $e) {
                $thrown = $e;
            }
            expect($thrown->getLineText())->toBe('ключ: 1.10');
            expect($thrown->getSpan()['end'])->toBe(14);
            expect(mb_strlen($thrown->getLineText(), 'UTF-8'))->toBe(10);
        });

        it('tolerates an envelope field it does not know', function () {
            // 0.8.0 appended `message` to the nine-field envelope. php reads
            // fields by key, so a tenth field cannot break the other nine —
            // java parsed positionally and DID break (see task #303).
            $thrown = null;
            try {
                Ktav::loadsStrict("version: 1.10\n");
            } catch (KtavException $e) {
                $thrown = $e;
            }
            expect($thrown->getError())->toBe('LossyScalar');
            expect($thrown->getError())->not->toBe('Message');
        });

        it('getMessage() is the core\'s own rendering, not a reconstructed sentence', function () {
            // Task #303: this binding used to always build its own
            // "Ktav <class> ..." sentence. Distinguishes the two by their
            // actual, different wording rather than just asserting
            // non-empty.
            $thrown = null;
            try {
                Ktav::loadsStrict("version: 1.10\n");
            } catch (KtavException $e) {
                $thrown = $e;
            }
            expect($thrown->getMessage())->toContain(
                'would be inferred as a number and silently canonicalised'
            );
            expect(strpos($thrown->getMessage(), 'Ktav LossyScalar'))->toBe(false);
        });
    });
});
