<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Pure-backend comment-preserving formatter. Grounding for every
 * expectation: src/Pure/PureFormatter.php (hierarchical event render),
 * src/Pure/PureParser.php (processLine: ONLY `##` opens a comment —
 * spec § 3.4; a single `#` line is content) and PureWriter's pretty
 * emitters (every pair/item line ends with "\n", four spaces per
 * indent level). Comments verbatim, blank runs >= 2 collapse to 1, no
 * blank padding inside brackets, fixed point, key order untouched,
 * equals canonicalFromSource when no comments or blank lines.
 */
describe('PureBackend::format (comment-preserving formatter)', function () {

    it('preserves `##` comments verbatim and collapses blank runs', function () {
        // § 3.4 / PureParser::processLine (src/Pure/PureParser.php,
        // `substr($src, $ts, 2) === '##'`): only a two-# comment is
        // trivia; comments render verbatim (renderEvents 'comment'
        // branch), and a blank run is flushed as ONE "\n" before the
        // next non-blank event.
        $src = "## head\na: 1\n## mid\nb: 2\n\n\n## tail\n";
        expect(PureBackend::format($src))->toBe("## head\na: 1\n## mid\nb: 2\n\n## tail\n");
    });

    it('treats a lone `#` line as content, never a comment (§ 3.4)', function () {
        // Single # is NOT trivia (PureParser.php processLine: only the
        // two-byte '##' dispatches to a comment event), so the line is
        // a scalar array item and the document has no trivia — the
        // formatter output equals the canonical form of the same text.
        expect(PureBackend::loads("#not-a-comment\n"))->toBe(['#not-a-comment']);
        expect(PureBackend::format("#not-a-comment\n"))->toBe("#not-a-comment\n");
        expect(PureBackend::format("#not-a-comment\n"))->toBe(PureBackend::canonicalFromSource("#not-a-comment\n"));
    });

    it('is a fixed point: format(format(x)) === format(x)', function () {
        $docs = [
            "## head\na: 1\n## mid\nb: 2\n\n\n## tail\n",
            "key: [\n\n  alpha\n  beta\n\n]\n\n\n\nother: value\n",
        ];
        foreach ($docs as $src) {
            $once = PureBackend::format($src);
            expect(PureBackend::format($once))->toBe($once);
        }
    });

    it('drops blank padding inside brackets and reindents canonically', function () {
        // The array body carries trivia, so the pair renders via the
        // kids path: PureWriter::emitCompoundOpen emits `key: [` for a
        // PureList leaf ($leafIsList, § 5.9.6) and the kid item lines
        // re-indent at four spaces per level (PureFormatter::renderItem
        // -> PureWriter::emitItemPublic); the document-level blank run
        // collapses to one "\n" (renderEvents $top flush).
        expect(PureBackend::format("key: [\n\n  alpha\n  beta\n\n]\n\n\n\nother: value\n"))
            ->toBe("key: [\n    alpha\n    beta\n]\n\nother: value\n");
    });

    it('renders a dotted key with trivia as nested blocks, object then array leaf', function () {
        // § 5.9.10: dotted keys render one `{` level per segment, and
        // an Array leaf opens `[` (PureWriter::emitCompoundOpen with
        // $leafIsList; closers innermost-first in
        // PureFormatter::renderPair). Without the kind-aware opener the
        // formatter emitted `key: {` for an array body — text that
        // cannot re-parse.
        $src = "a.b: [\n\n  x\n  y\n\n]\nz: 9\n";
        $out = PureBackend::format($src);
        expect($out)->toBe("a: {\n    b: [\n        x\n        y\n    ]\n}\nz: 9\n");
        // The formatted output must itself be valid Ktav with the same
        // value (independent correctness check, not a tautology).
        expect(PureBackend::loads($out))->toBe(['a' => ['b' => ['x', 'y']], 'z' => 9]);
    });

    it('equals source canonicalization when there are no comments or blank lines', function () {
        $docs = [
            "srv:\n  port: 8080\n  tags: [\n    a\n    b\n]\n",
            "[\none\n2\n]\n",
            "a: {}\n",
        ];
        foreach ($docs as $src) {
            // PureFormatter::format short-circuits to canonicalFromSource
            // when hasTrivia() finds no blank/comment event (nested or
            // not) — src/Pure/PureFormatter.php lines 32-35.
            expect(PureBackend::format($src))->toBe(PureBackend::canonicalFromSource($src));
        }
    });

    it('key order is preserved', function () {
        $out = PureBackend::format("zebra: 1\napple: 2\n");
        expect(strpos($out, 'zebra') < strpos($out, 'apple'))->toBe(true);
    });

    it('keeps a GRANDCHILD comment (whole ancestor chains retained, FIX C)', function () {
        // The comment lives in the innermost kid stream; closeCompound
        // now retains a child stream whenever hasTriviaDeep finds
        // trivia at ANY depth (src/Pure/PureParser.php closeCompound +
        // hasTriviaDeep :344-354), so the outer pair — with no direct
        // trivia of its own — still renders via the block path.
        // Trace (round-3 src report, FIX C #2): 'a' opens at indent 0,
        // 'b' at indent 1, the comment renders verbatim (no added
        // indent), 'x: value' at indent 2, closers innermost-first
        // (PureFormatter::renderPair :188-192).
        $src = "a: {\nb: {\n## keep\nx: value\n}\n}\n";
        $out = PureBackend::format($src);
        expect($out)->toBe("a: {\n    b: {\n## keep\n        x: value\n    }\n}\n");
        // The formatted text reparses to the same value…
        expect(PureBackend::loads($out))->toBe(['a' => ['b' => ['x' => 'value']]]);
        // …and is a fixed point.
        expect(PureBackend::format($out))->toBe($out);
    });

    it('keeps an EXPLICIT empty Array root identity (§ 5.9.3, FIX C)', function () {
        // detectRoot marks a lone `[` root explicit; emit() then sets
        // $wrap even though the stream holds only a comment event
        // (src/Pure/PureFormatter.php:72-91), so the `[`/`]` bytes
        // survive and the document cannot collapse to an Object root.
        $src = "[\n## keep\n]\n";
        $out = PureBackend::format($src);
        expect($out)->toBe("[\n## keep\n]\n");
        // Fixed point.
        expect(PureBackend::format($out))->toBe($out);
        // The PHP value model cannot distinguish empty {} from [] on
        // loads — the preserved BYTES are the identity proof. A bare
        // no-trivia explicit empty root still reparses to [].
        expect(PureBackend::loads("[\n]\n"))->toBe([]);
    });

    it('throws the structured envelope on invalid source', function () {
        $closure = function () {
            PureBackend::format('a: [');
        };
        expect($closure)->toThrow(new KtavException());
    });
});
