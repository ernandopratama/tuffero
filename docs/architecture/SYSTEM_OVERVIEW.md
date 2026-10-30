# Gambaran Sistem (Kondisi Aktual)

> Diverifikasi terhadap kode pada 2026-10-30. Semua yang ada di dokumen ini `IMPLEMENTED` kecuali diberi label lain. Arsitektur domain e-commerce yang belum dibangun ada di [PROPOSED_DOMAIN_ARCHITECTURE.md](PROPOSED_DOMAIN_ARCHITECTURE.md).

## 1. Ringkasan

Tuffero saat ini adalah **kerangka aplikasi**, belum toko online yang berfungsi. Yang sudah ada:

- Laravel 13.38 (PHP 8.5 lokal, PHP 8.4 di Docker) dengan PostgreSQL.
- Frontend Vue 3 Single Page Application (SPA) dengan `vue-router` dan Pinia, dibundel Vite 8.
- Homepage berupa port template pihak ketiga "Backeri" (Bootstrap 5.3.3 + jQuery + plugin) ke komponen Vue.
- API JSON `/api/v1` dengan tiga endpoint autentikasi (Sanctum, sesi cookie) dan satu endpoint health.
- Paket RBAC `spatie/laravel-permission` terpasang, tetapi belum ada role atau permission.
- Docker Compose untuk development lokal (app, queue, nginx, postgres).

Belum ada: katalog, keranjang, checkout, order, stok, gudang, pembayaran, pengiriman, promosi, admin fungsional, AI. Lihat [../AI_PROJECT_CONTEXT.md](../AI_PROJECT_CONTEXT.md) bagian 10.

## 2. Arsitektur Saat Ini

```mermaid
flowchart LR
    subgraph Browser
        SPA["Vue 3 SPA<br/>resources/js/app.js"]
        Legacy["Skrip template Backeri<br/>public/js/*.js (jQuery, GSAP, Swiper)"]
    end
    subgraph Laravel["Laravel 13 (php-fpm)"]
        Web["routes/web.php<br/>GET /{any} → view('welcome')"]
        Api["routes/api.php<br/>/api/v1/*"]
        Auth["AuthController"]
        Health["HealthController"]
        MW["SecurityHeadersMiddleware<br/>(global)"]
    end
    DB[("PostgreSQL<br/>users, sessions, cache,<br/>jobs, roles/permissions,<br/>personal_access_tokens")]
    Queue["queue:work<br/>(tidak ada job aplikasi)"]
    Ext1["demo.awaikenthemes.com<br/>(video hero)"]
    Ext2["fonts.googleapis.com"]

    SPA -- "HTML shell" --> Web
    SPA -- "axios JSON + cookie sesi" --> Api
    Api --> Auth --> DB
    Api --> Health --> DB
    SPA -- "Home.vue onMounted" --> Legacy
    Legacy -.-> Ext1
    SPA -.-> Ext2
    Queue --> DB
```

## 3. Siklus Request Frontend ke Backend

### 3.1 Muat halaman (semua path non-API)

1. Browser meminta path apa pun, misalnya `/`, `/login`, `/admin`.
2. `routes/web.php` menangkap semua path dengan `Route::get('/{any}', ...)->where('any', '.*')` dan mengembalikan `resources/views/welcome.blade.php`.
3. `welcome.blade.php` memuat 8 file CSS template dari `public/css`, Google Fonts (Lora, Plus Jakarta Sans), lalu `@vite(['resources/css/app.css', 'resources/js/app.js'])`, dan menyediakan `<div id="app">`.
4. `resources/js/app.js` membuat aplikasi Vue, memasang Pinia dan router, mendaftarkan helper global `$can` dan `$hasRole`, lalu `mount('#app')`.
5. `resources/js/router.js` memilih komponen halaman (lazy-loaded) dan menjalankan guard `beforeEach`.

Konsekuensi: path yang tidak ada (termasuk `GET /api/v1/path-tidak-ada`) mengembalikan HTML SPA dengan status 200, bukan 404. Lihat temuan SEC-11 di [../security/SECURITY_AUDIT.md](../security/SECURITY_AUDIT.md).

### 3.2 Panggilan API

```mermaid
sequenceDiagram
    participant C as Komponen Vue / Pinia store
    participant AX as utils/api.js (axios)
    participant L as Laravel
    participant MW as Middleware
    participant CT as Controller
    participant DB as PostgreSQL

    C->>AX: api.get('/auth/user')
    AX->>L: GET /api/v1/auth/user<br/>Accept: application/json, cookie sesi, X-XSRF-TOKEN
    L->>MW: grup "web" (sesi, CSRF, cookie) + auth:sanctum + SecurityHeaders
    MW->>CT: AuthController@user
    CT->>DB: baca user, role, permission (Spatie)
    DB-->>CT: data
    CT-->>AX: JSON {success, message, data}
    AX-->>C: response.data
    Note over AX: 401 → router.push({name: 'Login'})
```

- `resources/js/utils/api.js`: axios instance, `baseURL: '/api/v1'`, `withCredentials: true`, header `Accept: application/json`. Interceptor mengarahkan ke halaman Login bila menerima 401.
- Semua respons memakai format dari `app/Traits/ApiResponse.php`: `{success, message, data}` untuk sukses dan `{success, message, errors}` untuk gagal.
- `bootstrap/app.php` membuat semua error di `api/*` berformat JSON dan menormalkan 401/403 (termasuk exception Spatie) ke format yang sama.

