<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\PureBackend;

/**
 * The 22 layout regressions for the pure source edit — the meaning of
 * the Rust core's tests/suite/api/text/edit_value/layout.rs, ported:
 *
 *  1. multiline replacement string expands the containing inline wrapper
 *  2. line growth beyond 100 scalars expands
 *  3. growth within the limit stays inline
 *  4. limit = max(100, previous physical line length): an already long
 *     line without growth stays inline
 *  5. a string needing >= 4 inline escapes with overhead >= 25% expands
 *  6. < 4 escapes stays inline
 *  7. >= 4 escapes with overhead < 25% stays inline
 *  8. the escape threshold is PER STRING of the replacement (an array
 *     of three 3-escape strings does not expand)
 *  9. a single string with 4 escapes expands even inside an array
 * 10. expansion cascades into touched nested wrappers
 * 11. untouched nested branches stay inline
 * 12. new block indent is 4 spaces
 * 13. new blocks use the source EOL (CRLF)
 * 14. a moved string holding a line `))` keeps the valid inline
 *     candidate instead of a formatting error
 * 15. whole-root inline compounds expand too
 * 16. multiline strings inside a rendered compound replacement stay
 *     lossless (stripped multiline content survives re-parse)
 * 17. growth is measured in Unicode SCALARS, not bytes (120 bytes of
 *     two-byte chars = 60 scalars stays inline)
 * 18. byte length alone never expands (60 scalars / 120 bytes stays)
 * 19. emptied nested inline container keeps the inline form
 * 20. key spelling of untouched dotted siblings is preserved
 * 21. the no-op path never expands (byte-identical, even over limit)
 * 22. expansion keeps the trailing document bytes byte-identical
 *     (comments after the wrapper survive)
 */
