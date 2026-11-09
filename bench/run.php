<?php

declare(strict_types=1);

/**
 * Benchmark driver: runs every library x scenario x size case in its own PHP process,
 * kills it after --timeout seconds and appends one JSON line per case to the results file.
 *
 * Usage: php run.php [--rows=100000,1000000] [--libs=ours,spatie,maatwebsite]
 *                    [--scenarios=W1,W2,W3,R1,R2,X1] [--timeout=600] [--out=results.json]
 */

date_default_timezone_set('UTC');

$options = ['rows' => '100000,1000000', 'libs' => 'ours,spatie,maatwebsite', 'scenarios' => 'W1,W2,W3,R1,R2,X1', 'timeout' => '600', 'out' => __DIR__.'/results.json'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(rows|libs|scenarios|timeout|out)=(.+)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
    $options[$m[1]] = $m[2];
}

$sizes = array_map('intval', explode(',', $options['rows']));
$libs = explode(',', $options['libs']);
$scenarios = explode(',', $options['scenarios']);
$timeout = (int) $options['timeout'];
$out = $options['out'];
$php = [PHP_BINARY, '-d', 'memory_limit=2G'];

foreach ($libs as $lib) {
    if (! in_array($lib, ['ours', 'spatie', 'maatwebsite'], true)) {
        fwrite(STDERR, "unknown lib: $lib\n");
        exit(2);
    }
}

/** @var list<array{string, string, int}> $cases */
$cases = [];
foreach ($sizes as $rows) {
    foreach ($scenarios as $scenario) {
        if ($scenario === 'X1') {
            continue; // size-independent, appended once below
        }
        foreach ($libs as $lib) {
            $cases[] = [$lib, $scenario, $rows];
        }
    }
}
if (in_array('X1', $scenarios, true)) {
    foreach ($libs as $lib) {
        array_unshift($cases, [$lib, 'X1', 10_000]);
    }
}

function stamp(): string
{
    return gmdate('H:i:s');
}

/**
 * Runs a command with a hard wall-clock limit.
 *
 * @param list<string> $command
 * @return array{stdout: string, stderr: string, exit: int, timed_out: bool, seconds: float}
 */
function runLimited(array $command, int $timeout): array
{
    $start = hrtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $timedOut = false;

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
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
    $exit = proc_close($process);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $status['running'] ? $exit : ($status['exitcode'] >= 0 ? $status['exitcode'] : $exit), 'timed_out' => $timedOut, 'seconds' => (hrtime(true) - $start) / 1e9];
}

$total = count($cases);
$failed = 0;
$prepared = [];
echo '['.stamp().'] '.$total.' cases, timeout '.$timeout."s, results -> $out\n";

foreach ($cases as $index => [$lib, $scenario, $rows]) {
    $label = sprintf('(%d/%d) %s %s rows=%d', $index + 1, $total, $lib, $scenario, $rows);

    if (in_array($scenario, ['R1', 'R2'], true) && ! isset($prepared[$rows])) {
        echo '['.stamp()."] preparing input files for $rows rows\n";
        $gen = runLimited([...$php, __DIR__.'/generate.php', (string) $rows], 3600);
        if ($gen['exit'] !== 0) {
            fwrite(STDERR, "input generation failed: {$gen['stdout']}{$gen['stderr']}\n");
            exit(1);
        }
        $prepared[$rows] = true;
    }

    echo '['.stamp()."] START $label\n";
    $run = runLimited([...$php, __DIR__.'/run-one.php', $lib, $scenario, (string) $rows], $timeout);

    $result = null;
    foreach (array_reverse(preg_split('/\R/', $run['stdout']) ?: []) as $line) {
        if (str_starts_with($line, '{')) {
            $result = json_decode($line, true);
            break;
        }
    }

    if ($run['timed_out']) {
        $result = ['lib' => $lib, 'scenario' => $scenario, 'rows' => $rows, 'ok' => false, 'error' => "timeout after {$timeout}s", 'seconds' => null];
    } elseif (! is_array($result)) {
        $tail = trim(substr($run['stdout'].$run['stderr'], -300));
        $result = ['lib' => $lib, 'scenario' => $scenario, 'rows' => $rows, 'ok' => false, 'error' => 'crashed (exit '.$run['exit'].'): '.$tail, 'seconds' => null];
    }

    $result['wall_seconds'] = round($run['seconds'], 1);
    $result['at'] = gmdate('c');
    $result['php'] = PHP_VERSION;
    file_put_contents($out, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);

    if (! $result['ok']) {
        $failed++;
    }
    echo '['.stamp().'] DONE  '.$label.' '.($result['ok'] ? 'ok' : 'FAILED ('.substr((string) ($result['error'] ?? 'mismatches'), 0, 100).')')
        .' seconds='.($result['seconds'] ?? 'n/a').' peak_mb='.($result['peak_mb'] ?? 'n/a')."\n";
}

echo '['.stamp()."] finished: $total cases, $failed not ok\n";
