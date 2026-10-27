<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

describe('pure object source mutations (Rust C ABI reference 714c)', function () {
    it('inserts into an inline Object using the Rust lossless splice oracle', function () {
        expect(PureBackend::insertValue("settings: {old: +01}\n", 'settings.new', 2))
            ->toBe("settings: {old: +01, new: 2}\n");
    });

    it('inserts through textual and literal-segment paths and preserves ## comments', function () {
        expect(PureBackend::insertValue("a: 1
", 'a\.b', 2))->toBe("a: 1
\"a.b\": 2
");
        expect(PureBackend::insertValueSegments("a: 1
", ['a.b'], 2))->toBe("a: 1
\"a.b\": 2
");
        expect(PureBackend::insertValue("## head
a: 1
## tail
", 'b', 2))
            ->toBe("## head
a: 1
## tail
b: 2
");
    });

    it('inserts a missing final Object key without disturbing surrounding comments or spelling', function () {
        $src = "## heading\nsettings: {old: +01}\n## tail\n";
        expect(PureBackend::insertValue($src, 'settings.new', 2))
            ->toBe("## heading\nsettings: {old: +01, new: 2}\n## tail\n");
    });

    it('handles ArrayObject values', function () {
        expect(PureBackend::insertValue("base: 0\n", 'obj', new \ArrayObject(['x' => 1, 'y' => 2])))
            ->toBe("base: 0\nobj: {\n    x: 1\n    y: 2\n}\n");
    });

    it('uses an independent expected string for trailing-comma insertion and tests no-op rename bytes', function () {
        $src = "first: 1\nobj: {a: +01, b: 2,}\nlast: 3";
        expect(PureBackend::renameKey($src, 'obj.a', 'a'))->toBe($src);
        expect(PureBackend::insertValue("obj: {a: +01,}\n", 'obj.b', 2))
            ->toBe("obj: {a: +01, b: 2,}\n");
    });

    it('checks mutation semantics by parsing actual output, including i64 and removal boundaries', function () {
        $inserted = PureBackend::insertValue("base: 0\n", 'n', 9007199254740993);
        expect(PureBackend::loads($inserted)['n'])->toBe(9007199254740993);

        $utf8 = "雪: 1\na: 2\n";
        $removed = PureBackend::removeValue($utf8, 'a');
        expect(PureBackend::loads($removed))->toBe(['雪' => 1]);

        $rootArray = PureBackend::removeValue("[1, 2, 3]\n", '0');
        expect(PureBackend::loads($rootArray))->toBe([2, 3]);

        $dotted = PureBackend::removeValue("a.b: 1\n", 'a.b');
        expect($dotted)->toBe("a: {}\n");

        expect(PureBackend::loads($dotted))->toBe(['a' => []]);
    });

    it('covers root whitespace/comments with braces, BOM spacing, and mutation EOLs', function () {
        $cases = [
            ["\n## { lead }\nobj: {a: 1}\n", 'obj.b', 2, "\n## { lead }\nobj: {a: 1, b: 2}\n", ['obj' => ['a' => 1, 'b' => 2]]],
            ["\xEF\xBB\xBF  a: 1\r\n", 'b', 2, "\xEF\xBB\xBF  a: 1\r\nb: 2\r\n", ['a' => 1, 'b' => 2]],
        ];
        foreach ($cases as [$src, $path, $value, $expected, $semantic]) {
            $actual = PureBackend::insertValue($src, $path, $value);
            expect($actual)->toBe($expected);
            if ($semantic !== null) { expect(PureBackend::loads($actual))->toBe($semantic); }
        }
        $actual = PureBackend::insertValue("\xE2\x80\x83## { Unicode whitespace }\r\n{a: 1}\r\n", 'b', 2);
        expect($actual)->toBe("\xE2\x80\x83## { Unicode whitespace }\r\n{a: 1, b: 2}\r\n");
        expect(PureBackend::loads($actual))->toBe(['a' => 1, 'b' => 2]);
        $eol = "obj: {a: 1}\r\n";
        $removed = PureBackend::removeValue($eol, 'obj.a');
        expect($removed)->toBe("obj: {}\r\n");
        expect(PureBackend::loads($removed))->toBe(['obj' => []]);
        $root = PureBackend::removeValue("[1, 2, 3]\r", '1');
        expect($root)->toBe("[1, 3]\r");
        expect(PureBackend::loads($root))->toBe([1, 3]);
    });

    it('does not prune empty ancestors after removal', function () {
        expect(PureBackend::removeValue("a: {b: {c: 1}}\n", 'a.b.c'))
            ->toBe("a: {b: {}}\n");
        expect(PureBackend::removeValue("a.b: 1\n", 'a.b'))
            ->toBe("a: {}\n");
    });

    it('reports invalid textual path byte offsets after multibyte segments', function () {
        $caught = null;
        try { PureBackend::removeValue("a: 1\n", "é\\x"); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('EditInvalidPath');
        expect($caught->getBody())->toBe('2');
        expect($caught->getMessage())->toBe('invalid edit path (bad escape or empty segment) at byte 2');
    });

    it('removes an existing value at the path end while retaining its siblings and comments', function () {
        $src = "a: 1\nb: 2\nc: 3\n";
        expect(PureBackend::removeValue($src, 'b'))->toBe("a: 1\nc: 3\n");
        expect(PureBackend::removeValueSegments("obj: {\n  \"a.b\": 1\n  c: 2\n}\n", ['obj', 'a.b']))
            ->toBe("obj: {\n  c: 2\n}\n");
    });

    it('removes with textual paths and preserves comments around the removed member', function () {
        expect(PureBackend::removeValue("## top\na: 1\n## mid\nb: 2\n## end\n", 'a'))
            ->toBe("## top\n## mid\nb: 2\n## end\n");
        expect(PureBackend::removeValue("a: [1, 2, 3]\n", 'a.1'))->toBe("a: [1, 3]\n");
    });

    it('renames by textual path and literal segments; new key is one decoded key', function () {
        $src = "obj: {\n  \"a.b\": 1\n  keep: 2\n}\n";
        expect(PureBackend::renameKey($src, 'obj.a\\.b', 'new.name'))
            ->toBe("obj: {\n  \"new.name\": 1\n  keep: 2\n}\n");
        expect(PureBackend::renameKeySegments($src, ['obj', 'a.b'], 'new.name'))
            ->toBe("obj: {\n  \"new.name\": 1\n  keep: 2\n}\n");
    });

    it('renames block Object keys through their block anchor and rejects Array parents as unsupported', function () {
        expect(PureBackend::renameKey("a: {\n x: 1\n}\n", 'a', 'b'))->toBe("b: {\n x: 1\n}\n");
        $caught = null;
        try { PureBackend::renameKey("items: [x]\n", 'items.0', 'renamed'); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('EditUnsupported');
    });

    it('preserves value and member order on rename and is byte-identical for same-key no-op', function () {
        $src = "a: 1\nk:: +042\nb: 3\n";
        expect(PureBackend::renameKey($src, 'k', 'renamed'))->toBe("a: 1\nrenamed:: +042\nb: 3\n");
        expect(PureBackend::renameKey($src, 'k', 'k'))->toBe($src);
    });

    it('preserves ## comments for leaf edits and rejects destructive comment loss', function () {
        $src = "a: {\n  ## inner\n  x: 1\n}\n";
        expect(PureBackend::renameKey($src, 'a.x', 'y'))->toBe("a: {\n  ## inner\n  y: 1\n}\n");
        $caught = null;
        try { PureBackend::removeValue($src, 'a'); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('EditUnsupported');
    });

    it('covers trailing commas, UTF-8, i64 values and lossless spellings', function () {
        expect(PureBackend::loads("n: 9223372036854775807\n")['n'])->toBe(9223372036854775807);
        expect(PureBackend::insertValue("a: {old: +01,}\n", 'a.new', 9223372036854775807))
            ->toBe("a: {old: +01, new: 9223372036854775807,}\n");
        expect(PureBackend::insertValue("a: {old: +01}\n", 'a.雪', 'café'))->toBe("a: {old: +01, 雪: café}\n");
        expect(PureBackend::renameKey("a: {old: +01,}\n", 'a.old', 'new'))->toBe("a: {new: +01,}\n");
        expect(PureBackend::removeValue("a: {old: +01,}\n", 'a.old'))->toBe("a: {}\n");
        $caught = null;
        try { PureBackend::insertValue("a: {\n", 'a.new', 1); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('UnclosedCompound');
        $caught = null;
        try { PureBackend::insertValue("a: \xE2\x82\n", 'a.new', 1); } catch (KtavException $e) { $caught = $e; }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('InvalidUtf8');
        expect($caught->getSpan())->toBe(['start' => 3, 'end' => 5]);
    });

    it('returns typed errors for collisions, missing targets and unsupported comment loss', function () {
        $cases = [
            [static function () { PureBackend::insertValue("a: 1\n", 'a', 2); }, 'EditAlreadyExists'],
            [static function () { PureBackend::removeValue("a: 1\n", 'missing'); }, 'EditNotFound'],
            [static function () { PureBackend::renameKey("a: 1\n", 'missing', 'b'); }, 'EditNotFound'],
            [static function () { PureBackend::renameKey("a: 1\nb: 2\n", 'a', 'b'); }, 'EditAlreadyExists'],
            [static function () { PureBackend::removeValue("a: 1\n", ''); }, 'EditInvalidPath'],
            [static function () { PureBackend::renameKey("a: 1\n", 'a', ''); }, 'EditUnsupported'],
        ];
        foreach ($cases as $case) {
            $caught = null;
            try { $case[0](); } catch (KtavException $e) { $caught = $e; }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            expect($caught->getError())->toBe($case[1]);
        }
    });
});
