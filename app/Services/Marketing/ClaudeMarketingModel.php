<?php

namespace App\Services\Marketing;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;

/**
 * Draf konten lewat Claude API dengan paket resmi Anthropic (D-061, D-079). Kunci hanya dari .env server.
 * Isi prompt dan jawaban tidak ditulis ke log; pesan galat hanya menyebut jenis kegagalan.
 */
class ClaudeMarketingModel implements MarketingModel
{
    /** Termasuk token berpikir model dan hasil pencarian yang dirangkum. */
    private const MAX_TOKENS = 4096;

    /** Batas pencarian web per permintaan, supaya biaya riset tren terkendali. */
    private const MAX_SEARCHES = 5;

    public function generate(string $system, string $prompt, bool $search): array
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            throw new MarketingModelException('ANTHROPIC_API_KEY belum diisi.');
        }

        $client = new Client(
            apiKey: $key,
            baseUrl: (string) config('services.anthropic.base_url'),
            requestOptions: ['timeout' => (float) config('services.anthropic.marketing_timeout'), 'maxRetries' => 1],
        );

        $workspace = config('services.anthropic.workspace_id');

        try {
            $message = $client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: (string) config('services.anthropic.models.marketing'),
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                tools: $search ? [['name' => 'web_search', 'type' => 'web_search_20250305', 'maxUses' => self::MAX_SEARCHES]] : null,
                workspaceID: is_string($workspace) && $workspace !== '' ? $workspace : null,
            );
        } catch (APIException $exception) {
            throw new MarketingModelException('Claude API gagal: '.class_basename($exception), previous: $exception);
        }

        if ($message->stopReason === 'refusal') {
            throw new MarketingModelException('Claude menolak membuat draf ini. Ubah brief lalu coba lagi.');
        }

        $text = '';
        $sources = [];
        $searched = [];
        foreach ($message->content as $block) {
            // Hasil pencarian mentah: hanya cadangan sumber bila model menandai kutipan sendiri di dalam teks.
            if ($block->type === 'web_search_tool_result' && is_array($block->content)) {
                foreach ($block->content as $result) {
                    $url = $result->url ?? null;
                    if (is_string($url) && str_starts_with($url, 'https://')) {
                        $searched[$url] = ['title' => (string) ($result->title ?? $url), 'url' => $url];
                    }
                }
            }
            if ($block->type !== 'text') {
                continue;
            }
            $text .= $block->text;
            foreach ($block->citations ?? [] as $citation) {
                $url = $citation->url ?? null;
                if (is_string($url) && str_starts_with($url, 'https://')) {
                    $sources[$url] = ['title' => (string) ($citation->title ?? $url), 'url' => $url];
                }
            }
        }

        // Penanda kutipan buatan model (<cite index="...">) bukan bagian draf; tampilkan teksnya saja.
        $hadCiteTags = str_contains($text, '<cite');
        $text = (string) preg_replace('/<\/?cite[^>]*>/i', '', $text);
        if ($sources === [] && $hadCiteTags) {
            $sources = array_slice($searched, 0, 5);
        }

        if (trim($text) === '') {
            throw new MarketingModelException('Claude tidak memberi jawaban yang bisa dipakai.');
        }

        return ['text' => $text, 'sources' => array_values($sources)];
    }
}
