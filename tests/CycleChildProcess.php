<?php

namespace Ktav\Tests;

final class CycleChildProcess
{
    private static function drain($pipe, &$captured, &$lastMarker): void
    {
        $chunk = stream_get_contents($pipe);
        if ($chunk === false || $chunk === '') { return; }
        echo $chunk; flush(); $captured = substr($captured . $chunk, -65536);
        if (preg_match_all('/CASE [^\r\n]+/', $chunk, $matches) && $matches[0]) { $lastMarker = end($matches[0]); }
    }

    public static function run(string $backend, ?string $library = null): void
    {
        $root = dirname(__DIR__); $env = getenv();
        if ($backend === 'native') {
            if ($library === null || !is_file($library)) { throw new \RuntimeException('Native cycle child artifact missing'); }
            $env['KTAV_LIB_PATH'] = $library;
        } else {
            $guardPath = $root . '/target/.ktav-cycle-native-must-not-exist';
            if (file_exists($guardPath)) { throw new \RuntimeException('Pure child native-artifact guard path exists: ' . $guardPath); }
            $env['KTAV_LIB_PATH'] = $guardPath;
        }
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=20', $root . '/tests/ValueCycleChild.php', $backend];
        $pipes = []; $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        if (!is_resource($process)) { throw new \RuntimeException('Unable to start cycle child for ' . $backend); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $captured = ''; $lastMarker = '(none)'; $deadline = (hrtime(true) / 1e9) + 30.0; $exitCode = null; $timedOut = false; $terminateDeadline = null; $forceKillSent = false; $forceKillSucceeded = false; $forcedStopDeadline = null; $stopped = false;
        try {
            while (true) {
                self::drain($pipes[1], $captured, $lastMarker); self::drain($pipes[2], $captured, $lastMarker);
                $status = proc_get_status($process);
                if (!$status['running']) { if ($status['exitcode'] >= 0) { $exitCode = $status['exitcode']; } break; }
                $now = (hrtime(true) / 1e9);
                if (!$timedOut && $now >= $deadline) { $timedOut = true; proc_terminate($process); $terminateDeadline = $now + 2.0; }
                elseif ($timedOut && !$forceKillSent && $now >= $terminateDeadline) { $forceKillSent = true; $forceKillSucceeded = proc_terminate($process, 9); $forcedStopDeadline = $now + 1.0; }
                elseif ($forceKillSent && $now >= $forcedStopDeadline) { break; }
                usleep(20000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                if (!$forceKillSent) { $forceKillSucceeded = proc_terminate($process, 9); $forceKillSent = true; }
                $stopDeadline = (hrtime(true) / 1e9) + 1.0;
                do {
                    self::drain($pipes[1], $captured, $lastMarker); self::drain($pipes[2], $captured, $lastMarker);
                    $status = proc_get_status($process);
                    if (!$status['running']) { $stopped = true; if ($status['exitcode'] >= 0) { $exitCode = $status['exitcode']; } break; }
                    usleep(20000);
                } while ((hrtime(true) / 1e9) < $stopDeadline);
            } else { $stopped = true; if ($status['exitcode'] >= 0) { $exitCode = $status['exitcode']; } }
            self::drain($pipes[1], $captured, $lastMarker); self::drain($pipes[2], $captured, $lastMarker);
            fclose($pipes[1]); fclose($pipes[2]);
            if ($stopped) { $closeCode = proc_close($process); if ($exitCode === null || $exitCode < 0) { $exitCode = $closeCode; } }
        }
        if (!$stopped) { throw new \RuntimeException('Unable to confirm child stop backend=' . $backend . ' last-marker=' . $lastMarker . ' force-kill-requested=' . ($forceKillSucceeded ? 'yes' : 'no') . '; OS process termination is not guaranteed'); }
        if ($timedOut) { throw new \RuntimeException('Cycle child wall timeout backend=' . $backend . ' last-marker=' . $lastMarker . ' output-tail=' . $captured); }
        $expected = 'COMPLETE backend=' . $backend . ' cycles=12 controls=' . ($backend === 'pure' ? '30' : '15');
        if ($exitCode !== 0 || strpos($captured, $expected) === false) { throw new \RuntimeException('Cycle child failed backend=' . $backend . ' exit=' . $exitCode . ' last-marker=' . $lastMarker . ' expected=' . $expected); }
    }
}
