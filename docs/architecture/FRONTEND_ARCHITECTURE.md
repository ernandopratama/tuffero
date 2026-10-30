# Arsitektur Frontend

> Diverifikasi terhadap kode pada 2026-10-30.

## 1. Stack Aktual

| Komponen | Versi terpasang | Dipakai untuk |
|---|---|---|
| Vue | 3.5.x | Seluruh UI (SPA) |
| vue-router | 5.x | Routing sisi klien (`createWebHistory`) |
| Pinia | 4.x | State (`stores/auth.js`) |
| axios | 1.x | HTTP ke `/api/v1` |
| Vite | 8.3.x + `laravel-vite-plugin` 3.x + `@vitejs/plugin-vue` 6.x | Bundling |
| Tailwind CSS | 4.3.x via `@tailwindcss/vite` | Utility class di halaman Login/Admin |
| quill, `@fortawesome/fontawesome-free` | Terpasang | **Belum dipakai** di kode (disamakan dengan proyek suntrack) |
| Template Backeri | Bootstrap 5.3.3, jQuery 3.7.1, GSAP, SplitText, ScrollTrigger, Swiper, WOW, Magnific Popup, SlickNav, counterup, waypoints, parallaxie, YTPlayer, SmoothScroll | Homepage saja, dimuat dari `public/js` |

Catatan: arah yang disetujui adalah **hybrid** (D-021, 2026-10-30): halaman toko dirender server dengan Blade + Tailwind (+ Alpine.js), area `/admin` tetap Vue SPA. Kode di bawah ini belum dimigrasi; masih Vue SPA untuk semua path. Riwayat keputusan: OQ-02 ([../project-management/OPEN_QUESTIONS.md](../project-management/OPEN_QUESTIONS.md)).

## 2. Struktur

```text
resources/js/
├── app.js            # createApp, Pinia, router, $can/$hasRole, mount('#app')
├── App.vue           # <router-view>
├── bootstrap.js      # window.axios + header X-Requested-With
├── router.js         # definisi route + guard beforeEach
├── legacy.js         # loader berurutan untuk 17 skrip template Backeri
├── stores/auth.js    # user, isAuthenticated, can(), hasRole(), login/logout/fetchUser
├── utils/api.js      # axios instance /api/v1 + interceptor 401
├── layouts/AdminLayout.vue
├── pages/            # Home, Login, Dashboard, AccessDenied
├── components/       # kosong
└── composables/      # kosong
```

`resources/views/layouts/app.blade.php`, `partials/header.blade.php`, `partials/footer.blade.php`, dan `legacy/*.blade.php` adalah sisa iterasi Blade sebelumnya. Tidak ada route yang merender file tersebut.

## 3. Inventaris Halaman

| Path | Komponen | Akses | Isi | Status |
|---|---|---|---|---|
| `/` | `pages/Home.vue` | Publik | Port 1:1 template Backeri: konten demo, teks Inggris, brand "Backeri", produk/menu contoh, newsletter, footer | `PARTIALLY IMPLEMENTED`: tampil, tetapi bukan konten Tuffero dan tidak terhubung ke data |
| `/login` | `pages/Login.vue` | Tamu saja | Form email + password, memanggil API login | `IMPLEMENTED` (belum ada user di DB) |
| `/admin` | `layouts/AdminLayout.vue` + `pages/Dashboard.vue` | Butuh sesi | Header "TUFFERO Admin", tombol Keluar, judul "Dashboard" | Kerangka saja |
| `/admin/forbidden` | `pages/AccessDenied.vue` | Butuh sesi | Teks "Akses ditolak" | Kerangka saja |

Tidak ada route 404 di router. Path tak dikenal merender halaman kosong di dalam `<router-view>`.

## 4. Cara Homepage Bekerja

