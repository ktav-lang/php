<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\KtavException;

/**
 * Cross-backend round trips — the independent-oracle cross-checks.
 * Two implementations agreeing is necessary but NOT sufficient, so
 * wherever a spec canonical oracle exists the canonical bytes are
 * asserted against it as well (see CorpusValidSpec); here both
 * backends must additionally agree on VALUES both ways, and every
 * dumps output of either backend must parse back, in BOTH backends,
 * to the same value.
 */
describe('cross-backend round trips (native <-> pure)', function () {

    beforeAll(function () {
        Corpus::manifest();
        Corpus::requireNative();
    });

    // -----------------------------------------------------------------
    // Synthetic value set: broad shapes. Empty LIST compounds are now
    // included ('empty list'): since the round-6 empty-array identity
    // fix (PureWriter::toInternal — a nested empty PHP array is a
    // PureList, mirroring the native wire layer where json_encode keeps
    // nested empty arrays as JSON `[]`, WireJson.php:118-123), both
    // backends agree on the empty-list value AND its canonical bytes
    // `tags: []` (corpus: tests/valid/arrays/empty_inline.*). Empty
    // OBJECT compounds remain excluded: the public PHP value model
    // still cannot carry `{}` vs `[]` identity through loads() (a
    // parsed empty object comes back as PHP []), so a {} entry would
    // not round-trip as a distinct value — documented limitation.
    // -----------------------------------------------------------------
    $values = [
        'flat object' => ['b' => 2, 'a' => 1, 'c' => true, 'd' => null],
        // The `['$i' => digits]` typed-integer wire convention
        // cross-checked in BOTH directions (moved here from
        // ValueModelSpec so the pure-only run stays native-free).
        'typed big integer' => ['v' => ['$i' => '123456789012345678901234567890']],
        'nested object' => ['srv' => ['host' => '127.0.0.1', 'port' => 8080, 'tls' => true]],
        'list root' => ['alpha', 42, 0.5, false, null, 'end'],
        'list of objects' => [['name' => 'a', 'n' => 1], ['name' => 'b', 'n' => 2]],
        'object of lists' => ['tags' => ['prod', 'eu-west-1'], 'ports' => [80, 443]],
        // Empty LIST: pure toInternal → PureList (`tags: []`), native
        // wire json_encode → `{"tags":[]}` (WireJson.php:118-123);
        // both parse back to ['tags' => []] and both backends emit the
        // identical canonical bytes (corpus arrays/empty_inline).
        'empty list' => ['tags' => []],
        'deep nesting' => ['l1' => ['l2' => ['l3' => ['l4' => ['leaf' => 'x']]]]],
        'dotted-merged' => Corpus::call('pure', 'loads', "a.b: 1\nc: 2\na.d: 3\n"),
        'negative + floats' => ['z' => -17, 'f' => 0.75, 'tiny' => 1e-3, 'big' => 1e10],
        'quoted keys' => ['cache:redis' => 'enabled', 'say "hi": now' => 'ok', 'a.b' => 'not dotted'],
        'unicode' => ["ключ" => 'значение', 'emoji' => "🚀", 'esc' => "hello!"],
        'multiline string' => Corpus::call('pure', 'loads', "script: (\n    line1\n    line2\n)\n"),
        // § 5.3 <sep-end>: the raw marker `::` requires whitespace or
        // EOL after it — the glued form `ver::007` is itself a
        // MissingSeparatorSpace error (§ 6.10; corpus invalid fixture
        // missing_separator_space/raw_marker_glued_colon.ktav = `key:::value`),
        // so this group-loading loads() call must use the spec-valid
        // SPACED form. Same value either way: raw marker = literal
        // String '007'.
        'raw marker' => Corpus::call('pure', 'loads', "ver:: 007\n"),
    ];

    // Expected PARSE output per entry. Defaults to the same value that
    // goes INTO the writer; the big-integer entry is deliberately
    // different: the `['$i' => digits]` tagged shape is the WRITER
    // input convention (PureWriter::toInternal -> reviveInteger,
    // src/Pure/PureWriter.php:76-114, same convention as
    // WireJson::reviveInteger), while the public parse contract
    // delivers the bare digits string. Never blanket-equalise the two
    // representations — compare the parse result against $expected.
    $expected = $values;
    $expected['typed big integer'] = ['v' => '123456789012345678901234567890'];

    foreach ($values as $name => $value) {
        $want = $expected[$name];
        it("pure dumps -> native loads agrees with pure loads: $name", function () use ($value, $want) {
            $text = Corpus::call('pure', 'dumps', $value);
            expect($text)->not->toBe('');
            $fromNative = Corpus::call('native', 'loads', $text);
            $fromPure = Corpus::call('pure', 'loads', $text);
            expect(Corpus::equals($fromNative, $fromPure))->toBe(true);
            expect(Corpus::equals($want, $fromNative))->toBe(true);
        });

        it("native dumps -> pure loads round-trips: $name", function () use ($value, $want) {
            $text = Corpus::call('native', 'dumps', $value);
            expect($text)->not->toBe('');
            $fromNative = Corpus::call('native', 'loads', $text);
            $fromPure = Corpus::call('pure', 'loads', $text);
            expect(Corpus::equals($want, $fromPure))->toBe(true);
            expect(Corpus::equals($fromNative, $fromPure))->toBe(true);
        });

        it("canonical bytes agree across backends and re-parse identically: $name", function () use ($value) {
            $nativeCanonical = Corpus::call('native', 'emitCanonical', $value);
            $pureCanonical = Corpus::call('pure', 'emitCanonical', $value);
            expect($nativeCanonical)->toBe($pureCanonical);

            expect(Corpus::equals(
                Corpus::call('native', 'loads', $pureCanonical),
                Corpus::call('pure', 'loads', $nativeCanonical)
            ))->toBe(true);
        });
    }

    // -----------------------------------------------------------------
    // Corpus valid fixtures where both backends are available: loads()
    // must agree, and dumps outputs of each must parse back equal in
    // both backends. (Byte-exact canonical-vs-spec is asserted per
    // fixture in CorpusValidSpec — agreement alone cannot pass a wrong
    // output.)
    // -----------------------------------------------------------------
    foreach (Corpus::fixtures('valid') as $rel => $stem) {
        it("valid corpus :: loads agreement + dumps reparses :: $rel", function () use ($stem) {
            Corpus::requireNative();
            $src = Corpus::source($stem);
            $oracle = Corpus::oracle($stem);

            $n = Corpus::call('native', 'loads', $src);
            $p = Corpus::call('pure', 'loads', $src);
            expect(Corpus::equals($n, $p))->toBe(true);
            expect(Corpus::equals($oracle, $p))->toBe(true);

            // dumps of the loaded value: outputs of either backend
            // must parse back, in both backends, to the same value.
            // Values containing empty compounds lose that identity in
            // PHP, so compare against a reparse, not the raw $oracle.
            foreach (['native', 'pure'] as $backend) {
                $text = Corpus::call($backend, 'dumps', $p);
                expect(Corpus::equals(
                    Corpus::call('native', 'loads', $text),
                    Corpus::call('pure', 'loads', $text)
                ))->toBe(true);
            }
        });
    }
});
