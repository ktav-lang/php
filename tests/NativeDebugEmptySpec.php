<?php

declare(strict_types=1);

namespace Ktav\Tests;

use Ktav\Ktav;

describe('native debug ABI: empty input', function () {
    /**
     * Regression for the empty-input native boundary defect (task 21).
     *
     * Ground truth: `ktav_loads` builds
     * `core::slice::from_raw_parts(src, src_len)`
     * (rust/src/cabi/declare.rs:76; same pattern in every exported
     * call, e.g. :110). Rust requires a NON-NULL, aligned pointer EVEN
     * WHEN the length is 0 — release builds hide the violation, DEBUG
     * builds (debug_assertions) detect it and abort. The caller
     * (src/NativeLib.php) therefore must always hand over a live
     * >=1-byte buffer while passing the ORIGINAL (possibly 0) length.
     *
     * The library this spec runs against MUST be a DEBUG Rust build
     * (cargo build -p ktav-cabi, no --release) wired via
     * KTAV_LIB_PATH — a release-built binary would NOT catch the
     * NULL-with-len-0 UB. Semantics: an empty source is a VALID empty
     * Object document (spec § 5.0.1 / § 5.2); the canonical rendering
     * of an empty Object is ZERO bytes (rust render.rs emit_root).
     */
    beforeAll(function () {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('ext-ffi required for the native debug ABI regression');
        }
        // Provenance gate: NativeLib::ffi() throws KtavException on any
        // non-ABI-2 binary (src/NativeLib.php assertAbiVersion), so the
        // first native call below fails the run on a stale ABI-1
        // artifact. With the immutable git-core pin the version STRING
        // is informational — only non-emptiness is asserted here.
        if (Ktav::nativeVersion() === '') {
            throw new \RuntimeException('loaded ktav_cabi reports an empty version');
        }
    });

    it('loads("") → empty Object shape', function () {
        expect(Ktav::loads(''))->toBe([]);
    });

    it('loadsStrict("") → empty is not lossy', function () {
        expect(Ktav::loadsStrict(''))->toBe([]);
    });

    it('format("") → zero-byte output', function () {
        expect(Ktav::format(''))->toBe('');
    });

    it('canonicalFromSource("") → zero bytes', function () {
        expect(Ktav::canonicalFromSource(''))->toBe('');
    });

    it('emitCanonical([]) → zero bytes (writer side of empty Object)', function () {
        expect(Ktav::emitCanonical([]))->toBe('');
    });

    it('dumps([]) → zero-byte empty Object document', function () {
        // JSON '{}' is the wire input, not the rendered Ktav output.
        expect(Ktav::dumps([]))->toBe('');
    });

    it('positive control: a: 1 → [a => 1]', function () {
        // Guards against passing with a silently-wrong library: the
        // non-empty path must still parse correctly.
        expect(Ktav::loads('a: 1'))->toBe(['a' => 1]);
    });
});
