<?php

declare(strict_types=1);

namespace Ktav\Tests\Pure;

use Ktav\Ktav;
use Ktav\PureBackend;
use Ktav\Tests\TestPaths;

/**
 * Shared plumbing for the dual-backend corpus runners: fail-closed
 * manifest loading, fixture discovery, oracle decoding, structural
 * equality, and uniform backend dispatch over the two facades
 * (\Ktav\Ktav native and \Ktav\PureBackend pure) which expose the
 * identical static surface.
 */
final class Corpus
{
    /** The closed category set (manifest schema_version 1). */
    public const CATEGORIES = [
        'valid' => 228,
        'invalid' => 90,
        'unrepresentable' => 5,
        'parseable-unrepresentable' => 4,
        'strict-lossy' => 13,
    ];

    public const SCHEMA_VERSION = 1;
    public const EXPECTED_FLAG_COUNT = 9;
    public const DEFINED_FLAGS = ['raw_bytes'];

    /** All backends this suite knows (KTAV_ENABLED_BACKENDS validates
     * against this closed set — unknown values must throw, never be
     * silently ignored, or a typo would silently drop coverage). */
    public const KNOWN_BACKENDS = ['native', 'pure'];

    /** @var array<string, mixed>|null */
    private static $manifest;

    /** @var string[]|null */
    private static $backends;

    // -----------------------------------------------------------------
    // Fail-closed manifest guard
    // -----------------------------------------------------------------

