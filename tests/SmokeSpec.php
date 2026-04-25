<?php

declare(strict_types=1);

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Tests\TestPaths;

describe('Ktav (smoke)', function () {

    beforeAll(function () {
        TestPaths::init();
        if (!TestPaths::cabiBuilt()) {
            skipIf(true, 'cabi not built (' . TestPaths::cabi() . ') — run `cargo build --release -p ktav-cabi`');
        }
    });

    describe('::loads', function () {

        it('parses a basic typed document', function () {
            $src = "service: web\n"
                . "port:i 8080\n"
                . "ratio:f 0.75\n"
                . "tls: true\n"
                . "tags: [\n    prod\n    eu-west-1\n]\n"
                . "db.host: primary\n"
                . "db.timeout:i 30\n";
            $cfg = Ktav::loads($src);

            expect($cfg['service'])->toBe('web');
            expect($cfg['port'])->toBe(8080);
            expect($cfg['ratio'])->toBeCloseTo(0.75, 12);
            expect($cfg['tls'])->toBe(true);
            expect($cfg['tags'])->toBe(['prod', 'eu-west-1']);
            expect($cfg['db']['host'])->toBe('primary');
            expect($cfg['db']['timeout'])->toBe(30);
        });

        it('throws on syntactically invalid input', function () {
            $closure = function () {
                Ktav::loads('a: [');
            };
            expect($closure)->toThrow(new KtavException());
        });

    });

    describe('::dumps', function () {

        it('round-trips a simple document', function () {
            $doc = [
                'name'    => 'demo',
                'count'   => 42,
                'ratio'   => 0.5,
                'flag'    => true,
                'nothing' => null,
                'nested'  => ['inner' => 1],
            ];
            $text = Ktav::dumps($doc);
            expect($text)->not->toBe('');

            $back = Ktav::loads($text);
            expect($back['name'])->toBe('demo');
            expect($back['count'])->toBe(42);
            expect($back['flag'])->toBe(true);
            expect($back['nothing'])->toBeNull();
            expect($back['nested']['inner'])->toBe(1);
        });

        it('rejects a sequential-array root', function () {
            $closure = function () {
                Ktav::dumps([1, 2, 3]);
            };
            expect($closure)->toThrow(new KtavException());
        });

    });

    describe('arbitrary-precision integers', function () {

        it('round-trips digits beyond PHP_INT_MAX', function () {
            $huge = '99999999999999999999999999999';
            $cfg = Ktav::loads('value:i ' . $huge);
            // Out of PHP int range — comes back as the digit string.
            expect($cfg['value'])->toBe($huge);

            // Wrap it as `['$i' => 'digits']` to feed back in.
            $text = Ktav::dumps(['v' => ['$i' => $huge]]);
            expect($text)->toContain($huge);
        });

    });

    describe('::nativeVersion', function () {

        it('returns a non-empty version string', function () {
            $v = Ktav::nativeVersion();
            expect($v)->not->toBe('');
        });

    });

});
