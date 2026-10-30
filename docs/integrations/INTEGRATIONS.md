# Integrasi Eksternal

> Status diverifikasi 2026-10-30 dengan memeriksa `composer.json`, `package.json`, `config/`, `.env.example`, dan seluruh `app/`. **Tidak ada integrasi pembayaran, pengiriman, email transaksional, atau AI yang diimplementasikan.**

## 1. Ringkasan Status

| Layanan | Status | Bukti |
|---|---|---|
| Midtrans | `PLANNED` (D-015) | Tidak ada paket, config, route webhook, atau kode |
| Biteship | `PLANNED` (D-020); `NOT IMPLEMENTED` di kode | Provider pengiriman MVP, disetujui Ko Rifan 2026-10-30 |
| J&T, SiCepat langsung | Ditunda; dapat dievaluasi setelah MVP (D-020 menggantikan D-014 untuk MVP) | Tidak ada kode |
| Email | `NOT IMPLEMENTED` | `MAIL_MAILER=log` di `.env.example`; tidak ada Mailable/Notification |
| Storage file | Bawaan Laravel | Disk `local` dengan `serve => true` (route `/storage/{path}` bertanda tangan); tidak ada upload di aplikasi |
| AI CS / AI marketing | `PLANNED` (P1, D-016, D-017) | Tidak ada kode, vendor belum dipilih |
| Google Fonts | Aktif di frontend | `welcome.blade.php` memuat Lora dan Plus Jakarta Sans (font template, bukan font Tuffero) |
| Bunny Fonts (build) | Dikonfigurasi | `vite.config.js` `bunny('Manrope')`, `bunny('Playfair Display')` |
| `demo.awaikenthemes.com` | Aktif (hotlink) | Video hero di `pages/Home.vue` |
| YouTube | Aktif (tautan popup) | Tautan video demo di `pages/Home.vue` |
| Redis | Ekstensi PHP terpasang di Docker | Tidak dipakai (cache/queue/sesi memakai database) |

## 2. Midtrans — `PLANNED`

Sumber: D-015, PRD FR-04, MVP §2 Pembayaran, `TUFFERO/Panduan_Payment_Gateway_Tuffero.pdf`.

Ketergantungan bisnis: akun merchant dan metode pembayaran aktif (Q-016). Notulen rapat menyebut Ko Rifan akan mendaftar; status pendaftaran belum diketahui.

Persyaratan saat implementasi:

- Mulai dari sandbox; credential sandbox dan produksi terpisah di environment server.
- Gunakan produk hosted/tokenized (Snap atau Core API sesuai keputusan) agar data kartu tidak melewati server Tuffero.
- Verifikasi `signature_key` setiap notifikasi; cocokkan `order_id` dan `gross_amount` dengan database; opsional cek status via API.
- Proses notifikasi secara idempoten; tahan terhadap urutan yang tidak berurutan.
- Redirect browser bukan bukti pembayaran.
- Tangani status pending, settlement/capture, deny, cancel, expire, refund sesuai dukungan produk.
- Alur lengkap: [../architecture/PROPOSED_DOMAIN_ARCHITECTURE.md](../architecture/PROPOSED_DOMAIN_ARCHITECTURE.md) §6. Keamanan: [../security/SECURITY_REQUIREMENTS.md](../security/SECURITY_REQUIREMENTS.md) §F.

## 3. Pengiriman — Biteship untuk MVP (D-020)

| Opsi | Sumber | Catatan |
|---|---|---|
| API langsung J&T + SiCepat | D-014, Notulen, PRD v0.2 FR-05 | Keputusan tercatat. Butuh kerja sama/PKS, akses sandbox, mapping wilayah, mungkin IP whitelist |
| Biteship (agregator) | Panduan API Pengiriman (rekomendasi kandidat MVP); instruksi audit 2026-10-30 | Belum tercatat sebagai keputusan. Ketersediaan J&T/SiCepat di Biteship untuk akun Tuffero harus dikonfirmasi penyedia |
| API langsung Anteraja | Panduan API Pengiriman | Pembanding |

Keputusan 2026-10-30 (D-020): **Biteship** untuk MVP, disetujui Ko Rifan. J&T dan SiCepat dipakai melalui Biteship bila tersedia untuk akun dan gudang Malang (masih harus dikonfirmasi ke Biteship). API langsung kurir dapat dievaluasi kemudian. Tabel di atas adalah riwayat opsi yang dibandingkan.

Persyaratan apa pun providernya (Panduan API Pengiriman §8, PRD FR-05):

- `ShippingService` dengan provider yang dapat diganti.
- Ongkir hanya dari respons API yang valid; diambil ulang di server saat checkout.
- Pembuatan shipment idempoten per order; kegagalan API tidak membuat resi ganda.
- Webhook/tracking diverifikasi, idempoten, dan disimpan sebagai histori.
- Credential di environment server, tidak di browser, repository, atau log.

Keamanan webhook Biteship (tanda tangan, header, IP) **`NOT VERIFIED`**: pencarian dokumentasi resmi pada 2026-10-30 tidak menemukan informasi ini. Konfirmasi ke Biteship sebelum implementasi.

## 4. AI — `PLANNED` (P1)

- AI CS: website saja, sumber pengetahuan terkontrol, eskalasi ke admin CS manusia, tanpa kewenangan mengubah pesanan atau menjanjikan refund (FR-08).
- AI marketing: draft skrip video, riset tren dengan sumber/periode, caption, kalender, pengingat; publikasi hanya setelah persetujuan manusia (FR-09).
- Vendor, biaya, retensi percakapan, dan privasi masih terbuka (Q-011 sampai Q-014).
