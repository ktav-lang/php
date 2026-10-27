<?php

declare(strict_types=1);

use Ktav\PureBackend;

function sameRunCheck(string $label, int $fixture, string $actual, string $literal, array $semantic): void
{
    if ($actual !== $literal) { throw new RuntimeException("$label fixture $fixture literal bytes differ"); }
    $parsed = PureBackend::loads($actual);
    if ($parsed !== $semantic) {
        throw new RuntimeException(sprintf('%s fixture %d semantic oracle differs: actual=%s expected=%s', $label, $fixture, var_export($parsed, true), var_export($semantic, true)));
    }
}

function sameRunInt(string $name, int $default, int $minimum): int
{
    $value = getenv($name);
    if ($value === false || $value === '') { return $default; }
    if (!preg_match('/^[0-9]{1,5}$/', $value) || (int)$value < $minimum || (int)$value > 10000) {
        throw new RuntimeException("$name must be between $minimum and 10000");
    }
    return (int)$value;
}

function sameRunBenchmark(string $srcRoot, string $label, int $warmups, int $repetitions): array
{
    spl_autoload_register(static function (string $class) use ($srcRoot): void {
        $prefix = 'Ktav\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
        $file = $srcRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (!is_file($file)) { throw new RuntimeException("Class file missing from selected source tree: $class"); }
        require $file;
    }, true, true);
    if (!class_exists('Ktav\\PureBackend')) { throw new RuntimeException("PureBackend missing from selected source tree: $srcRoot"); }
    $cases = [
        ['insert', "cfg: {old: +01}\n", ['cfg', 'new'], 2, "cfg: {old: +01, new: 2}\n", ['cfg' => ['old' => '+01', 'new' => 2]]],
        ['remove', "obj: {a: 1}\r\n", ['obj', 'a'], null, "obj: {}\r\n", ['obj' => []]],
        ['rename', "cfg: {\"a.b\": 1, c: 2}\n", ['cfg', 'a.b'], 'd', "cfg: {d: 1, c: 2}\n", ['cfg' => ['d' => 1, 'c' => 2]]],
        ['insert', "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1,}]\nlast: 2\r", ['cfg', '0', 'b'], 2, "\xEF\xBB\xBFfirst: 1\r\ncfg: [{a: 1, b: 2,}]\nlast: 2\r", ['first' => 1, 'cfg' => [['a' => 1, 'b' => 2]], 'last' => 2]],
    ];
    $ops = [
        'insert' => static function ($src, $path, $value) { return PureBackend::insertValueSegments($src, $path, $value); },
        'remove' => static function ($src, $path) { return PureBackend::removeValueSegments($src, $path); },
        'rename' => static function ($src, $path, $value) { return PureBackend::renameKeySegments($src, $path, $value); },
    ];
    $results = [];
    foreach ($cases as $index => $case) {
        [$op, $source, $path, $value, $literal, $semantic] = $case;
        $invoke = static function () use ($ops, $op, $source, $path, $value) { return $ops[$op]($source, $path, $value); };
        sameRunCheck($label, $index, $invoke(), $literal, $semantic);
        for ($i = 0; $i < $warmups; ++$i) { $invoke(); }
        $start = hrtime(true);
        $last = null;
        for ($i = 0; $i < $repetitions; ++$i) { $last = $invoke(); }
        $elapsed = hrtime(true) - $start;
        sameRunCheck($label, $index, $last, $literal, $semantic);
        $results[] = ['backend' => $label, 'operation' => $op, 'fixture' => $index, 'raw_total_mutation_ns' => $elapsed, 'raw_mean_mutation_ns' => $elapsed / $repetitions, 'warmups' => $warmups, 'repetitions' => $repetitions];
    }
    return $results;
}

