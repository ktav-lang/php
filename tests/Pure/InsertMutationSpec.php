<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

describe('pure insert mutation regressions (Rust edit_mutate.rs oracle)', function () {
    $insert = static function (string $source, string $path, $value): string {
        return PureBackend::insertValue($source, $path, $value);
    };
    $segments = static function (string $source, array $path, $value): string {
        return PureBackend::insertValueSegments($source, $path, $value);
    };
    $fails = static function (callable $call, string $error): KtavException {
        $caught = null;
        try { $call(); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe($error);
        return $caught;
    };

    it('matches inline dotted-path mutation support boundaries from Rust', function () use ($fails) {
        expect(PureBackend::renameKey("cfg: {a.b: 1, a.c: 2}\n", 'cfg.a.b', 'd'))
            ->toBe("cfg: {a.d: 1, a.c: 2}\n");
        $fails(static function () {
            PureBackend::removeValue("cfg: {a.b: 1, a.c: 2}\n", 'cfg.a.b');
        }, 'EditUnsupported');
        $fails(static function () {
            PureBackend::insertValue("cfg: {a.b: 1}\n", 'cfg.a.x', 2);
        }, 'EditUnsupported');
    });

    it('splices quoted literal-dot keys, escaped terminal commas, and spaced structural trailing commas', function () use ($insert, $fails) {
        $quoted = "cfg: {\"a.b\": 1, c: 2}\n";
        expect(PureBackend::renameKey($quoted, 'cfg.a\\.b', 'd'))
            ->toBe("cfg: {d: 1, c: 2}\n");
        expect(PureBackend::removeValue($quoted, 'cfg.a\\.b'))
            ->toBe("cfg: {c: 2}\n");

        expect($insert("cfg: {a: p\\,}\n", 'cfg.b', 2))
            ->toBe("cfg: {a: p\\,, b: 2}\n");
        expect($insert("cfg: {a: 1,   }\n", 'cfg.b', 2))
            ->toBe("cfg: {a: 1, b: 2,   }\n");
    });

    it('preserves inline empty, whitespace, trailing-comma, and semantic exact-byte fixtures', function () use ($insert) {
        expect($insert("a: {}\n", 'a.k', 'v'))->toBe("a: {k: v}\n");
        expect($insert("a: {   }\n", 'a.k', 'v'))->toBe("a: {k: v}\n");
        expect($insert("a: {x: 1,   }\n", 'a.k', 2))->toBe("a: {x: 1, k: 2,   }\n");

        $source = "a: {x: +01,   }\n";
        $mutated = $insert($source, 'a.k', 2);
        expect($mutated)->toBe("a: {x: +01, k: 2,   }\n");
        expect(PureBackend::loads($mutated)['a'])->toBe(['x' => '+01', 'k' => 2]);
    });

    it('preserves mixed EOLs and BOM while mutating an Object nested in an Array', function () use ($insert) {
        $source = "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1,}]\nlast: 2\r";
        expect($insert($source, 'cfg.0.b', 2))
            ->toBe("\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1, b: 2,}]\nlast: 2\r");
    });

    it('returns the Rust Unsupported oracle for inline dotted mutation paths', function () use ($fails) {
        $fails(static function () {
            PureBackend::removeValue("cfg: {a.b: 1, a.c: 2}\n", 'cfg.a.b');
        }, 'EditUnsupported');
    });

    it('matches root EOL, comment, BOM and empty-root insertion oracles', function () use ($insert) {
        expect($insert("a: 1\n", 'b', 2))->toBe("a: 1\nb: 2\n");
        expect($insert('a: 1', 'b', 'v'))->toBe("a: 1\nb: v");
        expect($insert("a: 1\n## tail\n", 'b', 'v'))->toBe("a: 1\n## tail\nb: v\n");
        expect($insert("a: 1\r\n", 'b', 'v'))->toBe("a: 1\r\nb: v\r\n");
        expect($insert("a: 1\rb: 2", 'c', 'v'))->toBe("a: 1\rb: 2\rc: v");
        expect($insert("\xEF\xBB\xBFa: 1\n", 'b', 'v'))->toBe("\xEF\xBB\xBFa: 1\nb: v\n");
        expect($insert("## header\n", 'k', 'v'))->toBe("## header\nk: v\n");
        expect($insert("\xEF\xBB\xBF", 'k', 'v'))->toBe("\xEF\xBB\xBF\nk: v");
        expect($insert("{}\n", 'k', 1))->toBe("{k: 1}\n");
        expect($insert('{}', 'k', 'v'))->toBe('{k: v}');
        expect($insert("{\n}\n", 'k', 'v'))->toBe("{\n    k: v\n}\n");
        expect($insert("a.b: 1\na.c: 2\n", 'd', 'v'))->toBe("a.b: 1\na.c: 2\nd: v\n");
    });

    it('matches block, inline and array-object layout oracles', function () use ($insert) {
        expect($insert("a: {\n  x: 1\n}\nb: 2\n", 'a.k', 'v'))->toBe("a: {\n  x: 1\n  k: v\n}\nb: 2\n");
        expect($insert("a: {\n}\n", 'a.k', 'v'))->toBe("a: {\n    k: v\n}\n");
        expect($insert("a: {\r\n}\r\n", 'a.k', 'v'))->toBe("a: {\r\n    k: v\r\n}\r\n");
        expect($insert("a: {x: 1,}\n", 'a.k', 2))->toBe("a: {x: 1, k: 2,}\n");
        expect($insert("a: {x: 1}\n", 'a.k', 'é雪'))->toBe("a: {x: 1, k: é雪}\n");
        expect($insert("a: {}\n", 'a.k', 'v'))->toBe("a: {k: v}\n");
        expect($insert("a: {x: 1}\n", 'a.k', 9223372036854775807))->toBe("a: {x: 1, k: 9223372036854775807}\n");
        $mutatedI64 = $insert("base: 0\n", 'n', 9007199254740993);
        expect(PureBackend::loads($mutatedI64)['n'])->toBe(9007199254740993);
        expect(PureBackend::loads("n: 9223372036854775807\n")['n'])->toBe(9223372036854775807);
        expect($insert("a: {x: +01}\n", 'a.k', 2))->toBe("a: {x: +01, k: 2}\n");
        expect($insert("a: {x: 1}\n", 'a.k', 'p,q'))->toBe("a: {x: 1, k: p\\,q}\n");
        expect($insert("a: {x: 1}\n", 'a.k', '42'))->toBe("a: {x: 1, k: \\u00342}\n");
        expect($insert("a: {x: 1}\n", 'a.k', "p\nq"))->toBe("a: {\n    x: 1\n    k: ((\np\nq\n    ))\n}\n");
        expect($insert("top: [{a: 1}]\n", 'top.0.c', 'v'))->toBe("top: [{a: 1, c: v}]\n");
        expect($insert("top: [\n  {\n    a: 1\n  }\n]\n", 'top.0.b', 'v'))->toBe("top: [\n  {\n    a: 1\n    b: v\n  }\n]\n");
        expect($insert("a: 1\n", 'n', ['x' => 1, 'y' => 'z']))->toBe("a: 1\nn: {\n    x: 1\n    y: z\n}\n");
        expect($insert("a: {x: 1}\n", 'a.k', "a\rb"))->toBe("a: {x: 1, k: a\\rb}\n");
        expect($insert("a: {x: 1}\n", 'a.k', "a\n))\nb"))->toBe("a: {x: 1, k: a\\n))\\nb}\n");
        expect($insert("o: {i: {x: 1}}\n", 'o.i.k', "p\nq"))->toBe("o: {\n    i: {\n        x: 1\n        k: ((\np\nq\n        ))\n    }\n}\n");
        expect($insert("a: {}\n", 'a.k', str_repeat('x', 101)))->toBe("a: {\n    k: " . str_repeat('x', 101) . "\n}\n");
    });

    it('quotes or escapes keys and treats segments as literal keys', function () use ($insert, $segments) {
        expect($insert("a: 1\n", 'a\\.b', 'v'))->toBe("a: 1\n\"a.b\": v\n");
        expect($segments("a: 1\n", ['a.b'], 'v'))->toBe("a: 1\n\"a.b\": v\n");
        expect($insert("a: 1\n", 'x,y', 'v'))->toBe("a: 1\n\"x,y\": v\n");
        expect($segments("k: 1\n", ['x\\y'], 'v'))->toBe("k: 1\nx\\\\y: v\n");
        expect($insert("a: 1\n", '0', 'v'))->toBe("a: 1\n0: v\n");
    });

    it('preserves comments and ignores braces in comments in block insertion', function () use ($insert) {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            $source = "## { header{$eol}{{$eol}}{$eol}## tail{$eol}";
            expect($insert($source, 'k', 'v'))->toBe("## { header{$eol}{{$eol}    k: v{$eol}}{$eol}## tail{$eol}");
        }
    });

    it('returns the insertion error oracles without overwriting', function () use ($insert, $segments, $fails) {
        $e = $fails(static function () use ($insert) { $insert("a: 1\n", 'a', 'x'); }, 'EditAlreadyExists');
        expect($e->getMessage())->toBe('edit target already exists; insertion never overwrites');
        $fails(static function () use ($insert) { $insert("a: 1\n", 'b.c', 'v'); }, 'EditNotFound');
        $fails(static function () use ($insert) { $insert("a: 1\n", 'a.c', 'v'); }, 'EditNotFound');
        $fails(static function () use ($segments) { $segments("a: 1\n", [], 'v'); }, 'EditNotFound');
        $fails(static function () use ($insert) { $insert("a: 1\n", '', 'v'); }, 'EditInvalidPath');
        $fails(static function () use ($insert) { $insert("list: [x]\n", 'list.1', 'v'); }, 'EditUnsupported');
        $fails(static function () use ($insert) { $insert("a: {y: 1}\n", 'a.y', 'v'); }, 'EditAlreadyExists');
        $fails(static function () use ($insert) { $insert("a: {\n", 'k', 'v'); }, 'UnclosedCompound');
        $fails(static function () use ($insert) { $insert("a: \xFF\n", 'k', 'v'); }, 'InvalidUtf8');
    });
});
