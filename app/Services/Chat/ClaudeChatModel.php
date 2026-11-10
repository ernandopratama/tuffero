<?php

namespace App\Services\Chat;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;

/**
 * Jawaban chat lewat Claude API dengan paket resmi Anthropic (D-061, D-081). Kunci hanya dari .env server.
 * Isi percakapan tidak ditulis ke log; pesan galat hanya menyebut jenis kegagalan.
 */
class ClaudeChatModel implements ChatModel
{
    /** Termasuk token berpikir model; jawaban dibatasi ±80 kata lewat prompt, jadi ini longgar. */
    private const MAX_TOKENS = 1024;

    public function reply(string $system, array $messages): ?string
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            throw new ChatModelException('ANTHROPIC_API_KEY belum diisi.');
        }

        $client = new Client(
            apiKey: $key,
            baseUrl: (string) config('services.anthropic.base_url'),
            // Pelanggan menunggu di chat: satu kali coba ulang saja, lalu widget menawarkan WhatsApp.
            requestOptions: ['timeout' => (float) config('services.anthropic.timeout'), 'maxRetries' => 1],
        );

        $workspace = config('services.anthropic.workspace_id');

        try {
            $message = $client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: array_map(fn (array $message) => ['role' => $message['role'], 'content' => $message['text']], $messages),
                model: (string) config('services.anthropic.models.chat'),
                // Aturan, katalog, dan FAQ sama untuk semua pelanggan: di-cache agar permintaan berikutnya lebih murah.
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                // Pertanyaan toko sederhana; usaha rendah = jawaban cepat dan murah.
                outputConfig: ['effort' => 'low'],
                workspaceID: is_string($workspace) && $workspace !== '' ? $workspace : null,
            );
        } catch (APIException $exception) {
            throw new ChatModelException('Claude API gagal: '.class_basename($exception), previous: $exception);
        }

        // Ditolak oleh pengaman model: widget menjawab dengan tawaran WhatsApp.
        if ($message->stopReason === 'refusal') {
            return null;
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text) === '' ? null : $text;
    }
}
