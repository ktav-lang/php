<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Independent expected tests for the repaired inline-compound paths
 * (§ 5.8). Every expectation is grounded in the spec grammar text:
 *
 * - § 5.8 productions: <inline-pair-list> splits on unescaped depth-0
 *   commas, with ONE optional trailing `(ws) ","`; <inline-value> is a
 *   nested `{...}` / `[...]` or an <inline-scalar> — the decision is
 *   taken ONCE at the first non-ws byte of the inline-value position
 *   (§ 5.8.5), for object pairs AND array items alike.
 * - § 5.8 <inline-raw-scalar>: after `::` the bytes up to the first
 *   unescaped `,` / `}` / `]` are the literal String body (escapes
 *   processed); an initial `{` or `[` is literal data and never
 *   recurses.
 * - § 5.3.3: quote opacity applies at inline object key positions.
 * - § 6.12 / § 6.11 diagnoses: mismatched closer, content after the
 *   closer, empty inline array item, nesting depth limit.
 *
 * Implementation grounding: src/Pure/PureInlineScan.php (one scan per
 * region; `{`/`[` opens a compound at valueStart OR itemStart) and
 * src/Pure/PureParser.php parseInlineBody/inlineValue/inlinePair.
 *
 * Pure backend only by design: these are parser-oracle tests for the
 * native-language implementation and must run in the pure-only CI job
 * (KTAV_ENABLED_BACKENDS=pure) with zero native references.
 */
