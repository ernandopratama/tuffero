# Audit Keamanan Tuffero

- **Tanggal audit:** 2026-10-30
- **Cakupan:** working tree lokal branch `main` (termasuk perubahan yang belum di-commit), PostgreSQL lokal (baca saja), Docker Compose development lokal.
- **Metode:** pembacaan kode dan konfigurasi, `php artisan route:list`, `about`, `db:show`, `migrate:status`, request non-destruktif ke aplikasi lokal (tinker dan `curl` ke `localhost:8080`), `composer audit`, `npm audit`, Larastan.
- **Tidak dilakukan:** uji penetrasi, fuzzing, pengujian terhadap sistem produksi (belum ada), pembacaan `.env`, uji di browser.
- **Batasan:** aplikasi belum memiliki fitur e-commerce. Sebagian besar risiko e-commerce belum bisa diuji karena kodenya belum ada; persyaratannya ada di [SECURITY_REQUIREMENTS.md](SECURITY_REQUIREMENTS.md). Tidak ditemukannya kerentanan tertentu **tidak** berarti aplikasi aman. Audit ini bukan pengganti uji penetrasi profesional.

## 1. Kontrol Keamanan yang Sudah Ada

| Kontrol | Bukti |
|---|---|
| Hash password bcrypt (12 rounds) | `app/Models/User.php` cast `hashed`; `.env.example` `BCRYPT_ROUNDS=12` |
| CSRF pada endpoint auth | `routes/api.php` grup `web`; request tanpa token → 419 (diverifikasi) |
| Regenerasi sesi saat login, invalidasi saat logout | `AuthController@login`, `@logout` |
| Pesan login generik (tidak membedakan email salah vs password salah) | `AuthController@login` |
| Error API berformat JSON untuk 401/403 | `bootstrap/app.php` |
| Cookie sesi `HttpOnly` dan `SameSite=Lax` (default) | `config/session.php` |
| Header `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` | `SecurityHeadersMiddleware`; terverifikasi di respons |
| Mass assignment dibatasi | `#[Fillable(['name','email','password'])]` pada `User` |
| Tidak ada raw query, `v-html`, `innerHTML`, `{!! !!}` di kode aktif | Pencarian kode |
| `.env` diabaikan Git dan dikecualikan dari image Docker | `.gitignore`, `.dockerignore` |
| Nginx menolak dotfile | `docker/nginx.conf`; `GET /.env` → 403 (diverifikasi) |
| Analisis statis Larastan level 5 lulus 0 error | `phpstan.neon` |

## 2. Temuan

Severity mempertimbangkan kondisi saat ini: belum ada user, belum ada data pelanggan, belum ada produksi. Beberapa temuan naik severity saat fitur terkait dibuka; ini dicatat di kolom Dampak.

### 2.1 Kerentanan / Kelemahan Terkonfirmasi

