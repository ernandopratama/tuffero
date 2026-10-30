# TUFFERO — Project Status & Handover

> **Tujuan dokumen:** Menjadi catatan status proyek yang bisa dibaca ChatGPT, Claude Code, atau developer lain agar pekerjaan dapat dilanjutkan tanpa mengulang discovery dari awal.
>
> **Penting:** Perbarui dokumen ini setiap kali ada perubahan berarti. Jangan mencatat kredensial, API key, password, token, atau isi file `.env` ke dalam dokumen ini.
>
> **Legenda:** **[Fakta]** = diverifikasi langsung pada audit 2026-10-26. **[Asumsi]** = belum diverifikasi. **[Terbuka]** = pertanyaan yang belum dijawab.

## 1. Identitas Proyek

- **Nama proyek:** Tuffero E-commerce
- **Repository GitHub:** https://github.com/ernandopratama/tuffero
- **Visibilitas repository:** Private (pertahankan sampai audit keamanan selesai)
- **Folder lokal:** `C:\Users\THINKPAD\Desktop\Project\tuffero`
- **Framework:** Laravel 13.38.0 (PHP 8.5.6 lokal; `composer.json` mensyaratkan `^8.3`) **[Fakta]**
- **Database yang dikonfigurasi:** PostgreSQL **[Fakta]**
- **Status deployment:** Belum dikonfirmasi **[Terbuka]**
- **Tanggal pembaruan:** 2026-10-26 (audit kondisi aktual; pembaruan Tahap B migrasi frontend ada di bagian 13.1)

## 2. Tujuan Produk

Tuffero adalah proyek e-commerce B2C untuk produk bakery. Website direncanakan memiliki katalog produk, keranjang, checkout, pembayaran, pengiriman, dan fungsi administrasi.

Ruang lingkup final dan prioritas fitur mengacu pada `docs/product/PRD.md` (v0.2) dan `docs/product/MVP_SCOPE.md` (v0.2). Jangan menganggap fitur sudah selesai hanya karena tercantum di rencana.

## 3. Hasil Audit (2026-10-26)

### 3.1 Lingkungan **[Fakta]**

| Item | Hasil |
|---|---|
| PHP | 8.5.6 (CLI, ZTS) |
| Composer | 2.9.7 |
| Node / npm | v26.0.0 / 11.12.1 |
| `vendor/` | Ada |
| `node_modules/` | Tidak ada saat audit awal. Sudah ada setelah `npm install` pada Tahap B (bagian 13.1) |
| Laravel Boost | Belum terpasang; tidak ada di `composer.json` (lihat bagian 3.7) |
| Dependency utama | `laravel/framework ^13.17`, `laravel/tinker`; dev: `laravel/pail`, `laravel/pao`, `laravel/pint`, `phpunit ^12.5.12` |
| Frontend tooling | Vite 8, `laravel-vite-plugin`, Tailwind CSS 4 (`@tailwindcss/vite`); tanpa Alpine.js, tanpa axios |

### 3.2 Git **[Fakta]**

- Branch: `main`, sejajar dengan `origin/main` (`## main...origin/main`, tanpa ahead/behind). Hanya ada satu branch lokal dan satu remote branch.
- HEAD: `064f7bb Merge origin/main and resolve README conflict`. Tidak ada `.git/MERGE_HEAD` dan tidak ada file konflik (unmerged). **Konflik merge `README.md` yang dicatat sebelumnya sudah selesai.**
- Riwayat: `4064f05` Initial commit (remote), `e4ba05e` chore: initialize Tuffero Laravel project, `064f7bb` merge.
- Remote `ls-remote` menunjukkan `origin/main` di `064f7bb`, jadi merge sudah ter-push.
- Perubahan belum di-commit: `CLAUDE.md` (dimodifikasi, +11 baris), `routes/web.php` (dimodifikasi), dan belum ter-track: `.claude/`, `docs/TUFFERO_PROJECT_STATUS.md`, `install.ps1`, `public/css/`, `public/images/`, `public/js/`, `public/webfonts/`, `resources/views/index.blade.php`.
- `.gitignore` tidak mengabaikan `public/css|js|images|webfonts`, jadi aset template akan ikut ter-commit (lihat risiko R-3).
- Audit ini tidak melakukan merge, commit, push, reset, atau perubahan riwayat.

### 3.3 Konfigurasi database **[Fakta]**

