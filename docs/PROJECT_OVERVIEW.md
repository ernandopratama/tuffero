# Gambaran Proyek Tuffero

## Apa itu Tuffero

Tuffero adalah brand bakery/roti kering yang saat ini berjualan di Shopee dan Instagram (@tuffero.bakery). Proyek ini membangun **website e-commerce B2C milik brand** di domain `tuffero.com`, sebagai kanal penjualan resmi di luar marketplace.

## Tujuan Bisnis (PRD v0.2 §2)

1. Kanal penjualan resmi di luar marketplace.
2. Pembelian mudah lewat guest checkout.
3. Pembayaran online lewat Midtrans.
4. Konsistensi stok, pembayaran, pesanan, dan pengiriman.
5. Integrasi pengiriman lewat Biteship untuk MVP (D-020); J&T/SiCepat lewat Biteship bila tersedia.
6. Fondasi promosi/voucher dan modul AI bertahap.
7. Deployment dan operasi dikelola internal.

## Orang dan Peran

| Peran | Siapa |
|---|---|
| Pemilik bisnis, pengambil keputusan | Ko Rifan / tim Tuffero |
| Technical lead, pengelola infrastruktur | Ernando |
| Pengguna sistem | Pembeli B2C (tamu), admin operasional, admin CS, admin marketing |

## Cakupan

- **P0 (wajib peluncuran):** landing page, katalog dan detail produk, keranjang, guest checkout, perhitungan server-side, order dengan snapshot, Midtrans, stok multi-gudang (Malang aktif), satu order satu gudang, integrasi kurir, admin, fondasi promosi/voucher, keamanan dasar, backup, test, dokumentasi deployment.
- **P1:** AI Customer Service, AI marketing, diskon ongkir, gudang Jakarta, sinkronisasi Shopee (jika diputuskan).
- **Di luar MVP:** login wajib pelanggan, aplikasi mobile, ERP/akuntansi, multi-vendor, split order, publikasi AI otomatis, reseller/grosir, diskon kuantitas aktif.

Detail: [product/PRD.md](product/PRD.md), [product/MVP_SCOPE.md](product/MVP_SCOPE.md), aturan: [product/BUSINESS_RULES.md](product/BUSINESS_RULES.md).

## Target

Peluncuran sebelum Ramadan 2027. Tanggal pasti belum ditetapkan (D-018, Q-010).

## Kondisi Saat Ini (2026-10-30)

Fondasi teknis sudah ada; fitur e-commerce belum dimulai. Lihat [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md) §10 dan [project-management/PROJECT_STATUS.md](project-management/PROJECT_STATUS.md).