| ID | Kelemahan | Severity | Komponen | Bukti | Dampak | Rekomendasi | Status | Verifikasi |
|---|---|---|---|---|---|---|---|---|
| SEC-01 | Tidak ada rate limit pada login | **Medium** sekarang; **High** begitu ada akun admin | API | `routes/api.php`: `POST /api/v1/auth/login` tanpa `throttle`; tidak ada `RateLimiter` di `app/` | Brute force dan credential stuffing terhadap akun admin tanpa hambatan | `RateLimiter::for('login')` per email+IP dan per IP; respons 429; log kegagalan | Open | Feature test: percobaan ke-N+1 mendapat 429 |
| SEC-02 | CSP efektif tidak membatasi apa pun | Medium | Frontend/Backend | `SecurityHeadersMiddleware`: `default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https: http:` | CSP tidak menghalangi XSS atau skrip pihak ketiga dari domain mana pun | Susun CSP berbasis allowlist (self, Midtrans, provider yang diperlukan); hilangkan `unsafe-eval`, wildcard `https:`/`http:`; pakai nonce/hash. Uji dulu dengan `Content-Security-Policy-Report-Only` | Open | Cek header + uji skrip dari domain tak dikenal diblokir |
| SEC-03 | HTTPS tidak dipaksakan; tidak ada HSTS; cookie sesi tidak ditandai `Secure` | Medium (High di produksi) | Infra/Backend | Respons tanpa `Strict-Transport-Security`; `config/session.php` `secure => env('SESSION_SECURE_COOKIE')` tidak diset di `.env.example` | Cookie sesi admin dapat terkirim lewat HTTP dan disadap | Produksi: TLS, redirect HTTPS, HSTS, `SESSION_SECURE_COOKIE=true`, `TrustProxies` sesuai | Open | Cek header dan atribut cookie di produksi |
| SEC-04 | Endpoint health publik membocorkan pesan exception dan environment | Medium | API | `HealthController@check`: `'error: '.$e->getMessage()` dan `'environment' => config('app.env')` dalam respons; route tanpa auth | Pesan error PDO dapat memuat host, port, nama database, dan username database | Respons publik hanya `ok`/`unhealthy`; detail hanya ke log, atau batasi endpoint (token/IP internal). Laravel sudah punya `/up` | Open | Test: simulasi DB gagal, respons tanpa detail |
| SEC-05 | Dependensi `symfony/mime` rentan (CVE-2026-45070, email header injection) | Low (aplikasi belum mengirim email); Medium saat email aktif | Dependensi | `composer audit`: PKSA-wtxr-p26d-nn42, severity medium, terpasang 8.1.7 (patch di 8.1.10) | Injeksi header email saat fitur email memakai input pengguna | `composer update symfony/mime` (butuh persetujuan; tidak dilakukan audit ini) | Open | `composer audit` bersih |
| SEC-06 | Paket `quill` 2.0.3 rentan XSS (fitur ekspor HTML) | Low | Dependensi | `npm audit`: GHSA-v3m3-f69x-jf25; paket tidak dipakai kode | Risiko bila kelak dipakai untuk konten rich text | Hapus jika tidak dipakai, atau pakai versi yang sudah diperbaiki dan sanitasi output | Open | `npm audit` bersih |
| SEC-07 | 17 skrip pihak ketiga tanpa inventaris lisensi/versi + hotlink video ke domain demo | Medium | Frontend/Supply chain | `resources/js/legacy.js`, `public/js/*`, `pages/Home.vue` (video `demo.awaikenthemes.com`) | Skrip yang tidak terkelola menambah permukaan XSS/supply chain; bila halaman checkout memuat skrip yang sama, ini bertentangan dengan prinsip PCI DSS untuk skrip halaman pembayaran; aset bisa hilang/diganti pihak lain | Inventaris skrip, hapus yang tidak perlu, jangan muat skrip template di halaman checkout/admin, host aset sendiri, verifikasi lisensi (T-1) | Open | Daftar skrip terdokumentasi; checkout tanpa skrip template |
| SEC-08 | Docker Compose development memakai default lemah dan membuka port DB ke semua interface | Low (khusus dev) | Infra | `docker-compose.yml`: `POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}`, port `5433:5432` (bind 0.0.0.0); respons memuat `Server: nginx/1.27.5` dan `X-Powered-By: PHP/8.4.27` | Bila dipakai di server atau jaringan bersama, DB dapat diakses dengan password default; versi software terekspos | Bind DB ke `127.0.0.1`, hapus fallback password, `server_tokens off`, `expose_php=Off`; jangan pakai compose ini untuk produksi | Open | Inspeksi konfigurasi + header |
| SEC-09 | Path API yang tidak ada mengembalikan HTML SPA 200 | Low | API | `routes/web.php` catch-all `.*`; `GET /api/v1/nope` → 200 HTML (diverifikasi) | Klien API dan monitoring salah membaca error; menyulitkan deteksi pemindaian | Kecualikan `api/*` dari catch-all (`->where('any', '^(?!api).*$')`) | Open | Test: `GET /api/v1/nope` → 404 JSON |

### 2.2 Kontrol Keamanan yang Belum Ada