- Hanya kunci non-rahasia yang dibaca dari `.env`: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=tuffero`. Tidak ada kredensial yang disalin ke dokumen ini.
- `SESSION_DRIVER`, `CACHE_STORE`, dan `QUEUE_CONNECTION` memakai `database`.
- `.env` diabaikan Git.
- `php artisan migrate:status` berhasil terhubung ke database dan menampilkan tiga migration berstatus **Ran** (batch 1): `create_users_table`, `create_cache_table`, `create_jobs_table`. Ini membuktikan koneksi PostgreSQL berfungsi dan migration bawaan sudah dijalankan. Perintah ini hanya membaca status.

### 3.4 Kode aplikasi **[Fakta]**

| Area | Kondisi aktual |
|---|---|
| `app/Models` | Hanya `User` (bawaan Laravel) |
| `app/Http/Controllers` | Hanya `Controller` dasar (kosong) |
| `app/Providers` | Hanya `AppServiceProvider` |
| `database/migrations` | 3 migration bawaan (users, cache, jobs). Tidak ada tabel domain |
| `database/seeders` | Hanya `DatabaseSeeder` bawaan |
| `routes/web.php` | Satu route `GET /` yang memanggil `view('index')`; belum ada route lain. `routes/api.php` tidak ada |
| Route aktif (`route:list`) | `GET /`, `GET /up`, `GET|PUT storage/{path}` (4 route, semuanya bawaan framework kecuali `/`) |
| `resources/views` | `welcome.blade.php` (bawaan, tidak lagi dipakai route) dan `index.blade.php` |
| `resources/css/app.css`, `resources/js/app.js` | Berkas bawaan Vite; belum dipakai `index.blade.php` |
| `public/` | `css/`, `js/`, `images/`, `webfonts/` hasil salinan template "Backeri" (Awaiken) |

`resources/views/index.blade.php` adalah hasil konversi `theme/Tuffero/html.awaikenthemes.com/backeri/index-video.html` (template pihak ketiga). Isinya masih **konten demo template (brand "Backeri")**, bukan konten Tuffero; belum mengikuti design system Tuffero. Menu navigasi selain Home mengarah ke `#`. Video hero masih hotlink ke `demo.awaikenthemes.com`. Komentar HTTrack masih ada di berkas. Aset memuat Google Fonts dari CDN.

### 3.5 Testing **[Fakta]**

- `php artisan test`: **lulus, 2 dari 2 test, 2 assertion** (`tests/Feature/ExampleTest.php` dan `tests/Unit/ExampleTest.php`, keduanya bawaan Laravel).
- `phpunit.xml` memakai SQLite `:memory:` dengan cache/session `array`, jadi test tidak menyentuh database PostgreSQL.
- Tidak ada test untuk logika bisnis. Test `ExampleTest` Feature kemungkinan memeriksa route `/`; belum diverifikasi apakah masih sesuai setelah route diubah ke `view('index')` (lulus pada audit ini).
- Belum dijalankan: `npm install`, `npm run build`, `php artisan serve` dengan pengecekan visual di browser, `vendor/bin/pint --test`.

### 3.6 Folder `TUFFERO/` dan `theme/` **[Fakta]**

- `TUFFERO/` berisi: `Notulen Rapat 26-10.txt`, `Tuffero_Design_System.txt`, `Tuffero_Meeting_Discovery_Guide.pdf`, `Tuffero_MVP_Scope_v0.1.pdf`, `Tuffero_PRD_v0.1.pdf`, `Panduan_API_Pengiriman_Tuffero.pdf`, `Panduan_Payment_Gateway_Tuffero.pdf`. Dokumen PDF dan notulen **belum dibaca** pada audit ini; isinya tidak diklaim di sini.
- `theme/` berisi hasil mirror HTTrack template Backeri (HTML statis, aset, cache HTTrack). Bukan bagian aplikasi.

### 3.7 Catatan `CLAUDE.md` **[Fakta]**

`CLAUDE.md` (dimodifikasi, belum di-commit) berisi instruksi bootstrap Laravel Boost, yaitu `composer require laravel/boost --dev` dan `php artisan boost:install`. Laravel Boost belum terpasang. `AGENTS.md` ada di root. Audit ini tidak memasang Boost karena di luar lingkup (tidak boleh mengubah kode/dependency).

## 4. Status Fitur

Status: `Belum mulai`, `Kerangka`, `Sedang dikerjakan`, `Selesai`, `Terblokir`. Tidak ada fitur P0 yang berstatus `Selesai`.

