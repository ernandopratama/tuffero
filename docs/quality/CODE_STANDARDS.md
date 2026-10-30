# Standar Kode

> Konvensi yang **sudah dipakai** di kode per 2026-10-30, ditambah aturan wajib dari PRD dan persyaratan keamanan. Arsitektur dan konvensi disamakan dengan proyek internal `suntrack-mysql` atas instruksi developer (2026-10-30), kecuali database tetap PostgreSQL.

## 1. PHP / Laravel

- Format: Laravel Pint preset `laravel` (`pint.json`). Jalankan `vendor/bin/pint` sebelum commit.
- Analisis statis: Larastan level 5 untuk `app/` (`phpstan.neon`), harus 0 error.
- Controller API di `App\Http\Controllers\Api\V1`, route di `routes/api.php` di bawah prefix `v1`.
- Respons API selalu lewat trait `App\Traits\ApiResponse` (`success()`, `error()`), format `{success, message, data|errors}`.
- Endpoint SPA yang memakai sesi cookie dibungkus middleware `web` (sesi + CSRF) dan `auth:sanctum` untuk yang terlindungi.
- Otorisasi: permission Spatie (`permission:nama.aksi`) di route + Policy untuk akses per objek. Guard frontend tidak dihitung sebagai otorisasi.
- Validasi: Form Request untuk endpoint baru (saat ini `login` masih memakai `$request->validate`).
- Akses data: repository turunan `App\Repositories\BaseRepository` bila query lebih dari sederhana (belum ada implementasi).
- Model: atribut `#[Fillable]` dan `#[Hidden]` (gaya Laravel 13), cast untuk tipe.
- Uang: integer rupiah, tanpa float.
- Operasi stok/order/pembayaran: dalam `DB::transaction` dengan locking yang sesuai.
- Jangan pernah mengubah migration yang sudah berjalan di lingkungan bersama.

## 2. JavaScript / Vue

- Vue 3 Composition API dengan `<script setup>`.
- Halaman di `resources/js/pages` (huruf kecil), layout di `layouts`, komponen reusable di `components`, logika data di `composables`, state global di `stores` (Pinia).
- HTTP hanya lewat `resources/js/utils/api.js`.
- Route di `resources/js/router.js`; `meta.permission`/`meta.role` hanya untuk UX.
- Tidak memakai `v-html` untuk data dari pengguna atau API.
- Styling memakai token Tailwind Tuffero (`bg-primary`, `text-secondary`, `bg-accent`, `border-border`, `font-accent`).

## 3. Git

- Branch kerja terpisah dari `main`; commit kecil dan terukur.
- Jangan commit `.env`, credential, dump database, atau file besar yang tidak perlu.
- Pesan commit menjelaskan tujuan perubahan.

## 4. Dokumentasi

- Perubahan perilaku = update dokumen terkait + [../project-management/CHANGELOG.md](../project-management/CHANGELOG.md). Lihat [../README.md](../README.md).
- Setelah mengubah kode, jalankan `graphify update .` agar knowledge graph di `graphify-out/` tetap mutakhir (instruksi `CLAUDE.md`).
