<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Pure source-edit semantics (spec 0.9): splice invariants, path
 * grammar, the three edit envelopes and marker/form preservation.
 * Every expectation mirrors the contract:
 *
 * - replace-existing only (no create/remove/rename);
 * - bytes outside the effective edit survive: comments, key spelling,
 *   key order, BOM, CR/LF/CRLF, missing final EOL, dotted form;
 * - byte-identical no-op even for noncanonical source spelling;
 * - structural replacement of a comment-carrying container and
 *   noncontiguous dotted/reopen targets are EditUnsupported BEFORE the
 *   splice; leaf edits inside such containers keep working;
 * - marker transitions are minimal (only the second ':' of a pair
 *   switches; the original separator whitespace is preserved).
 *
 * Layout regressions (inline expansion rules) live in
 * EditLayoutSpec.php; native parity in tests/EditNativeSpec.php.
 */
describe('pure source edit (spec 0.9 semantics)', function () {

    $edit = function (string $src, string $path, $replacement): string {
        return PureBackend::editValue($src, $path, $replacement);
    };

    $editSegs = function (string $src, array $segments, $replacement): string {
        return PureBackend::editValueSegments($src, $segments, $replacement);
    };

    /** Asserts EditInvalidPath with the decimal byte offset in body. */
    $invalidPath = function (string $path, int $offset) {
        $caught = null;
        try {
            PureBackend::editValue('a: 1' . "\n", $path, 2);
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe('EditInvalidPath');
        expect($caught->getBody())->toBe((string) $offset);
        expect($caught->getErrorLine())->toBe(null);
        expect($caught->getSpan())->toBe(null);
        expect(strpos($caught->getMessage(), 'invalid edit path') === 0)->toBe(true);
    };

    /** Asserts the given edit error code. */
    $fails = function (callable $fn, string $code) {
        $caught = null;
        try {
            $fn();
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe($code);
        return $caught;
    };

    // -------------------------------------------------------------
    // Path grammar
    // -------------------------------------------------------------

    it('rejects an empty textual path at offset 0', function () use ($invalidPath) {
        $invalidPath('', 0);
    });

    it('rejects leading / trailing / doubled dots at the dot offset', function () use ($invalidPath) {
        $invalidPath('.a', 0);
        $invalidPath('a.', 2);
        $invalidPath('a..b', 2);
    });

    it('rejects unknown escapes and dangling backslashes', function () use ($invalidPath) {
        $invalidPath('a\\x', 1); // \x is not \. or \\
        $invalidPath('a\\', 1); // trailing lone backslash
    });

    it('accepts literal dot and backslash escapes', function () use ($edit) {
        expect($edit('"a.b": 1' . "\n", 'a\\.b', 2))->toBe('"a.b": 2' . "\n");
        expect($edit('"a\\\\b": 1' . "\n", 'a\\\\b', 2))->toBe('"a\\\\b": 2' . "\n");
    });

    it('segments variant takes decoded literals with no unescaping', function () use ($editSegs) {
        // The segment 'a.b' is ONE literal key (the source spells it
        // quoted); no second unescape happens.
        expect($editSegs('"a.b": 1' . "\n", ['a.b'], 2))->toBe('"a.b": 2' . "\n");
        // Numeric segment: key in an Object, index in an Array.
        expect($editSegs('"0": 1' . "\n", ['0'], 2))->toBe('"0": 2' . "\n");
        expect($editSegs("[\n  1\n  2\n]\n", ['1'], 9))->toBe("[\n  1\n  9\n]\n");
    });

    it('numeric segments allow leading zeros in arrays but reject signs', function () use ($editSegs, $fails) {
        expect($editSegs("[\n  1\n  2\n]\n", ['01'], 9))->toBe("[\n  1\n  9\n]\n");
        $fails(function () use ($editSegs) {
            $editSegs("[\n  1\n]\n", ['-1'], 9);
        }, 'EditNotFound');
        $fails(function () use ($editSegs) {
            $editSegs("[\n  1\n]\n", ['+1'], 9);
        }, 'EditNotFound');
    });

    // -------------------------------------------------------------
    // NotFound
    // -------------------------------------------------------------

    it('rejects an empty segments list as NotFound', function () use ($editSegs, $fails) {
        $e = $fails(function () use ($editSegs) {
            $editSegs('a: 1' . "\n", [], 2);
        }, 'EditNotFound');
        expect($e->getBody())->toBe(null);
        expect(strpos($e->getMessage(), 'does not address an existing value') !== false)->toBe(true);
    });

    it('reports missing keys, index overflow and scalar descent as NotFound', function () use ($edit, $fails) {
        $fails(function () use ($edit) {
            $edit('a: 1' . "\n", 'b', 2);
        }, 'EditNotFound');
        $fails(function () use ($edit) {
            $edit("[\n  1\n]\n", '5', 2);
        }, 'EditNotFound');
        $fails(function () use ($edit) {
            $edit('a: 1' . "\n", 'a.b', 2);
        }, 'EditNotFound');
        // Index segment into an Object is a KEY lookup, and vice versa.
        $fails(function () use ($edit) {
            $edit("{\n  0: 1\n}\n", '1', 2);
        }, 'EditNotFound');
    });

    // -------------------------------------------------------------
    // Splice invariants
    // -------------------------------------------------------------

    it('replaces a scalar preserving siblings and the final EOL', function () use ($edit) {
        expect($edit("port: 8080\nhost: db\n", 'port', 9090))->toBe("port: 9090\nhost: db\n");
    });

    it('keeps a missing final EOL', function () use ($edit) {
        expect($edit('a: 1', 'a', 2))->toBe('a: 2');
    });

    it('preserves BOM and CRLF outside the edit', function () use ($edit) {
        expect($edit("\xEF\xBB\xBFa: 1\r\nb: 2\r\n", 'a', 3))->toBe("\xEF\xBB\xBFa: 3\r\nb: 2\r\n");
    });

    it('is byte-identical on a no-op even with noncanonical spelling', function () use ($edit) {
        expect($edit("a: 1_000\n", 'a', 1000))->toBe("a: 1_000\n");
        expect($edit("a: 0x10\n", 'a', 16))->toBe("a: 0x10\n");
    });

    it('preserves the dotted form when editing a dotted leaf', function () use ($edit) {
        expect($edit("outer.inner: 1\n", 'outer.inner', 2))->toBe("outer.inner: 2\n");
    });

    it('preserves comments outside the effective edit', function () use ($edit) {
        expect($edit("a: 1\n## note\nb: 2\n", 'a', 3))->toBe("a: 3\n## note\nb: 2\n");
    });

    it('keeps internal comments on a leaf edit inside a container', function () use ($edit) {
        expect($edit("c: {\n  ## inner\n  x: 1\n}\n", 'c.x', 5))
            ->toBe("c: {\n  ## inner\n  x: 5\n}\n");
    });

    it('refuses structural replacement of a comment-carrying container', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("c: {\n  ## inner\n  x: 1\n}\n", 'c', []);
        }, 'EditUnsupported');
        expect(strpos((string) $e->getBody(), 'comment') !== false)->toBe(true);
        expect(strpos((string) $e->getMessage(), 'no lossless source edit: ') === 0)->toBe(true);
    });

    it('refuses noncontiguous dotted/reopen whole targets', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("a: {}\na.y: 2\n", 'a', ['z' => 9]);
        }, 'EditUnsupported');
        // body = the core reason phrase, verbatim.
        expect($e->getBody())->toBe('target is a noncontiguous value (dotted-key/reopen intermediate);'
            . ' no lossless single-range splice exists');
        // The editable leaf still works.
        expect($edit("a: {}\na.y: 2\n", 'a.y', 7))->toBe("a: {}\na.y: 7\n");
    });

    it('refuses replacement strings containing CR', function () use ($edit, $fails) {
        $fails(function () use ($edit) {
            $edit("a: 1\n", 'a', "x\r\ny");
        }, 'EditUnsupported');
    });

    it('refuses a multiline string holding a line ))', function () use ($edit, $fails) {
        $fails(function () use ($edit) {
            $edit("a: 1\n", 'a', "x\n))\ny");
        }, 'EditUnsupported');
    });

    it('lets the source itself fail with the ordinary parser envelope', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("a: {\n", 'a', 2);
        }, 'UnclosedCompound');
        expect($e->getErrorLine())->toBe(1);
    });

    // -------------------------------------------------------------
    // Source-first transport order (C ABI parity)
    // -------------------------------------------------------------

    it('reports a broken SOURCE before an invalid textual path', function () use ($edit, $fails) {
        // C ABI: UTF-8 validate + parse source FIRST, then transport
        // decode. The parser envelope wins over EditInvalidPath.
        $fails(function () use ($edit) {
            $edit("a: {\n", 'a.', 2);
        }, 'UnclosedCompound');
    });

    it('reports a broken SOURCE before an empty segments list', function () use ($editSegs, $fails) {
        $fails(function () use ($editSegs) {
            $editSegs("a: {\n", [], 2);
        }, 'UnclosedCompound');
    });

    it('reports a broken SOURCE before a non-string segment', function () use ($editSegs, $fails) {
        $fails(function () use ($editSegs) {
            $editSegs("a: {\n", ['a', 5], 2);
        }, 'UnclosedCompound');
    });

    it('reports a non-string segment as a source-less Message transport failure', function () use ($editSegs, $fails) {
        // Aligned with the native JSON transport (invalid path JSON ->
        // Message envelope, no invented source location) — NOT
        // EditNotFound.
        $e = $fails(function () use ($editSegs) {
            $editSegs("a: 1\n", ['a', 5], 2);
        }, 'Message');
        expect($e->getErrorLine())->toBe(null);
        expect($e->getSpan())->toBe(null);
    });

    it('reports an invalid-UTF-8 textual path as a source-less Message', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("a: 1\n", "a\xFF", 2);
        }, 'Message');
        expect($e->getErrorLine())->toBe(null);
        expect($e->getSpan())->toBe(null);
    });

    it('reports an unsupported replacement type as a Message transport failure', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("a: 1\n", 'a', fopen('php://memory', 'r'));
        }, 'Message');
        expect($e->getErrorLine())->toBe(null);
    });

    it('surfaces a non-JSON-encodable segments transport before any native call', function () {
        // Ktav::editValueSegments must never swallow a json_encode
        // failure into empty path bytes: the typed transport error is
        // raised BEFORE the FFI call, so this holds with or without a
        // native artifact (no partial edit is possible).
        $caught = null;
        try {
            \Ktav\Ktav::editValueSegments("a: 1\n", ['a', fopen('php://memory', 'r')], 2);
        } catch (\Ktav\KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(\Ktav\KtavException::class);
        expect($caught->getError())->toBe('Message');
        expect(strpos((string) $caught->getMessage(), 'not JSON-encodable') !== false)->toBe(true);
    });

    // -------------------------------------------------------------
    // Marker transitions
    // -------------------------------------------------------------

    it('preserves an existing raw marker for a compatible string body', function () use ($edit) {
        expect($edit("k:: 5\n", 'k', 'x'))->toBe("k:: x\n");
    });

    it('strips only the second colon for a raw pair turning non-string', function () use ($edit) {
        expect($edit("k:: 5\n", 'k', 9090))->toBe("k: 9090\n");
    });

    it('adds the second colon when a plain pair needs raw provenance', function () use ($edit) {
        expect($edit("k: 5\n", 'k', 'null'))->toBe("k:: null\n");
        expect($edit("k: 5\n", 'k', 'true'))->toBe("k:: true\n");
    });

    it('keeps the original separator whitespace through marker changes', function () use ($edit) {
        expect($edit("k:  5\n", 'k', 'null'))->toBe("k::  null\n");
    });

    it('switches raw markers on array items', function () use ($edit) {
        expect($edit("arr: [\n  5\n]\n", 'arr.0', 'null'))->toBe("arr: [\n  :: null\n]\n");
        expect($edit("arr: [\n  :: null\n]\n", 'arr.0', 5))->toBe("arr: [\n   5\n]\n");
    });

    it('keeps an empty inline array string item as ()', function () use ($edit) {
        expect($edit('cfg: {a: [x]}' . "\n", 'cfg.a.0', ''))
            ->toBe('cfg: {a: [()]}' . "\n");
    });

    // -------------------------------------------------------------
    // Types and forms
    // -------------------------------------------------------------

    it('preserves int vs float and string identity', function () use ($edit) {
        expect($edit("a: 1\n", 'a', 2.5))->toBe("a: 2.5\n");
        expect($edit("a: 1\n", 'a', '2'))->toBe("a:: 2\n");
        expect($edit("a: 1.5\n", 'a', 2))->toBe("a: 2\n");
        expect($edit("a: 1\n", 'a', null))->toBe("a: null\n");
        expect($edit("a: 1\n", 'a', true))->toBe("a: true\n");
    });

    it('refuses a tagged >i64 Integer: the spliced body re-parses as a String', function () use ($edit, $fails) {
        // An arbitrary-precision $i payload is written digit-exactly,
        // but § 5.2 rule 13 re-classifies an >i64 literal as a String —
        // the verification target (String) can never equal the typed
        // Integer replacement, so core refuses (fail closed).
        $fails(function () use ($edit) {
            $edit("a: 1\n", 'a', ['$i' => '123456789012345678901234567890']);
        }, 'EditUnsupported');
    });

    it('replaces a whole inline container inline when it fits', function () use ($edit) {
        // A PHP [] follows the nested empty-LIST convention (documented
        // like the writer's); an Object identity needs nonempty pairs.
        expect($edit("k: {a: 1}\n", 'k', [7]))->toBe("k: [7]\n");
        expect($edit("k: {a: 1}\n", 'k', []))->toBe("k: []\n");
    });

    it('re-opens an emptied block container (empty list convention)', function () use ($edit) {
        expect($edit("k: {\n  x: 1\n}\n", 'k', []))->toBe("k: [\n]\n");
    });

    it('empty stdClass is the explicit empty Object; non-empty fails closed', function () use ($edit, $fails) {
        // Core emptying_block_containers: the empty OBJECT identity.
        expect($edit("a: {\n  x: 1\n}\n", 'a', new \stdClass()))->toBe("a: {\n}\n");
        // Nested empty Object inside an array.
        expect($edit("a: [old]\n", 'a', [new \stdClass()]))->toBe("a: [{}]\n");
        // Non-empty — INCLUDING a self-referencing one — is a Message
        // before any recursion (these calls return, never hang).
        $nonEmpty = new \stdClass();
        $nonEmpty->q = 1;
        $fails(function () use ($edit, $nonEmpty) {
            $edit("a: 1\n", 'a', $nonEmpty);
        }, 'Message');
        $selfRef = new \stdClass();
        $selfRef->self = $selfRef;
        $fails(function () use ($edit, $selfRef) {
            $edit("a: 1\n", 'a', $selfRef);
        }, 'Message');
    });

    it('renders a non-empty compound replacement as a 4-space block', function () use ($edit) {
        expect($edit("k: {\n  x: 1\n}\n", 'k', ['y' => 2]))->toBe("k: {\n    y: 2\n}\n");
    });

    it('does not reformat ancestors on a type change', function () use ($edit) {
        // Scalar -> compound in a block pair renders the compound body
        // like the core's render_compound_item(level 1) + reindent:
        // children at target indent + 4, closer at the target indent.
        expect($edit("k: {\n  x: 1\n  y: two\n}\n", 'k.x', ['q' => 1]))
            ->toBe("k: {\n  x: {\n      q: 1\n  }\n  y: two\n}\n");
        // A type change never touches SIBLING bytes (core
        // type_change_does_not_force_unrelated_layout_changes).
        expect($edit("a: {x: [1], keep: [2]}\n", 'a.x', true))->toBe("a: {x: true, keep: [2]}\n");
        expect($edit("a: [1]\n", 'a', '42'))->toBe("a:: 42\n");
    });

    it('replaces a multiline block string with a verbatim block', function () use ($edit) {
        expect($edit("k: (\n  old\n)\n", 'k', "line1\nline2"))
            ->toBe("k: ((\nline1\nline2\n))\n");
    });

    it('converts a block target scalar to multiline via verbatim block', function () use ($edit) {
        expect($edit("a: 1\n", 'a', "l1\nl2"))->toBe("a: ((\nl1\nl2\n))\n");
    });

    it('routes edge-whitespace strings through the multiline form', function () use ($edit) {
        expect($edit("a: 1\n", 'a', ' x '))->toBe("a: ((\n x \n))\n");
    });

    it('multibyte bodies stay literal under a plain marker', function () use ($edit) {
        expect($edit("a: 1\n", 'a', 'привет'))->toBe("a: привет\n");
    });

    it('handles nested inline leaf edits inside inline compounds', function () use ($edit) {
        expect($edit("cfg: {a: {x: 1, y: 2}}\n", 'cfg.a.x', 9))
            ->toBe("cfg: {a: {x: 9, y: 2}}\n");
        expect($edit("cfg: {a: [1, 2]}\n", 'cfg.a.1', 9))
            ->toBe("cfg: {a: [1, 9]}\n");
    });

    it('verifies semantically and refuses a corrupted patch (fail closed)', function () use ($edit, $fails) {
        // A no-op-looking replacement of a duplicated-key source is
        // unreachable post-parse; the guard here is the mandatory
        // verification path, exercised via the noncontiguous case.
        $fails(function () use ($edit) {
            $edit("a: {}\na.y: 2\n", 'a.y.x', 1);
        }, 'EditNotFound');
    });

    // -------------------------------------------------------------
    // Exact numeric identity (no lossy PHP float arithmetic)
    // -------------------------------------------------------------

    it('treats negative zero float as a byte-identical no-op', function () use ($edit) {
        // -0.0 must survive: ini-independent shortest round-trip digits
        // keep the sign of zero; a lossy (string) cast or -0.0 === 0.0
        // would collapse it onto +0 and falsely report a no-op or a
        // semantic mismatch.
        expect($edit("a: -0.0\n", 'a', -0.0))->toBe("a: -0.0\n");
    });

    it('distinguishes -0.0 from 0.0 on a real change', function () use ($edit) {
        expect($edit("a: 0.0\n", 'a', -0.0))->toBe("a: -0.0\n");
        expect($edit("a: -0.0\n", 'a', 0.0))->toBe("a: 0.0\n");
    });

    it('keeps in-i64 integers beyond 2^53 exact on a no-op', function () use ($edit) {
        // 2^53 + 1 is exactly representable in i64 but NOT in double:
        // equality must go through digit strings, never float math.
        $src = "a: 9007199254740993\n";
        expect($edit($src, 'a', 9007199254740993))->toBe($src);
        expect($edit($src, 'a', ['$i' => '9007199254740993']))->toBe($src);
    });

    it('refuses a tagged big Integer replacing a String-typed overflow literal', function () use ($edit, $fails) {
        // An i64-overflow literal parses as a STRING (§ 5.2 rule 13);
        // a tagged Integer replacement has a different type, so the
        // verification correctly refuses (mirrors the Rust core).
        $fails(function () use ($edit) {
            $edit("a: 123456789012345678901234567890\n", 'a', ['$i' => '123456789012345678901234567890']);
        }, 'EditUnsupported');
    });

    // -------------------------------------------------------------
    // Inline fallback when no lossless standalone form exists
    // -------------------------------------------------------------

    // -------------------------------------------------------------
    // Strict wire ingest (tagged payloads) — native codec parity
    // -------------------------------------------------------------

    it('rejects a non-integer $i payload as a Message, never a coerced scalar', function () use ($edit, $fails) {
        $e = $fails(function () use ($edit) {
            $edit("a: 0\n", 'a', ['$i' => '12a3']);
        }, 'Message');
        expect($e->getErrorLine())->toBe(null);
    });

    it('rejects a non-decimal $f payload — the false 0.0 no-op is closed', function () use ($edit, $fails) {
        // REGRESSION: (float)'abc' collapsed to 0.0 and could report a
        // byte-identical no-op against `a: 0.0`. It must be a Message
        // transport failure, like the native codec.
        $e = $fails(function () use ($edit) {
            $edit("a: 0.0\n", 'a', ['$f' => 'abc']);
        }, 'Message');
        expect($e->getErrorLine())->toBe(null);
    });

    it('rejects an $f payload without a dot or exponent', function () use ($edit, $fails) {
        $fails(function () use ($edit) {
            $edit("a: 0.0\n", 'a', ['$f' => '5']);
        }, 'Message');
    });

    it('rejects a non-string tag payload', function () use ($edit, $editSegs, $fails) {
        $fails(function () use ($edit) {
            $edit("a: 0\n", 'a', ['$i' => 5]);
        }, 'Message');
        $fails(function () use ($editSegs) {
            $editSegs("a: 0\n", ['a'], ['$f' => null]);
        }, 'Message');
    });

    it('canonicalizes $i like the core: +0001, -0, leading zeros', function () use ($edit) {
        // +0001 -> 1, -0 -> 0, 000... -> trimmed: equal values are
        // byte-identical no-ops regardless of wire spelling.
        expect($edit("a: 1\n", 'a', ['$i' => '+0001']))->toBe("a: 1\n");
        expect($edit("a: 0\n", 'a', ['$i' => '-0']))->toBe("a: 0\n");
        expect($edit("a: 9007199254740993\n", 'a', ['$i' => '0009007199254740993']))
            ->toBe("a: 9007199254740993\n");
        // A real change writes the CANONICAL text, not the wire spelling.
        expect($edit("a: 1\n", 'a', ['$i' => '+0002']))->toBe("a: 2\n");
        expect($edit("a: 1\n", 'a', ['$i' => '-007']))->toBe("a: -7\n");
    });

    it('canonicalizes $f like the core: finite shortest round-trip', function () use ($edit, $fails) {
        expect($edit("a: 1\n", 'a', ['$f' => '2.50']))->toBe("a: 2.5\n");
        // Non-finite wire-only payload passes through verbatim — and
        // then re-parses as a String, so verification correctly refuses.
        $fails(function () use ($edit) {
            $edit("a: 1\n", 'a', ['$f' => '1e400']);
        }, 'EditUnsupported');
    });

    it('keeps Object equality order-insensitive (Rust IndexMap PartialEq)', function () use ($edit, $editSegs) {
        // Equal object, different key order: BYTE-IDENTICAL no-op.
        $src = "k: {a: 1, b: 2}\n";
        expect($edit($src, 'k', ['b' => 2, 'a' => 1]))->toBe($src);
        expect($editSegs($src, ['k'], ['b' => 2, 'a' => 1]))->toBe($src);
        // Arrays stay ORDERED: a reordered array is a real change.
        expect($edit("k: {a: [1, 2]}\n", 'k.a', [2, 1]))->toBe("k: {a: [2, 1]}\n");
        // Nested objects compare order-insensitively at every level.
        expect($edit("k: {a: {x: 1, y: 2}, b: 3}\n", 'k', ['b' => 3, 'a' => ['y' => 2, 'x' => 1]]))
            ->toBe("k: {a: {x: 1, y: 2}, b: 3}\n");
    });

    it('splices a reordered object when a value actually differs', function () use ($edit) {
        expect($edit("k: {a: 1, b: 2}\n", 'k', ['b' => 3, 'a' => 1]))
            ->toBe("k: {b: 3, a: 1}\n");
    });

    it('keeps the inline candidate when a moved string holds CR', function () use ($edit) {
        // requires_block fires (\r present); the moved value decodes to
        // a CR-bearing string that has no lossless standalone line, so
        // the valid INLINE candidate (with \r still escaped) is kept
        // instead of a formatting error.
        expect($edit("cfg: {a: 1}\n", 'cfg.a', "p\rq"))
            ->toBe("cfg: {a: p\\rq}\n");
    });
});
