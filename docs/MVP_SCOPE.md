# Tuffero E-commerce — MVP Scope

- **Versi:** 0.2
- **Status:** Draft untuk persetujuan
- **Tanggal:** 26 Oktober 2026
- **Target:** Sebelum Ramadan 2027; tanggal pasti belum ditetapkan

## 1. Sasaran MVP
Meluncurkan website B2C Tuffero dengan katalog, guest checkout, Midtrans, pengelolaan pesanan dan stok, serta pengiriman dari gudang Malang. Struktur backend disiapkan untuk gudang tambahan di masa depan. Setiap order hanya boleh dipenuhi dari satu gudang.

## 2. P0 — Wajib untuk Peluncuran

### Storefront dan katalog
- [ ] Landing page mengikuti design system Tuffero.
- [ ] Katalog dan halaman detail produk.
- [ ] Data nama, deskripsi, foto, harga, SKU, varian, berat, dimensi paket.
- [ ] Produk aktif/nonaktif dan status stok.
- [ ] Responsif dan aksesibilitas dasar.

### Keranjang dan checkout
- [ ] Keranjang tanpa login.
- [ ] Checkout meminta nama penerima, email, nomor HP, dan alamat lengkap.
- [ ] Server menghitung ulang harga, promo, ongkir, stok, dan total.
- [ ] Snapshot item, SKU/varian, harga, diskon, alamat, dan total pada order.
- [ ] Konfirmasi pesanan dan halaman status pesanan.
- [ ] Metode aman melihat status pesanan tanpa akun ditentukan sebelum produksi.

### Gudang dan stok
- [ ] Gudang Malang aktif pada peluncuran.
- [ ] Model data mendukung gudang tambahan, termasuk Jakarta.
- [ ] Satu pesanan dialokasikan ke satu gudang.
- [ ] Semua item order harus tersedia dari gudang yang sama.
- [ ] Checkout ditahan jika tidak ada satu gudang yang dapat memenuhi semua item.
- [ ] Pencegahan stok minus dan overselling.
- [ ] Reservasi/pelepasan stok; durasi final disetujui.
- [ ] Admin dapat mengelola stok per SKU/varian dan gudang.

### Pembayaran
- [ ] Integrasi Midtrans sandbox.
- [ ] Verifikasi notifikasi server dan idempotensi.
- [ ] Penanganan status pending, sukses, gagal, kedaluwarsa, dan pembatalan sesuai dukungan gateway.
- [ ] Produksi hanya setelah akun dan metode pembayaran aktif serta pengujian lolos.

### Pengiriman
- [ ] Integrasi J&T dan SiCepat sesuai dokumentasi dan akses yang diberikan.
- [ ] Cek ongkir/layanan, pembuatan resi, dan tracking jika endpoint tersedia.
- [ ] Simpan kurir, layanan, biaya, resi, dan status.
- [ ] Kegagalan API tidak membuat pengiriman ganda.
- [ ] Gunakan mock/sandbox jika akses belum tersedia; jangan mengklaim integrasi produksi sudah selesai.
- [ ] Jika API belum siap, pemilik bisnis harus menyetujui secara tertulis apakah rilis ditunda atau memakai prosedur sementara.

### Admin dan operasi
- [ ] Login dan otorisasi admin.
- [ ] CRUD produk/varian.
- [ ] Manajemen stok, pesanan, pembayaran, dan pengiriman.
- [ ] Logging perubahan penting.
- [ ] Environment aman, backup, dokumentasi deployment dan rollback.

### Promosi dan voucher
- [ ] Struktur backend dasar promosi/voucher.
- [ ] Validasi promo server-side.
- [ ] Area frontend dapat disiapkan dengan label “Coming Soon” untuk fitur belum aktif.
- [ ] Diskon kuantitas tidak aktif pada peluncuran awal.

