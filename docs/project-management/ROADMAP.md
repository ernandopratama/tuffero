# Roadmap — `PROPOSED`

> Usulan urutan kerja dari audit 2026-10-30. Belum disetujui. Prioritas produk mengikuti [../product/MVP_SCOPE.md](../product/MVP_SCOPE.md) (P0 sebelum P1). Prioritas keamanan mengikuti [../security/SECURITY_AUDIT.md](../security/SECURITY_AUDIT.md) §3. Target: sebelum Ramadan 2027 (tanggal pasti belum ditetapkan, Q-010).

## Fase 0 — Fondasi dan keputusan (segera)

- Commit pekerjaan yang ada (OQ-11) dan rapikan working tree (OQ-09, OQ-10).
- OQ-01 dan OQ-02 sudah diputuskan (D-020 Biteship, D-021 hybrid). Putuskan OQ-04 (lisensi template), Q-009 (role admin), Q-008 (akses order guest).
- Migrasi frontend ke hybrid: halaman toko ke Blade, SPA dibatasi ke `/admin` (tugas terpisah).
- Seeder role/permission + akun admin awal, rate limit login (SEC-01, SEC-10).
- CI: test, Larastan, Pint, `composer audit`, `npm audit`.

## Fase 1 — Katalog (P0)

- Migration + model: produk, varian/SKU, gudang (Malang aktif), stok per gudang dengan constraint.
- Admin CRUD produk/varian/stok dengan permission dan audit log.
- API publik katalog + halaman katalog dan detail produk sesuai design system.
- Data katalog dari tim Tuffero (gate MVP §6.1).

## Fase 2 — Keranjang, checkout, order (P0)

- Keranjang tamu, checkout guest, perhitungan server-side, alokasi satu gudang, reservasi stok, idempotency key, snapshot order.
- Halaman status order guest dengan mekanisme aman (Q-008).
- Test konkurensi di PostgreSQL.

## Fase 3 — Pembayaran (P0)

- Midtrans sandbox: transaksi, webhook terverifikasi, idempoten, pelepasan reservasi saat gagal/kedaluwarsa. Produksi setelah akun aktif (Q-016).

## Fase 4 — Pengiriman (P0)

- `ShippingService` + provider Biteship (D-020); tarif, shipment idempoten, tracking/webhook. Mock sampai akses API Biteship tersedia.

## Fase 5 — Promosi (P0 fondasi)

- Struktur voucher + validasi server; fitur belum aktif tampil "Coming Soon"; diskon kuantitas tidak aktif.

## Fase 6 — Produksi (P0)

- VPS, TLS, env produksi, backup + uji restore, monitoring, runbook, rollback (Q-015, SEC-03, SEC-13, SEC-14).
- Hardening P1 dari audit keamanan; UAT pemilik bisnis; uji penetrasi (direkomendasikan).

## Fase 7 — P1 setelah transaksi inti stabil

- AI Customer Service, AI marketing, diskon ongkir, gudang Jakarta, sinkronisasi Shopee jika diputuskan.