| Area (referensi MVP) | Status | Bukti / catatan |
|---|---|---|
| Bootstrap project Laravel | Selesai | Laravel 13.38.0, `php artisan` berjalan, test bawaan lulus |
| Koneksi PostgreSQL | Terverifikasi | `migrate:status` terhubung dan membaca tabel migrations |
| Migrasi database | Hanya bawaan | 3 migration bawaan berstatus Ran; belum ada tabel domain |
| Git sinkron dengan GitHub | Selesai | Konflik `README.md` selesai; `main` sama dengan `origin/main` |
| Menjalankan aplikasi lokal | Belum diverifikasi | `php artisan serve` dan tampilan browser belum diuji; `node_modules` belum ada |
| Pipeline frontend Tailwind 4 + Vite | Build berhasil; belum diuji visual | `npm run build` sukses, token Tuffero ada di CSS hasil build. `index.blade.php` belum memakai `@vite` (bagian 13.1) |
| Landing page storefront | Kerangka | `index.blade.php` dari template Backeri dengan konten demo, belum bermerek Tuffero, belum memakai design system |
| Katalog produk, detail, varian, SKU | Belum mulai | Tidak ada model, migration, controller, route, atau view |
| Keranjang dan guest checkout | Belum mulai | Tidak ada kode |
| Gudang, stok, reservasi, aturan satu gudang | Belum mulai | Tidak ada model/tabel |
| Order dan snapshot | Belum mulai | Tidak ada kode |
| Midtrans | Belum mulai | Tidak ada package/kode; akses akun belum dikonfirmasi (Q-016) |
| Pengiriman J&T / SiCepat | Belum mulai | Tidak ada kode; akses API belum dikonfirmasi (Q-017) |
| Promosi / voucher | Belum mulai | Tidak ada kode |
| Autentikasi / admin | Belum mulai | Hanya model `User` dan tabel `users` bawaan; tanpa route auth, tanpa role, tanpa panel admin |
| Logging perubahan penting, backup, deployment | Belum mulai | Belum ada runbook (Q-015) |
| Testing | Hanya bawaan | 2 test bawaan lulus; tidak ada test domain |
| AI CS / AI marketing (P1) | Belum mulai | Sesuai prioritas P1 |

## 5. Konteks Teknis dan Keputusan

Keputusan di bagian ini mengikuti `docs/project-management/DECISION_LOG.md` (D-001 s.d. D-019) dan tidak diubah oleh audit.

- Stack awal proyek: Laravel, Blade, PostgreSQL, Tailwind CSS, Alpine.js bila diperlukan, dan Vite. Pada kondisi aktual, Tailwind 4 dan Vite terkonfigurasi di `package.json`, tetapi view memakai CSS/JS template Backeri (Bootstrap) dari `public/`. Alpine.js belum ada.
- Payment gateway yang direncanakan: Midtrans.
- Pengiriman yang direncanakan: integrasi langsung API J&T dan SiCepat, bergantung pada akses/kemitraan.
- Model bisnis: B2C.
- Checkout diharapkan tidak mewajibkan login pelanggan jika memungkinkan.
- Peluncuran awal menggunakan satu gudang di Malang, dengan kemungkinan gudang kedua di Jakarta pada masa depan; model data harus multi-gudang sejak awal.
- Satu pesanan hanya boleh dipenuhi dari satu gudang. Jika satu gudang tidak dapat memenuhi semua item, checkout harus diblokir dengan penjelasan yang jelas; jangan membagi satu order otomatis.
- Harga, promo, ongkir, stok, dan total dihitung ulang di server.
- Design system: Dark Chocolate `#2B1B14`, Champagne Gold `#C6A15B`, Warm Ivory `#F6F0E5`, Roasted Brown `#6D4C3D`, White `#FFFFFF`, Border `#E3D5C0`; Manrope dengan Playfair Display Italic sebagai aksen.

## 6. Kesenjangan terhadap PRD dan MVP

Hampir seluruh butir P0 pada `docs/product/MVP_SCOPE.md` masih belum dikerjakan (semua checkbox P0 masih kosong, dan kondisi kode sesuai itu). Kesenjangan utama:

1. Tidak ada skema domain: produk, varian, SKU, gudang, stok, keranjang, order, item order, pembayaran, pengiriman, promo/voucher.
2. Tidak ada alur transaksi: keranjang, guest checkout, perhitungan server-side, snapshot order.
3. Tidak ada integrasi: Midtrans (sandbox dan webhook idempoten) dan kurir J&T/SiCepat.
4. Tidak ada autentikasi/otorisasi admin dan panel admin.
5. Landing page masih konten template pihak ketiga dan belum sesuai design system Tuffero.
6. Test hanya bawaan; belum ada test untuk stok, concurrency, webhook, atau otorisasi.
7. Operasi: belum ada backup, restore, deployment, dan runbook.

