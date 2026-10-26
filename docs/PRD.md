# Tuffero E-commerce — Product Requirements Document (PRD)

- **Versi:** 0.2
- **Status:** Draft untuk review pemilik bisnis
- **Tanggal:** 26 Oktober 2026
- **Target rilis:** Sebelum Ramadan 2027; tanggal pasti belum ditentukan
- **Pemilik bisnis:** Ko Rifan / tim Tuffero
- **Technical lead dan pengelola infrastruktur:** Ernando

> Dokumen ini memisahkan keputusan yang telah disampaikan dari asumsi dan pertanyaan terbuka. Hal yang belum dikonfirmasi tidak boleh dianggap sebagai keputusan final.

## 1. Ringkasan Produk
Tuffero membangun website e-commerce B2C milik brand menggunakan Laravel. Pelanggan diusahakan dapat checkout tanpa membuat akun dengan mengisi email, nomor HP, nama penerima, dan alamat. MVP berfokus pada katalog, checkout, pembayaran Midtrans, pengelolaan pesanan, stok, dan pengiriman dari satu gudang aktif di Malang. Struktur backend harus mendukung gudang tambahan, termasuk Jakarta di masa depan, tetapi setiap pesanan hanya boleh dipenuhi dari satu gudang.

## 2. Tujuan
1. Menyediakan kanal penjualan resmi Tuffero di luar marketplace.
2. Mempermudah pembelian melalui guest checkout.
3. Memproses pembayaran melalui Midtrans setelah akun dan metode pembayaran aktif.
4. Menjaga konsistensi stok, pembayaran, pesanan, dan pengiriman.
5. Mendukung integrasi langsung J&T dan SiCepat, bergantung akses API dan kerja sama.
6. Menyiapkan fondasi promosi/voucher dan modul AI bertahap.
7. Mendukung deployment dan operasi teknis yang dikelola internal.

## 3. Pengguna
- **Pembeli B2C:** melihat produk dan checkout tanpa wajib membuat akun.
- **Admin operasional:** mengelola produk, SKU/varian, stok, pesanan, pembayaran, pengiriman, dan promosi.
- **Admin CS:** menerima eskalasi dari AI Customer Service.
- **Admin marketing:** meninjau draft dan kalender konten yang dibantu AI.

Reseller/grosir tidak termasuk MVP kecuali diputuskan kemudian.

## 4. Cakupan Produk
### P0 — wajib untuk peluncuran
- Landing page, katalog, detail produk, foto, deskripsi, harga, SKU, dan varian.
- Keranjang dan guest checkout.
- Pengumpulan email, nomor HP, nama penerima, dan alamat lengkap.
- Perhitungan harga, promo, ongkir, dan total akhir di server.
- Pembuatan pesanan dengan snapshot harga dan item saat transaksi.
- Midtrans: sandbox, verifikasi notifikasi server, idempotensi, dan pencatatan status.
- Inventori multi-gudang secara struktural; gudang Malang satu-satunya yang aktif saat peluncuran.
- Satu pesanan hanya dapat dipenuhi dari satu gudang; seluruh item harus tersedia dari gudang yang sama.
- Integrasi langsung J&T dan SiCepat setelah akses API dan persyaratan kerja sama tersedia.
- Admin untuk produk, varian, stok, pesanan, pembayaran, dan pengiriman.
- Fondasi backend promosi/voucher; frontend dapat menampilkan “Coming Soon” untuk fitur yang belum aktif.
- Keamanan dasar, logging, backup, responsif, automated tests, dan dokumentasi deployment.

### P1 — setelah transaksi inti stabil
- AI Customer Service website dengan eskalasi ke admin manusia.
- AI marketing untuk skrip video, riset niche/tren, caption, kalender konten, dan pengingat.
- Diskon ongkir setelah aturan subsidi toko dikonfirmasi.
- Gudang Jakarta setelah lokasi dan operasi siap.
- Sinkronisasi stok Shopee jika diputuskan sebagai kebutuhan.

