# Tuffero E-commerce — Decision Log

- **Versi:** 0.3
- **Tanggal pembaruan:** 30 Oktober 2026
- **Tujuan:** Mencatat keputusan, asumsi sementara, pertanyaan terbuka, dan PIC konfirmasi.

## 1. Keputusan yang Sudah Disampaikan

| ID | Area | Keputusan | Status / dampak |
|---|---|---|---|
| D-001 | Framework | Menggunakan Laravel | Disepakati; fondasi aplikasi. |
| D-002 | Domain | `tuffero.com` dikelola di Jagoan Hosting | Disepakati; DNS dikelola di provider domain. |
| D-003 | Hosting | Rencana VPS Niagahoster | Direncanakan; paket, OS, dan resource belum ditentukan. |
| D-004 | Infrastruktur | Ernando menangani VPS, domain, SSL, backup, deployment, version control | Disepakati; perlu runbook, monitoring, restore, dan rollback. |
| D-005 | Bisnis | B2C | Disepakati; reseller/grosir bukan MVP. |
| D-006 | Katalog | Harga, foto, daftar produk, SKU, varian, deskripsi | Disepakati; berat dan dimensi juga perlu untuk ongkir. |
| D-007 | Checkout | Diusahakan tanpa login; email, nomor HP, alamat | Arah disepakati; cara aman melihat status order perlu ditentukan. |
| D-008 | Gudang aktif | Satu gudang di Malang saat peluncuran | Disepakati. |
| D-009 | Arsitektur gudang | Backend siap untuk multi-gudang, termasuk rencana Jakarta | Disepakati; gunakan model gudang sejak awal. |
| D-010 | Pemenuhan | Satu pesanan hanya dikirim dari satu gudang | Disepakati; tidak boleh auto-split order. |
| D-011 | Promosi/voucher | Siapkan backend dan frontend; fitur belum aktif dapat bertanda “Coming Soon” | Disepakati; rule harus server-side. |
| D-012 | Diskon kuantitas | Belum menjadi fokus; “Coming Soon” | Disepakati; tidak aktif pada peluncuran awal. |
| D-013 | Diskon ongkir | Toko kemungkinan menanggung, tetapi perlu konfirmasi | Belum final; jangan mengunci aturan subsidi. |
| D-014 | Kurir | Integrasi API langsung J&T dan SiCepat | Arah disepakati; bergantung kerja sama, akses, dokumentasi, dan testing. |
| D-015 | Pembayaran | Midtrans | Disepakati; aktivasi akun dan metode pembayaran menjadi ketergantungan. |
| D-016 | AI CS | Website saja, dengan eskalasi ke admin CS manusia | Arah disepakati; perlu trigger eskalasi dan knowledge base. |
| D-017 | AI marketing | Skrip video, niche/tren, caption, kalender dan pengingat konten | Kebutuhan disepakati; kanal, sumber, frekuensi, dan PIC perlu ditetapkan. |
| D-018 | Target rilis | Sebelum Ramadan 2027 | Target awal; tanggal pasti belum ditetapkan. |
| D-019 | Desain | Dark Chocolate `#2B1B14`, Champagne Gold `#C6A15B`, Warm Ivory `#F6F0E5`, Roasted Brown `#6D4C3D`, White `#FFFFFF`, Border `#E3D5C0`; Manrope dan Playfair Display Italic sebagai aksen | Mengikuti `Tuffero_Design_System.txt`; logo asli tidak diubah. |

## 1a. Keputusan Tambahan (30 Oktober 2026)

| ID | Area | Keputusan | Status / dampak |
|---|---|---|---|
| D-020 | Kurir | Pengiriman MVP memakai **Biteship** sebagai agregator. J&T dan SiCepat dipakai melalui Biteship sejauh layanannya tersedia untuk akun dan gudang Malang. Integrasi API langsung kurir dapat dievaluasi kemudian. Kode pengiriman memakai `ShippingService` dengan provider yang dapat diganti. | Disepakati; disetujui Ko Rifan, dicatat Ernando. **Menggantikan D-014 untuk MVP.** Ketersediaan layanan J&T/SiCepat di Biteship dari Malang masih harus dikonfirmasi ke Biteship. |
| D-021 | Frontend | Arsitektur frontend **hybrid**: halaman toko (beranda, katalog, detail produk, keranjang, checkout, status pesanan) dirender server dengan Blade + Tailwind CSS (+ Alpine.js bila perlu); area admin `/admin` memakai Vue 3 SPA (vue-router, Pinia) dengan API `/api/v1`. Backend, model, dan aturan bisnis tetap satu. | Disepakati Ernando (technical lead). Alasan: SEO, pratinjau link Instagram/WhatsApp, dan kecepatan tampil di HP untuk toko; interaktivitas untuk admin; tanpa server Node tambahan. Migrasi kode (Home ke Blade, SPA dibatasi ke `/admin`) adalah tugas terpisah. |

