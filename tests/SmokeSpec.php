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

        it('renders a top-level Array (spec § 5.0.1, since 0.1.1)', function () {
            $text = Ktav::dumps(['alpha', 'beta', 'gamma']);
            expect($text)->not->toBe('');

            $back = Ktav::loads($text);
            expect($back)->toBe(['alpha', 'beta', 'gamma']);
        });

        it('rejects a bare-scalar root', function () {
            $closure = function () {
                Ktav::dumps(42);
            };
            expect($closure)->toThrow(new KtavException());
        });

    });

    describe('::dumpsForceStrings', function () {

        it('coerces every leaf scalar to a String', function () {
            $doc = [
                'name'    => 'demo',
                'count'   => 42,
                'ratio'   => 0.5,
                'flag'    => true,
                'nothing' => null,
                'nested'  => ['inner' => 1],
            ];
            $text = Ktav::dumpsForceStrings($doc);
            expect($text)->not->toBe('');

            // Round-trip back: every leaf scalar is now a String.
            $back = Ktav::loads($text);
            expect($back['name'])->toBe('demo');
            expect($back['count'])->toBe('42');
            expect($back['ratio'])->toBe('0.5');
            expect($back['flag'])->toBe('true');
            expect($back['nothing'])->toBe('null');
            expect($back['nested']['inner'])->toBe('1');
        });

        it('works with a top-level Array', function () {
            $text = Ktav::dumpsForceStrings([1, true, null]);
            $back = Ktav::loads($text);
            expect($back)->toBe(['1', 'true', 'null']);
        });

    });

    describe('top-level Array detection (spec § 5.0.1, since 0.1.1)', function () {

        it('parses bare-scalar first line as a list', function () {
            $cfg = Ktav::loads("alpha\nbeta\ngamma\n");
            expect($cfg)->toBe(['alpha', 'beta', 'gamma']);
        });

        it('parses typed-marker first line as a list', function () {
            $cfg = Ktav::loads(":i 42\n:i 7\n");
            expect($cfg)->toBe([42, 7]);
        });

        it('still treats key:value first line as Object', function () {
            $cfg = Ktav::loads("port:i 8080\n");
            expect($cfg)->toBe(['port' => 8080]);
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
