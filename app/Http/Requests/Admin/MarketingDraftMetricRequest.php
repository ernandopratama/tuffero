<?php

namespace App\Http\Requests\Admin;

use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Rules\AllowedVideoUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketingDraftMetricRequest extends FormRequest
{
    /** Izin dijaga di rute (`content_metrics.manage` dan MFA). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tanggal ukur dibandingkan dengan hari ini di Asia/Jakarta (tim bekerja di WIB), bukan zona aplikasi (UTC).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var MarketingDraft $draft */
        $draft = $this->route('draft');
        $metric = $this->route('metric');
        $platform = $this->input('platform');
        $validPlatform = is_string($platform) && isset(MarketingDraftMetric::PLATFORMS[$platform]);
        $count = ['required', 'integer', 'min:0', 'max:999999999999'];
        $optional = ['nullable', 'integer', 'min:0', 'max:999999999999'];

        return [
            'platform' => ['required', 'string', Rule::in(array_keys(MarketingDraftMetric::PLATFORMS))],
            // Host tautan harus sesuai platform yang dipilih; server tidak membuka tautan ini.
            'post_url' => ['required', 'string', 'max:500', ...($validPlatform ? [new AllowedVideoUrl($platform)] : [])],
            'measured_on' => [
                'required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Jakarta')->toDateString(),
                Rule::unique('marketing_draft_metrics', 'measured_on')
                    ->where('marketing_draft_id', $draft->id)
                    ->where('platform', $platform)
                    ->ignore($metric instanceof MarketingDraftMetric ? $metric->id : null),
            ],
            'views' => $count,
            'likes' => $count,
            'shares' => $count,
            'comments' => $count,
            'saves' => $count,
            'reach' => $optional,
            'new_followers' => $optional,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'Pilih platform.',
            'platform.in' => 'Platform hanya TikTok atau Instagram.',
            'post_url.required' => 'Tempel tautan posting.',
            'post_url.max' => 'Tautan maksimal 500 karakter.',
            'measured_on.required' => 'Isi tanggal ukur.',
            'measured_on.date_format' => 'Tanggal ukur tidak valid.',
            'measured_on.before_or_equal' => 'Tanggal ukur tidak boleh di masa depan.',
            'measured_on.unique' => 'Catatan untuk platform dan tanggal ini sudah ada. Ubah catatan itu saja.',
            '*.integer' => 'Isi dengan angka bulat.',
            '*.min' => 'Angka tidak boleh negatif.',
            '*.max' => 'Angka terlalu besar.',
            '*.required' => 'Wajib diisi (isi 0 bila belum ada).',
        ];
    }
}
