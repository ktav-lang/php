<?php

declare(strict_types=1);

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Tests\TestPaths;

describe('structured error envelope (issue rust#12)', function () {

    beforeAll(function () {
        TestPaths::init();
        if (!TestPaths::cabiBuilt()) {
            skipIf(true, 'cabi not built (' . TestPaths::cabi() . ') — run `cargo build --release -p ktav-cabi`');
        }
    });

    it('parse error carries the nine structured fields', function () {
        $thrown = null;
        try {
            Ktav::loadsStrict("a: 1.10\n");
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
        // Since ktav 0.7.2: the core's own Display rendering, taken
        // verbatim — no longer this binding's old "Ktav <class> ..."
        // reconstruction (task #303).
        expect($thrown->getMessage())->toBe(
            "Syntax error: Line 1: LossyScalar: '1.10' would be inferred as a number "
            . "and silently canonicalised to '1.1'; append '::' to keep it a String "
            . 'or write the canonical form'
        );

        // Never raw JSON.
        expect(strpos($thrown->getMessage(), '{') !== 0)->toBe(true);
    });

    it('writer rejection is named apart and carries reason + path', function () {
        $thrown = null;
        try {
            Ktav::emitCanonical(['srv' => ['port' => ['$f' => '1e999']]]);
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('UnrepresentableAt');
        expect($thrown->getReason())->toBe('NonFiniteFloat');
        expect($thrown->getPath())->toBe(['srv', 'port']);
        expect($thrown->getSpan())->toBeNull();
        // Since ktav 0.7.2: the core's own Display rendering, verbatim.
        expect($thrown->getMessage())->toBe(
            'NonFiniteFloat: a Float is NaN or ±Infinity (spec § 5.9.0) at ["srv", "port"]'
        );
    });

    it('syntax error surfaces a class name, not a plain string', function () {
        $thrown = null;
        try {
            Ktav::loads('a: [');
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect(is_string($thrown->getError()))->toBe(true);
        expect($thrown->getError())->not->toBe('');
        expect($thrown->getMessage())->not->toBe('');
        expect(strpos($thrown->getMessage(), '{') !== 0)->toBe(true);
    });

    it('non-ktav conditions are wrapped uniformly as Message with honest nulls', function () {
        $thrown = null;
        try {
            Ktav::loads("\x80\x81");
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBe('Message');
        expect($thrown->getReason())->toBeNull();
        expect($thrown->getErrorLine())->toBeNull();
        // Since ktav 0.7.2: the core's own Display rendering, verbatim —
        // no longer this binding's old "Ktav Message" reconstruction.
        expect($thrown->getMessage())->toBe(
            'input is not valid UTF-8: invalid utf-8 sequence of 1 bytes from index 0'
        );
    });

    it('PHP-side throws carry null envelope fields', function () {
        $thrown = null;
        try {
            Ktav::dumps(42);
        } catch (KtavException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeAnInstanceOf(KtavException::class);
        expect($thrown->getError())->toBeNull();
        expect($thrown->getReason())->toBeNull();
        expect($thrown->getErrorLine())->toBeNull();
        expect($thrown->getLineText())->toBeNull();
        expect($thrown->getSpan())->toBeNull();
        expect($thrown->getPath())->toBeNull();
        expect($thrown->getBody())->toBeNull();
        expect($thrown->getCanonical())->toBeNull();
        expect($thrown->getSpecSection())->toBeNull();
        expect($thrown->getMessage())->not->toBe('');
    });

    it('path segments are exact decoded keys, never split on dots', function () {
        $e = KtavException::fromEnvelope([
            'error' => 'UnrepresentableAt',
            'reason' => 'EmptyKeyName',
            'path' => ['a.b', ''],
        ]);

        expect($e->getPath())->toBe(['a.b', '']);
        expect($e->getMessage())->not->toBe(json_encode(['error' => 'UnrepresentableAt', 'reason' => 'EmptyKeyName', 'path' => ['a.b', '']]));
    });

});
