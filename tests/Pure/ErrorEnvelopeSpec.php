<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Pure-backend error envelopes: the same nine structured fields as
 * the native backend, built directly (never over the JSON wire).
 * Message text follows the Rust core's Display rendering verbatim,
 * like the native binding since 0.8.0. Spans are UTF-8 BYTE offsets;
 * lines are 1-based over raw bytes (LF / lone CR / CRLF each one
 * terminator) before BOM removal.
 */
describe('PureBackend structured error envelope', function () {

    it('strict lossy scalar carries all nine fields', function () {
        $thrown = null;
        try {
            PureBackend::loadsStrict("a: 1.10\n");
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('LossyScalar');
        expect($thrown->getReason())->toBeNull();
        expect($thrown->getErrorLine())->toBe(1);
        expect($thrown->getLineText())->toBe('a: 1.10');
        expect($thrown->getSpan())->toBe(['start' => 0, 'end' => 7]);
        expect($thrown->getPath())->toBeNull();
        expect($thrown->getBody())->toBe('1.10');
        expect($thrown->getCanonical())->toBe('1.1');
        expect($thrown->getSpecSection())->toBe('§3.6/§5.2');
        expect($thrown->getMessage())->toBe(
            "Syntax error: Line 1: LossyScalar: '1.10' would be inferred as a number "
            . "and silently canonicalised to '1.1'; append '::' to keep it a String "
            . 'or write the canonical form'
        );
        expect(strpos($thrown->getMessage(), '{') !== 0)->toBe(true);
    });

    it('writer rejection is named apart and carries reason + path', function () {
        $thrown = null;
        try {
            PureBackend::emitCanonical(['srv' => ['port' => ['$f' => '1e999']]]);
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('UnrepresentableAt');
        expect($thrown->getReason())->toBe('NonFiniteFloat');
        expect($thrown->getPath())->toBe(['srv', 'port']);
        expect($thrown->getSpan())->toBeNull();
        // Exact bytes assembled by PureWriter::floatText (src/Pure/
        // PureWriter.php:444): PureFloat::reasonDisplay('NonFiniteFloat')
        // (src/Pure/PureFloat.php:22-23, verbatim) . ' at ' .
        // phpDebugList(['srv','port']) (PureWriter.php:610-617, Rust
        // Vec<String> Debug form).
        expect($thrown->getMessage())->toBe(
            'NonFiniteFloat: a Float is NaN or ±Infinity (spec § 5.9.0) at ["srv", "port"]'
        );
    });

    it('matches the Rust invalid UTF-8 body while retaining its raw byte span', function () {
        $thrown = null;
        try {
            PureBackend::loads("abc\xE2\x82A");
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getBody())->toBe('input is not valid UTF-8: invalid utf-8 sequence of 2 bytes from index 3');
        expect($thrown->getSpan())->toBe(['start' => 3, 'end' => 5]);
        expect($thrown->getError())->toBe('InvalidUtf8');
        expect($thrown->getErrorLine())->toBe(1);
        expect($thrown->getLineText())->toBeNull();
    });
    it('uses Rust Utf8Error Display independently of the raw span', function () {
        $cases = [
            ["\xFF\x80", 'invalid utf-8 sequence of 1 bytes from index 0', [0, 2]],
            ["\xE2\x82A", 'invalid utf-8 sequence of 2 bytes from index 0', [0, 2]],
            ["\xF4\x90\x80\x80", 'invalid utf-8 sequence of 1 bytes from index 0', [0, 4]],
            ["\xE2\x82", 'incomplete utf-8 byte sequence from index 0', [0, 2]],
        ];
        foreach ($cases as [$source, $body, $span]) {
            $thrown = null;
            try { PureBackend::loads($source); } catch (KtavException $e) { $thrown = $e; }
            expect($thrown)->toBeAnInstanceOf(KtavException::class);
            expect($thrown->getBody())->toBe('input is not valid UTF-8: ' . $body);
            expect($thrown->getSpan())->toBe(['start' => $span[0], 'end' => $span[1]]);
            expect($thrown->getErrorLine())->toBe(1);
        }
    });

    it('invalid UTF-8 fails before grammar with a nonempty byte span', function () {
        $thrown = null;
        try {
            PureBackend::loads("\x80\x81");
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('InvalidUtf8');
        expect($thrown->getReason())->toBeNull();
        // 1-based line over raw bytes; these bytes are on the first
        // line. The message carries the byte offset; the span covers
        // the malformed bytes (nonempty, byte-indexed).
        expect($thrown->getErrorLine())->toBe(1);
        expect(strpos($thrown->getMessage(), 'byte offset') !== false)->toBe(true);
        $span = $thrown->getSpan();
        expect($span)->not->toBeNull();
        expect($span['start'])->toBe(0);
        expect($span['end'] > $span['start'])->toBe(true);
    });

    it('invalid UTF-8 wins over a grammar error on the same input', function () {
        // Grammatically broken AND byte-invalid: the category must be
        // exactly InvalidUtf8, never a second one.
        $thrown = null;
        try {
            PureBackend::loads("a: [\n\xFF");
        } catch (KtavException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('InvalidUtf8');
    });

    it('byte spans are BYTE offsets, not character offsets', function () {
        $line1 = "ключ: значение\n"; // 27 UTF-8 bytes
        $thrown = null;
        try {
            PureBackend::loads($line1 . "a: [\n");
        } catch (KtavException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getErrorLine())->toBe(2);
        $span = $thrown->getSpan();
        expect($span)->not->toBeNull();
        expect($span['start'])->toBe(strlen($line1) + 3); // the '[' opener
    });

    it('CRLF counts as ONE terminator for line numbers', function () {
        $thrown = null;
        try {
            PureBackend::loads("a: 1\r\nb: 2\r\n\x80");
        } catch (KtavException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getErrorLine())->toBe(3);
    });

    it('PHP-side scalar root rejection carries its spec reason', function () {
        $thrown = null;
        try {
            PureBackend::dumps(42);
        } catch (KtavException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('Unrepresentable');
        expect($thrown->getReason())->toBe('ScalarRoot');
        expect($thrown->getErrorLine())->toBeNull();
        expect($thrown->getLineText())->toBeNull();
        expect($thrown->getSpan())->toBeNull();
        expect($thrown->getPath())->toBeNull();
        expect($thrown->getBody())->toBeNull();
        expect($thrown->getCanonical())->toBeNull();
        expect($thrown->getSpecSection())->toBe('§5.9.0');
        expect($thrown->getMessage())->not->toBe('');
    });

    it('PHP non-finite floats carry the writer refusal reason', function () {
        foreach ([NAN, INF, -INF] as $value) {
            $caught = null;
            try {
                PureBackend::emitCanonical(['value' => $value]);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->not->toBeNull();
            expect($caught->getReason())->toBe('NonFiniteFloat');
            expect($caught->getSpecSection())->toBe('§5.9.0');
        }
    });

    it('path segments are exact decoded keys, never split on dots', function () {
        $e = KtavException::fromEnvelope([
            'error' => 'UnrepresentableAt',
            'reason' => 'EmptyKeyName',
            'path' => ['a.b', ''],
        ]);
        expect($e->getPath())->toBe(['a.b', '']);
    });
});