if (getenv('BENCH_CHILD') === '1') {
    try {
        $src = getenv('BENCH_SRC');
        if (!is_string($src) || !is_dir($src) || !in_array(getenv('BENCH_LABEL'), ['base', 'candidate'], true)) { throw new RuntimeException('Invalid child source/label'); }
        $warmups = sameRunInt('BENCH_WARMUPS', 5, 0);
        $repetitions = sameRunInt('BENCH_REPETITIONS', 30, 1);
        $series = sameRunInt('BENCH_SERIES', 7, 1);
        if ($series !== 1) { throw new RuntimeException('Child BENCH_SERIES must be 1'); }
        echo json_encode(['raw_results' => sameRunBenchmark($src, getenv('BENCH_LABEL'), $warmups, $repetitions)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    exit(0);
}

function runPureChild(string $src, string $label, string $baseRef, string $candidateRef, int $warmups, int $repetitions): array
{
    $env = array_merge(getenv(), ['BENCH_CHILD' => '1', 'BENCH_SRC' => $src, 'BENCH_LABEL' => $label, 'BENCH_WARMUPS' => (string)$warmups, 'BENCH_REPETITIONS' => (string)$repetitions, 'BENCH_SERIES' => '1', 'BENCH_BASE_REF' => $baseRef, 'BENCH_CANDIDATE_REF' => $candidateRef]);
    $stdoutFile = tmpfile();
    $stderrFile = tmpfile();
    if ($stdoutFile === false || $stderrFile === false) { throw new RuntimeException('Unable to create child output streams'); }
    $proc = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe', 'r'], 1 => $stdoutFile, 2 => $stderrFile], $pipes, null, $env);
    if (!is_resource($proc)) { fclose($stdoutFile); fclose($stderrFile); throw new RuntimeException('Unable to start child'); }
    fclose($pipes[0]);
    $status = proc_close($proc);
    rewind($stdoutFile);
    rewind($stderrFile);
    $stdout = stream_get_contents($stdoutFile);
    $stderr = stream_get_contents($stderrFile);
    fclose($stdoutFile);
    fclose($stderrFile);
    if ($status !== 0) { throw new RuntimeException("$label child failed: $stderr"); }
    $data = json_decode($stdout, true);
    if (!is_array($data) || !isset($data['raw_results']) || !is_array($data['raw_results'])) { throw new RuntimeException("Invalid $label child output"); }
    return $data['raw_results'];
}

try {
    $base = getenv('BENCH_BASE_SRC');
    $candidate = getenv('BENCH_CANDIDATE_SRC');
    $baseRef = getenv('BENCH_BASE_REF');
    $candidateRef = getenv('BENCH_CANDIDATE_REF');
    if (!is_string($base) || !is_dir($base) || !is_string($candidate) || !is_dir($candidate)
        || $baseRef !== '3665dcfd6afb30b69ac5afb0ed8b4ce147df6325'
        || !is_string($candidateRef) || !preg_match('/^[0-9a-f]{40}$/', $candidateRef)) { throw new RuntimeException('Invalid/missing refs or source trees'); }
    $warmups = sameRunInt('BENCH_WARMUPS', 5, 0);
    $repetitions = sameRunInt('BENCH_REPETITIONS', 30, 1);
    $series = sameRunInt('BENCH_SERIES', 7, 1);
    $runs = [];
    for ($round = 0; $round < $series; ++$round) {
        foreach (($round % 2 === 0 ? ['base', 'candidate'] : ['candidate', 'base']) as $which) {
            $runs[] = ['ref' => $which === 'base' ? $baseRef : $candidateRef, 'round' => $round, 'results' => runPureChild($which === 'base' ? $base : $candidate, $which, $baseRef, $candidateRef, $warmups, $repetitions)];
        }
    }
    echo json_encode(['php_version' => PHP_VERSION, 'base_ref' => $baseRef, 'candidate_ref' => $candidateRef, 'method' => 'one child process per source per round; alternating order; strict literal and semantic oracle; no timing gate', 'raw_runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
