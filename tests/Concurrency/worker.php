<?php

/**
 * Proses pembalap untuk tes konkurensi: php tests/Concurrency/worker.php <direktori> <indeks>
 *
 * Satu worker hidup selama satu skenario dan mengikuti banyak putaran. Tiap putaran r: menunggu plan_r, menyiapkan aksinya,
 * menulis armed_r_<indeks>, lalu menunggu berkas go_r (penghalang). Setelah go_r muncul semua worker memulai serentak dan
 * menulis result_r_<indeks>. Berkas stop mengakhiri worker. Windows tidak punya pcntl, jadi sinkronisasi memakai berkas.
 */

use Tests\Concurrency\Actions;
use Tests\Concurrency\Boot;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $dir, $index] = $argv + [null, null, null];

if (! $dir || $index === null) {
    fwrite(STDERR, "Pemakaian: worker.php <direktori> <indeks>\n");
    exit(2);
}

$wait = function (string $path, float $timeout = 180.0) use ($dir): bool {
    $deadline = microtime(true) + $timeout;
    while (! file_exists("{$dir}/{$path}")) {
        if (file_exists("{$dir}/stop") || microtime(true) > $deadline) {
            return false;
        }
        usleep(100);
        clearstatcache(true);
    }

    return true;
};

$write = function (string $name, array $data) use ($dir): void {
    file_put_contents("{$dir}/{$name}.tmp", json_encode($data, JSON_THROW_ON_ERROR));
    rename("{$dir}/{$name}.tmp", "{$dir}/{$name}");
};

Boot::app();
$write("up_{$index}", ['pid' => getmypid()]);

for ($round = 1; ; $round++) {
    if (! $wait("plan_{$round}")) {
        exit(0);
    }

    $job = json_decode(file_get_contents("{$dir}/plan_{$round}"), true, flags: JSON_THROW_ON_ERROR)[$index] ?? null;
    $fire = null;
    $result = null;

    try {
        $fire = $job ? Actions::prepare($job['action'], $job['args']) : null;
    } catch (Throwable $e) {
        $result = ['type' => 'error', 'class' => $e::class, 'message' => 'prepare: '.$e->getMessage()];
    }

    $write("armed_{$round}_{$index}", []);

    if (! $wait("go_{$round}")) {
        exit(0);
    }

    $started = microtime(true);
    $result ??= $fire ? Actions::run($fire) : ['type' => 'idle'];
    $write("result_{$round}_{$index}", $result + ['started_at' => $started, 'duration_ms' => round((microtime(true) - $started) * 1000, 1)]);
}