describe('inline compounds (§ 5.8, pure parser oracles)', function () {

    /** @return mixed */
    $loads = function (string $src) {
        return PureBackend::loads($src);
    };

    /** Asserts the source throws KtavException with the exact category
     *  and the given substring in the message. */
    $fails = function (string $src, string $category, string $messageContains) {
        $caught = null;
        try {
            PureBackend::loads($src);
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe($category);
        expect(strpos((string) $caught->getMessage(), $messageContains) !== false)->toBe(true);
    };

    it('parses an inline object root', function () use ($loads) {
        // § 5.8: `{` at the root anchors an Object root; `a: 1` is a
        // plain inline pair (no separator whitespace required, § 6.10).
        expect($loads('{a: 1}'))->toBe(['a' => 1]);
    });

    it('preserves inline object insertion order', function () use ($loads) {
        // § 5.9.11: canonical/ordering rule — no sorting; the Value
        // keeps pair order (PHP assoc array preserves insertion order).
        expect($loads('{a: 1, b: 2}'))->toBe(['a' => 1, 'b' => 2]);
        expect(array_keys($loads('{z: 1, m: 2, a: 3}')))->toBe(['z', 'm', 'a']);
    });

    it('parses nested inline arrays at item positions', function () use ($loads) {
        // § 5.8.5 note: at an inline-ARRAY item position a leading `[`
        // opens a nested inline array — the itemStart state must open
        // the compound exactly like valueStart (PureInlineScan.php
        // `$state === 'valueStart' || $state === 'itemStart'`).
        expect($loads('[[1],[2]]'))->toBe([[1], [2]]);
        expect($loads('[[1, 2], [3]]'))->toBe([[1, 2], [3]]);
    });

    it('parses an inline object inside an inline array', function () use ($loads) {
        expect($loads('[{a: 1}]'))->toBe([['a' => 1]]);
        expect($loads('[{a: 1}, {b: 2}]'))->toBe([['a' => 1], ['b' => 2]]);
    });

    it('parses quoted keys inside inline compounds (§ 5.3.3)', function () use ($loads) {
        // Quote opacity at the inline key position: `}`, `:`, `.` and
        // `,` inside the quoted segment are data, never delimiters.
        expect($loads('{"a.b": 1, c: 2}'))->toBe(['a.b' => 1, 'c' => 2]);
        expect($loads('{"a}": 1}'))->toBe(['a}' => 1]);
        expect($loads("{\"a,b\": 1}"))->toBe(['a,b' => 1]);
    });

    it('keeps `::`-raw inline values literal, including a leading `[`/`{`', function () use ($loads) {
        // § 5.8 <inline-raw-scalar>: after `::` the body runs to the
        // first unescaped `,` / `}` / `]`; an initial `{` / `[` is
        // literal data and the production never dispatches to
        // <inline-value> (§ 5.8.5 "decision taken once").
        expect($loads('{v:: [x}'))->toBe(['v' => '[x']);
        expect($loads('{v:: {y}'))->toBe(['v' => '{y']);
        // Escapes ARE processed in the raw body (§ 5.8.2).
        expect($loads('{v:: [a\\u0021}'))->toBe(['v' => '[a!']);
    });

    it('accepts exactly one trailing comma (§ 5.8 inline-pair-list/item-list)', function () use ($loads, $fails) {
        expect($loads('{a: 1,}'))->toBe(['a' => 1]);
        expect($loads('[1,]'))->toBe([1]);
        // A SECOND empty segment is not the recognised trailing comma
        // (§ 6.12 defect, PureParser::parseInlineBody pops one only).
        $fails('[a,,b]', 'MalformedInlineCompound', 'empty inline array item');
    });

    it('uses "" for an explicitly empty inline pair value (§ 5.8.2)', function () use ($loads) {
        expect($loads('{a:}'))->toBe(['a' => '']);
        expect($loads('{a: }'))->toBe(['a' => '']);
    });

    it('keeps a raw nested body literal across a sibling compound (§ 4/§ 5.8)', function () use ($loads) {
        // § 4 raw-value termination: after `::` the scanner stays in
        // valueRaw; `[` is a literal opener, NOT a compound start, and
        // only `}`/`]`/`,` terminate the segment. So `[ {v:: [x} ]`
        // is ONE compound whose item is the object `{v: "[x"}` — the
        // inner `[x` is raw String data (PureInlineScan kid frames;
        // trace in the round-3 src report, FIX B).
        expect($loads('[ {v:: [x} ]'))->toBe([['v' => '[x']]);
    });

    it('rejects an empty inline array item as MalformedInlineCompound', function () use ($fails) {
        // § 5.8.3: an empty inline-array item is NOT ""; unlike the
        // object pair (whose empty value is ""), an array item has no
        // separator to anchor it.
        $fails('[a,,b]', 'MalformedInlineCompound', 'empty inline array item');
        $fails('[,]', 'MalformedInlineCompound', 'empty inline array item');
    });

    it('diagnoses a mismatched closer while a value is in progress as UnterminatedInlineCompound', function () use ($fails) {
        // Corpus invalid/inline/raw_mode_does_not_absorb_mismatched_closer
        // (`{a:: x]}` → UnterminatedInlineCompound): the `]` ends the raw
        // scalar and leaves the object unclosed — Rust scan_inline_closer
        // has no mismatch error, and compound.rs maps a missing matching
        // closer (None branch, :320) to UnterminatedInlineCompound.
        // The mechanism is KIND-INDEPENDENT: an inline ARRAY item is a
        // VALUE state (PureInlineScan maps itemStart → valueScalar for
        // kind 'a'; keyStart → keyBody only for kind 'o'), so the first
        // scalar byte of EITHER compound kind enters valueScalar, where
        // a crossed `}` / `]` terminates the value with closer=null.
        // `[1, 2}` is the same shape after the comma re-arms itemStart.
        $fails('{a:: x]}', 'UnterminatedInlineCompound', 'inline compound not closed');
        $fails('{a: 1]', 'UnterminatedInlineCompound', 'inline compound not closed');
        $fails('[a: 1}', 'UnterminatedInlineCompound', 'inline compound not closed');
        $fails('[1, 2}', 'UnterminatedInlineCompound', 'inline compound not closed');
    });

    // Crossed closer AFTER a closed compound (corpus
    // invalid/inline/crossed_closer_after_closed_array_no_quote,
    // MalformedInlineCompound) stays covered by CorpusInvalidSpec —
    // the pure scan already matches (CI d224334 ✓).

    it('re-arms quoted-key recognition after every dot (spec § 5.3.2/§ 5.3.3)', function () use ($loads) {
        // spec.md:1221-1231: quoted-segment recognition reapplies after
        // EACH dot — a quote right after the dot opens an opaque skip
        // (PureInlineScan dot → keyStart re-arm).
        expect($loads('{a."b}": 1}'))->toBe(['a' => ['b}' => 1]]);
    });

    it('terminates a raw value at a comma before a quoted key with a comma (§ 4/§ 5.3.3)', function () use ($loads) {
        // `::` raw body ends at the first unescaped comma; the quoted
        // key segment that follows is comma-opaque, so its ',' is data.
        expect($loads('{v:: x, "k,z": 2}'))->toBe(['v' => 'x', 'k,z' => 2]);
    });

    it('rejects content after the closing delimiter', function () use ($fails) {
        // § 6.12: non-ws bytes past the same-line closer are a defect
        // (PureParser::inlineValue / inlineCompoundValue junk check).
        $fails('{a: 1} x', 'MalformedInlineCompound', 'content after the closing delimiter');
        $fails('v: {a: 1} junk', 'MalformedInlineCompound', 'content after the closing delimiter');
    });

    it('rejects trailing junk after a NESTED compound inside inline compounds', function () use ($fails) {
        // Corpus invalid/inline/malformed_trailing_content_nested_array
        // (`{a: [1] junk}` → MalformedInlineCompound, "content after an
        // already-closed compound"): the kid-tree consumption sites must
        // verify that only whitespace separates the child's closer from
        // the segment end (PureParser::inlineTrailingJunk).
        $fails('{a: [1] junk}', 'MalformedInlineCompound', 'content after the closing delimiter');
        $fails('[[1] junk, 2]', 'MalformedInlineCompound', 'content after the closing delimiter');
        // Legal shape: the remainder after the child's closer is empty.
        expect(PureBackend::loads('{a: [1], b: 2}'))->toBe(['a' => [1], 'b' => 2]);
    });

    it('treats multibyte whitespace as one whitespace unit (§ 3.3)', function () use ($loads) {
        // Corpus valid/inline/object/nbsp_before_nested_array (`{a: [1]}`
        // with NBSP before `[` → {"a": [1]}): every ws-consuming loop
        // must advance by wsLenAt's returned WIDTH (NBSP is 0xC2 0xA0,
        // two bytes), never one byte per iteration.
        expect($loads("{a:\u{00A0}[1]}"))->toBe(['a' => [1]]);
        // Corpus valid/scalars/whitespace_boundary_value shape: NBSP-
        // wrapped scalar trims to exactly 'x'.
        expect($loads("x:\u{00A0}x\u{00A0}"))->toBe(['x' => 'x']);
    });

    it('enforces the declared inline depth limit of 128', function () use ($fails) {
        // Declared limit: PureScanner::MAX_INLINE_DEPTH = 128
        // (src/Pure/PureScanner.php); parseInlineBody fails with
        // MalformedInlineCompound 'nesting depth exceeds limit (128)'
        // once the recursion depth reaches it (§ 9 limit note). 127
        // nested levels parse; 129 are rejected.
        $ok = 'v: ' . str_repeat('[', 127) . 'x' . str_repeat(']', 127) . "\n";
        $got = PureBackend::loads($ok);
        $cur = $got['v'];
        for ($i = 0; $i < 126; $i++) {
            $cur = $cur[0];
        }
        expect($cur[0])->toBe('x');

        $fails(
            'v: ' . str_repeat('[', 129) . 'x' . str_repeat(']', 129) . "\n",
            'MalformedInlineCompound',
            'nesting depth exceeds limit (128)'
        );
    });

    it('rejects an unterminated inline compound', function () use ($fails) {
        // § 6.11: the closer must appear on the same line.
        $fails('{a: 1', 'UnterminatedInlineCompound', 'inline compound not closed');
        $fails('v: [a, b', 'UnterminatedInlineCompound', 'inline compound not closed');
    });
});