| ID | Kontrol yang hilang | Severity | Komponen | Bukti | Dampak | Rekomendasi | Status | Verifikasi |
|---|---|---|---|---|---|---|---|---|
| SEC-10 | Tidak ada pemisahan admin vs pengguna lain; tidak ada role/permission terdefinisi | High saat endpoint admin dibuat | Backend | Tabel `roles`/`permissions` kosong; route SPA `/admin` hanya `requiresAuth`; tidak ada Policy | Setiap akun yang bisa login (termasuk akun pelanggan di masa depan) akan diperlakukan sebagai admin | Definisikan role (Q-009), seeder role/permission, middleware `permission:` di semua route admin, Policy per model | Open | Test otorisasi per role |
| SEC-11 | Tidak ada MFA untuk admin | High sebelum peluncuran | Backend | Tidak ada fitur MFA | Kompromi password admin = kompromi toko | MFA (TOTP) wajib untuk role admin/staf | Open | Test login admin tanpa faktor kedua ditolak |
| SEC-12 | Tidak ada audit log autentikasi dan aksi admin | Medium | Backend | `AuthController` tanpa logging; tidak ada tabel audit | Insiden tidak dapat ditelusuri | Log event auth; tabel audit untuk aksi admin dan perubahan sensitif | Open | Test: login gagal tercatat |
| SEC-13 | Konfigurasi produksi aman belum ada (`APP_DEBUG=false`, env produksi, secret management) | High sebelum produksi | Infra | `.env.example` `APP_DEBUG=true`; `php artisan about` lokal: Debug ENABLED; respons 419 menampilkan kelas exception dan trace | Stack trace dan detail konfigurasi bocor jika debug terbawa ke produksi | Template env produksi, checklist deploy, verifikasi `about` di server | Open | `php artisan about` di produksi: Debug OFF |
| SEC-14 | Belum ada backup, restore teruji, monitoring, alert | High sebelum peluncuran | Infra | Tidak ada konfigurasi/runbook (Q-015) | Kehilangan data order/pembayaran; insiden tidak terdeteksi | Backup terenkripsi off-site, uji restore, monitoring error dan uptime | Open | Uji restore terdokumentasi |
| SEC-15 | Tidak ada test keamanan | Medium | Kualitas | `tests/` hanya 2 test bawaan | Regresi keamanan tidak terdeteksi | Lihat [../quality/TESTING_STRATEGY.md](../quality/TESTING_STRATEGY.md) | Open | Test berjalan di CI |
| SEC-16 | Audit dependensi belum otomatis; `package-lock.json` belum di-commit | Low | Supply chain | `git status`: `?? package-lock.json`; tidak ada CI | Build tidak reprodusibel; kerentanan baru tidak terpantau | Commit lockfile; jalankan `composer audit`/`npm audit` di CI | Open | CI gagal saat ada advisory tinggi |

### 2.3 Risiko yang Perlu Diselidiki Lebih Lanjut

| ID | Risiko | Severity | Bukti | Langkah |
|---|---|---|---|---|
| SEC-17 | Route bawaan `GET/PUT /storage/{path}` aktif karena disk `local` `serve => true` | Informational | `route:list`; `config/filesystems.php` | Fitur ini memakai URL bertanda tangan; pastikan tidak dibutuhkan dan nonaktifkan bila tidak dipakai; pastikan `APP_KEY` produksi kuat dan rahasia. `NOT VERIFIED`: perilaku tanda tangan tidak diuji |
| SEC-18 | CORS memakai default framework (`config/cors.php` tidak dipublikasikan) | Low | Tidak ada file config | Verifikasi nilai efektif; batasi origin ke domain Tuffero sebelum produksi |
| SEC-19 | Skrip jQuery template tetap berjalan setelah navigasi SPA | Low | `legacy.js` hanya memuat sekali, tanpa teardown | Uji di browser; pastikan tidak ada handler yang memproses input di halaman lain |
| SEC-20 | Google Fonts dan video eksternal mengirim IP pengunjung ke pihak ketiga | Low | `welcome.blade.php`, `Home.vue` | Kaji dari sisi UU PDP; pertimbangkan self-host (Design System §6 sudah menyarankan) |
| SEC-21 | `sanctum.expiration` null (token tidak kedaluwarsa) | Informational | `config/sanctum.php` | Saat ini token tidak diterbitkan (SPA memakai sesi). Set masa berlaku jika token API mulai dipakai |
| SEC-22 | Header `X-XSS-Protection` sudah usang | Informational | `SecurityHeadersMiddleware` | Hapus atau set `0`; andalkan CSP |
| SEC-23 | `TUFFERO/Tuffero_PRD_v0.1.pdf` dan `Tuffero_MVP_Scope_v0.1.pdf` terhapus dari working tree | Informational | `git status` `D` | Konfirmasi penghapusan disengaja (OQ-09); bukan isu keamanan langsung |