## 2. Asumsi Sementara (Belum Keputusan Final)

| ID | Asumsi | Tindak lanjut |
|---|---|---|
| A-001 | Stok website belum dianggap tersinkron otomatis dengan Shopee | Konfirmasi apakah stok sama dan apakah sinkronisasi dibutuhkan. |
| A-002 | Pelanggan dapat memilih layanan kurir yang tersedia | Konfirmasi setelah akses API dan proses operasional diketahui. |
| A-003 | Stok mungkin perlu direservasi saat pembayaran pending | Setujui durasi serta kapan reservasi dibuat/dilepas. |
| A-004 | Harga, promo, ongkir, dan total harus divalidasi server-side | Terapkan sebagai aturan teknis. |
| A-005 | AI menghasilkan draft yang disetujui manusia, bukan publikasi otomatis | Konfirmasi jika kebijakan berubah. |

## 3. Pertanyaan Terbuka dan PIC yang Disarankan

| ID | Pertanyaan | PIC | Status |
|---|---|---|---|
| Q-001 | Apakah stok website dan Shopee sama? Apakah perlu sinkronisasi otomatis? | Ko Rifan / operasional | Terbuka |
| Q-002 | Apakah harga website sama dengan Shopee? | Ko Rifan | Terbuka |
| Q-003 | Pelanggan memilih kurir atau sistem memilih layanan? | Ko Rifan / operasional | Terbuka |
| Q-004 | Aturan diskon ongkir: nominal/persentase, batas, area, minimum transaksi, periode, dan penanggung biaya | Ko Rifan | Terbuka |
| Q-005 | Aturan voucher: kombinasi, kuota, masa berlaku, minimum transaksi, cakupan produk, batas per pelanggan | Ko Rifan | Terbuka |
| Q-006 | Durasi reservasi stok saat pembayaran pending | Ko Rifan / developer | Terbuka |
| Q-007 | Kebijakan pembatalan, retur, refund, barang rusak/salah kirim | Ko Rifan / operasional | Terbuka |
| Q-008 | Cara aman guest melihat status pesanan | Product/technical lead | Terbuka |
| Q-009 | Role admin dan batas kewenangan | Ko Rifan / technical lead | Terbuka |
| Q-010 | Tanggal peluncuran, UAT owner, dan KPI | Ko Rifan | Terbuka |
| Q-011 | Knowledge base, trigger eskalasi, jam layanan, retensi percakapan AI CS | Ko Rifan / CS | Terbuka |
| Q-012 | Kanal, sumber tren, frekuensi, PIC approval, kanal pengingat AI marketing | Ko Rifan / marketing | Terbuka |
| Q-013 | Anggaran hosting, gateway, API, dan AI | Ko Rifan | Terbuka |
| Q-014 | Privasi dan retensi data pelanggan/percakapan AI | Ko Rifan / technical lead | Terbuka |
| Q-015 | Detail VPS: paket, OS, resource, akses, deployment, monitoring | Ernando | Terbuka |
| Q-016 | Status akun Midtrans, dokumen, metode pembayaran yang diaktifkan | Ko Rifan | Terbuka |
| Q-017 | Status kerja sama dan akses API J&T serta SiCepat | Ko Rifan / developer | Terbuka |

## 4. Riwayat Perubahan

### v0.3 — 30 Oktober 2026
- Menambahkan D-020: Biteship untuk MVP, menggantikan D-014 (API langsung J&T dan SiCepat) untuk MVP. Q-017 berubah menjadi konfirmasi ketersediaan J&T/SiCepat melalui Biteship.
- Menambahkan D-021: frontend hybrid (toko Blade, admin Vue).
- Baris D-014 dan Q-017 di atas tidak dihapus agar riwayat keputusan tetap terbaca.

### v0.2 — 26 Oktober 2026
- Memasukkan hasil rapat dan klarifikasi terbaru.
- Menetapkan satu gudang aktif Malang dengan arsitektur multi-gudang.
- Menegaskan satu pesanan hanya boleh dipenuhi dari satu gudang.
- Memasukkan guest checkout, Midtrans, J&T/SiCepat, promosi/voucher, dan kebutuhan AI.
- Menetapkan Ernando sebagai pengelola infrastruktur.
- Menandai diskon ongkir, sinkronisasi Shopee, dan aturan operasional tertentu sebagai terbuka.

### v0.1
Draft awal dari discovery sebelum rapat; digantikan untuk perencanaan oleh v0.2.
