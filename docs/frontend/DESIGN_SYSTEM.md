# Design System Tuffero

> Ringkasan dari sumber resmi `TUFFERO/Tuffero_Design_System.txt` (D-019). Jika berbeda, file sumber yang berlaku. Dokumen ini juga mencatat sejauh mana design system sudah diterapkan di kode.

## 1. Arah Brand

- Kepribadian: premium, modern, hangat, elegan, ramah.
- Arah visual: Dark Chocolate & Champagne Gold.
- Utamakan foto produk, informasi produk yang jelas, whitespace lega, alur belanja sederhana.
- **Logo Tuffero asli tidak boleh diubah atau digambar ulang dengan font website.** File logo resmi belum ada di repository.
- Hero memakai foto produk/bakery Tuffero berkualitas dengan overlay cokelat gelap `linear-gradient(rgba(43,27,20,0.68), rgba(43,27,20,0.80))`.
- Gambar harus foto produk Tuffero yang autentik; jangan memakai gambar yang menyesatkan.

Belum ada di dokumen sumber: arah "Luxury Editorial" dan dukungan bilingual Inggris/Indonesia. Keduanya disebut pada instruksi audit 2026-10-30 dan perlu dikonfirmasi (OQ-03).

## 2. Token Warna

| Token | Nama | HEX | Penggunaan |
|---|---|---|---|
| `color-primary` | Dark Chocolate | `#2B1B14` | Header, footer, section gelap, heading di latar terang |
| `color-accent` | Champagne Gold | `#C6A15B` | CTA utama, ikon terpilih, highlight, aksen |
| `color-accent-hover` | (hover yang disarankan) | `#D8B978` | Hover tombol utama |
| `color-background` | Warm Ivory | `#F6F0E5` | Latar halaman |
| `color-secondary` | Roasted Brown | `#6D4C3D` | Teks pendukung |
| `color-surface` | Pure White | `#FFFFFF` | Kartu produk, form, checkout |
| `color-border` | Soft Border | `#E3D5C0` | Border, divider, outline input |

Aturan: CTA utama emas dengan teks Dark Chocolate; emas dipakai hemat; komposisi kira-kira 55% Warm Ivory, 30% Dark Chocolate, 10% Roasted Brown, 5% Champagne Gold; cek kontras (hindari teks emas kecil di latar cokelat gelap); status error/sukses memakai warna status yang aksesibel, bukan warna brand.

## 3. Tipografi

- Utama: **Manrope** (navigasi 500–600, body 400, nama produk 500–600, harga 600–700, tombol/label 600–700, heading 500–600).
- Aksen: **Playfair Display Italic**, hanya untuk beberapa kata di hero, headline promo, tagline pendek. Tidak untuk paragraf panjang, detail produk, navigasi, atau checkout.

| Elemen | Desktop | Mobile |
|---|---:|---:|
| Hero heading | 52–64 px | 34–42 px |
| Section heading | 32–40 px | 26–32 px |
| Nama produk | 16–20 px | 16–18 px |
| Body, navigasi, CTA | 14–16 px | 14–16 px |
| Label kecil / eyebrow | 11–12 px | 11–12 px |

## 4. Status Penerapan di Kode (2026-10-30)

| Aspek | Status | Bukti |
|---|---|---|
| Token warna dan font sebagai tema Tailwind | `IMPLEMENTED` | `resources/css/app.css` blok `@theme` |
| Font Manrope + Playfair Display dikonfigurasi di build | `IMPLEMENTED` (pemuatan di browser belum diverifikasi) | `vite.config.js` `bunny(...)` |
| Halaman Login dan Admin memakai token | `IMPLEMENTED` | `pages/Login.vue`, `layouts/AdminLayout.vue` |
| Homepage memakai design system | `NOT IMPLEMENTED` | `pages/Home.vue` memakai template Backeri: font Lora + Plus Jakarta Sans, warna template, brand "Backeri", konten demo |
| Logo resmi | `NOT IMPLEMENTED` | Homepage memakai `public/images/logo.svg` milik template; admin memakai teks "TUFFERO Admin" |
| Foto produk Tuffero | `NOT IMPLEMENTED` | Semua gambar dari template |
| Checklist aksesibilitas (fokus, kontras, responsif) | `UNKNOWN` | Belum diuji di browser |

Design System menyebut stack "Laravel Blade, Tailwind CSS, Alpine.js". Keputusan D-021 (hybrid) menerapkannya untuk halaman toko; admin memakai Vue SPA. Kode saat ini masih Vue SPA untuk semua path.

## 5. Checklist Penerimaan Desain

Dari dokumen sumber §9; semua belum tercentang:

- [ ] Palet warna dipakai konsisten.
- [ ] Manrope font utama; Playfair Display Italic hanya aksen.
- [ ] Emas dipakai selektif; teks tombol utama terbaca.
- [ ] Foto produk mencerminkan produk Tuffero asli.
- [ ] Responsif di desktop, tablet, mobile.
- [ ] Fokus keyboard terlihat.
- [ ] Kontras teks diperiksa.
- [ ] Produk, keranjang, checkout jelas dan mudah dipakai.