### 2.4 Rekomendasi untuk Fitur Mendatang

Fitur berikut belum ada; persyaratannya wajib diterapkan saat dibangun: integritas checkout dan stok (TRX-01 sampai TRX-14), pembayaran Midtrans (PAY-01 sampai PAY-12), pengiriman (SHP-01 sampai SHP-07), akses order guest (AUTH-14), upload gambar produk (WEB-06), pelindungan data pribadi (DATA-01 sampai DATA-08). Lihat [SECURITY_REQUIREMENTS.md](SECURITY_REQUIREMENTS.md).

## 3. Roadmap Perbaikan

Tidak ada perbaikan yang diterapkan dalam audit ini. Semua butir menunggu persetujuan.

### P0 — Wajib sebelum produksi atau sebelum fitur terkait dibuka

| Butir | Temuan |
|---|---|
| Rate limit login sebelum membuat akun admin pertama | SEC-01 |
| Role/permission + otorisasi server-side sebelum endpoint admin pertama | SEC-10 |
| Konfigurasi produksi aman (`APP_DEBUG=false`, secret, env produksi) | SEC-13 |
| HTTPS, HSTS, cookie `Secure` | SEC-03 |
| Verifikasi signature + idempotensi webhook Midtrans sebelum pembayaran aktif | PAY-04, PAY-07 |
| Integritas harga/total/stok di server, transaksi + lock, satu gudang per order sebelum checkout aktif | TRX-01 sampai TRX-11 |
| Mekanisme aman status order guest sebelum halaman status order dibuka | AUTH-14 (Q-008) |

### P1 — Sebelum peluncuran publik

| Butir | Temuan |
|---|---|
| MFA admin | SEC-11 |
| Audit log auth dan aksi admin | SEC-12 |
| Backup terenkripsi, uji restore, monitoring dan alert | SEC-14 |
| Test keamanan otomatis untuk alur kritis | SEC-15 |
| Health endpoint tanpa detail internal | SEC-04 |
| CSP ketat (mulai report-only) | SEC-02 |
| Inventaris/hapus skrip pihak ketiga; tidak ada skrip template di checkout/admin | SEC-07 |
| Update `symfony/mime` | SEC-05 |
| Rate limit checkout, voucher, lookup order, webhook | TRX-13, PAY-11 |

### P2 — Hardening dan operasional

| Butir | Temuan |
|---|---|
| Hardening Docker Compose dev (bind lokal, tanpa password default, sembunyikan versi) | SEC-08 |
| CORS dibatasi | SEC-18 |
| Audit dependensi di CI + commit `package-lock.json` | SEC-16 |
| Hapus `quill` jika tidak dipakai | SEC-06 |
| Kajian UU PDP (retensi, hak subjek data, notifikasi kebocoran) bersama pemilik bisnis dan hukum | DATA-04, DATA-08 |
| Evaluasi CDN/WAF/proteksi bot | INF-10 |

### P3 — Kematangan jangka panjang

| Butir | Temuan |
|---|---|
| 404 JSON untuk path API tak dikenal | SEC-09 |
| Nonaktifkan `serve` disk lokal bila tidak dipakai | SEC-17 |
| Self-host font dan media | SEC-20 |
| Hapus `X-XSS-Protection` | SEC-22 |
| Masa berlaku token Sanctum bila token dipakai | SEC-21 |
| Uji penetrasi profesional sebelum atau segera setelah peluncuran | — |

## 4. Riwayat Audit

| Tanggal | Ringkasan |
|---|---|
| 2026-10-30 | Audit pertama: 9 kelemahan terkonfirmasi, 7 kontrol hilang, 7 risiko untuk diselidiki. Tidak ada temuan Critical karena belum ada data pelanggan, pembayaran, atau sistem produksi. |
