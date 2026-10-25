<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\PureBackend;
use Ktav\Tests\TestPaths;

/**
 * Spec 0.9 corpus guard + fixture-driven runner — bound to the REAL
 * on-disk schema of versions/0.9/tests @ a452756 (no guessed shapes):
 *
 * - manifest.json: schema_version 1, CLOSED category set with exact
 *   counts (valid 228 / invalid 162 / unrepresentable 5 /
 *   parseable-unrepresentable 4 / strict-lossy 13; total 412) and a
 *   fixture_flags list whose only defined flag is raw_bytes;
 * - a fixture is one <stem> per directory: .ktav/.json/.canonical.ktav
 *   under valid/, .ktav/.json under invalid/, parseable-unrepresentable/
 *   and strict-lossy/, and ONLY a .json oracle under unrepresentable/;
 * - the oracle is the SIBLING <stem>.json: invalid carries
 *   {expected_error, expected_location?} with location
 *   {line, start, end | minimum_end} (raw-byte offsets, exactly one of
 *   end / minimum_end); strict-lossy carries lax_value +
 *   {expected_error=LossyScalar, body, canonical}; parseable-
 *   unrepresentable and unrepresentable carry value +
 *   {unrepresentable_reason};
 * - diagnostics09/: exactly 30 eof_* + 42 utf8_* invalid fixtures
 *   (the 72 new 0.9 diagnostics). ALL of them are executed through
 *   BOTH backends' raw-bytes entrypoints — UTF-8 validation precedes
 *   grammar, InvalidUtf8 never decodes line_text.
 *
 * The corpus is REQUIRED: a missing/stale directory FAILS the run, it
 * never skips. Expected oracles come only from the sibling .json —
 * nothing is substituted.
 */
final class Corpus09
{
    /** The closed 0.9 category set with pinned counts. */
    public const CATEGORIES = [
        'valid' => 228,
        'invalid' => 162,
        'unrepresentable' => 5,
        'parseable-unrepresentable' => 4,
        'strict-lossy' => 13,
    ];

    public const TOTAL = 412;

    /** New diagnostics09 inside invalid: 30 EOF + 42 malformed UTF8. */
    public const DIAGNOSTICS09_EOF = 30;
    public const DIAGNOSTICS09_UTF8 = 42;

    public const SCHEMA_VERSION = 1;
    public const DEFINED_FLAGS = ['raw_bytes'];

    /** @var array<string, mixed>|null */
    private static $manifest;

