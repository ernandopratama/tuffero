<?php

use App\Models\MarketingDraft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Draf lama dari Hermes/AI berisi tanda Markdown (**, #); dibersihkan sekali, sama seperti saat simpan.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketing_drafts')->orderBy('id')->each(function ($row) {
            $title = MarketingDraft::plain($row->title);
            $body = MarketingDraft::plain($row->body);

            if ($title !== $row->title || $body !== $row->body) {
                DB::table('marketing_drafts')->where('id', $row->id)->update(['title' => $title, 'body' => $body]);
            }
        });
    }

    public function down(): void
    {
        // Tanda Markdown yang dibuang tidak bisa dipulihkan.
    }
};
