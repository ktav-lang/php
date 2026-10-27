<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Ktav\Ktav;
use Ktav\PureBackend;

function benchmarkMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float)$values[$middle] : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
}

function boundedBenchmarkInt($value, string $label, int $default, int $minimum): int
{
    if ($value === false || $value === '') { return $default; }
    if (!is_string($value) || !preg_match('/^[0-9]{1,5}$/', $value)) {
        fwrite(STDERR, "$label must be an integer between $minimum and 10000\n"); exit(2);
    }
    $number = (int)$value;
    if ($number < $minimum || $number > 10000) {
        fwrite(STDERR, "$label must be an integer between $minimum and 10000\n"); exit(2);
    }
    return $number;
}

function checkResult(string $backend, int $fixture, string $actual, string $literal, array $semantic): void
{
    if ($actual !== $literal) { throw new RuntimeException("$backend fixture $fixture literal bytes differ"); }
    if ($backend === 'native') { $parsed = Ktav::loads($actual); }
    else { $parsed = PureBackend::loads($actual); }
    if ($parsed !== $semantic) {
        throw new RuntimeException(sprintf(
            "%s fixture %d semantic oracle differs: actual=%s (%s), expected=%s (%s)",
            $backend,
            $fixture,
            var_export($parsed, true),
            gettype($parsed),
            var_export($semantic, true),
            gettype($semantic)
        ));
    }
}

$warmups = boundedBenchmarkInt(getenv('BENCH_WARMUPS'), 'warmups', 5, 0);
$repetitions = boundedBenchmarkInt(getenv('BENCH_REPETITIONS'), 'repetitions', 30, 1);
$series = boundedBenchmarkInt(getenv('BENCH_SERIES'), 'series', 7, 1);
if (!extension_loaded('ffi')) { fwrite(STDERR, "FFI extension required\n"); exit(2); }

$cases = [
    ['insert', "cfg: {old: +01}\n", ['cfg', 'new'], 2, "cfg: {old: +01, new: 2}\n", ['cfg' => ['old' => '+01', 'new' => 2]]],
    ['remove', "obj: {a: 1}\r\n", ['obj', 'a'], null, "obj: {}\r\n", ['obj' => []]],
    ['rename', "cfg: {\"a.b\": 1, c: 2}\n", ['cfg', 'a.b'], 'd', "cfg: {d: 1, c: 2}\n", ['cfg' => ['d' => 1, 'c' => 2]]],
    ['insert', "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1,}]\nlast: 2\r", ['cfg', '0', 'b'], 2, "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1, b: 2,}]\nlast: 2\r", ['first' => 1, 'cfg' => [['a' => 1, 'b' => 2]], 'last' => 2]],
];
$operations = [
    'insert' => static function ($backend, $src, $path, $value) { return $backend::insertValueSegments($src, $path, $value); },
    'remove' => static function ($backend, $src, $path) { return $backend::removeValueSegments($src, $path); },
    'rename' => static function ($backend, $src, $path, $value) { return $backend::renameKeySegments($src, $path, $value); },
];
$backends = ['native' => Ktav::class, 'pure' => PureBackend::class];
$order = [];
for ($s = 0; $s < $series; ++$s) { $order[] = $s % 2 ? ['pure', 'native'] : ['native', 'pure']; }
$raw = [];
foreach ($cases as $index => $case) {
    [$op, $source, $path, $value, $literal, $semantic] = $case;
    $samplesByBackend = ['native' => [], 'pure' => []];
    foreach ($order as $seriesIndex => $backendOrder) {
        foreach ($backendOrder as $name) {
            $backend = $backends[$name];
            $invoke = static function () use ($operations, $op, $backend, $source, $path, $value) { return $operations[$op]($backend, $source, $path, $value); };
            checkResult($name, $index, $invoke(), $literal, $semantic);
            for ($i = 0; $i < $warmups; ++$i) { $invoke(); }
            $start = hrtime(true);
            $lastOutput = null;
            for ($j = 0; $j < $repetitions; ++$j) { $lastOutput = $invoke(); }
            $samplesByBackend[$name][] = hrtime(true) - $start;
            // Validate the actual final output from this timed batch, outside timing.
            checkResult($name, $index, $lastOutput, $literal, $semantic);
        }
    }
    foreach ($samplesByBackend as $name => $samples) {
        $means = array_map(static function ($n) use ($repetitions) { return $n / $repetitions; }, $samples);
        $raw[] = ['backend' => $name, 'operation' => $case[0], 'fixture' => $index, 'raw_total_mutation_ns' => $samples, 'raw_mean_mutation_ns' => $means, 'median_mutation_ns' => benchmarkMedian($means), 'warmups' => $warmups, 'repetitions_per_sample' => $repetitions, 'series' => $series];
    }
}
echo json_encode(['php_version' => PHP_VERSION, 'os' => PHP_OS . ' ' . php_uname('r'), 'method' => 'mutation-only timed regions; alternating backend order by series; literal bytes and independent semantic oracle checked before and after measurement; descriptive only, no speedup claim', 'raw_results' => $raw], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
