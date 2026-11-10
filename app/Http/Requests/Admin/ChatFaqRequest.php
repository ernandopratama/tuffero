<?php

namespace App\Http\Requests\Admin;

use App\Models\ChatFaq;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ChatFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:120'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'keywords' => [$required, 'string', 'max:500', $this->keywordRule()],
            'answer_id' => [$required, 'string', 'max:600'],
            'answer_en' => ['sometimes', 'nullable', 'string', 'max:600'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_suggested' => ['sometimes', 'boolean'],
        ];
    }

    /** Kata kunci pendek ("di", "ke") akan cocok dengan hampir semua kalimat, jadi ditolak. */
    private function keywordRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $words = ChatFaq::parseKeywords((string) $value);

            if (! $words || count($words) > ChatFaq::MAX_KEYWORDS) {
                $fail('Isi 1 sampai '.ChatFaq::MAX_KEYWORDS.' kata kunci, dipisah koma.');

                return;
            }

            foreach ($words as $word) {
                $length = mb_strlen($word);
                if ($length < ChatFaq::MIN_KEYWORD_LENGTH || $length > ChatFaq::MAX_KEYWORD_LENGTH) {
                    $fail("Kata kunci \"{$word}\" harus {$this->lengthRange()} karakter.");

                    return;
                }
            }
        };
    }

    private function lengthRange(): string
    {
        return ChatFaq::MIN_KEYWORD_LENGTH.' sampai '.ChatFaq::MAX_KEYWORD_LENGTH;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.max' => 'Judul maksimal 120 karakter.',
            'title_en.max' => 'Judul maksimal 120 karakter.',
            'keywords.required' => 'Kata kunci wajib diisi.',
            'keywords.max' => 'Kata kunci maksimal 500 karakter.',
            'answer_id.required' => 'Jawaban Bahasa Indonesia wajib diisi.',
            'answer_id.max' => 'Jawaban maksimal 600 karakter.',
            'answer_en.max' => 'Jawaban maksimal 600 karakter.',
            'sort_order.integer' => 'Urutan harus berupa angka bulat.',
        ];
    }
}
