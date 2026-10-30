# Konteks Proyek untuk AI Assistant dan Developer Baru

> Referensi onboarding utama untuk Claude, AI coding assistant lain, dan developer baru. Diverifikasi terhadap kode pada 2026-10-30. Jika dokumen ini berbeda dengan kode, **kode yang menentukan apa yang sudah ada**; ikuti [README.md](README.md) §2.

## Instruksi Wajib

> Before implementing any feature, study the relevant documentation and inspect the existing implementation. Understand the current execution flow before modifying it. Never assume that a planned feature already exists. Follow approved business rules and existing architecture. Apply the documented security requirements to every relevant change. If requirements are unclear or conflicting, ask for clarification before making consequential changes.

(Sebelum mengimplementasikan fitur apa pun, pelajari dokumentasi terkait dan periksa implementasi yang ada. Pahami alur eksekusi saat ini sebelum mengubahnya. Jangan pernah menganggap fitur yang direncanakan sudah ada. Ikuti aturan bisnis yang disetujui dan arsitektur yang ada. Terapkan persyaratan keamanan yang terdokumentasi pada setiap perubahan yang relevan. Jika kebutuhan tidak jelas atau bertentangan, minta klarifikasi sebelum membuat perubahan yang berdampak.)

Aturan kerja tambahan:

- Jangan membaca, menampilkan, atau meng-commit `.env`. Jangan menulis secret ke kode, log, atau dokumen.
- Jangan menjalankan operasi destruktif (hapus data, `migrate:fresh`, `git reset --hard`, force push) tanpa persetujuan eksplisit.
- Jangan commit atau push tanpa diminta.
- Perubahan kecil dan terukur; jelaskan file yang berubah dan cara mengujinya.
- Setelah mengubah kode: jalankan test, Larastan, Pint; perbarui dokumen terkait dan [project-management/CHANGELOG.md](project-management/CHANGELOG.md); jalankan `graphify update .`.
- Repository punya knowledge graph di `graphify-out/`. Menurut `CLAUDE.md`, jalankan `graphify query "<pertanyaan>"` sebelum menelusuri kode.

## Urutan Baca Wajib

1. Dokumen ini.
2. [README.md](README.md) — kebijakan sumber kebenaran dan label status.
3. [product/BUSINESS_RULES.md](product/BUSINESS_RULES.md) — aturan bisnis yang tidak boleh dilanggar.
4. [project-management/OPEN_QUESTIONS.md](project-management/OPEN_QUESTIONS.md) dan [project-management/DECISION_LOG.md](project-management/DECISION_LOG.md) §3 — yang belum diputuskan.
5. [architecture/SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md), lalu dokumen arsitektur area yang akan diubah.
6. [security/SECURITY_REQUIREMENTS.md](security/SECURITY_REQUIREMENTS.md) bagian yang relevan dan [security/SECURITY_AUDIT.md](security/SECURITY_AUDIT.md).
7. [quality/CODE_STANDARDS.md](quality/CODE_STANDARDS.md) dan [quality/TESTING_STRATEGY.md](quality/TESTING_STRATEGY.md).
8. Untuk fitur produk: [product/PRD.md](product/PRD.md) dan [product/MVP_SCOPE.md](product/MVP_SCOPE.md).

## 1. Apa itu Tuffero

Website e-commerce B2C milik brand bakery Tuffero di `tuffero.com`. Pembeli berbelanja tanpa akun (guest checkout), membayar lewat Midtrans, dan barang dikirim dari satu gudang (Malang saat peluncuran). Lihat [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md).

## 2. Tujuan Bisnis

Kanal penjualan resmi di luar Shopee; checkout mudah; pembayaran terverifikasi; stok, order, pembayaran, pengiriman konsisten; fondasi promo dan AI; operasi dikelola internal. Target peluncuran sebelum Ramadan 2027 (tanggal pasti belum ada).

## 3. Cakupan Produk

