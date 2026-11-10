<?php

declare(strict_types=1);

/**
 * Benchmark driver: runs every library x scenario x size case in its own PHP process,
 * kills it after --timeout seconds and appends one JSON line per case to the results file.
 *
 * Usage: php run.php [--rows=100000,1000000] [--libs=none,ours,spatie,maatwebsite]
 *                    [--scenarios=W1,W2,W3,R1,R2,X1] [--timeout=600] [--repeat=1]
 *                    [--var-dir=<path>] [--out=results.json]
 *
 * With --repeat=N every case runs N times and the library order is rotated on each repetition
 * (ours, spatie, maatwebsite; then spatie, maatwebsite, ours; ...) so no library always runs first
 * or last. The pseudo-library "none" (generator only, W scenarios) is the floor and is not rotated.
 */
date_default_timezone_set('UTC');

$options = [
    'rows' => '100000,1000000',
    'libs' => 'none,ours,spatie,maatwebsite',
    'scenarios' => 'W1,W2,W3,R1,R2,X1',
    'timeout' => '600',
    'repeat' => '1',
    'var-dir' => __DIR__.'/var',
    'out' => __DIR__.'/results.json',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(rows|libs|scenarios|timeout|repeat|var-dir|out)=(.+)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
    $options[$m[1]] = $m[2];
}

$sizes = array_map('intval', explode(',', $options['rows']));
$allLibs = explode(',', $options['libs']);
$scenarios = explode(',', $options['scenarios']);
$timeout = (int) $options['timeout'];
$repeat = max(1, (int) $options['repeat']);
$out = $options['out'];
$php = [PHP_BINARY, '-d', 'memory_limit=2G'];

foreach ($allLibs as $lib) {
    if (! in_array($lib, ['none', 'ours', 'spatie', 'maatwebsite'], true)) {
        fwrite(STDERR, "unknown lib: $lib\n");
        exit(2);
    }
}

is_dir($options['var-dir']) || mkdir($options['var-dir'], 0777, true);
$varDir = realpath($options['var-dir']);
$env = array_merge(getenv(), ['BENCH_VAR_DIR' => $varDir]);

/** Best-effort description of the storage under the var directory. */
function storageInfo(string $dir): string
{
    $df = trim((string) shell_exec('df -P '.escapeshellarg($dir).' 2>/dev/null | tail -1'));
    $parts = preg_split('/\s+/', $df) ?: [];
    $device = $parts[0] ?? 'unknown';
    $mount = $parts[5] ?? '';
    $info = "$dir on $device (mounted at $mount)";
    if (PHP_OS_FAMILY === 'Darwin' && str_starts_with($device, '/dev/')) {
        $details = [];
        foreach (explode("\n", (string) shell_exec('diskutil info '.escapeshellarg($device).' 2>/dev/null')) as $line) {
            if (preg_match('/^\s*(Device Location|Solid State|Protocol|Media Name|File System Personality):\s*(.+)$/', $line, $m) === 1) {
                $details[] = $m[1].' '.trim($m[2]);
            }
        }
        $info .= $details === [] ? '' : ' ['.implode(', ', $details).']';
    }

    return $info;
}

$storage = storageInfo($varDir);

/** @var list<array{string, string, int, int}> $cases lib, scenario, rows, repetition */
$cases = [];
if (in_array('X1', $scenarios, true)) {
    foreach (array_diff($allLibs, ['none']) as $lib) {
        $cases[] = [$lib, 'X1', 10_000, 1];
    }
}
$real = array_values(array_diff($allLibs, ['none']));
foreach ($sizes as $rows) {
    foreach ($scenarios as $scenario) {
        if ($scenario === 'X1') {
            continue; // size-independent, runs once above
        }
        for ($rep = 1; $rep <= $repeat; $rep++) {
            $order = $real;
            for ($shift = ($rep - 1) % max(1, count($real)); $shift > 0; $shift--) {
                $order[] = array_shift($order);
            }
            if (in_array('none', $allLibs, true) && $scenario[0] === 'W') {
                array_unshift($order, 'none');
            }
            foreach ($order as $lib) {
                $cases[] = [$lib, $scenario, $rows, $rep];
            }
        }
    }
}