### Di luar MVP saat ini
- Login wajib bagi pelanggan.
- Aplikasi mobile native, ERP, akuntansi, HR, sistem produksi/resep, multi-vendor.
- Pembagian satu pesanan ke beberapa gudang/pengiriman.
- Publikasi konten AI tanpa persetujuan manusia.
- Sinkronisasi otomatis dengan Shopee sebelum kebutuhan dan akses teknis dipastikan.
- Diskon kuantitas aktif pada peluncuran awal; tampilkan “Coming Soon”.
- Penjualan reseller/grosir.

## 5. Kebutuhan Fungsional
### FR-01 — Katalog
Admin mengelola produk dan varian. Data minimal: nama, deskripsi, foto, harga, SKU, varian, status publikasi, berat dan dimensi paket jika tersedia. Data awal berasal dari katalog Tuffero di Shopee, tetapi daftar final perlu diserahkan oleh tim Tuffero.

### FR-02 — Guest checkout
Pelanggan dapat berbelanja tanpa akun. Checkout meminta nama penerima, email, nomor HP, dan alamat. Semua harga, stok, promo, ongkir, dan total dihitung ulang di server; data dari browser tidak dipercaya sebagai nilai final. Metode aman untuk melihat status pesanan tanpa akun masih perlu diputuskan.

### FR-03 — Gudang dan stok
- Gudang Malang aktif pada MVP; database mendukung banyak gudang.
- Setiap pesanan dialokasikan ke tepat satu gudang.
- Semua item pesanan harus tersedia dari gudang yang sama.
- Jika tidak ada satu gudang yang dapat memenuhi seluruh keranjang, checkout ditahan dengan pesan yang jelas; sistem tidak boleh otomatis membagi pesanan.
- Perubahan stok harus aman terhadap request bersamaan dan mencegah stok minus.
- Rekomendasi teknis: reservasi stok sementara selama pembayaran pending, lalu lepaskan ketika gagal/kedaluwarsa/dibatalkan sesuai aturan. Durasi reservasi perlu disetujui.
- Sinkronisasi stok dengan Shopee belum diputuskan; perlu prosedur operasional sementara.

### FR-04 — Midtrans
Gunakan sandbox terlebih dahulu. Verifikasi notifikasi dari server, proses secara idempoten, dan jangan mengandalkan redirect browser sebagai bukti pembayaran. Simpan credential di environment server, bukan repository. Produksi menunggu akun, metode pembayaran, dan kredensial aktif.

### FR-05 — Pengiriman
Integrasi langsung J&T dan SiCepat bergantung pada kerja sama, dokumentasi, credential, dan pengujian API. Sistem dirancang untuk cek tarif/layanan, pembuatan resi, dan tracking jika endpoint tersedia. Simpan snapshot alamat, kurir, layanan, ongkir, resi, dan status. Kegagalan API tidak boleh membuat pengiriman ganda. Pilihan kurir oleh pelanggan versus sistem masih perlu dikonfirmasi.

### FR-06 — Promosi dan voucher
Siapkan struktur backend dan area frontend. Fitur yang belum aktif diberi label “Coming Soon”. Fokus awal adalah promosi/voucher; diskon kuantitas belum aktif. Rule kombinasi promo, periode, kuota, minimum transaksi, cakupan SKU, dan batas penggunaan perlu disepakati. Validasi harus dilakukan di server. Diskon ongkir sementara diperkirakan ditanggung toko, tetapi belum final.

### FR-07 — Admin
Admin memiliki autentikasi dan otorisasi terpisah. Cakupan pengelolaan: produk, varian, stok, pesanan, status pembayaran, pengiriman, dan promosi. Perubahan penting dicatat. Role final perlu disepakati.

### FR-08 — AI Customer Service
Kanal awal hanya website. AI menjawab dari sumber yang disetujui seperti katalog dan kebijakan toko. Percakapan dapat dieskalasikan ke admin CS manusia, dengan konteks percakapan. AI tidak boleh mengubah pesanan atau menjanjikan refund/kompensasi tanpa otorisasi. Vendor/model, trigger eskalasi, jam layanan, dan retensi chat masih terbuka.

### FR-09 — AI Marketing
Membantu skrip video, saran niche/tren, caption, kalender konten, dan pengingat. Klaim tren harus disertai sumber/periode. Output berupa draft untuk persetujuan manusia; publikasi otomatis tidak termasuk. Kanal, frekuensi, sumber tren, PIC approval, dan kanal pengingat perlu ditetapkan.