## 4. Alur Autentikasi (`IMPLEMENTED`, belum dipakai untuk data apa pun)

```mermaid
sequenceDiagram
    actor U as Pengguna
    participant P as pages/Login.vue
    participant S as stores/auth.js
    participant L as Laravel
    U->>P: isi email + password
    P->>S: auth.login(form)
    S->>L: GET /sanctum/csrf-cookie
    L-->>S: cookie XSRF-TOKEN
    S->>L: POST /api/v1/auth/login (X-XSRF-TOKEN)
    L->>L: validate email/password → Auth::attempt → session()->regenerate()
    alt kredensial benar
        L-->>S: 200 {user: id, name, email, role, roles, permissions}
        S-->>P: true → router ke /admin (Dashboard)
    else salah
        L-->>S: 401 "The provided credentials do not match our records."
        S-->>P: tampilkan pesan error
    end
```

- Guard router (`resources/js/router.js`): route `meta.public` lewat langsung; route lain memanggil `fetchUser()` jika belum ada user; `requiresAuth` tanpa sesi diarahkan ke Login; `guestOnly` dengan sesi diarahkan ke Dashboard; `meta.permission` dan `meta.role` dicek terhadap data user dari server.
- Guard di frontend **hanya untuk UX**. Belum ada endpoint admin di backend yang perlu dilindungi; saat dibuat, wajib memakai middleware `permission:`/`role:` atau Policy di server.
- Tabel `users` saat ini kosong dan tidak ada seeder, jadi login belum bisa dipakai siapa pun.
- Logout: `POST /api/v1/auth/logout` → `Auth::guard('web')->logout()`, invalidasi sesi, regenerasi token CSRF.

## 5. Arsitektur Deployment

### 5.1 Development lokal (`IMPLEMENTED`)

Dua cara menjalankan:

1. Host langsung: `php artisan serve` (port 8000) + PostgreSQL lokal port 5432 + `npm run dev`/`npm run build`.
2. Docker Compose (`docker-compose.yml`), diverifikasi berjalan 2026-10-30:

```mermaid
flowchart LR
    B["Browser<br/>localhost:8080"] --> N["web<br/>nginx:1.27-alpine<br/>docker/nginx.conf"]
    N -- "FastCGI :9000" --> A["app<br/>php:8.4-fpm (Dockerfile)"]
    Q["queue<br/>php artisan queue:work"] --> D
    A --> D[("db<br/>postgres:17-alpine<br/>host port 5433")]
    H["Folder project di host"] -.->|bind mount| A & Q & N
```

- Kode di-mount dari host, sehingga `vendor/` dan `public/build` dari host yang dipakai container.
- Database container terpisah dari PostgreSQL lokal di port 5432.
- `Dockerfile` multi-stage: Node 24 untuk `npm run build`, lalu `php:8.4-fpm-bookworm` dengan ekstensi `pdo_pgsql`, `gd`, `intl`, `zip`, `bcmath`, `opcache`, `pcntl`, `redis`.

### 5.2 Produksi (`PLANNED`, belum ada)

Menurut D-002 sampai D-004: domain `tuffero.com` di Jagoan Hosting, VPS Niagahoster, dikelola Ernando. Paket VPS, OS, cara deploy, SSL, backup, dan monitoring belum ditentukan (Q-015). Tidak ada konfigurasi produksi, CI/CD, atau runbook di repository. Lihat [../infrastructure/ENVIRONMENT_AND_DEPLOYMENT.md](../infrastructure/ENVIRONMENT_AND_DEPLOYMENT.md).

## 6. Struktur Direktori yang Relevan

| Path | Isi aktual |
|---|---|
| `app/Http/Controllers/Controller.php` | Base controller dengan trait `AuthorizesRequests` |
| `app/Http/Controllers/Api/V1/` | `AuthController`, `HealthController` |
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | Header keamanan global |
| `app/Traits/ApiResponse.php` | Format respons JSON standar |
| `app/Repositories/BaseRepository.php`, `app/Contracts/Repositories/RepositoryInterface.php` | Pola repository generik; **belum dipakai** oleh kelas mana pun |
| `app/Models/User.php` | Satu-satunya model; trait `HasApiTokens`, `HasRoles` |
| `bootstrap/app.php` | Routing web/api, middleware global, alias Spatie, format error API |
| `routes/web.php`, `routes/api.php` | Catch-all SPA; API v1 |
| `resources/views/welcome.blade.php` | Shell SPA |
| `resources/views/layouts`, `partials`, `legacy/` | Blade lama; **tidak dipakai route mana pun** |
| `resources/js/` | SPA Vue (lihat [FRONTEND_ARCHITECTURE.md](FRONTEND_ARCHITECTURE.md)) |
| `public/css`, `public/js`, `public/images`, `public/webfonts` | Aset template Backeri |
| `theme/` | Mirror HTTrack template Backeri; bukan bagian aplikasi |
| `TUFFERO/` | Dokumen bisnis/desain (lihat [../product/REFERENCE_DOCUMENTS.md](../product/REFERENCE_DOCUMENTS.md)) |
| `Dockerfile`, `docker-compose.yml`, `docker/nginx.conf`, `.dockerignore` | Container development |
| `phpstan.neon`, `pint.json` | Larastan level 5 untuk `app/`; Pint preset `laravel` |
