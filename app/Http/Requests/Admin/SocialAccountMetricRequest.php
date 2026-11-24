<?php

namespace App\Http\Requests\Admin;

use App\Models\SocialAccountMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocialAccountMetricRequest extends FormRequest
{
    /** Izin dijaga di rute (`social_metrics.manage` dan MFA). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tanggal dibandingkan dengan hari ini di Asia/Jakarta (tim bekerja di WIB), bukan zona aplikasi (UTC).
     * Angka milik platform lain wajib kosong.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $metric = $this->route('metric');
        $platform = $this->input('platform');
        $valid = is_string($platform) && isset(SocialAccountMetric::PLATFORMS[$platform]);
        $today = now('Asia/Jakarta')->toDateString();
        $count = ['integer', 'min:0', 'max:999999999999'];

        $rules = [
            'platform' => ['required', 'string', Rule::in(array_keys(SocialAccountMetric::PLATFORMS))],
            'period_days' => ['required', 'integer', Rule::in(SocialAccountMetric::PERIODS)],
            'period_end' => [
                'required', 'date_format:Y-m-d', 'before_or_equal:'.$today,
                Rule::unique('social_account_metrics', 'period_end')
                    ->where('platform', $platform)
                    ->where('period_days', $this->input('period_days'))
                    ->where('is_test', $this->boolean('is_test'))
                    ->ignore($metric instanceof SocialAccountMetric ? $metric->id : null),
            ],
            'measured_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:period_end'],
            'is_test' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'followers_total' => ['required', ...$count],
        ];

        $mine = $valid ? SocialAccountMetric::fieldsFor($platform) : SocialAccountMetric::allFields();
        foreach (SocialAccountMetric::allFields() as $field) {
            if ($field === 'followers_total') {
                continue;
            }
            $rules[$field] = in_array($field, $mine, true) ? ['nullable', ...$count] : ['prohibited'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'Pilih platform.',
            'platform.in' => 'Platform hanya TikTok atau Instagram.',
            'period_days.required' => 'Pilih periode.',
            'period_days.in' => 'Periode hanya 1, 7, atau 30 hari.',
            'period_end.required' => 'Isi hari terakhir periode.',
            'period_end.date_format' => 'Tanggal tidak valid.',
            'period_end.before_or_equal' => 'Hari terakhir periode tidak boleh di masa depan.',
            'period_end.unique' => 'Catatan untuk platform, periode, dan tanggal ini sudah ada. Ubah catatan itu saja.',
            'measured_on.required' => 'Isi tanggal ukur.',
            'measured_on.date_format' => 'Tanggal ukur tidak valid.',
            'measured_on.before_or_equal' => 'Tanggal ukur tidak boleh di masa depan.',
            'measured_on.after_or_equal' => 'Tanggal ukur tidak boleh sebelum hari terakhir periode.',
            'followers_total.required' => 'Isi total followers.',
            '*.prohibited' => 'Angka ini tidak ada di platform yang dipilih. Kosongkan.',
            '*.integer' => 'Isi dengan angka bulat.',
            '*.min' => 'Angka tidak boleh negatif.',
            '*.max' => 'Angka terlalu besar.',
        ];
    }
}
