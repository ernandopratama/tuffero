<?php

namespace App\Http\Requests\Admin;

use App\Models\NewsItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NewsItemRequest extends FormRequest
{
    /** Izin dijaga di rute (`news.manage`). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(NewsItem::KINDS))],
            'title' => ['required', 'string', 'max:150'],
            'title_en' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:300'],
            'body_en' => ['nullable', 'string', 'max:300'],
            'cta_label' => ['nullable', 'string', 'max:40', 'required_with:cta_url'],
            'cta_label_en' => ['nullable', 'string', 'max:40'],
            'cta_url' => ['nullable', 'string', 'max:255', 'required_with:cta_label', $this->safeLink(...)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Tautan tombol hanya ke halaman toko sendiri ("/promo/...") atau https. Skema lain (javascript:, data:, http:)
     * dan "//host" (alamat tanpa skema ke situs lain) ditolak supaya popup tidak bisa membelokkan pengunjung.
     */
    private function safeLink(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $internal = str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_contains($value, '\\') && ! preg_match('/[\x00-\x20]/', $value);
        $external = str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false;

        if (! $internal && ! $external) {
            $fail('Tautan harus diawali "/" (halaman toko) atau "https://".');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.in' => 'Jenis tidak dikenal.',
            'title.required' => 'Judul wajib diisi.',
            'body.required' => 'Teks singkat wajib diisi.',
            'body.max' => 'Teks singkat maksimal 300 karakter.',
            'cta_label.required_with' => 'Isi teks tombol bila ada tautan.',
            'cta_url.required_with' => 'Isi tautan bila ada teks tombol.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
        ];
    }
}