## 3. P1 — Setelah Transaksi Inti Stabil
- [ ] AI Customer Service website dengan eskalasi ke admin manusia.
- [ ] AI marketing: skrip video, riset niche/tren, caption, kalender konten, pengingat.
- [ ] Diskon ongkir setelah aturan final disetujui.
- [ ] Pengembangan dan aktivasi promo/voucher lanjutan setelah rule disepakati.
- [ ] Gudang Jakarta saat lokasi dan operasi siap.
- [ ] Sinkronisasi stok Shopee jika diputuskan sebagai kebutuhan.

Modul P1 boleh dirancang lebih awal jika tidak menghambat transaksi inti, tetapi implementasinya tidak boleh menunda P0 tanpa persetujuan.

## 4. Di Luar Scope MVP
- Login wajib pelanggan.
- Aplikasi mobile native.
- ERP, HR, akuntansi, dan sistem produksi/resep.
- Multi-vendor.
- Pemecahan satu order ke beberapa gudang/pengiriman.
- Publikasi AI tanpa persetujuan manusia.
- Sinkronisasi marketplace otomatis sebelum kebutuhan dan akses tersedia.
- Diskon kuantitas aktif saat peluncuran.
- Reseller/grosir B2B.

## 5. Kriteria Penerimaan

### Katalog
- Produk aktif menampilkan data yang benar dan varian yang tersedia.
- Produk nonaktif tidak bisa dibeli.
- Harga dan stok bersumber dari server.

### Checkout
- Pelanggan dapat checkout tanpa akun.
- Data kontak dan alamat divalidasi.
- Total dihitung ulang server-side.
- Snapshot harga dan item tersimpan pada order.

### Gudang/stok
- Setiap order mempunyai tepat satu gudang pemenuhan.
- Order tidak dibagi ke beberapa gudang.
- Checkout ditolak jika tidak ada satu gudang yang dapat memenuhi semua item.
- Request bersamaan tidak menyebabkan stok minus.
- Reservasi dan perubahan status konsisten.

### Pembayaran
- Notifikasi diverifikasi server.
- Notifikasi duplikat tidak menggandakan perubahan.
- Secret key tidak masuk version control.
- Status order mengikuti status pembayaran terverifikasi.

### Pengiriman
- Ongkir/layanan hanya ditampilkan berdasarkan respons API valid.
- Resi dan status tersimpan dengan benar.
- Kegagalan API tidak menghasilkan pengiriman ganda.
- Integrasi produksi belum dianggap lulus sebelum akses dan endpoint benar-benar diuji.

### Promosi
- Rule promo divalidasi di server.
- Promo nonaktif tidak dapat digunakan melalui manipulasi request.
- Diskon kuantitas yang belum aktif tampil “Coming Soon”.

### Operasional
- Otorisasi admin diuji.
- Backup dapat dipulihkan dalam uji restore.
- Pengujian alur sukses, gagal, dan edge case selesai.
- Pemilik bisnis menyetujui UAT sebelum produksi.

## 6. Gate / Ketergantungan
1. **Katalog:** data produk lengkap diterima.
2. **Pembayaran produksi:** akun Midtrans dan metode pembayaran aktif; verifikasi teknis selesai.
3. **Kurir produksi:** akses/API J&T dan SiCepat tersedia dan diuji.
4. **Promo:** aturan promo yang diaktifkan disetujui.
5. **Stok:** diputuskan apakah website dan Shopee berbagi stok.
6. **Peluncuran:** UAT disetujui, domain/SSL/deployment tervalidasi, backup/restore diuji, dan runbook tersedia.

## 7. Belum Final
- Sinkronisasi stok Shopee.
- Subsidi diskon ongkir.
- Detail aturan voucher/promosi.
- Durasi reservasi stok.
- Metode verifikasi status pesanan guest.
- Pemilihan kurir oleh pelanggan atau sistem.
- Kebijakan retur/refund/pembatalan.
- Tanggal peluncuran pasti.
- Prioritas, vendor, dan biaya AI.
