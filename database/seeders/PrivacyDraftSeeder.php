<?php

namespace Database\Seeders;

use App\Models\TermsSection;
use Illuminate\Database\Seeder;

/**
 * Opsional (D-139): menyalin teks Kebijakan Privasi bawaan di lang/id dan lang/en menjadi butir awal di dashboard, sebagai DRAF
 * (tidak tayang), supaya Admin Toko tinggal menyunting lalu menayangkannya. Selama belum ada butir tayang, toko tetap memakai teks
 * bawaan itu. Tidak dipanggil DatabaseSeeder dan tidak menaikkan versi. Hanya jalan bila belum ada butir Privasi sama sekali.
 * Jalankan dengan php artisan db:seed --class=PrivacyDraftSeeder.
 */
class PrivacyDraftSeeder extends Seeder
{
    public function run(): void
    {
        if (TermsSection::where('type', TermsSection::PRIVACY)->exists()) {
            $this->command?->info('Sudah ada butir Privasi; dilewati.');

            return;
        }

        $items = fn (string $locale) => collect(trans('store.pages.contact.legal.docs', [], $locale))->firstWhere('id', 'privasi')['items'] ?? [];
        $id = $items('id');
        $en = $items('en');

        foreach ($id as $i => [$titleId, $bodyId]) {
            TermsSection::create([
                'type' => TermsSection::PRIVACY,
                'sort_order' => ($i + 1) * 10,
                'title_id' => $titleId,
                'title_en' => $en[$i][0] ?? $titleId,
                'body_id' => $bodyId,
                'body_en' => $en[$i][1] ?? $bodyId,
                'is_published' => false,
            ]);
        }

        $this->command?->info(count($id).' butir Privasi disalin sebagai draf.');
    }
}
