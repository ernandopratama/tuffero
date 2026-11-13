<?php

namespace Database\Seeders;

use App\Models\ReplyTemplate;
use Illuminate\Database\Seeder;

/**
 * Template balasan awal untuk CS. Hanya mengisi bila tabel masih kosong, jadi suntingan admin tidak tertimpa saat deploy.
 * Isinya sengaja tidak menyebut nominal atau menjanjikan hasil; CS melengkapi bagian dalam tanda kurung sebelum mengirim.
 */
class ReplyTemplateSeeder extends Seeder
{
    /** @return list<array{title: string, scope: string, body: string}> */
    public function templates(): array
    {
        return [
            [
                'title' => 'Info status dan resi', 'scope' => 'order',
                'body' => "Halo {nama}, pesanan {no_pesanan} saat ini {status}. Nomor resi: {resi}.\nBila ada yang ingin ditanyakan, silakan balas pesan ini. Terima kasih sudah berbelanja di Tuffero.",
            ],
            [
                'title' => 'Minta video unboxing', 'scope' => 'refund',
                'body' => 'Halo {nama}, kami menerima pengajuan {no_refund} untuk pesanan {no_pesanan}. Agar bisa kami periksa, mohon kirim video unboxing paket: nomor resi terlihat di awal video, paket masih tersegel saat mulai direkam, isi dan kondisi produk terlihat jelas, dan video utuh tanpa dipotong. Terima kasih.',
            ],
            [
                'title' => 'Konfirmasi retur produk utuh', 'scope' => 'refund',
                'body' => 'Halo {nama}, terkait pengajuan {no_refund} untuk pesanan {no_pesanan}. Retur produk utuh hanya bisa diproses bila paket diterima paling lama 3 hari kalender yang lalu, produk masih utuh dan belum dibuka, dan Anda mengirim video unboxing. Mohon konfirmasi bahwa syarat ini terpenuhi.',
            ],
            [
                'title' => 'Refund disetujui, minta data transfer', 'scope' => 'refund',
                'body' => 'Halo {nama}, pengajuan {no_refund} untuk pesanan {no_pesanan} sudah disetujui. Untuk pengembalian dana, mohon kirim nama pemilik dan data rekening atas nama pemesan. Setelah dana kami transfer, kami kabari kembali.',
            ],
            [
                'title' => 'Pembatalan belum bisa disetujui', 'scope' => 'cancellation',
                'body' => 'Halo {nama}, mohon maaf, pengajuan pembatalan {no_pengajuan} untuk pesanan {no_pesanan} belum bisa kami setujui. Alasannya: (isi alasan). Terima kasih atas pengertiannya.',
            ],
        ];
    }

    public function run(): void
    {
        if (ReplyTemplate::query()->exists()) {
            return;
        }

        foreach ($this->templates() as $i => $template) {
            ReplyTemplate::create([...$template, 'sort_order' => $i + 1]);
        }
    }
}