    /**
     * Loads and fully validates manifest.json against the closed
     * schema. ANY deviation — missing corpus, wrong schema_version,
     * unknown category, count drift, unknown flags, flag on a missing
     * fixture — throws. Never skips: a stale or truncated corpus must
     * fail CI, not vanish.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $dir = TestPaths::spec();
        if (!is_dir($dir)) {
            throw new \RuntimeException(
                'conformance corpus missing at "' . $dir . '" — set KTAV_SPEC_DIR or pin the spec submodule'
                . ' (required SHA 388982118c0d8f767bf283356ac9c9bb50fdc3f5)'
            );
        }

        $path = $dir . '/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException('corpus manifest missing: ' . $path);
        }
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('corpus manifest is not an object: ' . $path);
        }

        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'corpus manifest schema_version must be exactly ' . self::SCHEMA_VERSION
                . ', got ' . json_encode($manifest['schema_version'] ?? null)
                . ' — this runner does not guess at unrecognized manifest shapes'
            );
        }

        $categories = $manifest['categories'] ?? null;
        if (!is_array($categories)) {
            throw new \RuntimeException('corpus manifest has no categories map');
        }
        $expectedNames = array_keys(self::CATEGORIES);
        $actualNames = array_keys($categories);
        // SET equality: sort BOTH sides before the strict comparison.
        // The manifest's own key order is not normative; comparing an
        // unsorted expected list against a sorted actual list rejected
        // even the exact correct manifest (round-2 review P1).
        sort($expectedNames);
        sort($actualNames);
        if ($actualNames !== $expectedNames) {
            throw new \RuntimeException(
                'corpus category set is not the closed set {'
                . implode(', ', $expectedNames) . '}; got {' . implode(', ', $actualNames) . '}'
            );
        }
        foreach (self::CATEGORIES as $name => $count) {
            $declared = $categories[$name]['count'] ?? null;
            if ($declared !== $count) {
                throw new \RuntimeException(
                    "corpus manifest declares $name count " . json_encode($declared)
                    . ", expected $count — corpus and runner disagree"
                );
            }
        }

        $flags = $manifest['fixture_flags'] ?? null;
        if (!is_array($flags)) {
            throw new \RuntimeException('corpus manifest has no fixture_flags list');
        }
        if (count($flags) !== self::EXPECTED_FLAG_COUNT) {
            throw new \RuntimeException(
                'corpus manifest fixture_flags count ' . count($flags)
                . ' != expected ' . self::EXPECTED_FLAG_COUNT
            );
        }
        foreach ($flags as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('corpus manifest fixture_flags entry is not an object');
            }
            $unknown = array_diff((array) ($entry['flags'] ?? []), self::DEFINED_FLAGS);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    'corpus manifest fixture entry carries undefined flags: ' . implode(',', $unknown)
                );
            }
            $cat = $entry['category'] ?? null;
            if (!in_array($cat, $expectedNames, true)) {
                throw new \RuntimeException('corpus manifest fixture entry has unknown category: ' . json_encode($cat));
            }
            $fixture = $entry['fixture'] ?? null;
            if (!is_string($fixture)) {
                throw new \RuntimeException('corpus manifest fixture entry has no fixture path');
            }
            $ktav = $dir . '/' . $cat . '/' . $fixture . '.ktav';
            if (!is_file($ktav)) {
                throw new \RuntimeException('flagged fixture file missing: ' . $ktav);
            }
        }

        self::$manifest = $manifest;
        return self::$manifest;
    }

    // -----------------------------------------------------------------
    // Fixture discovery
    // -----------------------------------------------------------------

    /**
     * Fixture stems of a category, relative-name → absolute stem path
     * (no extension). valid/invalid/parseable-unrepresentable/
     * strict-lossy walk recursively for `.ktav` (`.canonical.ktav`
     * companions excluded); unrepresentable is flat `.json`.
     *
     * @return array<string, string> sorted by relative name
     */
    public static function fixtures(string $category): array
    {
        self::manifest(); // fail closed first
        $dir = TestPaths::spec() . '/' . $category;
        if (!is_dir($dir)) {
            throw new \RuntimeException('corpus category directory missing: ' . $dir);
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $items = [];
        foreach ($iter as $file) {
            $path = $file->getPathname();
            if ($category === 'unrepresentable') {
                if (substr($path, -5) !== '.json') {
                    continue;
                }
            } else {
                if (substr($path, -5) !== '.ktav' || strpos($path, '.canonical.ktav') !== false) {
                    continue;
                }
            }
            $rel = str_replace('\\', '/', substr($path, strlen($dir) + 1, -5));
            $items[$rel] = substr($path, 0, -5);
        }
        ksort($items);

        $declared = self::CATEGORIES[$category];
        if (count($items) !== $declared) {
            throw new \RuntimeException(
                "corpus category $category has " . count($items)
                . " fixtures on disk, manifest requires exactly $declared"
            );
        }
        return $items;
    }

    /** Raw bytes of a fixture's `.ktav` (never text-munged). */
    public static function source(string $stem): string
    {
        return (string) file_get_contents($stem . '.ktav');
    }

    /** Whether the `.json` oracle exists for a stem. */
    public static function hasOracle(string $stem): bool
    {
        return is_file($stem . '.json');
    }

    /**
     * Decoded `.json` oracle: assoc arrays, big ints kept as digit
     * strings (the binding's `$i` wire convention) — never generated
     * from any implementation.
     *
     * @return mixed
     */
    public static function oracle(string $stem)
    {
        return json_decode(
            (string) file_get_contents($stem . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
        );
    }

    /** Whether a valid/ fixture has a `.canonical.ktav` companion. */
    public static function hasCanonical(string $stem): bool
    {
        return is_file($stem . '.canonical.ktav');
    }

    public static function canonicalBytes(string $stem): string
    {
        return (string) file_get_contents($stem . '.canonical.ktav');
    }

    // -----------------------------------------------------------------
    // Value comparison (matches tests/ConformanceSpec conventions)
    // -----------------------------------------------------------------

    /**
     * Structural equality: floats by tolerance, arrays keyed
     * identically and recursively equal, everything else strict.
     * Ints beyond PHP_INT_MAX arrive as digit strings on both sides
     * (oracle decoded with JSON_BIGINT_AS_STRING) so `===` holds.
     *
     * @param mixed $a
     * @param mixed $b
     */
    public static function equals($a, $b): bool
    {
        if (is_float($a) && is_float($b)) {
            return $a === $b || abs($a - $b) < 1e-12;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!self::equals($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * Fixture JSON value → PHP value: the `$float` sentinel (used in
     * unrepresentable/ oracles) maps to the native PHP float.
     *
     * @param mixed $v
     * @return mixed
     */
    public static function translateFixtureValue($v)
    {
        if (is_array($v)) {
            if (count($v) === 1 && isset($v['$float']) && is_string($v['$float'])) {
                switch ($v['$float']) {
                    case 'NaN': return NAN;
                    case 'Infinity': return INF;
                    case '-Infinity': return -INF;
                }
                throw new \InvalidArgumentException('unknown $float fixture value');
            }
            $out = [];
            foreach ($v as $k => $sub) {
                $out[$k] = self::translateFixtureValue($sub);
            }
            return $out;
        }
        return $v;
    }

    // -----------------------------------------------------------------
    // Backend dispatch (identical static surfaces)
    // -----------------------------------------------------------------

    /** @return class-string */
    public static function backendClass(string $backend): string
    {
        return $backend === 'native' ? Ktav::class : PureBackend::class;
    }

    // -----------------------------------------------------------------
    // Backend enablement (KTAV_ENABLED_BACKENDS)
    // -----------------------------------------------------------------

    /**
     * Backends enabled this run, from the KTAV_ENABLED_BACKENDS env var
     * (comma-separated; default 'native,pure'). Unknown or empty values
     * THROW — a typo like 'natve' must fail CI loudly, not silently
     * drop every native cell.
     *
     * @return string[]
     */
    public static function backends(): array
    {
        if (self::$backends !== null) {
            return self::$backends;
        }
        $raw = getenv('KTAV_ENABLED_BACKENDS');
        $raw = $raw === false || trim($raw) === '' ? 'native,pure' : $raw;
        $list = [];
        foreach (explode(',', $raw) as $part) {
            $name = strtolower(trim($part));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, self::KNOWN_BACKENDS, true)) {
                throw new \RuntimeException(
                    "unknown KTAV_ENABLED_BACKENDS entry '$name' — expected a comma-separated"
                    . ' subset of {' . implode(', ', self::KNOWN_BACKENDS) . '}'
                );
            }
            if (!in_array($name, $list, true)) {
                $list[] = $name;
            }
        }
        if ($list === []) {
            throw new \RuntimeException('KTAV_ENABLED_BACKENDS enables no backend at all');
        }
        self::$backends = $list;
        return self::$backends;
    }

    /** Whether the native backend is enabled for this run. */
    public static function nativeEnabled(): bool
    {
        return in_array('native', self::backends(), true);
    }

    /** Whether the native backend can run in this process. */
    public static function nativeAvailable(): bool
    {
        TestPaths::init();
        return TestPaths::cabiBuilt();
    }

    /**
     * Runs one operation on one backend. Pure runs everywhere; native
     * callers must first confirm nativeAvailable() (the pure-only CI
     * job has no Rust toolchain and no built artifact by design).
     *
     * @param mixed ...$args
     * @return mixed
     */
    public static function call(string $backend, string $operation, ...$args)
    {
        return call_user_func([self::backendClass($backend), $operation], ...$args);
    }

    /**
     * Native cells: hard failure when native is enabled but its
     * artifact is missing (the NATIVE CI job — a missing build output
     * is a CI defect and must fail, never silently skip); a plain skip
     * only when native is not enabled at all (the pure-only CI job,
     * KTAV_ENABLED_BACKENDS=pure).
     */
    public static function requireNative(): void
    {
        if (!self::nativeEnabled()) {
            skipIf(true, 'native backend not enabled (KTAV_ENABLED_BACKENDS) — pure-only run, native cells skipped');
        }
        if (!self::nativeAvailable()) {
            throw new \RuntimeException(
                'native backend is enabled (KTAV_ENABLED_BACKENDS) but the cabi artifact is missing at "'
                . TestPaths::cabi() . '" — build it with `cargo build --release -p ktav-cabi`,'
                . ' or set KTAV_ENABLED_BACKENDS=pure; a missing native artifact fails the run, it never skips'
            );
        }
    }
}
