# Pertanyaan Terbuka

> Pertanyaan bisnis Q-001 sampai Q-017 tetap dicatat di [DECISION_LOG.md](DECISION_LOG.md) §3 dan tidak diulang di sini. Pertanyaan teknis T-1 sampai T-5 ada di [PROJECT_STATUS.md](PROJECT_STATUS.md) §8. Dokumen ini mencatat pertanyaan **baru** dari audit 2026-10-30. Saat terjawab, catat keputusannya di Decision Log dan tandai di sini.

| ID | Pertanyaan | Latar belakang | Dampak bila tidak diputuskan | PIC disarankan | Status |
|---|---|---|---|---|---|
| OQ-01 | Penyedia pengiriman MVP: API langsung J&T + SiCepat (D-014) atau Biteship sebagai agregator (rekomendasi Panduan API Pengiriman, disebut sebagai integrasi utama pada instruksi audit)? Jika Biteship, apakah J&T dan SiCepat tersedia untuk akun dan gudang Malang? | INC-01, INC-08 di [../product/REFERENCE_DOCUMENTS.md](../product/REFERENCE_DOCUMENTS.md) | Desain `ShippingService`, credential, dan jadwal integrasi | Ko Rifan + Ernando | **Selesai 2026-10-30**: Biteship untuk MVP, disetujui Ko Rifan (D-020) |
| OQ-02 | Arah frontend final: Vue 3 SPA (kondisi kode sejak 2026-10-30, disamakan dengan suntrack-mysql) atau Blade + Tailwind + Alpine.js (Design System, PRD v0.1)? Perlu dicatat sebagai keputusan di Decision Log | INC-03 | Pola halaman katalog/checkout, SEO halaman produk (SPA perlu strategi SEO), ukuran bundle | Ernando | **Selesai 2026-10-30**: hybrid, toko Blade dan admin Vue (D-021) |
| OQ-03 | Apakah website wajib bilingual Inggris/Indonesia, dan apakah arah desain "Luxury Editorial" disetujui? | INC-05; tidak ada di PRD/MVP/Design System | Struktur konten, i18n, URL, data produk dua bahasa | Ko Rifan | Terbuka |
| OQ-04 | Apakah template Backeri boleh dipakai di produksi (lisensi), atau hanya acuan layout? (lanjutan T-1) | Homepage saat ini 100% konten template; tidak ada bukti lisensi di `theme/` | Risiko hukum; homepage perlu dibangun ulang | Ernando | Terbuka |
| OQ-05 | Siapa PIC respons insiden keamanan dan kanal eskalasinya? | [../security/SECURITY_REQUIREMENTS.md](../security/SECURITY_REQUIREMENTS.md) §J | Insiden tidak tertangani | Ko Rifan + Ernando | Terbuka |
| OQ-06 | Apakah MFA wajib untuk semua akun admin/staf? | SEC-11 | Keamanan admin | Ko Rifan + Ernando | Terbuka (rekomendasi: wajib) |
| OQ-07 | Ambang dan tindakan anti-fraud (review manual, batas order per HP/IP)? | TRX-14 | Tanpa keputusan, sistem hanya mencatat sinyal | Ko Rifan | Terbuka |
| OQ-08 | Apakah dibutuhkan CDN/WAF/proteksi bot, dan anggarannya? | INF-10, Q-013 | Ketahanan terhadap bot dan DDoS | Ernando | Terbuka |
| OQ-09 | Apakah penghapusan `TUFFERO/Tuffero_PRD_v0.1.pdf` dan `TUFFERO/Tuffero_MVP_Scope_v0.1.pdf` dari working tree disengaja? | `git status` menunjukkan `D`; file masih di commit `e4ba05e` | Kejelasan arsip | Ernando | Terbuka |
| OQ-10 | Apakah file tooling di root (`install.ps1` untuk codebase-memory-mcp, `.claude/`, `graphify-out/`) akan di-commit? | Belum ter-track | Kebersihan repository | Ernando | **Selesai 2026-10-30**: tidak di-commit; `/theme`, `/graphify-out`, `/.claude`, `/install.ps1` ditambahkan ke `.gitignore` |
| OQ-11 | Apakah perubahan ke stack suntrack (Sanctum, Spatie, Larastan, Docker) dan perubahan lain yang belum di-commit akan di-commit? | `git status` banyak perubahan belum di-commit | Risiko kehilangan pekerjaan | Ernando | **Selesai 2026-10-30**: di-commit ke branch `dev` (main untuk deployment) |