    // -----------------------------------------------------------------
    // Fail-closed manifest guard
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $path = self::dir() . '/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException('spec 0.9 corpus manifest missing: ' . $path);
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('spec 0.9 corpus manifest is not an object');
        }
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'spec 0.9 corpus manifest schema_version must be exactly '
                . self::SCHEMA_VERSION . ', got '
                . json_encode($manifest['schema_version'] ?? null)
            );
        }
        $categories = $manifest['categories'] ?? null;
        if (!is_array($categories)) {
            throw new \RuntimeException('spec 0.9 corpus manifest has no categories map');
        }
        foreach (self::CATEGORIES as $name => $count) {
            if (($categories[$name]['count'] ?? null) !== $count) {
                throw new \RuntimeException(
                    "spec 0.9 manifest declares $name count "
                    . json_encode($categories[$name]['count'] ?? null)
                    . ", expected $count"
                );
            }
        }
        $flags = $manifest['fixture_flags'] ?? null;
        if (!is_array($flags) || $flags === []) {
            throw new \RuntimeException('spec 0.9 corpus manifest has an empty fixture_flags list');
        }
        foreach ($flags as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('spec 0.9 fixture_flags entry is not an object');
            }
            $unknown = array_diff((array) ($entry['flags'] ?? []), self::DEFINED_FLAGS);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    'spec 0.9 fixture carries undefined flags: ' . implode(',', $unknown)
                );
            }
            $cat = $entry['category'] ?? null;
            if (!isset(self::CATEGORIES[$cat])) {
                throw new \RuntimeException('spec 0.9 fixture_flags entry has unknown category: ' . json_encode($cat));
            }
            $fixture = $entry['fixture'] ?? null;
            if (!is_string($fixture) || $fixture === '') {
                throw new \RuntimeException('spec 0.9 fixture_flags entry has no fixture path');
            }
            $suffix = $cat === 'unrepresentable' ? '.json' : '.ktav';
            if (!is_file(self::dir() . '/' . $cat . '/' . $fixture . $suffix)) {
                throw new \RuntimeException(
                    'flagged spec 0.9 fixture file missing: ' . $cat . '/' . $fixture . $suffix
                );
            }
        }
        self::$manifest = $manifest;
        return $manifest;
    }

    /**
     * Fail-closed directory check.
     */
    public static function dir(): string
    {
        $dir = TestPaths::spec09();
        if (!is_dir($dir)) {
            throw new \RuntimeException(
                'spec 0.9 corpus missing at "' . $dir . '" — set KTAV_SPEC09_DIR'
                . ' or pin the spec submodule to a45275688557dc9ecc24a82afb491dd46e615d4d'
            );
        }
        return $dir;
    }

    /**
     * Recursive $suffix collector under $dir. For `valid` the derived
     * <stem>.canonical.ktav companions are excluded (they are
     * expectations, not counted fixtures).
     *
     * @return string[]
     */
    private static function collectFiles(string $dir, string $suffix, bool $excludeCanonical): array
    {
        $len = strlen($suffix);
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                foreach (self::collectFiles($path, $suffix, $excludeCanonical) as $f) {
                    $out[] = $f;
                }
                continue;
            }
            if (substr($entry, -$len) !== $suffix) {
                continue;
            }
            if ($excludeCanonical && substr($entry, -15) === '.canonical.ktav') {
                continue;
            }
            $out[] = $path;
        }
        return $out;
    }

    /**
     * Validate the closed category/count contract and collect fixture
     * STEMS. Category shapes differ (unrepresentable has no .ktav).
     * Any deviation throws.
     *
     * @return array<string, string[]> category => sorted .ktav paths
     *     (unrepresentable => oracle .json paths)
     */
    public static function fixtures(): array
    {
        self::manifest();
        $dir = self::dir();
        $out = [];
        $total = 0;
        foreach (self::CATEGORIES as $cat => $count) {
            $catDir = $dir . '/' . $cat;
            if (!is_dir($catDir)) {
                throw new \RuntimeException('spec 0.9 corpus category dir missing: ' . $catDir);
            }
            $suffix = $cat === 'unrepresentable' ? '.json' : '.ktav';
            // Fixtures live in NESTED per-topic subdirectories
            // (valid/arrays/…, invalid/diagnostics09/…, …) — a flat
            // scandir sees only directories and finds ZERO fixtures
            // (CI PR1: "declares 228 valid fixtures, found 0"). Walk
            // the tree.
            $files = self::collectFiles($catDir, $suffix, $cat === 'valid');
            sort($files);
            if (count($files) !== $count) {
                throw new \RuntimeException(
                    "spec 0.9 corpus declares $count $cat fixtures, found " . count($files)
                    . ' in ' . $catDir . ' — corpus and runner disagree'
                );
            }
            $out[$cat] = $files;
            $total += count($files);
        }
        if ($total !== self::TOTAL) {
            throw new \RuntimeException("spec 0.9 corpus total $total != " . self::TOTAL);
        }
        self::guardDiagnostics09($out['invalid']);
        return $out;
    }

    /**
     * diagnostics09/: exactly 30 eof_* + 42 utf8_* stems among the
     * invalid fixtures — the 72 new 0.9 diagnostics.
     *
     * @param string[] $invalidKtavPaths
     */
    public static function guardDiagnostics09(array $invalidKtavPaths): void
    {
        $dir = self::dir() . '/invalid/diagnostics09';
        if (!is_dir($dir)) {
            throw new \RuntimeException('spec 0.9 diagnostics09 dir missing: ' . $dir);
        }
        $eof = 0;
        $utf8 = 0;
        foreach ($invalidKtavPaths as $path) {
            if (strpos($path, $dir . '/') !== 0) {
                continue;
            }
            $stem = basename($path, '.ktav');
            if (strpos($stem, 'eof_') === 0) {
                ++$eof;
            } elseif (strpos($stem, 'utf8_') === 0) {
                ++$utf8;
            } else {
                throw new \RuntimeException(
                    'unexpected diagnostics09 stem (not eof_*/utf8_*): ' . $stem
                );
            }
        }
        if ($eof !== self::DIAGNOSTICS09_EOF || $utf8 !== self::DIAGNOSTICS09_UTF8) {
            throw new \RuntimeException(
                "spec 0.9 diagnostics09 counts eof=$eof utf8=$utf8, expected "
                . self::DIAGNOSTICS09_EOF . '/' . self::DIAGNOSTICS09_UTF8
            );
        }
    }

    // -----------------------------------------------------------------
    // Sibling .json oracles (the REAL schema — no sidecars, no aliases)
    // -----------------------------------------------------------------

    /** Full oracle map for a fixture path, from its sibling .json. */
    public static function oracle(string $fixturePath): array
    {
        $dot = strrpos($fixturePath, '.');
        $jsonPath = ($dot === false ? $fixturePath : substr($fixturePath, 0, $dot)) . '.json';
        if (!is_file($jsonPath)) {
            throw new \RuntimeException('spec 0.9 fixture oracle missing: ' . $jsonPath);
        }
        $oracle = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($oracle)) {
            throw new \RuntimeException('spec 0.9 fixture oracle is not an object: ' . $jsonPath);
        }
        return $oracle;
    }

    /**
     * Normalized expected location or null:
     * ['line'=>int, 'start'=>int, 'end'=>int|null, 'minimumEnd'=>int|null].
     * Validates: line >= 1; start present; EXACTLY ONE of end /
     * minimum_end; nonempty covering span (end > start; minimum_end
     * >= start). An optional end / minimum_end is NEVER ignored.
     *
     * @param array<string, mixed> $oracle
     * @return array|null
     */
    public static function expectedLocation(array $oracle): ?array
    {
        $loc = $oracle['expected_location'] ?? null;
        if ($loc === null) {
            return null;
        }
        if (!is_array($loc)) {
            throw new \RuntimeException('expected_location is not an object');
        }
        $line = $loc['line'] ?? null;
        $start = $loc['start'] ?? null;
        if (!is_int($line) || $line < 1) {
            throw new \RuntimeException('expected_location.line must be an int >= 1');
        }
        if (!is_int($start) || $start < 0) {
            throw new \RuntimeException('expected_location.start must be an int >= 0');
        }
        $hasEnd = array_key_exists('end', $loc) && $loc['end'] !== null;
        $hasMin = array_key_exists('minimum_end', $loc) && $loc['minimum_end'] !== null;
        if ($hasEnd === $hasMin) {
            throw new \RuntimeException(
                'expected_location must carry EXACTLY ONE of end / minimum_end'
            );
        }
        $out = ['line' => $line, 'start' => $start, 'end' => null, 'minimumEnd' => null];
        if ($hasEnd) {
            if (!is_int($loc['end']) || $loc['end'] <= $start) {
                throw new \RuntimeException('expected_location.end must be an int > start (nonempty covering span)');
            }
            $out['end'] = $loc['end'];
        } else {
            if (!is_int($loc['minimum_end']) || $loc['minimum_end'] < $start) {
                throw new \RuntimeException('expected_location.minimum_end must be an int >= start');
            }
            $out['minimumEnd'] = $loc['minimum_end'];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------

    /**
     * Execute one fixture through one backend and assert the oracle.
     *
     * @param bool $native false = pure
     */
    public static function run(string $fixturePath, string $category, bool $native): void
    {
        switch ($category) {
            case 'invalid':
                self::runInvalid($fixturePath, $native);
                return;
            case 'unrepresentable':
                // No .ktav input exists (oracle-only category): validate
                // the oracle shape.
                $o = self::oracle($fixturePath);
                if (!is_string($o['unrepresentable_reason'] ?? null) || $o['unrepresentable_reason'] === '') {
                    throw new \RuntimeException(
                        'unrepresentable oracle lacks unrepresentable_reason: ' . $fixturePath
                    );
                }
                return;
            case 'parseable-unrepresentable':
                $o = self::oracle($fixturePath);
                if (!is_string($o['unrepresentable_reason'] ?? null) || $o['unrepresentable_reason'] === '') {
                    throw new \RuntimeException(
                        'parseable-unrepresentable oracle lacks unrepresentable_reason: ' . $fixturePath
                    );
                }
                $raw = (string) file_get_contents($fixturePath);
                $this2 = $native
                    ? self::callLoads($raw, true)
                    : self::callLoads($raw, false);
                if ($this2 !== null) {
                    throw new \RuntimeException(
                        "parseable-unrepresentable fixture $fixturePath failed to LAX-parse: "
                        . $this2->getMessage()
                    );
                }
                return;
            case 'strict-lossy':
                $o = self::oracle($fixturePath);
                $raw = (string) file_get_contents($fixturePath);
                // Lax parse must succeed (lax_value in the oracle).
                $laxErr = self::callLoads($raw, $native);
                if ($laxErr !== null) {
                    throw new \RuntimeException(
                        "strict-lossy fixture $fixturePath failed the LAX parse: "
                        . $laxErr->getMessage()
                    );
                }
                // Strict parse must throw LossyScalar with the oracle
                // body/canonical.
                $caught = self::callLoadsStrict($raw, $native);
                if ($caught === null) {
                    throw new \RuntimeException(
                        "strict-lossy fixture $fixturePath did not throw under loadsStrict"
                    );
                }
                if ($caught->getError() !== ($o['expected_error'] ?? 'LossyScalar')) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: expected_error "
                        . ($o['expected_error'] ?? 'LossyScalar') . ', got ' . $caught->getError()
                    );
                }
                if (isset($o['body']) && $caught->getBody() !== $o['body']) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: body mismatch — expected "
                        . json_encode($o['body']) . ', got ' . json_encode($caught->getBody())
                    );
                }
                if (isset($o['canonical']) && $caught->getCanonical() !== $o['canonical']) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: canonical mismatch — expected "
                        . json_encode($o['canonical']) . ', got ' . json_encode($caught->getCanonical())
                    );
                }
                return;
            case 'valid':
                $raw = (string) file_get_contents($fixturePath);
                $err = self::callLoads($raw, $native);
                if ($err !== null) {
                    throw new \RuntimeException(
                        "valid fixture $fixturePath failed to parse: " . $err->getMessage()
                    );
                }
                return;
        }
    }

    /**
     * One invalid fixture through one backend: the error category and
     * the expected_location oracle are validated strictly.
     */
    private static function runInvalid(string $fixturePath, bool $native): void
    {
        $oracle = self::oracle($fixturePath);
        $expectedError = $oracle['expected_error'] ?? null;
        if (!is_string($expectedError) || $expectedError === '') {
            throw new \RuntimeException('invalid oracle lacks expected_error: ' . $fixturePath);
        }
        $raw = (string) file_get_contents($fixturePath); // RAW bytes
        $caught = self::callLoads($raw, $native);
        if ($caught === null) {
            throw new \RuntimeException("invalid fixture $fixturePath parsed without an error");
        }
        if ($caught->getError() !== $expectedError) {
            throw new \RuntimeException(
                "fixture $fixturePath: expected_error $expectedError, got " . $caught->getError()
            );
        }
        $loc = self::expectedLocation($oracle);
        if ($loc !== null) {
            if ($caught->getErrorLine() !== $loc['line']) {
                throw new \RuntimeException(
                    "fixture $fixturePath: expected line {$loc['line']}, got "
                    . var_export($caught->getErrorLine(), true)
                );
            }
            $span = $caught->getSpan();
            if ($loc['end'] !== null) {
                if ($span === null
                    || $span['start'] !== $loc['start']
                    || $span['end'] !== $loc['end']) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: span mismatch — expected"
                        . " [{$loc['start']}, {$loc['end']}), got " . json_encode($span)
                    );
                }
            } else {
                // minimum_end: start exact, end at least the minimum.
                if ($span === null || $span['start'] !== $loc['start']) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: span start mismatch — expected"
                        . " {$loc['start']}, got " . json_encode($span)
                    );
                }
                if ($span['end'] < $loc['minimumEnd']) {
                    throw new \RuntimeException(
                        "fixture $fixturePath: span end {$span['end']} is below"
                        . " minimum_end {$loc['minimumEnd']}"
                    );
                }
            }
        }
        // InvalidUtf8 never decodes a line_text over bytes that are not
        // valid UTF-8 (no replacement-char fantasy).
        if ($caught->getError() === 'InvalidUtf8' && $caught->getLineText() !== null) {
            throw new \RuntimeException(
                "fixture $fixturePath: InvalidUtf8 must not decode line_text"
            );
        }
    }

    /** loads() over raw bytes; returns the KtavException or null. */
    private static function callLoads(string $raw, bool $native): ?KtavException
    {
        try {
            if ($native) {
                Ktav::loads($raw); // native bytes API (FFI buffer, byte-safe)
            } else {
                PureBackend::loads($raw); // pure parser over raw bytes
            }
            return null;
        } catch (KtavException $e) {
            return $e;
        }
    }

    /** loadsStrict() over raw bytes; returns the KtavException or null. */
    private static function callLoadsStrict(string $raw, bool $native): ?KtavException
    {
        try {
            if ($native) {
                Ktav::loadsStrict($raw);
            } else {
                PureBackend::loadsStrict($raw);
            }
            return null;
        } catch (KtavException $e) {
            return $e;
        }
    }
}
