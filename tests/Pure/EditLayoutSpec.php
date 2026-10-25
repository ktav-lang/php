<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * The 22 layout fixtures of the Rust core's
 * tests/suite/api/text/edit_value/layout.rs, ported with EXACT
 * literal source / replacement / expected bytes (both the textual-path
 * and the segments entry must produce the same output; the engine's
 * mandatory semantic verification stands in for the Rust `check`'s
 * re-parse assertion).
 *
 * Escaping note: `a\,b` in these fixtures is a BACKSLASH-COMMA
 * sequence in the source bytes; `'a,b,c,d'` contains THREE commas (3
 * escapes), `'a,b,c,d,e'` FOUR — the >= 4 threshold counts escape
 * sequences, per the core.
 */
describe('source edit layout — core layout.rs fixtures (22)', function () {

    $edit = function (string $src, string $path, $replacement): string {
        return PureBackend::editValue($src, $path, $replacement);
    };

    $editSegs = function (string $src, array $segments, $replacement): string {
        return PureBackend::editValueSegments($src, $segments, $replacement);
    };

    /** Textual path from decoded segments (escape \ and .). */
    $textual = function (array $path): string {
        $out = [];
        foreach ($path as $seg) {
            $out[] = str_replace(['\\', '.'], ['\\\\', '\\.'], $seg);
        }
        return implode('.', $out);
    };

    /** The Rust `check`: textual AND segments variants, exact output. */
    $check = function (string $source, array $path, $replacement, string $expected) use ($edit, $editSegs, $textual) {
        expect($edit($source, $textual($path), $replacement))->toBe($expected);
        expect($editSegs($source, $path, $replacement))->toBe($expected);
    };

    // 1 -----------------------------------------------------------------
    it('deep_leaf_preserves_each_source_form', function () use ($check) {
        foreach ([
            "targets.storagehost.address: old\ntargets.storagehost.user: mcp-agent\n",
            "targets: {storagehost: {address: old, user: mcp-agent}}\n",
            "targets: {\n  storagehost: {\n    address: old\n    user: mcp-agent\n  }\n}\n",
        ] as $source) {
            $check($source, ['targets', 'storagehost', 'address'], '192.168.0.88:22',
                str_replace('old', '192.168.0.88:22', $source));
        }
    });

    // 2 -----------------------------------------------------------------
    it('deep_array_leaf_preserves_mixed_forms', function () use ($check) {
        foreach ([
            "targets: [{storagehost: [old, keep]}, other]\n",
            "targets: [\n  {storagehost: [old, keep]}\n  other\n]\n",
            "targets: [\n  {\n    storagehost: [\n      old\n      keep\n    ]\n  }\n  other\n]\n",
        ] as $source) {
            $check($source, ['targets', '0', 'storagehost', '0'], 'new',
                str_replace('old', 'new', $source));
        }
    });

    // 3 -----------------------------------------------------------------
    it('whole_inline_containers_remain_inline', function () use ($check) {
        $check("a: {x: 1}\n", ['a'], ['y' => 2], "a: {y: 2}\n");
        $check("a: [1, 2]\n", ['a'], [3, 4], "a: [3, 4]\n");
        $check("a: {}\n", ['a'], ['y' => 2], "a: {y: 2}\n");
        $check("a: []\n", ['a'], [3, 4], "a: [3, 4]\n");
        $check("a: {}\n", ['a'], [3, 4], "a: [3, 4]\n");
    });

    // 4 -----------------------------------------------------------------
    it('emptying_block_containers_does_not_collapse_them', function () use ($check) {
        // parse("{}") = an EMPTY OBJECT; PHP expresses it as stdClass.
        $check("a: {\n  x: 1\n}\n", ['a'], new \stdClass(), "a: {\n}\n");
        $check("a: [\n  1\n]\n", ['a'], [], "a: [\n]\n");
    });

    // 5 -----------------------------------------------------------------
    it('newline_unfolds_only_inline_ancestor_chain', function () use ($check) {
        $check(
            "## top\na: [{x: old, untouched: [1, 2]}, {keep: yes}]\nz: keep\n",
            ['a', '0', 'x'], "one\ntwo",
            "## top\na: [\n    {\n        x: ((\none\ntwo\n        ))\n        untouched: [1, 2]\n    }\n    {keep: yes}\n]\nz: keep\n"
        );
    });

    // 6 -----------------------------------------------------------------
    it('newline_under_dotted_key_does_not_expand_dotted_ancestors', function () use ($check) {
        $check(
            "a.b: [old, keep]\na.c: other\n",
            ['a', 'b', '0'], "one\ntwo",
            "a.b: [\n    ((\none\ntwo\n    ))\n    keep\n]\na.c: other\n"
        );
    });

    // 7 -----------------------------------------------------------------
    it('dense_escapes_unfold_but_sparse_escapes_do_not', function () use ($check) {
        $check("a: [old, keep]\n", ['a', '0'], 'a,b,c,d,e',
            "a: [\n    a,b,c,d,e\n    keep\n]\n");
        $check("a: [old]\n", ['a', '0'], 'a,b,c,d',
            "a: [a\\,b\\,c\\,d]\n");
        $sparse = str_repeat('x', 30) . 'a,b,c,d,e';
        $check("a: [old]\n", ['a', '0'], $sparse,
            "a: [" . str_replace(',', '\\,', $sparse) . "]\n");
    });

    // 8 -----------------------------------------------------------------
    it('width_boundary_is_in_characters_not_bytes', function () use ($check) {
        foreach ([95 => false, 96 => true] as $size => $expanded) {
            $value = str_repeat('я', $size);
            $expected = $expanded
                ? "a: [\n    {$value}\n]\n"
                : "a: [{$value}]\n";
            $check("a: [old]\n", ['a', '0'], $value, $expected);
        }
    });

    // 9 -----------------------------------------------------------------
    it('already_long_line_only_unfolds_when_it_grows', function () use ($check) {
        $source = 'a: [' . str_repeat('x', 120) . ", keep]\n";
        $check($source, ['a', '0'], str_repeat('y', 120), str_replace('x', 'y', $source));
        $check($source, ['a', '0'], str_repeat('y', 121),
            "a: [\n    " . str_repeat('y', 121) . "\n    keep\n]\n");
    });

    // 10 ----------------------------------------------------------------
    it('width_expansion_stops_when_inner_line_fits', function () use ($check) {
        $value = str_repeat('x', 91);
        $check("a: [{x: old}]\n", ['a', '0', 'x'], $value,
            "a: [\n    {x: {$value}}\n]\n");
    });

    // 11 ----------------------------------------------------------------
    it('escape_overhead_boundary_is_exact', function () use ($check) {
        $dense = str_repeat('x', 7) . 'a,b,c,d,e';
        $sparse = str_repeat('x', 8) . 'a,b,c,d,e';
        $check("a: [old]\n", ['a', '0'], $dense, "a: [\n    {$dense}\n]\n");
        $check("a: [old]\n", ['a', '0'], $sparse,
            "a: [" . str_replace(',', '\\,', $sparse) . "]\n");
    });

    // 12 ----------------------------------------------------------------
    it('long_changed_leaf_does_not_unfold_unrelated_nested_branches', function () use ($check) {
        $value = str_repeat('x', 100);
        $check("a: [old, {untouched: [1, 2]}]\n", ['a', '0'], $value,
            "a: [\n    {$value}\n    {untouched: [1, 2]}\n]\n");
    });

    // 13 ----------------------------------------------------------------
    it('promoted_siblings_keep_values_when_inline_escapes_lose_meaning', function () use ($check) {
        $check(
            "a: [old, a\\,b, \\u00342, :: literal]\n",
            ['a', '0'], "x\ny",
            "a: [\n    ((\nx\ny\n    ))\n    a,b\n    :: 42\n    :: :: literal\n]\n"
        );
    });

    // 14 ----------------------------------------------------------------
    it('promoted_pairs_keep_key_spelling_and_raw_markers', function () use ($check) {
        $check(
            "a: {\"x.y\": old, nested.key::  42, n: 0007}\n",
            ['a', 'x.y'], "x\ny",
            "a: {\n    \"x.y\": ((\nx\ny\n    ))\n    nested.key::  42\n    n: 0007\n}\n"
        );
    });

    // 15 ----------------------------------------------------------------
    it('root_inline_promotion_preserves_bom_and_eof', function () use ($check) {
        $check(
            "\xEF\xBB\xBF[old, keep]",
            ['0'], "x\ny",
            "\xEF\xBB\xBF[\n    ((\nx\ny\n    ))\n    keep\n]"
        );
    });

    // 16 ----------------------------------------------------------------
    it('promotion_preserves_eol_comments_and_no_final_terminator', function () use ($check) {
        foreach (["\n", "\r", "\r\n"] as $eol) {
            $source = "## header{$eol}a: [old, keep]";
            $expected = "## header{$eol}a: [{$eol}    (({$eol}x{$eol}y{$eol}    )){$eol}    keep{$eol}]";
            $check($source, ['a', '0'], "x\ny", $expected);
        }
    });

    // 17 ----------------------------------------------------------------
    it('comments_outside_promoted_inline_value_remain_byte_exact', function () use ($check) {
        $check(
            "list: [\n  ## before\n  {x: old}\n  ## after\n]\n",
            ['list', '0', 'x'], "x\ny",
            "list: [\n  ## before\n  {\n      x: ((\nx\ny\n      ))\n  }\n  ## after\n]\n"
        );
    });

    // 18 ----------------------------------------------------------------
    it('noop_ignores_all_readability_thresholds', function () use ($check) {
        $source = 'a: [' . str_repeat('x', 140) . ", a\\,b\\,c\\,d\\,e]\n";
        $check($source, ['a', '0'], str_repeat('x', 140), $source);
        $check($source, ['a', '1'], 'a,b,c,d,e', $source);
    });

    // 19 ----------------------------------------------------------------
    it('type_change_does_not_force_unrelated_layout_changes', function () use ($check) {
        $check("a: {x: [1], keep: [2]}\n", ['a', 'x'], true, "a: {x: true, keep: [2]}\n");
        $check("a: [1]\n", ['a'], '42', "a:: 42\n");
    });

    // 20 ----------------------------------------------------------------
    it('reopened_inline_parent_promotes_only_its_physical_definition', function () use ($check) {
        $check(
            "a: {x: old}\n## gap\na.y: keep\n",
            ['a', 'x'], "x\ny",
            "a: {\n    x: ((\nx\ny\n    ))\n}\n## gap\na.y: keep\n"
        );
    });

    // 21 ----------------------------------------------------------------
    it('impossible_block_encoding_keeps_valid_inline_form', function () use ($check) {
        foreach (["a\rb", "a\n))\nb"] as $value) {
            $encoded = str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
            $check("a: [old]\n", ['a', '0'], $value, "a: [{$encoded}]\n");
        }
        $check("a: [old, a\\rb]\n", ['a', '0'], "x\ny", "a: [x\\ny, a\\rb]\n");
    });

    // 22 ----------------------------------------------------------------
    it('unsafe_comment_loss_still_fails_closed', function () use ($editSegs) {
        foreach (["a: {\n ## keep\n x: 1\n}\n", "a: [\n ## keep\n 1\n]\n"] as $source) {
            $caught = null;
            try {
                $editSegs($source, ['a'], null);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            expect($caught->getError())->toBe('EditUnsupported');
        }
    });
});
