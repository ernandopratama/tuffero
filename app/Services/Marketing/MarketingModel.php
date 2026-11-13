<?php

namespace App\Services\Marketing;

/**
 * Model bahasa untuk draf konten AI Marketing (D-079). Claude jadi implementasi pertama; tes memakai pengganti palsu.
 */
interface MarketingModel
{
    /**
     * @param  bool  $search  izinkan pencarian web (riset tren) agar klaim membawa sumber
     * @return array{text: string, sources: list<array{title: string, url: string}>}
     *
     * @throws MarketingModelException bila layanan model tidak bisa dihubungi, menolak, atau tidak memberi jawaban yang bisa dipakai
     */
    public function generate(string $system, string $prompt, bool $search): array;
}
