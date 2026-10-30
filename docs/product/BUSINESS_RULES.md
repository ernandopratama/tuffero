# Aturan Bisnis Tuffero

> Ringkasan aturan bisnis dari dokumen yang disetujui. Dokumen ini **tidak** membuat keputusan baru. Sumber utama tetap [../project-management/DECISION_LOG.md](../project-management/DECISION_LOG.md), [PRD.md](PRD.md), dan [MVP_SCOPE.md](MVP_SCOPE.md). Jika ada perbedaan, sumber utama yang berlaku.
>
> Status implementasi per 2026-10-30: **tidak ada satu pun aturan di bawah yang sudah diimplementasikan di kode**, karena belum ada modul katalog, keranjang, order, stok, pembayaran, pengiriman, atau promosi. Lihat [../AI_PROJECT_CONTEXT.md](../AI_PROJECT_CONTEXT.md) bagian 10.

## 1. Aturan yang Sudah Disepakati

| ID | Aturan | Sumber | Status kode |
|---|---|---|---|
| BR-01 | Model bisnis B2C. Reseller/grosir bukan MVP. | D-005, PRD §3 | `PLANNED` |
| BR-02 | Checkout diusahakan tanpa login. Data yang diminta: nama penerima, email, nomor HP, alamat lengkap. | D-007, PRD FR-02 | `PLANNED` |
| BR-03 | Login pelanggan tidak wajib (login wajib di luar MVP). | PRD §4 | `PLANNED` |
| BR-04 | Saat peluncuran hanya gudang Malang yang aktif. | D-008 | `PLANNED` |
| BR-05 | Model data harus mendukung banyak gudang sejak awal (rencana Jakarta). | D-009, PRD FR-03 | `PLANNED` |
| BR-06 | Satu pesanan hanya dipenuhi dari tepat satu gudang. | D-010, PRD FR-03 | `PLANNED` |
| BR-07 | Pesanan tidak boleh dipecah otomatis ke beberapa gudang/pengiriman. Jika tidak ada satu gudang yang bisa memenuhi seluruh keranjang, checkout ditahan dengan pesan yang jelas. | D-010, PRD FR-03, MVP §2 | `PLANNED` |
| BR-08 | Harga, promo, ongkir, stok, dan total dihitung ulang di server. Nilai dari browser tidak dipercaya. | A-004, PRD FR-02 | `PLANNED` |
| BR-09 | Order menyimpan snapshot item, SKU/varian, harga, diskon, alamat, dan total saat transaksi. | PRD §4, MVP §2 | `PLANNED` |
| BR-10 | Stok tidak boleh minus dan tidak boleh oversell pada request bersamaan. | PRD FR-03, MVP §5 | `PLANNED` |
| BR-11 | Pembayaran memakai Midtrans; mulai di sandbox. Redirect browser bukan bukti bayar. Notifikasi diverifikasi server dan diproses idempoten. | D-015, PRD FR-04 | `PLANNED` |
| BR-12 | Status order mengikuti status pembayaran yang terverifikasi. | MVP §5 | `PLANNED` |
| BR-13 | Promosi/voucher: backend disiapkan, validasi di server. Fitur yang belum aktif boleh tampil "Coming Soon". | D-011, PRD FR-06 | `PLANNED` |
| BR-14 | Diskon kuantitas tidak aktif saat peluncuran ("Coming Soon"). | D-012 | `PLANNED` |
| BR-15 | Promo nonaktif tidak dapat dipakai lewat manipulasi request. | MVP §5 | `PLANNED` |
| BR-16 | Kegagalan API kurir tidak boleh membuat pengiriman ganda. Snapshot alamat, kurir, layanan, ongkir, resi, status disimpan. | PRD FR-05 | `PLANNED` |
| BR-17 | Admin punya autentikasi dan otorisasi terpisah; perubahan penting dicatat. | PRD FR-07 | `PARTIALLY IMPLEMENTED` (lihat catatan) |
| BR-18 | AI CS hanya di website, eskalasi ke admin manusia. AI tidak boleh mengubah pesanan atau menjanjikan refund/kompensasi. | D-016, PRD FR-08 | `PLANNED` (P1) |
| BR-19 | Konten AI marketing berupa draft yang disetujui manusia; tidak ada publikasi otomatis. | D-017, A-005, PRD FR-09 | `PLANNED` (P1) |
| BR-20 | Secret (Midtrans, kurir) disimpan di environment server, tidak di repository, browser, atau log. | PRD FR-04, Panduan API Pengiriman §8 | `PLANNED` |
| BR-21 | Status order, pembayaran, dan pengiriman disimpan terpisah. | PRD v0.1 FR-05, Panduan Payment §6, Panduan Pengiriman §8 | `PLANNED` |
| BR-22 | Pengiriman MVP lewat Biteship; J&T/SiCepat lewat Biteship bila tersedia. Provider dapat diganti lewat `ShippingService`. | D-020 (menggantikan D-014 untuk MVP) | `PLANNED` |

Catatan BR-17: endpoint login/logout/user dan paket `spatie/laravel-permission` sudah ada, tetapi belum ada role, permission, pemisahan admin vs pelanggan, atau audit log. Lihat [../security/SECURITY_AUDIT.md](../security/SECURITY_AUDIT.md).

## 2. Asumsi Sementara (Belum Final)

Mengikuti Decision Log A-001 sampai A-005. Jangan diperlakukan sebagai aturan final.

- A-001: stok website tidak dianggap tersinkron otomatis dengan Shopee.
- A-002: pelanggan dapat memilih layanan kurir.
- A-003: stok mungkin direservasi saat pembayaran pending; durasi belum disetujui (Q-006).
- A-005: AI menghasilkan draft yang disetujui manusia.

## 3. Aturan yang Belum Diputuskan

Aturan ini **tidak boleh diimplementasikan dengan nilai karangan**. Buat konfigurasi yang dapat diubah atau tunggu keputusan.

| Topik | Pertanyaan | Referensi |
|---|---|---|
| Pemilihan kurir | Pelanggan atau sistem? | Q-003 |
| Subsidi ongkir | Nilai, batas, wilayah, minimum, periode, penanggung | D-013, Q-004 |
| Aturan voucher | Kombinasi, kuota, periode, minimum, cakupan SKU, batas per pelanggan | Q-005 |
| Durasi reservasi stok | Berapa lama, kapan dibuat/dilepas | Q-006 |
| Pembatalan, retur, refund | Kebijakan lengkap | Q-007 |
| Akses status pesanan guest | Mekanisme aman | Q-008 |
| Role admin | Daftar role dan kewenangan | Q-009 |
| Retensi data pribadi | Masa simpan dan dasar pemrosesan | Q-014 |
| Harga vs Shopee, stok vs Shopee | Sama atau berbeda, perlu sinkron atau tidak | Q-001, Q-002 |
