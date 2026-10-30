# Dokumen Referensi di Folder `TUFFERO/`

> Ringkasan isi folder `TUFFERO/` di root repository, dibaca lengkap pada audit 2026-10-30. File aslinya tetap di `TUFFERO/` dan tidak diubah. Ringkasan ini tidak menggantikan file aslinya.

## 1. Daftar Dokumen

| File | Tanggal/versi | Isi | Status sebagai sumber |
|---|---|---|---|
| `TUFFERO/Notulen Rapat 26-10.txt` | 26 Okt 2026 | Hasil rapat: Laravel, domain di Jagoan Hosting, VPS Niagahoster, B2C, katalog (harga, foto, SKU, varian, deskripsi), checkout tanpa login, promo/voucher (diskon kuantitas, diskon ongkir), API langsung J&T dan SiCepat, "Gudang ada 2 sementara masih di Malang", Midtrans (Ko Rifan mendaftar), AI CS dan marketing (skrip video). Kebutuhan staf IT: email perusahaan, akses Jagoan Hosting, Niagahoster, Midtrans. | Sumber primer keputusan rapat; sudah dituangkan ke PRD/MVP/Decision Log v0.2 |
| `TUFFERO/Tuffero_Design_System.txt` | Tidak bertanggal | Palet warna, tipografi, komponen, arah gambar, checklist desain. Menyebut stack Blade + Tailwind + Alpine.js. | Sumber kebenaran identitas visual (D-019). Lihat [../frontend/DESIGN_SYSTEM.md](../frontend/DESIGN_SYSTEM.md) |
| `TUFFERO/Panduan_API_Pengiriman_Tuffero.pdf` | 26 Okt 2026, v1.0 | Perbandingan Biteship, API langsung J&T, API langsung Anteraja. Rekomendasi: Biteship sebagai kandidat utama MVP, minta informasi J&T/Anteraja sebagai pembanding. Usulan arsitektur `ShippingService` dengan provider terpisah. Checklist dokumen dan template surat. | Bahan diskusi internal, bukan keputusan |
| `TUFFERO/Panduan_Payment_Gateway_Tuffero.pdf` | 26 Okt 2026, v1.0 | Perbandingan Midtrans vs integrasi langsung bank (BCA, Mandiri, BNI, SNAP BI). Rekomendasi Midtrans untuk MVP. Checklist teknis: sandbox, verifikasi notifikasi, idempotensi, status order/payment terpisah, rekonsiliasi. | Bahan diskusi; rekomendasinya sejalan dengan D-015 |
| `TUFFERO/Tuffero_Meeting_Discovery_Guide.pdf` | Draft pra-rapat | Daftar pertanyaan discovery: model penjualan, checkout, katalog, gudang Jakarta/Malang, Midtrans, Biteship vs J&T/Anteraja, n8n/AI agent, desain (referensi visual "Natureva"), jadwal. Kolom catatan keputusan kosong. | Panduan rapat; tidak berisi keputusan |
| `Tuffero_PRD_v0.1.pdf` | v0.1, pra-rapat | Draft PRD: stok Jakarta dan Malang terpisah, stack Laravel + PostgreSQL + Blade + Tailwind, Midtrans awal, evaluasi Biteship vs J&T/Anteraja, modular monolith. | **Digantikan** oleh [PRD.md](PRD.md) v0.2 |
| `Tuffero_MVP_Scope_v0.1.pdf` | v0.1, pra-rapat | Draft MVP: stok Jakarta dan Malang, metode checkout terbuka, refund otomatis tidak termasuk. | **Digantikan** oleh [MVP_SCOPE.md](MVP_SCOPE.md) v0.2 |

**Catatan file v0.1:** `TUFFERO/Tuffero_PRD_v0.1.pdf` dan `TUFFERO/Tuffero_MVP_Scope_v0.1.pdf` tercatat di commit `e4ba05e`, tetapi pada 2026-10-30 sudah **tidak ada di working tree** (`git status` menunjukkan `D`). Penghapusan ini tidak dilakukan oleh sesi audit. Isinya masih dapat dibaca dari Git (`git show HEAD:TUFFERO/Tuffero_PRD_v0.1.pdf`). Perlu dikonfirmasi apakah penghapusan disengaja (OQ-09).

## 2. Inkonsistensi Antar-Dokumen

Dokumen yang lebih baru berlaku, kecuali jika keputusan memang belum diambil. Item terbuka dicatat di [../project-management/OPEN_QUESTIONS.md](../project-management/OPEN_QUESTIONS.md).

| ID | Topik | Dokumen A | Dokumen B | Status |
|---|---|---|---|---|
| INC-01 | Penyedia pengiriman | Notulen, D-014, PRD v0.2: API langsung J&T dan SiCepat | Panduan API Pengiriman: Biteship kandidat utama; membandingkan J&T dan **Anteraja** (bukan SiCepat). Instruksi audit 2026-10-30 menyebut Biteship sebagai integrasi utama. | **Selesai**: D-020 (Biteship untuk MVP) |
| INC-02 | Gudang | PRD/MVP v0.1, Panduan Pengiriman: stok Jakarta dan Malang | PRD/MVP v0.2, D-008/D-009: hanya Malang aktif, Jakarta masa depan | Selesai: v0.2 berlaku |
| INC-03 | Stack frontend | Design System, PRD v0.1, Project Status §5: Blade + Tailwind + Alpine.js | Kode saat ini: Vue 3 SPA + vue-router + Pinia; homepage memakai Bootstrap/jQuery template Backeri | **Selesai**: D-021 (hybrid: toko Blade, admin Vue). Migrasi kode belum dilakukan |
| INC-04 | Diskon kuantitas | Notulen: disebut sebagai jenis promo | D-012: tidak aktif saat peluncuran | Selesai: D-012 berlaku |
| INC-05 | Bilingual EN/ID dan arah "Luxury Editorial" | Instruksi audit 2026-10-30 | Tidak ada di PRD, MVP, Decision Log, atau Design System ("Premium, modern, warm, elegant") | **Terbuka** (OQ-03) |
| INC-06 | Otomasi n8n | Discovery Guide, PRD v0.1 | Tidak disebut di v0.2; yang tersisa AI CS dan AI marketing (P1) | Dianggap tidak termasuk; konfirmasi bila perlu |
| INC-07 | Referensi visual | Discovery Guide: "Natureva" | Kode: template "Backeri" (Awaiken) | **Terbuka** (T-1, OQ-04): lisensi Backeri belum diverifikasi |
| INC-08 | Kurir pembanding | Panduan Pengiriman: Anteraja | Notulen/D-014: SiCepat | Selesai bersama D-020 |