P0: katalog, detail produk, keranjang, guest checkout, order + snapshot, Midtrans, stok multi-gudang (Malang aktif), integrasi kurir, admin, fondasi voucher, keamanan, backup, test. P1: AI CS, AI marketing, diskon ongkir, gudang Jakarta, sinkronisasi Shopee. Di luar MVP: login wajib, app mobile, ERP, multi-vendor, split order, reseller/grosir, diskon kuantitas aktif.

## 4. Aturan Bisnis yang Sudah Disepakati

Paling penting (lengkap di [product/BUSINESS_RULES.md](product/BUSINESS_RULES.md)):

- B2C; guest checkout dengan nama, email, HP, alamat.
- Gudang Malang satu-satunya yang aktif; model data multi-gudang sejak awal.
- **Satu order dipenuhi tepat satu gudang. Tidak ada split order otomatis.** Jika tidak ada gudang tunggal yang bisa memenuhi semua item, tolak checkout dengan pesan jelas.
- **Harga, promo, ongkir, stok, total selalu dihitung ulang di server.**
- Order menyimpan snapshot.
- Stok tidak boleh minus, termasuk saat request bersamaan.
- Midtrans: sandbox dulu, notifikasi diverifikasi server, idempoten, redirect bukan bukti bayar.
- Promo/voucher divalidasi server; diskon kuantitas tidak aktif saat peluncuran ("Coming Soon").
- AI tidak boleh mengubah order/refund dan tidak mempublikasikan konten tanpa persetujuan manusia.

Keputusan 2026-10-30: pengiriman MVP lewat Biteship (D-020); frontend hybrid, toko Blade dan admin Vue (D-021).

Belum diputuskan (jangan mengarang nilainya): pilihan kurir oleh pelanggan atau sistem, subsidi ongkir, aturan voucher, durasi reservasi stok, kebijakan refund/retur, cara aman guest melihat order, role admin, retensi data.

## 5. Stack Teknologi

| Lapisan | Aktual (terverifikasi) | Direncanakan |
|---|---|---|
| Backend | Laravel 13.38, PHP 8.5 lokal / 8.4 Docker | — |
| Database | PostgreSQL (18.3 lokal, 17 Docker) | — |
| Auth | Laravel Sanctum (SPA cookie), spatie/laravel-permission | MFA admin |
| Frontend | Vue 3 SPA, vue-router, Pinia, axios, Vite 8, Tailwind CSS 4 (tanpa preflight), untuk semua path | Hybrid (D-021): toko Blade + Tailwind (+ Alpine), admin `/admin` Vue SPA. Migrasi kode belum dilakukan |
| Homepage | Template Backeri: Bootstrap 5.3.3 + jQuery + plugin | Diganti konten/desain Tuffero |
| Pembayaran | — | Midtrans |
| Pengiriman | — | Biteship untuk MVP (D-020), J&T/SiCepat lewat Biteship bila tersedia |
| Dev tooling | Pint, Larastan 3 (level 5), PHPUnit 12, Docker Compose | CI |
| Hosting | — | VPS Niagahoster, domain di Jagoan Hosting |

## 6. Arsitektur Frontend Aktual

`routes/web.php` mengembalikan `welcome.blade.php` untuk semua path non-API → `resources/js/app.js` memasang Vue + Pinia + router → `router.js` me-lazy-load halaman. Halaman: `/` (Home, port template Backeri; memuat 17 skrip jQuery lewat `legacy.js`), `/login`, `/admin` (kerangka). HTTP lewat `utils/api.js` (axios, `/api/v1`, cookie, redirect ke Login saat 401). Detail: [architecture/FRONTEND_ARCHITECTURE.md](architecture/FRONTEND_ARCHITECTURE.md).

## 7. Arsitektur Backend Aktual

