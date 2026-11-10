<?php

namespace Database\Seeders;

use App\Models\ChatFaq;
use Illuminate\Database\Seeder;

/**
 * FAQ awal chat. Hanya fakta yang sudah tampil di situs atau sudah diputuskan: jam operasional (OQ-21), pengiriman,
 * cara memesan, hampers, voucher (D-066, D-069), dan jawaban "belum tahu lalu hubungi tim" untuk alergen dan izin edar (D-060).
 * Pembayaran, refund, dan pembatalan sengaja tidak diisi: kebijakannya belum diputuskan.
 * Dijalankan saat deploy (db:seed), jadi hanya mengisi bila tabel masih kosong dan tidak menimpa suntingan admin.
 */
class ChatFaqSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public function faqs(): array
    {
        return [
            [
                'title' => 'Jam operasional', 'title_en' => 'Opening hours', 'is_suggested' => true,
                'keywords' => 'jam buka, jam operasional, jam kerja, buka jam, hari libur, kapan buka, tutup jam, opening hours, open hours, what time, are you open',
                'answer_id' => 'Jam operasional kami Senin sampai Sabtu, pukul 09.00 sampai 16.00 WIB. Hari Minggu kami tutup.',
                'answer_en' => 'Our opening hours are Monday to Saturday, 09.00 to 16.00 WIB. We are closed on Sundays.',
            ],
            [
                'title' => 'Pengiriman', 'title_en' => 'Shipping', 'is_suggested' => true,
                'keywords' => 'pengiriman, dikirim, kurir, ekspedisi, sicepat, j&t, kirim dari mana, shipping, courier, delivery',
                'answer_id' => 'Pesanan dikirim dari Malang lewat J&T atau SiCepat. Ongkir dihitung saat checkout setelah alamat diisi. Untuk memperkirakan ongkir lebih dulu, pilih Cek Ongkir di menu chat.',
                'answer_en' => 'Orders ship from Malang via J&T or SiCepat. Shipping is calculated at checkout once your address is filled in. To estimate it first, choose Shipping Rates in the chat menu.',
            ],
            [
                'title' => 'Cara memesan', 'title_en' => 'How to order', 'is_suggested' => true,
                'keywords' => 'cara pesan, cara memesan, cara order, cara beli, cara belanja, tanpa akun, pesan online, how to order, how do i order, order online, without account, guest checkout',
                'answer_id' => 'Pilih produk di halaman Produk, masukkan ke keranjang, lalu selesaikan di checkout. Anda bisa memesan tanpa membuat akun.',
                'answer_en' => 'Choose a product on the Products page, add it to your cart, then complete checkout. No account is needed.',
            ],
            [
                'title' => 'Daya tahan produk', 'title_en' => 'Shelf life', 'is_suggested' => true,
                'keywords' => 'umur simpan, daya tahan, tahan berapa lama, bertahan berapa lama, kedaluwarsa, kadaluarsa, expired, shelf life, how long does it last',
                'answer_id' => 'Umur simpan berbeda untuk tiap produk. Lihat bagian Spesifikasi di halaman produk.',
                'answer_en' => 'Shelf life differs for each product. See the Specifications section on the product page.',
            ],
            [
                'title' => 'Hampers dan paket hadiah', 'title_en' => 'Hampers and gift sets', 'is_suggested' => false,
                'keywords' => 'hampers, hamper, parcel, paket hadiah, hantaran, gift set, gift box',
                'answer_id' => 'Kami menyediakan paket Nastar, Macaron, dan Pia yang siap dijadikan hadiah. Lihat halaman Layanan, bagian Hampers. Untuk kebutuhan khusus, hubungi tim kami lewat WhatsApp.',
                'answer_en' => 'We offer Nastar, Macaron, and Pia sets that are ready to give as gifts. See the Services page, Hampers section. For special requests, contact our team on WhatsApp.',
            ],
            [
                'title' => 'Kode voucher', 'title_en' => 'Voucher codes', 'is_suggested' => false,
                'keywords' => 'voucher, kupon, kode promo, kode diskon, promo code, coupon, discount code',
                'answer_id' => 'Masukkan kode voucher di halaman checkout, pada baris Voucher. Satu pesanan dapat memakai satu voucher. Syarat seperti minimal belanja tertera pada tiap voucher.',
                'answer_en' => 'Enter your voucher code at checkout, in the Voucher row. One voucher can be used per order. Conditions such as minimum spend are shown on each voucher.',
            ],
            [
                'title' => 'Alergi, halal, dan izin edar', 'title_en' => 'Allergens, halal, and permits', 'is_suggested' => true,
                'keywords' => 'alergi, alergen, allergy, allergen, kacang, gluten, laktosa, lactose, halal, bpom, izin edar',
                'answer_id' => 'Informasi alergen, status halal, dan izin edar belum tersedia di website. Demi keamanan Anda, mohon hubungi tim kami lewat WhatsApp sebelum memesan.',
                'answer_en' => 'Allergen, halal, and permit information is not yet available on the website. For your safety, please contact our team on WhatsApp before ordering.',
            ],
            [
                'title' => 'Kontak dan media sosial', 'title_en' => 'Contact and social media', 'is_suggested' => false,
                'keywords' => 'kontak, hubungi, nomor wa, nomor whatsapp, whatsapp, instagram, tiktok, media sosial, contact, social media, phone number',
                'answer_id' => 'Hubungi tim kami lewat WhatsApp (tombol di bawah), atau ikuti kami di Instagram @tuffero.bakery dan TikTok @tuffero.patisserie. Tim kami melayani Senin sampai Sabtu, pukul 09.00 sampai 16.00 WIB.',
                'answer_en' => 'Contact our team on WhatsApp (button below), or follow us on Instagram @tuffero.bakery and TikTok @tuffero.patisserie. Our team is available Monday to Saturday, 09.00 to 16.00 WIB.',
            ],
            [
                'title' => 'Pesanan dalam jumlah besar', 'title_en' => 'Large orders', 'is_suggested' => false,
                'keywords' => 'partai besar, jumlah besar, pesan banyak, borongan, grosir, reseller, hampers kantor, hampers perusahaan, bulk order, wholesale, large order',
                'answer_id' => 'Untuk pesanan dalam jumlah besar, hubungi tim kami lewat WhatsApp (tombol di bawah) agar kebutuhan Anda dibantu langsung.',
                'answer_en' => 'For large orders, please contact our team on WhatsApp (button below) so we can help you directly.',
            ],
            [
                'title' => 'Keluhan atau barang bermasalah', 'title_en' => 'Complaints or problem with an item', 'is_suggested' => false,
                'keywords' => 'rusak, pecah, hancur, salah kirim, salah barang, keluhan, komplain, kecewa, tidak sesuai, damaged, broken, wrong item, complaint',
                'answer_id' => 'Mohon maaf atas ketidaknyamanannya. Hubungi tim kami lewat WhatsApp (tombol di bawah) dengan nomor pesanan dan foto kondisi barang agar dapat kami bantu.',
                'answer_en' => 'We are sorry for the inconvenience. Please contact our team on WhatsApp (button below) with your order number and a photo of the item so we can help.',
            ],
        ];
    }

    public function run(): void
    {
        if (ChatFaq::exists()) {
            return;
        }

        foreach ($this->faqs() as $order => $faq) {
            ChatFaq::create([...$faq, 'sort_order' => $order + 1]);
        }
    }
}
