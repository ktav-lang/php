<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * Focused negative controls per major parser state, both backends.
 * Sources marked "corpus" are the exact bytes of an invalid/ fixture
 * (category grounded by that fixture's oracle); the rest come from
 * the normative examples in spec.md § 5.0.1 / § 5.3 / § 6.
 */
describe('negative controls per parser state (both backends)', function () {

    beforeAll(function () {
        Corpus::manifest();
    });

    /** Asserts both backends throw with the exact given category. */
    $throwsCategory = function (string $backend, string $src, string $category) {
        if ($backend === 'native') {
            Corpus::requireNative();
        }
        $caught = null;
        try {
            Corpus::call($backend, 'loads', $src);
        } catch (KtavException $e) {
            $caught = $e;
        }
        expect($caught)->toBeAnInstanceOf(KtavException::class);
        expect($caught->getError())->toBe($category);
    };

    /** Fixture source by invalid/ relative stem (byte-exact). */
    $invalid = function (string $rel): string {
        $fixtures = Corpus::fixtures('invalid');
        expect(isset($fixtures[$rel]))->toBe(true);
        return Corpus::source($fixtures[$rel]);
    };

    foreach (Corpus::backends() as $backend) {
        $prefix = $backend;

        // -- unclosed compounds --------------------------------------
        it("$prefix: unclosed multiline array", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unclosed/array'), 'UnclosedCompound');
        });
        it("$prefix: unclosed multiline stripped string", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unclosed/multiline_stripped'), 'UnclosedCompound');
        });
        it("$prefix: unclosed multiline verbatim string", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unclosed/multiline_verbatim'), 'UnclosedCompound');
        });
        it("$prefix: explicit root object never closed (EOF)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unclosed/explicit_root_object_bare'), 'UnclosedCompound');
        });
        it("$prefix: explicit root array never closed (EOF)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unclosed/explicit_root_array_bare'), 'UnclosedCompound');
        });

        // -- separators ----------------------------------------------
        it("$prefix: glued pair `key:value`", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('missing_separator_space/glued_pair'), 'MissingSeparatorSpace');
        });
        it("$prefix: glued raw marker root `::x`", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('missing_separator_space/raw_marker_item_glued'), 'MissingSeparatorSpace');
        });
        it("$prefix: glued raw marker `a::1`", function () use ($throwsCategory, $backend) {
            $throwsCategory($backend, "a::1\n", 'MissingSeparatorSpace');
        });
        it("$prefix: bare text line inside an object (MissingSeparator)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('missing_separator/bare_text_in_object'), 'MissingSeparator');
        });

        // -- keys ------------------------------------------------------
        it("$prefix: empty key (leading colon)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('empty_key/leading_colon'), 'EmptyKey');
        });
        it("$prefix: invalid key chars `a,b: 1` still selects an Object root (§ 5.0.1 rule 6)", function () use ($throwsCategory, $backend) {
            $throwsCategory($backend, "a,b: 1\n", 'InvalidKey');
        });
        it("$prefix: unterminated quoted key", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('unterminated_quoted_key/single_quote_in_object'), 'UnterminatedQuotedKey');
        });

        // -- dotted-path conflicts, both directions --------------------
        it("$prefix: leaf then dotted extension (BlockedByValue direction)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('key_path_conflict/leaf_then_extend'), 'KeyPathConflict');
        });
        it("$prefix: object then plain overwrite (Overwrite direction)", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('key_path_conflict/object_then_leaf'), 'KeyPathConflict');
        });
        it("$prefix: descend through an explicit object leaf", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('key_path_conflict/descend_through_explicit_object_leaf'), 'KeyPathConflict');
        });
        it("$prefix: dotted conflict inside an inline compound", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('inline/dotted_key_path_conflict'), 'KeyPathConflict');
        });
        it("$prefix: duplicate key", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('duplicate_keys/flat'), 'DuplicateKey');
        });

        // -- escapes ---------------------------------------------------
        it("$prefix: `\\t` is not a named escape", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('bad_escape/escape_t_not_recognised'), 'BadEscapeSequence');
        });
        it("$prefix: lone high surrogate", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('bad_escape/unicode_escape_lone_high_surrogate'), 'BadEscapeSequence');
        });

        // -- root shapes (§ 5.0.1) --------------------------------------
        it("$prefix: bare close brace first line", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('top_level/bare_close_brace_first'), 'UnbalancedBracket');
        });
        it("$prefix: orphan line after a top-level inline root", function () use ($throwsCategory, $invalid, $backend) {
            $throwsCategory($backend, $invalid('top_level/content_after_inline_root'), 'OrphanLineAfterTopLevelInline');
        });
        it("$prefix: `[bad]: 1` is a malformed inline attempt, never a pair", function () use ($throwsCategory, $backend) {
            // § 5.0.1: diagnosis precedence over rule 6 — category is
            // one of the two inline-compound diagnoses, never InvalidKey.
            $src = "[bad]: 1\n";
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $caught = null;
            try {
                Corpus::call($backend, 'loads', $src);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            expect(in_array($caught->getError(), ['UnterminatedInlineCompound', 'MalformedInlineCompound'], true))->toBe(true);
        });

        // -- depth limit (implementation-declared 128, § 9 limit note) --
        it("$prefix: inline nesting beyond depth 128 is rejected", function () use ($backend) {
            // Gate BEFORE any native reference: the pure-only CI job
            // (KTAV_ENABLED_BACKENDS=pure) must run every pure cell
            // with zero native calls; in the native job a missing
            // artifact now FAILS (Corpus::requireNative), never skips.
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $src = 'v: ' . str_repeat('[', 200) . str_repeat(']', 200) . "\n";
            $caught = null;
            try {
                Corpus::call($backend, 'loads', $src);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            if ($backend === 'pure') {
                expect($caught->getError())->toBe('MalformedInlineCompound');
            }
        });

        // -- line-ending / BOM boundaries --------------------------------
        it("$prefix: BOM + CRLF document parses", function () use ($backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $got = Corpus::call($backend, 'loads', "\xEF\xBB\xBFa: 1\r\nb: 2\r\n");
            expect(Corpus::equals($got, ['a' => 1, 'b' => 2]))->toBe(true);
        });
        it("$prefix: lone CR terminators parse", function () use ($backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $got = Corpus::call($backend, 'loads', "a: 1\rb: 2\r");
            expect(Corpus::equals($got, ['a' => 1, 'b' => 2]))->toBe(true);
        });
        it("$prefix: glued plain `a:1` at root is an array-item scalar, not a pair (§ 5.0.1 rule 7)", function () use ($backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $got = Corpus::call($backend, 'loads', "a:1\n");
            expect(Corpus::equals($got, ['a:1']))->toBe(true);
        });
        it("$prefix: scalar-root document parses as a one-item list", function () use ($backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            $got = Corpus::call($backend, 'loads', "42\n");
            expect($got)->toBe([42]);
        });
    }
});
