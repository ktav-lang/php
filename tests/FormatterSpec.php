<?php

declare(strict_types=1);

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Tests\TestPaths;

describe('Ktav::format (comment-preserving formatter)', function () {

    beforeAll(function () {
        TestPaths::init();
        if (!TestPaths::cabiBuilt()) {
            skipIf(true, 'cabi not built (' . TestPaths::cabi() . ') — run `cargo build --release -p ktav-cabi`');
        }
    });

    it('preserves comments verbatim', function () {
        $src = "# head\na: 1\n## mid\nb: 2\n\n\n# tail\n";
        $out = Ktav::format($src);

        // Full equality — pinned by probe: blank-line run collapsed, comments kept.
        expect($out)->toBe("# head\na: 1\n## mid\nb: 2\n\n# tail\n");
    });

    it('is a fixed point: format(format(x)) === format(x)', function () {
        $docs = [
            // comments + blank-line runs
            "# head\na: 1\n## mid\nb: 2\n\n\n# tail\n",
            // blank padding inside brackets + blank-line runs
            "key: [\n\n  alpha\n  beta\n\n]\n\n\n\nother: value\n",
        ];

        foreach ($docs as $src) {
            $once = Ktav::format($src);
            expect(Ktav::format($once))->toBe($once);
        }
    });

    it('collapses blank-line runs to one and drops blank padding inside brackets', function () {
        // "\n\n\n" between `b: 2` and `# tail` collapses to a single blank line.
        expect(Ktav::format("# head\na: 1\n## mid\nb: 2\n\n\n# tail\n"))
            ->toBe("# head\na: 1\n## mid\nb: 2\n\n# tail\n");

        // Blank lines inside brackets are dropped; content gets canonical indent.
        expect(Ktav::format("key: [\n\n  alpha\n  beta\n\n]\n\n\n\nother: value\n"))
            ->toBe("key: [\n    alpha\n    beta\n]\n\nother: value\n");
    });

    it('equals emitCanonical(loads(src)) when there are no comments and no blank lines', function () {
        $docs = [
            // nested object with an array
            "srv:\n  port: 8080\n  tags: [\n    a\n    b\n]\n",
            // top-level array
            "[\none\n2\n]\n",
        ];

        foreach ($docs as $src) {
            expect(Ktav::format($src))->toBe(Ktav::emitCanonical(Ktav::loads($src)));
        }
    });

    it('key order is preserved', function () {
        $out = Ktav::format("zebra: 1\napple: 2\n");
        $posZebra = strpos($out, 'zebra');
        $posApple = strpos($out, 'apple');

        expect($posZebra)->not->toBe(false);
        expect($posApple)->not->toBe(false);
        expect($posZebra < $posApple)->toBe(true);
    });

    it('throws the structured envelope on invalid source', function () {
        $closure = function () {
            Ktav::format('a: [');
        };
        expect($closure)->toThrow(new KtavException());
    });

});