Endpoint: `GET /api/v1/health`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/user`, plus `/sanctum/csrf-cookie` dan `/up`. Controller di `App\Http\Controllers\Api\V1`, respons lewat trait `ApiResponse`, error API JSON diatur di `bootstrap/app.php`, `SecurityHeadersMiddleware` global. `BaseRepository` ada tetapi belum dipakai. Tidak ada service, Form Request, Policy, job, event, atau notifikasi aplikasi. Detail: [architecture/BACKEND_ARCHITECTURE.md](architecture/BACKEND_ARCHITECTURE.md).

## 8. Arsitektur Database Aktual

Hanya tabel bawaan Laravel (`users`, `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`), Spatie (`roles`, `permissions`, 3 tabel pivot), dan Sanctum (`personal_access_tokens`). Semua tabel kosong kecuali `sessions`. Tidak ada tabel domain. Detail dan ERD usulan: [database/DATABASE.md](database/DATABASE.md).

## 9. Alur Aplikasi yang Benar-Benar Ada

1. Memuat halaman SPA (semua path).
2. Homepage statis dengan animasi template.
3. Login/logout admin via Sanctum (belum ada user di DB).
4. Health check.

Alur katalog, checkout, order, pembayaran, stok, pengiriman: **hanya usulan**, lihat [architecture/PROPOSED_DOMAIN_ARCHITECTURE.md](architecture/PROPOSED_DOMAIN_ARCHITECTURE.md).

## 10. Status Modul

| Modul | Status | File sumber terverifikasi | Yang berfungsi | Pekerjaan yang hilang |
|---|---|---|---|---|
| Homepage | `PARTIALLY IMPLEMENTED` | `resources/js/pages/Home.vue`, `resources/js/legacy.js`, `resources/views/welcome.blade.php`, `public/css`, `public/js` | Halaman tampil (HTTP 200), skrip template dimuat | Konten, logo, foto, design system Tuffero; lisensi template; tautan nav selain Home mengarah ke `#`; tidak terhubung data |
| Katalog produk | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Model, migration, API, halaman, admin |
| Detail produk | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Pencarian | `NOT IMPLEMENTED` | — | — | Semua (pencarian lanjutan P1 di MVP v0.1) |
| Kategori | `NOT IMPLEMENTED` | — | — | Struktur data kategori belum diputuskan (kategori disebut di PRD v0.1, tidak di v0.2) |
| Akun pelanggan | `NOT IMPLEMENTED` | — | — | Di luar MVP (login tidak wajib) |
| Keranjang | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Checkout | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Order | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Pembayaran (Midtrans) | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua; akun Midtrans belum dikonfirmasi |
| Inventori | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Gudang | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua |
| Pengiriman | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua; provider terbuka |
| Promosi dan voucher | `NOT IMPLEMENTED` (`PLANNED`) | — | — | Semua; aturan terbuka |
| Autentikasi admin | `PARTIALLY IMPLEMENTED` | `AuthController`, `routes/api.php`, `stores/auth.js`, `pages/Login.vue`, `router.js` | Login/logout/profil via sesi; CSRF; regenerasi sesi | Rate limit, MFA, user/role seeder, audit log |
| RBAC | `PARTIALLY IMPLEMENTED` | `composer.json`, `config/permission.php`, `bootstrap/app.php`, `User::HasRoles` | Paket, tabel, alias middleware | Role/permission belum didefinisikan atau dipakai |
| Dashboard admin | `PARTIALLY IMPLEMENTED` (kerangka) | `layouts/AdminLayout.vue`, `pages/Dashboard.vue` | Layout + tombol keluar | Semua fungsi admin |
| Customer service | `NOT IMPLEMENTED` | — | — | — |
| Fitur AI | `NOT IMPLEMENTED` (`PLANNED` P1) | — | — | Semua |
| Reporting | `NOT IMPLEMENTED` | — | — | Belum ada kebutuhan rinci |
| Health check | `IMPLEMENTED` | `HealthController` | DB + cache check | Jangan bocorkan detail error (SEC-04) |
| Docker dev | `IMPLEMENTED` | `Dockerfile`, `docker-compose.yml`, `docker/nginx.conf` | 4 container berjalan | Hardening (SEC-08); belum untuk produksi |
| Deployment produksi | `NOT IMPLEMENTED` (`PLANNED`) | — | — | VPS, TLS, backup, monitoring, runbook |
| Testing | `PARTIALLY IMPLEMENTED` | `tests/` | 2 test bawaan lulus; Larastan 0 error | Semua test fungsional dan keamanan |