## 7. Risiko

| ID | Risiko | Catatan |
|---|---|---|
| R-1 | Konten dan aset template berlisensi pihak ketiga ("Backeri", Awaiken) akan ter-commit ke repo | Konfirmasi lisensi dan kebutuhan atribusi sebelum dipakai produksi; ganti konten/brand dengan aset Tuffero asli |
| R-2 | Video hero hotlink ke `demo.awaikenthemes.com` | Bisa mati atau diblokir kapan saja; ganti dengan aset Tuffero sendiri |
| R-3 | `public/css|js|images|webfonts` (sekitar 150 berkas, termasuk berkas besar) belum di-track dan tidak diabaikan Git | Putuskan apakah di-commit, dipindah ke `resources/` agar diproses Vite, atau diabaikan |
| R-4 | Stack ganda: Bootstrap/jQuery dari template versus rencana Tailwind/Alpine/Vite | Putuskan satu pendekatan sebelum membangun halaman lain |
| R-5 | `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` bernilai `database` | Normal untuk default Laravel, tetapi tabel dan worker harus tersedia di lingkungan lain |
| R-6 | `CLAUDE.md` berisi bootstrap Boost yang belum dijalankan | Putuskan apakah Boost dipasang atau instruksi dihapus; perubahan belum di-commit |
| R-7 | Ketergantungan eksternal (Midtrans, API kurir) belum dikonfirmasi | Lihat Q-016 dan Q-017 |
| R-8 | Dokumen PDF/notulen di `TUFFERO/` belum diaudit | Bisa memuat keputusan yang belum tercermin di docs |

## 8. Pertanyaan Terbuka **[Terbuka]**

Pertanyaan bisnis Q-001 s.d. Q-017 tetap berlaku sesuai `docs/project-management/DECISION_LOG.md`. Tambahan dari audit teknis:

- T-1: Apakah template Backeri boleh dipakai (lisensi) atau hanya acuan layout?
- T-2: Pendekatan frontend: tetap Bootstrap dari template, atau Blade + Tailwind 4 + Vite sesuai rencana stack?
- T-3: Apakah aset `public/` hasil salinan template akan di-commit?
- T-4: Apakah Laravel Boost akan dipasang?
- T-5: Pilihan paket admin (build sendiri atau paket pihak ketiga seperti Filament) belum diputuskan.

## 9. Langkah Berikutnya yang Disarankan (belum dikerjakan)

1. Bereskan working tree: putuskan nasib `public/` aset template, `install.ps1`, `.claude/`, dan `CLAUDE.md`; lalu commit atas persetujuan.
2. Jalankan `php artisan serve` dan `npm install` lalu verifikasi tampilan `/`; tentukan pendekatan frontend (T-2).
3. Rancang skema domain (produk, varian/SKU, gudang, stok per gudang, order, item order, pembayaran, pengiriman) sebagai migration dengan constraint pencegah stok minus; tulis test sejak awal.
4. Bangun katalog read-only (model, seeder contoh, halaman daftar dan detail), lalu keranjang dan guest checkout dengan perhitungan server-side.
5. Integrasi Midtrans sandbox dengan webhook idempoten; mock layanan kurir sampai akses API ada.
6. Tambahkan autentikasi dan otorisasi admin (putuskan T-5 dan Q-009).
7. Paralel: dapatkan jawaban Q-001 s.d. Q-017 dan data katalog dari tim Tuffero.

## 10. Struktur Folder Penting

- `app/` — kode aplikasi, model, controller, dan sebagainya.
- `routes/` — definisi route.
- `resources/` — view Blade, CSS, dan JavaScript.
- `database/` — migration, factory, dan seeder.
- `tests/` — automated tests.
- `config/` — konfigurasi Laravel.
- `docs/` — dokumen PRD, MVP, keputusan, dan handover.
- `TUFFERO/` — dokumen referensi bisnis/desain.
- `theme/` — mirror HTTrack template Backeri (bukan bagian aplikasi).
- `public/css|js|images|webfonts` — aset hasil salinan template.
- `.env` — konfigurasi lokal rahasia; jangan commit atau salin ke laporan.