1. `welcome.blade.php` memuat CSS template (`bootstrap.min.css`, `custom.css`, dll.) sebelum bundle Vite.
2. `Home.vue` berisi HTML template Backeri hasil konversi dari `resources/views/legacy/backeri-index.blade.php`. Path aset diubah dari `{{ asset('images/x') }}` menjadi `/images/x`. Satu tag `<li>` yang tidak tertutup di menu "About Us" diperbaiki agar bisa dikompilasi Vue.
3. `vite.config.js` mematikan transformasi URL aset absolut (`transformAssetUrls: { base: null, includeAbsolute: false }`) agar `/images/...` tidak di-import sebagai modul.
4. Pada `onMounted`, `Home.vue` memanggil `loadLegacyScripts()` dari `legacy.js`. Fungsi ini menambahkan 17 tag `<script src="/js/...">` secara berurutan (`async = false`, ditunggu satu per satu), sekali per pemuatan halaman (flag `loaded`), lalu menyembunyikan `.preloader` dengan jQuery karena event `window.load` sudah lewat.
5. Skrip template (terutama `public/js/function.js`) memanipulasi DOM yang dirender Vue: SlickNav, Swiper, animasi GSAP/SplitText, counter, popup video YouTube, magic cursor.

Keterbatasan yang diketahui:

- Skrip jQuery tetap aktif setelah navigasi SPA ke `/login`; perilakunya belum diuji di browser.
- Form newsletter `action="#" method="POST"` tidak terhubung ke backend.
- `function.js` berisi handler form kontak ke `form-process.php`, yang tidak ada (homepage tidak memiliki form kontak, jadi handler ini tidak terpicu).
- Video hero hotlink ke `https://demo.awaikenthemes.com/assets/videos/backeri-video.mp4`.
- Tautan video YouTube demo.
- Teks `alt` gambar kosong di banyak tempat (aksesibilitas).
- Lisensi template Backeri belum terverifikasi (T-1).

## 5. Styling dan Design Token

- `resources/css/app.css` mengimpor `tailwindcss/theme.css` dan `tailwindcss/utilities.css` **tanpa preflight**, karena reset Tailwind merusak CSS Bootstrap di homepage. Akibatnya halaman Login/Admin tidak punya reset dasar Tailwind.
- Token Tuffero didefinisikan di blok `@theme`: `--color-primary` `#2B1B14`, `--color-accent` `#C6A15B`, `--color-accent-hover` `#D8B978`, `--color-background` `#F6F0E5`, `--color-secondary` `#6D4C3D`, `--color-surface` `#FFFFFF`, `--color-border` `#E3D5C0`, `--font-sans` Manrope, `--font-accent` Playfair Display.
- Token dipakai di `Login.vue` dan `AdminLayout.vue` (`bg-primary`, `bg-accent`, `border-border`, dll.).
- Homepage **tidak memakai** token Tuffero; ia memakai warna dan font template (Lora, Plus Jakarta Sans dari Google Fonts).
- `vite.config.js` mendeklarasikan font Manrope dan Playfair Display Italic lewat `bunny()` dari `laravel-vite-plugin/fonts`. Pemuatan font di browser belum diverifikasi.

Detail token: [../frontend/DESIGN_SYSTEM.md](../frontend/DESIGN_SYSTEM.md).

## 6. Responsif dan Aksesibilitas

- Homepage memakai grid Bootstrap dan menu mobile SlickNav dari template. Tampilan responsif belum diuji di browser pada audit ini.
- Login dan Admin memakai utility Tailwind sederhana. Belum ada pengujian kontras, fokus keyboard, atau pembaca layar.
- Viewport `maximum-scale=1` di `welcome.blade.php` membatasi zoom pengguna (masalah aksesibilitas).

## 7. Rencana Perbaikan Frontend (`PROPOSED`)

Belum disetujui; urutan disarankan:

1. Migrasi ke hybrid (D-021): homepage dan halaman toko ke Blade, catch-all SPA dibatasi ke `/admin`, login admin di `/admin/login`. Tugas terpisah dengan rencana sendiri. Putuskan status template Backeri (T-1).
2. Ganti konten demo dengan konten, logo, dan foto Tuffero asli.
3. Terapkan design system: token warna, Manrope, Playfair Display Italic aksen.
4. Tambah halaman katalog, detail produk, keranjang, checkout, status pesanan guest setelah API tersedia.
5. Tambah route 404, `alt` gambar, hapus `maximum-scale=1`.
6. Tentukan dukungan bilingual EN/ID (OQ-03).