## 11. Design System

Dark Chocolate `#2B1B14`, Champagne Gold `#C6A15B`, Warm Ivory `#F6F0E5`, Roasted Brown `#6D4C3D`, White `#FFFFFF`, Border `#E3D5C0`; Manrope utama, Playfair Display Italic aksen; logo asli tidak diubah; foto produk asli. Token sudah ada di `resources/css/app.css`; homepage belum memakainya. Bilingual EN/ID dan "Luxury Editorial" belum dikonfirmasi (OQ-03). Detail: [frontend/DESIGN_SYSTEM.md](frontend/DESIGN_SYSTEM.md).

## 12. Konvensi Kode

Lihat [quality/CODE_STANDARDS.md](quality/CODE_STANDARDS.md). Inti: API di `Api\V1` + `ApiResponse`; otorisasi di server (Spatie + Policy); validasi Form Request; uang integer; stok/order dalam transaksi DB; Vue `<script setup>`, HTTP lewat `utils/api.js`; tanpa `v-html` untuk data pengguna.

## 13. Struktur Direktori

Lihat [architecture/SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md) §6. Catatan: `resources/views/layouts|partials|legacy` dan `theme/` tidak dipakai aplikasi; `TUFFERO/` berisi dokumen bisnis.

## 14. Integrasi

Tidak ada integrasi eksternal yang diimplementasikan. Midtrans `PLANNED`; provider pengiriman terbuka; AI P1. Detail: [integrations/INTEGRATIONS.md](integrations/INTEGRATIONS.md).

## 15. Persyaratan Keamanan

Wajib: otorisasi di server untuk setiap aksi admin; rate limit auth dan endpoint sensitif; validasi input; tidak percaya harga/total dari browser; transaksi + lock untuk stok; idempotensi checkout dan webhook; verifikasi signature Midtrans; secret hanya di server; tidak menyimpan data kartu; tidak mencatat data sensitif di log. Lengkap: [security/SECURITY_REQUIREMENTS.md](security/SECURITY_REQUIREMENTS.md). Temuan terbuka: [security/SECURITY_AUDIT.md](security/SECURITY_AUDIT.md).

## 16. Persyaratan Testing

Feature test untuk setiap alur kritis; test konkurensi stok di PostgreSQL; test keamanan per daftar di [quality/TESTING_STRATEGY.md](quality/TESTING_STRATEGY.md) §3. Jalankan `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test` sebelum menyerahkan pekerjaan.

## 17. Status Implementasi Saat Ini

Fondasi teknis selesai; semua fitur P0 e-commerce belum dimulai. Banyak perubahan belum di-commit (OQ-11).

## 18. Keterbatasan yang Diketahui

- Homepage = konten demo template pihak ketiga dengan lisensi belum jelas dan hotlink video eksternal.
- Tailwind tanpa preflight demi kompatibilitas Bootstrap.
- Skrip jQuery template tidak dibersihkan saat navigasi SPA.
- Path tak dikenal (termasuk `/api/*`) mengembalikan HTML 200.
- Tidak ada user/role di database, jadi login belum bisa dipakai.
- Belum diuji di browser.

## 19. Keputusan yang Masih Terbuka

Bisnis: Q-001 sampai Q-017 di Decision Log. Baru: OQ-01 sampai OQ-11 di [project-management/OPEN_QUESTIONS.md](project-management/OPEN_QUESTIONS.md), terutama bilingual (OQ-03) dan lisensi template (OQ-04). OQ-01 dan OQ-02 sudah diputuskan (D-020, D-021).

## 20. Prioritas Pengembangan

Lihat [project-management/ROADMAP.md](project-management/ROADMAP.md) (`PROPOSED`): keputusan terbuka dan hardening auth → katalog → keranjang/checkout/order → Midtrans → pengiriman → fondasi promo → produksi → P1.