## 11. Dokumen Referensi

- `docs/product/PRD.md`
- `docs/product/MVP_SCOPE.md`
- `docs/project-management/DECISION_LOG.md`
- `TUFFERO/Tuffero_Design_System.txt`
- `TUFFERO/Notulen Rapat 26-10.txt` dan PDF payment/shipping di `TUFFERO/` (belum diaudit)

Jika isi file berbeda dari catatan di sini, periksa sumber aslinya dan perbarui catatan ini. Jangan menebak isi dokumen yang belum dibaca.

## 12. Perintah Pemeriksaan

```powershell
php artisan --version
php artisan route:list
php artisan migrate:status
php artisan test
git status
git log --oneline --graph --decorate -8
```

Hasil terakhir (2026-10-26): semua perintah di atas berhasil; lihat bagian 3.

## 13. Catatan Pekerjaan Terakhir

**Tanggal:** 2026-10-26

1. Membuat project Laravel lokal, inisialisasi Git, menambahkan remote `origin`, commit `e4ba05e`.
2. Push awal ditolak karena riwayat berbeda; merge `origin/main --allow-unrelated-histories` konflik pada `README.md`; konflik diselesaikan dan di-commit sebagai `064f7bb`, ter-push ke `origin/main`.
3. Mengonversi `index-video.html` template Backeri menjadi `resources/views/index.blade.php`, menyalin aset ke `public/`, dan mengubah route `/` ke `view('index')`. Belum di-commit.
4. Audit kondisi aktual (dokumen ini).

### 13.1 Migrasi Frontend ke Tailwind CSS 4 + Vite (Tahap A dan B)

**Tanggal:** 2026-10-26. Cakupan yang disetujui: audit (Tahap A) dan validasi pipeline build (Tahap B). Layout homepage belum dibangun.

**Kondisi awal**
- `package.json` sudah mendeklarasikan Tailwind 4, `@tailwindcss/vite`, Vite 8, dan `laravel-vite-plugin`, tetapi belum ada `package-lock.json`, `node_modules/`, dan `public/build`.
- `vite.config.js` dan `resources/css/app.css` masih memakai font bawaan Laravel (Instrument Sans) tanpa token warna Tuffero. `resources/js/app.js` hanya berisi komentar kosong.
- `index.blade.php` tidak memakai `@vite`. Halaman memuat 8 CSS dan 17 JS template Backeri dari `public/` (Bootstrap, jQuery, GSAP, dan lainnya), font Lora dan Plus Jakarta Sans dari Google Fonts, video hero hotlink ke `demo.awaikenthemes.com`, dan teks "Backeri".
- `routes/web.php` diawali BOM UTF-8 (`EF BB BF`) sebelum `<?php`, akibat penulisan dengan `Set-Content -Encoding utf8` pada sesi sebelumnya.
- Tidak ada berkas lisensi template di `theme/`. Satu-satunya petunjuk adalah header `Author : Awaiken` di `public/css/custom.css`. Lisensi belum terverifikasi. Logo resmi Tuffero tidak ditemukan di `TUFFERO/` maupun `public/`.

**Perubahan file**

| File | Perubahan | Alasan |
|---|---|---|
| `routes/web.php` | BOM dihapus; isi tidak berubah | BOM berisiko memicu output sebelum header |
| `vite.config.js` | Font `bunny('Instrument Sans')` diganti `bunny('Manrope')` (bobot 400 sampai 800) dan `bunny('Playfair Display')` (italic 400, 500, 600) | Mengikuti design system |
| `resources/css/app.css` | `--font-sans` menjadi Manrope; tambah `--font-accent` (Playfair Display); tambah token warna `--color-primary`, `--color-accent`, `--color-accent-hover` (`#D8B978`, hover yang disarankan design system), `--color-background`, `--color-secondary`, `--color-surface`, `--color-border` | Token Tailwind sesuai design system |
| `package-lock.json` | Baru, dibuat oleh `npm install` (94 KB) | Belum ada lockfile sebelumnya |
| `node_modules/`, `public/build/` | Dibuat; keduanya sudah ada di `.gitignore` | Hasil install dan build |

Tidak diubah: `index.blade.php`, seluruh aset `public/css|js|images|webfonts`, `resources/js/app.js`, `package.json`, `.npmrc`. Tidak ada berkas yang dihapus. Tidak ada commit atau push.

**Dependency (terpasang dari range di `package.json`, tidak ada paket baru)**

