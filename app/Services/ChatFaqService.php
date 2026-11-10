<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChatFaq;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FAQ chat (D-060). Tidak ada hapus: yang tak dipakai dinonaktifkan. Setiap perubahan dicatat di audit log.
 */
class ChatFaqService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): ChatFaq
    {
        return DB::transaction(function () use ($data, $actor) {
            $data = $this->normalize($data);
            $faq = ChatFaq::create([
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'keywords' => $data['keywords'],
                'answer_id' => $data['answer_id'],
                'answer_en' => $data['answer_en'] ?? null,
                'sort_order' => $data['sort_order'] ?? (int) ChatFaq::max('sort_order') + 1,
                'is_active' => $data['is_active'] ?? true,
                'is_suggested' => $data['is_suggested'] ?? false,
            ])->refresh();

            AuditLog::record('faq.created', $faq, ['title' => $faq->title], $actor->id);

            return $faq;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ChatFaq $faq, array $data, User $actor): ChatFaq
    {
        return DB::transaction(function () use ($faq, $data, $actor) {
            AuditLog::saveWithAudit($faq, $this->normalize($data), 'faq.updated', $actor);

            return $faq->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (isset($data['keywords'])) {
            $data['keywords'] = implode(', ', ChatFaq::parseKeywords($data['keywords']));
        }
        foreach (['title_en', 'answer_en'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? $data[$field] : null;
            }
        }

        return $data;
    }
}
