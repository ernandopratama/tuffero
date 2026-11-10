<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('ai:check')]
#[Description('Cek kunci Claude API dan model yang dipakai tiap tugas AI. Kunci tidak pernah dicetak.')]
class AiCheck extends Command
{
    public function handle(): int
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            $this->error('ANTHROPIC_API_KEY belum diisi di .env server.');

            return self::FAILURE;
        }

        $failed = false;
        foreach (config('services.anthropic.models') as $task => $model) {
            $failed = ! $this->checkModel((string) $task, (string) $model, $key) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkModel(string $task, string $model, string $key): bool
    {
        $request = Http::baseUrl((string) config('services.anthropic.base_url'))
            ->timeout((int) config('services.anthropic.timeout'))
            ->withHeaders(['x-api-key' => $key, 'anthropic-version' => (string) config('services.anthropic.version')]);

        // Kunci yang tidak terikat satu workspace wajib menyebut workspace-nya.
        if ($workspace = config('services.anthropic.workspace_id')) {
            $request = $request->withHeaders(['anthropic-workspace-id' => $workspace]);
        }

        try {
            $response = $request->get('/v1/models/'.rawurlencode($model));
        } catch (ConnectionException) {
            $this->error("{$task}: tidak bisa menghubungi Claude API. Periksa koneksi server.");

            return false;
        }

        if ($response->successful()) {
            $this->info("{$task}: OK, model {$model} tersedia.");

            return true;
        }

        $reason = match ($response->status()) {
            401 => 'kunci ditolak (salah, dicabut, atau kedaluwarsa)',
            403 => 'kunci tidak punya izin atau workspace salah',
            404 => "model {$model} tidak ditemukan; periksa nama model di .env",
            429 => 'terlalu banyak permintaan, coba lagi sebentar',
            default => 'kesalahan HTTP '.$response->status().': '.Str::limit((string) $response->json('error.message', 'tanpa pesan'), 200),
        };
        $this->error("{$task}: GAGAL, {$reason}.");

        return false;
    }
}