| Paket | Range | Terpasang |
|---|---|---|
| `tailwindcss` | `^4.0.0` | 4.3.3 |
| `@tailwindcss/vite` | `^4.0.0` | 4.3.3 |
| `vite` | `^8.0.0` | 8.3.5 |
| `laravel-vite-plugin` | `^3.1` | 3.2.0 |
| `concurrently` | `^10.0.3` | terpasang (tidak dipakai script saat ini) |
| `@laravel/multiplex` (optional) | `^0.4.1` | tidak diverifikasi |

Tidak ada Alpine.js, Bootstrap npm, atau jQuery npm. Bootstrap dan jQuery hanya ada sebagai berkas statis template di `public/`.

**Perintah dan hasil**

| Perintah | Hasil |
|---|---|
| `npm install` | Sukses: 56 paket ditambahkan, 0 vulnerabilities. `ignore-scripts=true` tidak menghambat |
| `npm run build` | Sukses (3,25 detik). Keluaran: `app-*.css` 56,81 kB, `app-*.js` 0,00 kB (kosong), `fonts-*.css`, `fonts-manifest.json`, berkas woff/woff2 Manrope dan Playfair Display. `public/build/manifest.json` terbentuk |
| Cek CSS hasil build | Token `--color-primary` `#2b1b14`, `--color-accent` `#c6a15b`, `--color-background` `#f6f0e5`, dan `--font-accent` ada |
| `php artisan test` | Lulus, 2 dari 2 test (test bawaan) |
| `php artisan view:cache` | Sukses |
| `git check-ignore` | `public/build` dan `node_modules` diabaikan Git |

**Belum diverifikasi**
- Pengujian visual di browser: belum dilakukan. `npm run dev` dan `php artisan serve` belum dijalankan.
- Font di `fonts-*.css` hanya dicek sebagian (`font-family: "Manrope"` muncul); pemuatan font di browser belum diuji.
- Belum ada halaman yang memanggil `@vite`, jadi hasil build belum dipakai oleh halaman mana pun.
- `vendor/bin/pint --test` belum dijalankan.

**Error / masalah yang belum selesai**
- Tidak ada error build atau test.
- Halaman `/` masih Bootstrap, jQuery, dan konten Backeri. Lisensi template, video demo eksternal, logo resmi Tuffero, dan stack ganda (R-1 sampai R-4) belum diselesaikan.
- `CLAUDE.md` masih berisi instruksi bootstrap Laravel Boost (R-6).
- Perubahan di atas belum di-commit.

**Langkah berikutnya (menunggu instruksi)**
1. Jalankan `npm run dev` dan `php artisan serve` untuk memeriksa pemuatan font dan token di browser.
2. Bangun layout homepage dengan Blade dan Tailwind (layout induk dengan `@vite`, header/footer sesuai design system), tanpa aset Backeri.
3. Dapatkan logo resmi Tuffero dan foto produk asli; putuskan nasib `public/css|js|images|webfonts` setelah lisensi dikonfirmasi.
4. Putuskan apakah JS interaktif memerlukan Alpine.js (perlu persetujuan).

### 13.2 Layout Homepage Tuffero (Blade + Tailwind 4 + Vite)

**Tanggal:** 2026-10-26.

**Kondisi awal:** `git status` sama seperti bagian 13.1 (belum ada commit). `resources/views/index.blade.php` masih konversi template Backeri (Bootstrap, jQuery, aset `public/`, tanpa `@vite`).

**Perubahan file**

| File | Perubahan |
|---|---|
| `resources/views/layouts/app.blade.php` | Baru. Layout induk dengan `@vite(['resources/css/app.css', 'resources/js/app.js'])`, skip link, `@include` header dan footer, `@yield('content')` |
| `resources/views/partials/header.blade.php` | Baru. Header Dark Chocolate, wordmark teks placeholder (bukan logo resmi), nav desktop, menu mobile memakai `<details>` tanpa JS, CTA emas |
| `resources/views/partials/footer.blade.php` | Baru. Footer dengan teks placeholder |
| `resources/views/index.blade.php` | Ditulis ulang: hero, kategori (4 kartu placeholder), produk unggulan (4 kartu statis "Segera Hadir", tombol nonaktif), CTA |
| `resources/views/legacy/backeri-index.blade.php` | Hasil pemindahan `index.blade.php` Backeri lama. Tidak dihapus. Bukan route aktif; masih memuat aset `public/` Backeri |

