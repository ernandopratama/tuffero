<?php

namespace App\Http\Requests\Admin;

use App\Models\AccountAudienceSnapshot;
use App\Models\SocialAccountMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SocialAudienceRequest extends FormRequest
{
    /** Izin dijaga di rute (`social_metrics.manage` dan MFA). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'platform' => ['required', 'string', Rule::in(array_keys(SocialAccountMetric::PLATFORMS))],
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now('Asia/Jakarta')->format('Y-m')],
            'is_test' => ['required', 'boolean'],
        ];
        foreach (AccountAudienceSnapshot::GROUPS as $group) {
            $rules[$group] = ['nullable', 'array', 'max:12'];
            $rules["$group.*.label"] = ['required', 'string', 'max:60'];
            $rules["$group.*.percent"] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * Minimal satu kelompok terisi, dan tiap kelompok tidak boleh melewati 100 persen.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $filled = false;
            foreach (AccountAudienceSnapshot::GROUPS as $group) {
                $items = $this->input($group);
                if (! is_array($items) || $items === []) {
                    continue;
                }
                $filled = true;
                $sum = 0.0;
                foreach ($items as $item) {
                    if (is_array($item) && is_numeric($item['percent'] ?? null)) {
                        $sum += (float) $item['percent'];
                    }
                }
                if ($sum > 100.5) {
                    $validator->errors()->add($group, 'Jumlah persen kelompok ini lebih dari 100.');
                }
            }
            if (! $filled) {
                $validator->errors()->add('age', 'Isi minimal satu kelompok: usia, gender, atau lokasi.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.in' => 'Platform hanya TikTok atau Instagram.',
            'month.date_format' => 'Pilih bulan yang valid.',
            'month.before_or_equal' => 'Bulan tidak boleh di masa depan.',
            '*.label.required' => 'Isi nama kelompok.',
            '*.label.max' => 'Nama maksimal 60 karakter.',
            '*.percent.required' => 'Isi persen.',
            '*.percent.numeric' => 'Persen harus angka.',
            '*.percent.min' => 'Persen tidak boleh negatif.',
            '*.percent.max' => 'Persen maksimal 100.',
            '*.max' => 'Maksimal 12 baris.',
        ];
    }
}
