<?php

namespace App\Services\Chat;

/**
 * Model bahasa untuk jawaban chat toko (D-081). Claude jadi implementasi pertama; tes memakai pengganti palsu.
 */
interface ChatModel
{
    /**
     * @param  list<array{role: 'user'|'assistant', text: string}>  $messages  percakapan, dimulai pesan pelanggan
     * @return string|null jawaban teks, atau null bila model tidak memberi jawaban yang bisa dipakai (ditolak, kosong)
     *
     * @throws ChatModelException bila layanan model tidak bisa dihubungi atau menolak permintaan
     */
    public function reply(string $system, array $messages): ?string;
}