Tidak diubah: `routes/web.php` (route `/` tetap `view('index')`), `.env`, `public/*` aset template, `app.css`, `vite.config.js`. Tanpa Bootstrap, jQuery, Alpine, atau aset Backeri di halaman baru. Tanpa gambar; semua gambar adalah kotak placeholder berlabel. Wordmark "TUFFERO" adalah teks biasa, bukan logo resmi. Teks hero/CTA/footer adalah copy placeholder.

**Dependency:** tidak ada perubahan (lihat 13.1).

**Perintah dan hasil**

| Perintah | Hasil |
|---|---|
| `npm run build` | Sukses (2,60 detik); `app-*.css` 64,25 kB |
| `php artisan view:cache` | Sukses |
| `php artisan test` | Lulus 2 dari 2 (termasuk `GET /` status 200) |

**Belum diverifikasi:** tampilan di browser (responsif, kontras, fokus keyboard, pemuatan font) belum diperiksa langsung. `pint --test` belum dijalankan. `legacy/backeri-index.blade.php` ikut dikompilasi `view:cache` dan perlu diputuskan nasibnya.

**Error belum selesai:** tidak ada error build/test. Masih terbuka: logo resmi, foto produk, copy resmi, nama kategori, lisensi Backeri, tautan nav (`Tentang`, `Produk`, `Kategori` hanya anchor di halaman), perubahan belum di-commit.

**Langkah berikutnya**
1. Cek visual dengan `npm run dev` + `php artisan serve` (mobile dan desktop).
2. Terima logo, foto, dan copy resmi; ganti placeholder.
3. Putuskan hapus atau simpan `views/legacy` dan aset `public/css|js|images|webfonts`.
4. Baru setelah itu: katalog dari database.

### 13.3 Pemulihan Tampilan Homepage Backeri

**Tanggal:** 2026-10-30.

**Penyebab perubahan tampilan:** pada 13.2, `resources/views/index.blade.php` ditulis ulang dengan Blade layout + Tailwind 4 (hero cokelat gelap, kartu placeholder), menggantikan konversi template Backeri (Bootstrap, jQuery, aset `public/`). Tidak ada commit, jadi riwayat Git tidak memuat versi lama; sumber pemulihan adalah salinan `resources/views/legacy/backeri-index.blade.php` (identik dengan `index.blade.php` sebelum 13.2).

**Metode:** salin `legacy/backeri-index.blade.php` ke `index.blade.php` (hash identik, terverifikasi). Desain Tailwind dipindah, tidak dihapus, ke `resources/views/legacy/tuffero-tailwind-index.blade.php`. Tanpa operasi Git, tanpa commit.

| File | Perubahan |
|---|---|
| `resources/views/index.blade.php` | Dipulihkan ke tampilan Backeri |
| `resources/views/legacy/tuffero-tailwind-index.blade.php` | Baru; arsip desain 13.2 (meng-extend `layouts.app`, bukan route aktif) |
| `layouts/app`, `partials/header`, `partials/footer` | Tidak diubah, tidak dipakai route aktif (dipakai arsip di atas) |
| `routes/web.php`, `vite.config.js`, `resources/css/app.css`, `.env` | Tidak diubah. Route `/` tetap `view('index')` |

**Tailwind/Vite:** dipertahankan. Halaman Backeri tidak memanggil `@vite`, jadi tidak ada konflik CSS; build tidak dijalankan ulang karena konfigurasi frontend tidak berubah.

**Pengujian:** `php artisan test` lulus 2 dari 2 (termasuk `GET /` 200). 82 referensi `asset()` di view diperiksa; semua ada di `public/` kecuali `images/hero-bg-video.mp4`, yang hanya muncul di komentar HTML. **Tampilan belum diuji di browser**; hanya diverifikasi lewat kode dan test.

**Status lisensi aset:** belum terverifikasi (T-1, R-1). Template Backeri oleh Awaiken; tidak ada berkas lisensi di `theme/`. Video hero masih hotlink ke `demo.awaikenthemes.com` (R-2). Jangan dipakai produksi sebelum lisensi dikonfirmasi.

**Langkah berikutnya:** cek visual dengan `php artisan serve`; konfirmasi lisensi Backeri; putuskan arah frontend final (T-2) sebelum mengerjakan halaman lain.

## 14. Template Update Harian

```text
Tanggal:
Tujuan sesi:
File yang dibuat/diubah:
Fitur yang selesai:
Perintah yang dijalankan:
Hasil test:
Masalah/error:
Keputusan baru:
Langkah berikutnya:
Commit Git (jika ada):
```