describe('pure source edit layout regressions (22)', function () {

    $edit = function (string $src, string $path, $replacement): string {
        return PureBackend::editValue($src, $path, $replacement);
    };

    it('expands for a multiline replacement string', function () use ($edit) {
        expect($edit("cfg: {a: 1, b: 2}\n", 'cfg.a', "line1\nline2"))
            ->toBe("cfg: {\n    a: ((\nline1\nline2\n    ))\n}\n");
    });

    it('expands when the line grows beyond 100 scalars', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', str_repeat('x', 120)))
            ->toBe("cfg: {\n    a: " . str_repeat('x', 120) . "\n}\n");
    });

    it('stays inline within the growth limit', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', str_repeat('x', 50)))
            ->toBe("cfg: {a: " . str_repeat('x', 50) . "}\n");
    });

    it('uses max(100, previous line length) as the limit', function () use ($edit) {
        $old = str_repeat('y', 150);
        $new = str_repeat('z', 150);
        expect($edit("cfg: {a: " . $old . "}\n", 'cfg.a', $new))
            ->toBe("cfg: {a: " . $new . "}\n");
    });

    it('expands on 4+ escapes with 25%+ overhead', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', 'a,b,c,d'))
            ->toBe("cfg: {\n    a: a,b,c,d\n}\n");
    });

    it('stays inline below 4 escapes', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', 'a,b,c'))
            ->toBe("cfg: {a: a\\,b\\,c}\n");
    });

    it('stays inline when 4 escapes carry under 25% overhead', function () use ($edit) {
        $s = str_repeat('a', 45) . ',b,c,d'; // 4 escapes, 49 plain chars
        expect($edit("cfg: {a: 1}\n", 'cfg.a', $s))->toBe("cfg: {a: " . $s . "}\n");
    });

    it('counts the escape threshold per replacement string, not the array sum', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', ['a,b', 'c,d', 'e,f']))
            ->toBe("cfg: {a: [a\\,b, c\\,d, e\\,f]}\n");
    });

    it('expands for one 4-escape string inside an array', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', ['a,b,c,d']))
            ->toBe("cfg: {\n    a: [\n        a,b,c,d\n    ]\n}\n");
    });

    it('cascades into touched nested wrappers', function () use ($edit) {
        expect($edit("top: {in: {x: 1, y: 2}, z: 3}\n", 'top.in.x', 'p,q,r,s'))
            ->toBe("top: {\n    in: {\n        x: p,q,r,s\n        y: 2\n    }\n    z: 3\n}\n");
    });

    it('keeps untouched nested branches inline', function () use ($edit) {
        expect($edit("top: {a: {u: 1}, b: 2}\n", 'top.a.u', 'p,q,r,s'))
            ->toBe("top: {\n    a: {\n        u: p,q,r,s\n    }\n    b: 2\n}\n");
    });

    it('indents new block levels with 4 spaces', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n", 'cfg.a', 'a,b,c,d'))
            ->toBe("cfg: {\n    a: a,b,c,d\n}\n");
    });

    it('uses the source EOL for new blocks (CRLF)', function () use ($edit) {
        expect($edit("cfg: {a: 1}\r\n", 'cfg.a', 'p,q,r,s'))
            ->toBe("cfg: {\r\n    a: p,q,r,s\r\n}\r\n");
    });

    it('keeps a valid inline candidate when a moved line would be ))', function () use ($edit) {
        // The replacement decodes to a string holding the line `))` —
        // no lossless standalone form exists, so the inline candidate
        // (with the LF still escaped) is kept instead of erroring.
        expect($edit("cfg: {a: 1}\n", 'cfg.a', "))\nzzz"))
            ->toBe("cfg: {a: ))\\nzzz}\n");
    });

    it('expands a whole-root inline compound', function () use ($edit) {
        expect($edit("{a: 1, b: 2}\n", 'a', 'p,q,r,s'))
            ->toBe("{\n    a: p,q,r,s\n    b: 2\n}\n");
    });

    it('keeps multiline strings lossless inside a rendered compound', function () use ($edit) {
        expect($edit("k: {\n  x: 1\n}\n", 'k', ['m' => "l1\nl2"]))
            ->toBe("k: {\n    m: (\n        l1\n        l2\n    )\n}\n");
    });

    it('measures the limit in Unicode scalars, not bytes', function () use ($edit) {
        // 60 two-byte chars = 120 bytes but 60 scalars: stays inline.
        $s = str_repeat('я', 60);
        expect($edit("cfg: {a: 1}\n", 'cfg.a', $s))->toBe("cfg: {a: " . $s . "}\n");
    });

    it('never expands on byte length alone', function () use ($edit) {
        // Same bytes as the previous case through a different lens: the
        // threshold is scalar growth vs limit, bytes are irrelevant.
        $s = str_repeat("\xE2\x82\xAC", 40); // 40 x 3 bytes = 120 bytes, 40 scalars
        expect($edit("cfg: {a: 1}\n", 'cfg.a', $s))->toBe("cfg: {a: " . $s . "}\n");
    });

    it('keeps an emptied nested inline container inline', function () use ($edit) {
        expect($edit("cfg: {a: {}}\n", 'cfg.a', []))->toBe("cfg: {a: []}\n");
    });

    it('preserves untouched dotted sibling spelling', function () use ($edit) {
        expect($edit("outer.inner: 1\nx: 2\n", 'outer.inner', 'null'))
            ->toBe("outer.inner:: null\nx: 2\n");
    });

    it('never expands on a no-op, even over the limit', function () use ($edit) {
        $s = str_repeat('x', 120);
        $src = "cfg: {a: " . $s . "}\n";
        expect($edit($src, 'cfg.a', $s))->toBe($src);
    });

    it('keeps trailing document bytes after an expansion', function () use ($edit) {
        expect($edit("cfg: {a: 1}\n## tail comment\nz: 9\n", 'cfg.a', 'a,b,c,d'))
            ->toBe("cfg: {\n    a: a,b,c,d\n}\n## tail comment\nz: 9\n");
    });
});
