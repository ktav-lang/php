<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;
use Ktav\PureBackend;

/**
 * Pure-backend PUBLIC API suite — the corpus is not a substitute.
 * Mirrors the important SmokeSpec cases against \Ktav\PureBackend.
 * No native artifact, FFI extension or Rust toolchain is needed here.
 */
describe('PureBackend (public API)', function () {

    it('is always available and reports its own version marker', function () {
        expect(PureBackend::isAvailable())->toBe(true);
        expect(PureBackend::version())->toBe('pure-0.8.1');
    });

    describe('::loads', function () {

        it('parses a basic typed document', function () {
            $src = "service: web\n"
                . "port: 8080\n"
                . "ratio: 0.75\n"
                . "tls: true\n"
                . "tags: [\n    prod\n    eu-west-1\n]\n"
                . "db.host: primary\n"
                . "db.timeout: 30\n";
            $cfg = PureBackend::loads($src);

            expect($cfg['service'])->toBe('web');
            expect($cfg['port'])->toBe(8080);
            expect($cfg['ratio'])->toBeCloseTo(0.75, 12);
            expect($cfg['tls'])->toBe(true);
            expect($cfg['tags'])->toBe(['prod', 'eu-west-1']);
            expect($cfg['db']['host'])->toBe('primary');
            expect($cfg['db']['timeout'])->toBe(30);
        });

        it('parses an empty / comments-only document as an empty Object (§ 5.0.1 rule 1)', function () {
            expect(PureBackend::loads(''))->toBe([]);
            expect(PureBackend::loads("## nothing here\n\n"))->toBe([]);
        });

        it('treats a single `#` as content, not a comment (§ 3.4)', function () {
            expect(PureBackend::loads("#not-a-comment\n"))->toBe(['#not-a-comment']);
        });

        it('throws on syntactically invalid input', function () {
            $closure = function () {
                PureBackend::loads('a: [');
            };
            expect($closure)->toThrow(new KtavException());
        });

        it('supports strict numeric spelling checks (§ 8.1)', function () {
            $lossy = function () {
                PureBackend::loadsStrict("version: 1.10\n");
            };
            expect($lossy)->toThrow(new KtavException());

            $cfg = PureBackend::loadsStrict("small: 1e-3\nlarge: 1e10\n");
            expect($cfg['small'])->toBeCloseTo(0.001, 12);
            expect($cfg['large'])->toBe(10000000000.0);
        });

        it('decodes unicode escapes with exactly four hex digits (§ 3.7.1)', function () {
            $cfg = PureBackend::loads('msg: [hello\u0021]' . "\n");
            expect($cfg['msg'])->toBe(['hello!']);
            $cfg = PureBackend::loads('s: [\u00411]' . "\n");
            expect($cfg['s'])->toBe(['A1']);
        });

        it('parses quoted keys (§ 5.3.3)', function () {
            expect(PureBackend::loads("\"cache:redis\": enabled\n"))->toBe(['cache:redis' => 'enabled']);
            expect(PureBackend::loads("'say \"hi\": now': ok\n"))->toBe(['say "hi": now' => 'ok']);
        });

        it('merges dotted keys across reopen (§ 5.3.2)', function () {
            $cfg = PureBackend::loads("a.b: 1\nc: 2\na.d: 3\n");
            expect($cfg)->toBe(['a' => ['b' => 1, 'd' => 3], 'c' => 2]);
        });

        it('extends an explicit object with later dotted keys (§ 5.3.2)', function () {
            $cfg = PureBackend::loads("a: {\n    x: 1\n}\na.y: 2\n");
            expect($cfg)->toBe(['a' => ['x' => 1, 'y' => 2]]);
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
            $text = PureBackend::dumps($doc);
            expect($text)->not->toBe('');

            $back = PureBackend::loads($text);
            expect(Corpus::equals($doc, $back))->toBe(true);
        });

        it('renders a top-level Array (spec § 5.0.1)', function () {
            $text = PureBackend::dumps(['alpha', 'beta', 'gamma']);
            $back = PureBackend::loads($text);
            expect($back)->toBe(['alpha', 'beta', 'gamma']);
        });

        it('emits an empty PHP array as an empty Object root: ZERO bytes', function () {
            // Grounding: rust/src/document/render/emitters/render.rs:68-79
            // emit_root() — an empty Object root emits ZERO bytes (the
            // object-body loop over zero pairs); an empty Array root
            // emits "[]\n". The facade feeds '{}' JSON for [] on the
            // native path too (src/Ktav.php dumpsImpl), so PureWriter
            // (PureWriter::emitRoot) matches the Rust writer exactly.
            expect(PureBackend::dumps([]))->toBe('');
        });

        it('rejects a bare-scalar root with ScalarRoot (§ 5.9.0)', function () {
            $caught = null;
            try {
                PureBackend::dumps(42);
            } catch (KtavException $e) {
                $caught = $e;
            }
            expect($caught)->toBeAnInstanceOf(KtavException::class);
            expect($caught->getError())->toBe('Unrepresentable');
            expect($caught->getReason())->toBe('ScalarRoot');
            expect($caught->getSpecSection())->toBe('§5.9.0');
        });
    });

    describe('::dumpsForceStrings', function () {

        it('coerces every leaf scalar to a String via the raw marker', function () {
            $doc = [
                'name'    => 'demo',
                'count'   => 42,
                'ratio'   => 0.5,
                'flag'    => true,
                'nothing' => null,
                'nested'  => ['inner' => 1],
            ];
            $text = PureBackend::dumpsForceStrings($doc);
            expect($text)->not->toBe('');

            $back = PureBackend::loads($text);
            expect($back['name'])->toBe('demo');
            expect($back['count'])->toBe('42');
            expect($back['ratio'])->toBe('0.5');
            expect($back['flag'])->toBe('true');
            expect($back['nothing'])->toBe('null');
            expect($back['nested']['inner'])->toBe('1');
        });

        it('works with a top-level Array', function () {
            $back = PureBackend::loads(PureBackend::dumpsForceStrings([1, true, null]));
            expect($back)->toBe(['1', 'true', 'null']);
        });
    });

    describe('::emitCanonical', function () {

        it('preserves insertion order (no sorting, § 5.9.11)', function () {
            $text = PureBackend::emitCanonical(['b' => 2, 'a' => 1]);
            expect(strpos($text, 'b') < strpos($text, 'a'))->toBe(true);

            $back = PureBackend::loads($text);
            expect($back['a'])->toBe(1);
            expect($back['b'])->toBe(2);
        });
    });
});