## 6. Kebutuhan Nonfungsional
- **Keamanan:** validasi server-side, CSRF, otorisasi, rate limiting yang sesuai, secret di environment, least privilege.
- **Integritas:** transaksi database untuk perubahan stok/pesanan, idempotensi webhook, pencegahan overselling.
- **Privasi:** minimisasi data, pembatasan akses, dan kebijakan retensi.
- **Keandalan:** logging error, retry yang aman, backup terjadwal, dan uji restore.
- **Kinerja:** optimasi gambar, pagination, asset caching, dan layout responsif.
- **Aksesibilitas:** keyboard navigation, fokus terlihat, kontras memadai, label dan pesan error yang jelas.
- **Maintainability:** Laravel, migrations/seeders, automated tests, Git, dokumentasi deployment.
- **Operasi:** domain `tuffero.com` di Jagoan Hosting; VPS direncanakan di Niagahoster. Ernando menangani VPS, domain, SSL, backup, deployment, dan version control.

## 7. Design System
Acuan `Tuffero_Design_System.txt`:
- Dark Chocolate `#2B1B14`
- Champagne Gold `#C6A15B`
- Warm Ivory `#F6F0E5`
- Roasted Brown `#6D4C3D`
- Pure White `#FFFFFF`
- Soft Border `#E3D5C0`
- Font utama Manrope; Playfair Display Italic sebagai aksen selektif.
- Gunakan foto produk Tuffero yang autentik dan pertahankan artwork logo asli.

## 8. Metrik Keberhasilan
Target angka belum ditetapkan. Ukur keberhasilan checkout, konversi, pembatalan, error integrasi, konsistensi status pembayaran, ketiadaan stok minus, serta kemampuan admin mengelola pesanan tanpa perubahan database manual.

## 9. Risiko dan Mitigasi
| Risiko | Mitigasi |
|---|---|
| API kurir belum tersedia | Mulai kerja sama sejak awal; gunakan mock/sandbox selama pengembangan. |
| Aktivasi Midtrans terlambat | Siapkan integrasi sandbox; produksi menunggu akun aktif. |
| Stok berbeda dengan Shopee | Tetapkan proses operasional dan keputusan sinkronisasi. |
| Keranjang tidak dapat dipenuhi satu gudang | Validasi kelayakan gudang sebelum checkout. |
| Aturan promo ambigu | Dokumentasikan rule dan uji edge case sebelum aktivasi. |
| AI memberi jawaban salah | Sumber pengetahuan terkontrol, batas kewenangan, eskalasi manusia. |
| Backup gagal dipulihkan | Uji restore dan dokumentasikan prosedur. |

## 10. Pertanyaan Terbuka
1. Apakah stok website dan Shopee sama? Apakah perlu sinkronisasi otomatis?
2. Apakah harga website sama dengan Shopee?
3. Pelanggan memilih kurir atau sistem memilih layanan?
4. Detail subsidi ongkir: nilai, batas, wilayah, minimum transaksi, periode, dan penanggung biaya.
5. Rule voucher: kombinasi, kuota, periode, minimum transaksi, cakupan SKU, batas per pelanggan.
6. Durasi reservasi stok saat pembayaran pending.
7. Kebijakan pembatalan, retur, refund, barang rusak/salah kirim.
8. Metode aman melihat status pesanan tanpa akun.
9. Role admin dan approval.
10. Tanggal peluncuran, pemilik UAT, dan KPI.
11. Sumber pengetahuan, trigger eskalasi, jam layanan, dan retensi AI CS.
12. Kanal, sumber tren, frekuensi, approval, dan pengingat AI marketing.
13. Anggaran hosting, gateway, API, dan AI.
14. Privasi serta masa retensi data pelanggan dan percakapan AI.

## 11. Kriteria Persetujuan
PRD menjadi baseline setelah pemilik bisnis meninjau scope, aturan satu pesanan/satu gudang disetujui, pertanyaan yang memengaruhi transaksi ditetapkan atau dicatat sebagai asumsi, data katalog tersedia, dan ketergantungan Midtrans/API kurir terdokumentasi.