function stamp(): string
{
    return gmdate('H:i:s');
}

/**
 * Runs a command with a hard wall-clock limit.
 *
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 * @return array{stdout: string, stderr: string, exit: int|null, signal: int|null, timed_out: bool, seconds: float}
 */
function runLimited(array $command, int $timeout, array $env): array
{
    $start = hrtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $timedOut = false;
    $exit = null;
    $signal = null;

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        // The exit status is only valid in the first status call that reports the process as finished;
        // proc_close() afterwards returns -1, so it is captured here and never taken from proc_close().
        $status = proc_get_status($process);
        if (! $status['running']) {
            $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
            $signal = $status['signaled'] ? $status['termsig'] : null;
            break;
        }
        if ((hrtime(true) - $start) / 1e9 > $timeout) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(50_000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'signal' => $signal, 'timed_out' => $timedOut, 'seconds' => (hrtime(true) - $start) / 1e9];
}

$total = count($cases);
$failed = 0;
$prepared = [];
echo '['.stamp().'] '.$total.' cases, repeat '.$repeat.', timeout '.$timeout."s, var-dir $storage, results -> $out\n";

foreach ($cases as $index => [$lib, $scenario, $rows, $rep]) {
    $label = sprintf('(%d/%d) %s %s rows=%d run=%d/%d', $index + 1, $total, $lib, $scenario, $rows, $rep, $scenario === 'X1' ? 1 : $repeat);

    if (in_array($scenario, ['R1', 'R2'], true) && ! isset($prepared[$rows])) {
        echo '['.stamp()."] preparing input files for $rows rows\n";
        $gen = runLimited([...$php, __DIR__.'/generate.php', (string) $rows], 3600, $env);
        if ($gen['exit'] !== 0) {
            fwrite(STDERR, "input generation failed: {$gen['stdout']}{$gen['stderr']}\n");
            exit(1);
        }
        $prepared[$rows] = true;
    }

    echo '['.stamp()."] START $label\n";
    $run = runLimited([...$php, __DIR__.'/run-one.php', $lib, $scenario, (string) $rows], $timeout, $env);

    $result = null;
    foreach (array_reverse(preg_split('/\R/', $run['stdout']) ?: []) as $line) {
        if (str_starts_with($line, '{')) {
            $result = json_decode($line, true);
            break;
        }
    }

    $base = ['lib' => $lib, 'scenario' => $scenario, 'rows' => $rows];
    if ($run['timed_out']) {
        $result = $base + ['ok' => false, 'error' => "timeout after {$timeout}s", 'seconds' => null];
    } elseif (! is_array($result)) {
        $tail = trim(substr($run['stdout'].$run['stderr'], -400));
        $how = $run['signal'] !== null ? 'killed by signal '.$run['signal'] : 'exit '.($run['exit'] ?? 'unknown');
        $result = $base + ['ok' => false, 'error' => "crashed ($how)".($tail === '' ? ', no output on stdout or stderr' : ": $tail"), 'seconds' => null];
    }

    $result['run'] = $rep;
    $result['wall_seconds'] = round($run['seconds'], 1);
    $result['at'] = gmdate('c');
    $result['php'] = PHP_VERSION;
    $result['var_dir'] = $storage;
    file_put_contents($out, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);

    if (! $result['ok']) {
        $failed++;
    }
    echo '['.stamp().'] DONE  '.$label.' '.($result['ok'] ? 'ok' : 'FAILED ('.substr((string) ($result['error'] ?? 'mismatches'), 0, 160).')')
        .' seconds='.($result['seconds'] ?? 'n/a').' peak_mb='.($result['peak_mb'] ?? 'n/a')."\n";
}

echo '['.stamp()."] finished: $total cases, $failed not ok\n";