## 15. Instruksi untuk AI / Developer Berikutnya

Sebelum mengubah kode:

1. Baca dokumen ini dan dokumen referensi di bagian 11.
2. Periksa kondisi aktual file dan Git; jangan menganggap status di dokumen selalu mutakhir.
3. Bedakan antara fitur yang direncanakan, sedang dibuat, dan sudah terbukti berjalan.
4. Jangan membaca, menampilkan, atau meng-commit `.env`; jangan meminta pengguna membagikan secret.
5. Jangan menjalankan perintah destruktif seperti `git reset --hard`, `git push --force`, atau menghapus database tanpa menjelaskan dampaknya dan mendapat persetujuan.
6. Buat perubahan sekecil dan seterukur mungkin, lalu jelaskan file yang berubah dan cara mengujinya.
7. Setelah pekerjaan selesai, bantu perbarui bagian status fitur dan catatan pekerjaan terakhir.

> **Catatan pembaruan 2026-10-30:** dokumen ini dipindah dari `docs/TUFFERO_PROJECT_STATUS.md` ke `docs/project-management/PROJECT_STATUS.md`. Bagian 1–15 adalah catatan historis dan tidak ditulis ulang. Kondisi terkini ada di bagian 16 dan 17. Bagian 3.4, 4, dan 10 tidak lagi mencerminkan kode (misalnya `index.blade.php` sudah tidak ada dan frontend sudah Vue SPA).

## 16. Perubahan Stack (2026-10-30)

Atas instruksi developer, dalam satu sesi:

1. **Frontend ke Vue 3.** Awalnya Inertia.js + Vue 3, lalu diganti Vue 3 SPA (vue-router, Pinia, axios) agar sama dengan proyek `suntrack-mysql`. Homepage Backeri di-port apa adanya ke `resources/js/pages/Home.vue`; skrip jQuery template dimuat oleh `resources/js/legacy.js`. `resources/views/index.blade.php` dihapus (salinannya tetap di `resources/views/legacy/backeri-index.blade.php`). `welcome.blade.php` menjadi shell SPA; `routes/web.php` menjadi catch-all.
2. **Backend disamakan dengan arsitektur suntrack-mysql (tanpa kode bisnisnya), DB tetap PostgreSQL.** Ditambah `laravel/sanctum`, `spatie/laravel-permission`, `larastan/larastan`; `routes/api.php` (`/api/v1/health`, `/api/v1/auth/login|logout|user`); `AuthController`, `HealthController`, `SecurityHeadersMiddleware`, `ApiResponse`, `BaseRepository`, `RepositoryInterface`; `bootstrap/app.php` dengan alias Spatie dan error JSON; `phpstan.neon`, `pint.json`. Migration permission dan personal access tokens dijalankan ke PostgreSQL lokal.
3. **Docker development.** `Dockerfile` (Node 24 + php:8.4-fpm, `pdo_pgsql`), `docker-compose.yml` (app, queue, web nginx :8080, db postgres :5433), `docker/nginx.conf`, `.dockerignore`. Diverifikasi berjalan: `/`, `/login`, `/api/v1/health` → 200; migrasi di DB container sukses.
4. `.env.example`: `DB_CONNECTION=pgsql`.

Tidak disalin dari suntrack: kode bisnis, PWA, theme store, ActivityLogger, DataScopeService, folder `deploy/webuzo`.

Belum di-commit. Pembaruan: arah frontend diputuskan hybrid (D-021) dan pengiriman MVP lewat Biteship (D-020) pada 2026-10-30; migrasi kode ke hybrid adalah tugas terpisah.

## 17. Status Terkini (Audit 2026-10-30)

Sumber terkini dan lengkap:

- Status modul: `docs/AI_PROJECT_CONTEXT.md` bagian 10.
- Temuan keamanan dan roadmap perbaikan: `docs/security/SECURITY_AUDIT.md`.
- Pertanyaan terbuka baru: `docs/project-management/OPEN_QUESTIONS.md`.
- Usulan urutan kerja: `docs/project-management/ROADMAP.md`.

Ringkas: fondasi teknis (Laravel 13, PostgreSQL, Vue SPA, auth API, RBAC package, Docker dev) ada. Seluruh fitur P0 e-commerce (katalog, keranjang, checkout, order, stok/gudang, Midtrans, pengiriman, promosi, admin fungsional) **belum dimulai**. Test hanya 2 test bawaan.
