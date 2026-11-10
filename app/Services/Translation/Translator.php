<?php

namespace App\Services\Translation;

/**
 * Penerjemah Indonesia ke Inggris (D-065). Claude jadi implementasi pertama; penyedia lain cukup mengganti binding.
 */
interface Translator
{
    /**
     * @param  array<string, string>  $texts  Teks Indonesia per kunci.
     * @return array<string, string> Terjemahan per kunci yang sama.
     *
     * @throws TranslationException bila penyedia gagal atau jawabannya tidak valid.
     */
    public function translate(array $texts): array;
}
