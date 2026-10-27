<?php

declare(strict_types=1);

namespace Ktav\Tests;

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\NativeLib;
use Ktav\PureBackend;

describe('native object source mutations (ABI 2 parity)', function () {
    beforeAll(function () { TestPaths::init(); });

    $requireMutationAbi = function (): void {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('FFI required: ABI-2 mutation exports must be available');
        }
        try {
            NativeLib::callEditBytes('ktav_insert_value', "a: 1\n", 'b', '2');
        } catch (KtavException $e) {
            if (!in_array($e->getError(), ['EditInvalidPath', 'EditNotFound', 'EditAlreadyExists', 'EditUnsupported'], true)) {
                throw $e;
            }
        }
    };

    $compareSuccess = function (callable $native, callable $pure, string $expected): void {
        $n = $native();
        $p = $pure();
        expect($n)->toBe($expected);
        expect($p)->toBe($expected);
    };

    $compareError = function (callable $native, callable $pure, string $expected) use ($requireMutationAbi): void {
        $requireMutationAbi();
        $n = $p = null;
        try { $native(); } catch (KtavException $e) { $n = $e; }
        try { $pure(); } catch (KtavException $e) { $p = $e; }
        expect($n)->toBeAnInstanceOf(KtavException::class);
        expect($p)->toBeAnInstanceOf(KtavException::class);
        expect($n->getError())->toBe($expected);
        expect($p->getError())->toBe($expected);
        expect($n->getError())->toBe($p->getError());
        expect($n->getBody())->toBe($p->getBody());
        expect($n->getErrorLine())->toBe($p->getErrorLine());
        expect($n->getSpan())->toBe($p->getSpan());
        expect($n->getMessage())->toBe($p->getMessage());
        expect($n->getReason())->toBe($p->getReason());
        expect($n->getPath())->toBe($p->getPath());
        expect($n->getLineText())->toBe($p->getLineText());
        expect($n->getCanonical())->toBe($p->getCanonical());
        expect($n->getSpecSection())->toBe($p->getSpecSection());
    };

    it('uses root metadata for multiline layout after BOM and spaces', function () use ($requireMutationAbi) {
        $requireMutationAbi();
        $source = "\xEF\xBB\xBF  {x: 1}\r\n";
        $expected = "\xEF\xBB\xBF  {\r\n    x: 1\r\n    y: ((\r\nfirst\r\nsecond\r\n    ))\r\n}\r\n";
        $native = Ktav::insertValue($source, 'y', "first\nsecond");
        $pure = PureBackend::insertValueSegments($source, ['y'], "first\nsecond");
        expect($native)->toBe($expected);
        expect($pure)->toBe($expected);
        expect(Ktav::loads($native))->toBe(['x' => 1, 'y' => "first\nsecond"]);
        expect(PureBackend::loads($pure))->toBe(['x' => 1, 'y' => "first\nsecond"]);
    });

    it('normalizes segment-array keys consistently across backends', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $compareSuccess(
            function () { return Ktav::insertValueSegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'new'], 2); },
            function () { return PureBackend::insertValueSegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'new'], 2); },
            "cfg: {old: 1, new: 2}\n"
        );
        $compareSuccess(
            function () { return Ktav::removeValueSegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'old']); },
            function () { return PureBackend::removeValueSegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'old']); },
            "cfg: {}\n"
        );
        $compareSuccess(
            function () { return Ktav::renameKeySegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'old'], 'renamed'); },
            function () { return PureBackend::renameKeySegments("cfg: {old: 1}\n", ['parent' => 'cfg', 'leaf' => 'old'], 'renamed'); },
            "cfg: {renamed: 1}\n"
        );
    });

    it('matches shared native and pure inline mutation fixtures', function () use ($requireMutationAbi, $compareSuccess, $compareError) {
        $requireMutationAbi();
        $src = "cfg: {a.b: 1, a.c: 2}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::renameKey($src, 'cfg.a.b', 'd'); },
            function () use ($src) { return PureBackend::renameKey($src, 'cfg.a.b', 'd'); },
            "cfg: {a.d: 1, a.c: 2}\n"
        );
        $compareError(
            function () use ($src) { return Ktav::removeValue($src, 'cfg.a.b'); },
            function () use ($src) { return PureBackend::removeValue($src, 'cfg.a.b'); },
            'EditUnsupported'
        );
        $src = "cfg: {a.b: 1}\n";
        $compareError(
            function () use ($src) { return Ktav::insertValue($src, 'cfg.a.x', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'cfg.a.x', 2); },
            'EditUnsupported'
        );

        $quoted = "cfg: {\"a.b\": 1, c: 2}\n";
        $compareSuccess(
            function () use ($quoted) { return Ktav::renameKey($quoted, 'cfg.a\\.b', 'd'); },
            function () use ($quoted) { return PureBackend::renameKey($quoted, 'cfg.a\\.b', 'd'); },
            "cfg: {d: 1, c: 2}\n"
        );
        $compareSuccess(
            function () use ($quoted) { return Ktav::removeValue($quoted, 'cfg.a\\.b'); },
            function () use ($quoted) { return PureBackend::removeValue($quoted, 'cfg.a\\.b'); },
            "cfg: {c: 2}\n"
        );
        $src = "cfg: {a: p\\,}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'cfg.b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'cfg.b', 2); },
            "cfg: {a: p\\,, b: 2}\n"
        );
        $src = "cfg: {a: 1,   }\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'cfg.b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'cfg.b', 2); },
            "cfg: {a: 1, b: 2,   }\n"
        );
        $src = "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1,}]\nlast: 2\r";
        $expected = "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1, b: 2,}]\nlast: 2\r";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'cfg.0.b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'cfg.0.b', 2); },
            $expected
        );
    });

    it('covers root comments/braces, BOM spacing, EOL mutation and root-array removal', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $src = "\n## { lead }\n{a: 1}\n";
        $expected = "\n## { lead }\n{a: 1, b: 2}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'b', 2); },
            $expected
        );
        expect(Ktav::loads(Ktav::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        expect(PureBackend::loads(PureBackend::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        $src = "\xEF\xBB\xBF  {a: 1}\r\n";
        $expected = "\xEF\xBB\xBF  {a: 1, b: 2}\r\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'b', 2); },
            $expected
        );
        expect(Ktav::loads(Ktav::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        expect(PureBackend::loads(PureBackend::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        $src = "\xE2\x80\x83## { Unicode }\r\n{a: 1}\r\n";
        $expected = "\xE2\x80\x83## { Unicode }\r\n{a: 1, b: 2}\r\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'b', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'b', 2); },
            $expected
        );
        expect(Ktav::loads(Ktav::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        expect(PureBackend::loads(PureBackend::insertValue($src, 'b', 2)))->toBe(['a' => 1, 'b' => 2]);
        $src = "obj: {a: 1}\r\n";
        $expected = "obj: {}\r\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'obj.a'); },
            function () use ($src) { return PureBackend::removeValue($src, 'obj.a'); },
            $expected
        );
        expect(Ktav::loads($expected))->toBe(['obj' => []]);
        $src = "[1, 2]";
        $expected = "[2]";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, '0'); },
            function () use ($src) { return PureBackend::removeValue($src, '0'); },
            $expected
        );
        expect(Ktav::loads(Ktav::removeValue($src, '0')))->toBe([2]);
        expect(PureBackend::loads(PureBackend::removeValue($src, '0')))->toBe([2]);

        $src = "\n## { lead }\r\n{a: 1, b: 2}\n";
        $native = Ktav::removeValue($src, 'a');
        $pure = PureBackend::removeValue($src, 'a');
        expect($native)->toBe("\n## { lead }\r\n{b: 2}\n");
        expect($pure)->toBe("\n## { lead }\r\n{b: 2}\n");
        expect(Ktav::loads($native))->toBe(['b' => 2]);
        expect(PureBackend::loads($pure))->toBe(['b' => 2]);

        $src = "\xEF\xBB\xBF  \n## { array lead }\r\n[1, 2]\n";
        $native = Ktav::removeValue($src, '0');
        $pure = PureBackend::removeValue($src, '0');
        expect($native)->toBe("\xEF\xBB\xBF  \n## { array lead }\r\n[2]\n");
        expect($pure)->toBe("\xEF\xBB\xBF  \n## { array lead }\r\n[2]\n");
        expect(Ktav::loads($native))->toBe([2]);
        expect(PureBackend::loads($pure))->toBe([2]);
    });

    it('checks parsed native mutation output independently of backend parity', function () use ($requireMutationAbi) {
        $requireMutationAbi();
        $large = Ktav::insertValue("base: 0\n", 'n', 9007199254740993);
        expect(Ktav::loads($large)['n'])->toBe(9007199254740993);

        $utf8 = "雪: 1\na: 2\n";
        expect(Ktav::loads(Ktav::removeValue($utf8, 'a')))->toBe(['雪' => 1]);
        expect(Ktav::loads(Ktav::removeValue("[1, 2, 3]\n", '0')))->toBe([2, 3]);
        expect(Ktav::loads(Ktav::removeValue("outer: {inner: {leaf: 1}}\n", 'outer.inner.leaf')))
            ->toBe(['outer' => ['inner' => []]]);
    });

    it('checks no-prune removal and invalid-path offset envelope parity', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $src = "a: {b: {c: 1}}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'a.b.c'); },
            function () use ($src) { return PureBackend::removeValue($src, 'a.b.c'); },
            "a: {b: {}}\n"
        );
        $native = $pure = null;
        try { Ktav::removeValue("a: 1\n", "é\\x"); } catch (KtavException $e) { $native = $e; }
        try { PureBackend::removeValue("a: 1\n", "é\\x"); } catch (KtavException $e) { $pure = $e; }
        expect($native)->toBeAnInstanceOf(KtavException::class);
        expect($pure)->toBeAnInstanceOf(KtavException::class);
        $expected = 'invalid edit path (bad escape or empty segment) at byte 2';
        foreach ([$native, $pure] as $error) {
            expect($error->getError())->toBe('EditInvalidPath');
            expect($error->getBody())->toBe('2');
            expect($error->getMessage())->toBe($expected);
            expect($error->getErrorLine())->toBe(null);
            expect($error->getSpan())->toBe(null);
        }
    });

    it('inserts into an inline Object using the Rust lossless splice oracle', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $src = "settings: {old: +01}\n";
        $expected = "settings: {old: +01, new: 2}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'settings.new', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'settings.new', 2); },
            $expected
        );
    });

    it('inserts a missing key and fails explicitly on collision', function () use ($requireMutationAbi, $compareSuccess, $compareError) {
        $requireMutationAbi();
        $src = "## heading\nsettings: {old: +01}\n## tail\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'settings.new', 2); },
            function () use ($src) { return PureBackend::insertValue($src, 'settings.new', 2); },
            "## heading\nsettings: {old: +01, new: 2}\n## tail\n"
        );
        $compareError(
            function () { return Ktav::insertValue("a: 1\n", 'a', 2); },
            function () { return PureBackend::insertValue("a: 1\n", 'a', 2); },
            'EditAlreadyExists'
        );
    });

    it('covers both path forms, ## lossless comments and array removal oracles', function () use ($requireMutationAbi, $compareSuccess, $compareError) {
        $requireMutationAbi();
        $compareSuccess(
            function () { return Ktav::insertValue("a: 1\n", 'a\\.b', 2); },
            function () { return PureBackend::insertValue("a: 1\n", 'a\\.b', 2); },
            "a: 1\n\"a.b\": 2\n"
        );
        $compareSuccess(
            function () { return Ktav::insertValueSegments("a: 1\n", ['a.b'], 2); },
            function () { return PureBackend::insertValueSegments("a: 1\n", ['a.b'], 2); },
            "a: 1\n\"a.b\": 2\n"
        );
        $src = "## top\na: 1\n## mid\nb: 2\n## end\n";
        $compareSuccess(
            function () { return Ktav::insertValueSegments("a: 1
", ['a.b'], 2); },
            function () { return PureBackend::insertValueSegments("a: 1
", ['a.b'], 2); },
            "a: 1
\"a.b\": 2
"
        );
        $src = "## top
a: 1
## mid
b: 2
## end
";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'a'); },
            function () use ($src) { return PureBackend::removeValue($src, 'a'); },
            "## top\n## mid\nb: 2\n## end\n"
        );
        $commented = "a: {\n  ## inner\n  x: 1\n}\n";
        $compareSuccess(
            function () use ($commented) { return Ktav::renameKey($commented, 'a.x', 'y'); },
            function () use ($commented) { return PureBackend::renameKey($commented, 'a.x', 'y'); },
            "a: {\n  ## inner\n  y: 1\n}\n"
        );
        $compareError(
            function () use ($commented) { return Ktav::removeValue($commented, 'a'); },
            function () use ($commented) { return PureBackend::removeValue($commented, 'a'); },
            'EditUnsupported'
        );
    });

    it('removes the addressed terminal value and keeps siblings', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $src = "a: 1\nb: 2\nc: 3\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'b'); },
            function () use ($src) { return PureBackend::removeValue($src, 'b'); },
            "a: 1\nc: 3\n"
        );
        $src = "a.b: 1\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'a.b'); },
            function () use ($src) { return PureBackend::removeValue($src, 'a.b'); },
            "a: {}\n"
        );
        $src = "obj: {\n  \"a.b\": 1\n  c: 2\n}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValueSegments($src, ['obj', 'a.b']); },
            function () use ($src) { return PureBackend::removeValueSegments($src, ['obj', 'a.b']); },
            "obj: {\n  c: 2\n}\n"
        );
    });

    it('renames via textual and literal-segment paths with literal dotted new keys', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $src = "obj: {\n  \"a.b\": 1\n  keep: 2\n}\n";
        $expected = "obj: {\n  \"new.name\": 1\n  keep: 2\n}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::renameKey($src, 'obj.a\\.b', 'new.name'); },
            function () use ($src) { return PureBackend::renameKey($src, 'obj.a\\.b', 'new.name'); },
            $expected
        );
        $compareSuccess(
            function () use ($src) { return Ktav::renameKeySegments($src, ['obj', 'a.b'], 'new.name'); },
            function () use ($src) { return PureBackend::renameKeySegments($src, ['obj', 'a.b'], 'new.name'); },
            $expected
        );
    });

    it('covers quoted inline members, escaped commas and ArrayObject mutation parity', function () use ($requireMutationAbi, $compareSuccess) {
        $requireMutationAbi();
        $compareSuccess(
            function () { return Ktav::insertValue("a: {x: 1}\n", 'a.k', 'p,q'); },
            function () { return PureBackend::insertValue("a: {x: 1}\n", 'a.k', 'p,q'); },
            "a: {x: 1, k: p\\,q}\n"
        );
        $arrayObject = new \ArrayObject(['x' => 1, 'y' => 2]);
        $expected = "base: 0\nobj: {\n    x: 1\n    y: 2\n}\n";
        $compareSuccess(
            function () use ($arrayObject) { return Ktav::insertValue("base: 0\n", 'obj', $arrayObject); },
            function () use ($arrayObject) { return PureBackend::insertValue("base: 0\n", 'obj', $arrayObject); },
            $expected
        );
    });

    it('preserves trailing commas, UTF-8, i64 values and untouched source spelling', function () use ($requireMutationAbi, $compareSuccess, $compareError) {
        $requireMutationAbi();
        $src = "a: {old: +01,}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'a.new', 9223372036854775807); },
            function () use ($src) { return PureBackend::insertValue($src, 'a.new', 9223372036854775807); },
            "a: {old: +01, new: 9223372036854775807,}\n"
        );
        expect(Ktav::loads("n: 9223372036854775807\n"))->toBe(['n' => 9223372036854775807]);
        expect(PureBackend::loads("n: 9223372036854775807\n"))->toBe(['n' => 9223372036854775807]);
        $src = "a: {old: +01}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::renameKey($src, 'a.old', 'new'); },
            function () use ($src) { return PureBackend::renameKey($src, 'a.old', 'new'); },
            "a: {new: +01}\n"
        );
        $src = "a: {old: +01}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::removeValue($src, 'a.old'); },
            function () use ($src) { return PureBackend::removeValue($src, 'a.old'); },
            "a: {}\n"
        );
        $src = "a: {old: +01}\n";
        $compareSuccess(
            function () use ($src) { return Ktav::insertValue($src, 'a.雪', 'café'); },
            function () use ($src) { return PureBackend::insertValue($src, 'a.雪', 'café'); },
            "a: {old: +01, 雪: café}\n"
        );
        $compareSuccess(
            function () { return Ktav::insertValue("a: {old: +01,}\n", 'a.new', 9223372036854775807); },
            function () { return PureBackend::insertValue("a: {old: +01,}\n", 'a.new', 9223372036854775807); },
            "a: {old: +01, new: 9223372036854775807,}\n"
        );
        $mutated = Ktav::insertValue("base: 0\n", 'n', 9007199254740993);
        expect(Ktav::loads($mutated)['n'])->toBe(9007199254740993);
        expect(PureBackend::loads(PureBackend::insertValue("base: 0\n", 'n', 9007199254740993))['n'])
            ->toBe(9007199254740993);
        $invalidUtf8 = "a: \xE2\x82\n";
        $compareError(
            function () use ($invalidUtf8) { return Ktav::insertValue($invalidUtf8, 'a.new', 1); },
            function () use ($invalidUtf8) { return PureBackend::insertValue($invalidUtf8, 'a.new', 1); },
            'InvalidUtf8'
        );
        $compareError(
            function () use ($invalidUtf8) { return Ktav::insertValue($invalidUtf8, '', 1); },
            function () use ($invalidUtf8) { return PureBackend::insertValue($invalidUtf8, '', 1); },
            'InvalidUtf8'
        );
    });

    it('matches invalid UTF-8 transport diagnostics and source-first validation', function () use ($requireMutationAbi) {
        $requireMutationAbi();
        $cases = [
            ["\xFF\x80", 'invalid utf-8 sequence of 1 bytes from index 0'], ["\x80", 'invalid utf-8 sequence of 1 bytes from index 0'],
            ["\xC2A", 'invalid utf-8 sequence of 1 bytes from index 0'], ["\xE2\x82A", 'invalid utf-8 sequence of 2 bytes from index 0'], ["\xE0\x80", 'invalid utf-8 sequence of 1 bytes from index 0'],
            ["\xED\xA0", 'invalid utf-8 sequence of 1 bytes from index 0'], ["\xF0\x80", 'invalid utf-8 sequence of 1 bytes from index 0'],
            ["\xF0\x9F\x92A", 'invalid utf-8 sequence of 3 bytes from index 0'],
            ["\xF4\x8F\x80", 'incomplete utf-8 byte sequence from index 0'],
            ["\xF4\x90\x80\x80", 'invalid utf-8 sequence of 1 bytes from index 0'],
            ["x\xE2\x82A", 'invalid utf-8 sequence of 2 bytes from index 1'],
            ["\xE2\x82", 'incomplete utf-8 byte sequence from index 0'], ["x\xF0\x9F\x92", 'incomplete utf-8 byte sequence from index 1'],
        ];
        foreach ($cases as [$bad, $detail]) {
            foreach ([
                [function () use ($bad) { return Ktav::removeValue("a: 1\n", $bad); }, function () use ($bad) { return PureBackend::removeValue("a: 1\n", $bad); }, 'path is not valid UTF-8: ' . $detail],
                [function () use ($bad) { return Ktav::renameKey("a: 1\n", 'a', $bad); }, function () use ($bad) { return PureBackend::renameKey("a: 1\n", 'a', $bad); }, 'new key is not valid UTF-8: ' . $detail],
            ] as [$native, $pure, $message]) {
                foreach ([$native, $pure] as $call) {
                    $error = null;
                    try { $call(); } catch (KtavException $e) { $error = $e; }
                    expect($error)->toBeAnInstanceOf(KtavException::class);
                    expect($error->getError())->toBe('Message');
                    expect($error->getMessage())->toBe($message);
                    expect($error->getBody())->toBe($message);
                    expect($error->getErrorLine())->toBe(null);
                    expect($error->getSpan())->toBe(null);
                    expect($error->getPath())->toBe(null);
                    expect($error->getReason())->toBe(null);
                    expect($error->getLineText())->toBe(null);
                    expect($error->getCanonical())->toBe(null);
                    expect($error->getSpecSection())->toBe(null);
                }
            }
        }
        $source = "a: {\n";
        foreach ([
            [function () { return Ktav::removeValue("a: {\n", "\xFF\x80"); }, function () { return PureBackend::removeValue("a: {\n", "\xFF\x80"); }],
            [function () { return Ktav::renameKey("a: {\n", 'a', "\xFF\x80"); }, function () { return PureBackend::renameKey("a: {\n", 'a', "\xFF\x80"); }],
        ] as [$native, $pure]) {
            foreach ([$native, $pure] as $call) {
                $error = null;
                try { $call(); } catch (KtavException $e) { $error = $e; }
                expect($error)->toBeAnInstanceOf(KtavException::class);
                expect($error->getError())->toBe('UnclosedCompound');
            }
        }
    });

    it('matches typed errors and source-first parser diagnostics', function () use ($requireMutationAbi, $compareError) {
        $compareError(
            function () { return Ktav::removeValue("a: 1\n", 'missing'); },
            function () { return PureBackend::removeValue("a: 1\n", 'missing'); },
            'EditNotFound'
        );
        $compareError(
            function () { return Ktav::renameKey("a: 1\nb: 2\n", 'a', 'b'); },
            function () { return PureBackend::renameKey("a: 1\nb: 2\n", 'a', 'b'); },
            'EditAlreadyExists'
        );
        $compareError(
            function () { return Ktav::renameKey("a: 1\n", 'a', ''); },
            function () { return PureBackend::renameKey("a: 1\n", 'a', ''); },
            'EditUnsupported'
        );
        $compareError(
            function () { return Ktav::renameKey("a: {\n", 'a.', 'b'); },
            function () { return PureBackend::renameKey("a: {\n", 'a.', 'b'); },
            'UnclosedCompound'
        );
    });
});
