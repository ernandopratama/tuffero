# Strategi Pengujian

## 1. Kondisi Saat Ini (2026-10-30)

| Test | Lokasi | Hasil terakhir |
|---|---|---|
| `Tests\Feature\ExampleTest` (`GET /` → 200) | `tests/Feature/ExampleTest.php` | Lulus |
| `Tests\Unit\ExampleTest` (`assertTrue(true)`) | `tests/Unit/ExampleTest.php` | Lulus |
| Larastan level 5 untuk `app/` | `phpstan.neon` | 0 error |
| Pint | `pint.json` (preset laravel) | Dijalankan; memperbaiki format `BaseRepository` |
| `npm run build` | — | Sukses |

Test memakai SQLite `:memory:`, session/cache `array`, queue `sync` (`phpunit.xml`). **Tidak ada test fungsional atau keamanan.** Tidak ada CI.

Perintah:

```bash
php artisan test
vendor/bin/phpstan analyse
vendor/bin/pint --test
composer audit
npm audit
```

## 2. Prinsip

- Test ditulis bersama fitur, bukan sesudahnya.
- Alur kritis (checkout, stok, pembayaran, otorisasi) wajib punya feature test sebelum dianggap selesai.
- Test konkurensi stok dan constraint `CHECK` dijalankan di **PostgreSQL**, bukan SQLite, karena perilaku locking berbeda. Siapkan koneksi test PostgreSQL terpisah.
- Integrasi Midtrans/kurir diuji dengan HTTP fake (`Http::fake`) dan fixture notifikasi; uji sandbox dilakukan manual dan dicatat.
- Jangan menguji terhadap sistem produksi.

## 3. Rencana Test Keamanan

Status: **semua `RECOMMENDED`, belum ada yang ditulis.** Kolom "Bisa ditulis sekarang" menunjukkan apakah kode yang diuji sudah ada.

| Area | Test | Persyaratan | Bisa ditulis sekarang |
|---|---|---|---|
| Autentikasi | Login benar → 200 + sesi baru; salah → 401 pesan generik | AUTH-04, AUTH-07 | Ya |
| Autentikasi | Logout menginvalidasi sesi; `GET /auth/user` sesudahnya → 401 | AUTH-08 | Ya |
| Rate limit | Login ke-N+1 → 429 | AUTH-03 | Setelah SEC-01 diperbaiki |
| CSRF | `POST /api/v1/auth/login` tanpa token XSRF → 419 | WEB-03 | Ya |
| Info disclosure | DB gagal → respons health tanpa pesan exception | WEB-14 | Setelah SEC-04 diperbaiki |
| API 404 | `GET /api/v1/nope` → 404 JSON | API-07 | Setelah SEC-09 diperbaiki |
| Header | Respons memuat CSP, HSTS (prod), nosniff, frame options | WEB-10, WEB-11 | Sebagian |
| Otorisasi admin | User tanpa permission → 403 di setiap endpoint admin | AUTHZ-02 | Saat endpoint admin dibuat |
| Eskalasi hak | User non-super-admin tidak bisa mengubah role/permission | AUTHZ-05 | Saat fitur dibuat |
| IDOR | Order milik orang lain tidak bisa dibaca dengan mengganti ID/ref | AUTHZ-06, TRX-10 | Saat order dibuat |
| Guest order | Lookup tanpa token valid → 404/403; token salah tidak membocorkan keberadaan order | AUTH-14 | Saat fitur dibuat |
| XSS | Nama produk/alamat berisi `<script>` tampil ter-escape | WEB-02 | Saat fitur dibuat |
| Validasi input | Field wajib, format email/HP, panjang maksimum | WEB-01 | Saat fitur dibuat |
| SQL injection | Parameter pencarian/filter berisi payload tidak mengubah query | WEB-04 | Saat katalog dibuat |
| Upload | File non-gambar, ukuran berlebih, ekstensi ganda ditolak | WEB-06 | Saat upload dibuat |
| Manipulasi harga | Request checkout dengan `price`/`total` palsu → total dari server | TRX-01 | Saat checkout dibuat |
| Voucher | Voucher kedaluwarsa/nonaktif/kuota habis ditolak; dipakai dua kali paralel hanya sekali berhasil | TRX-08 | Saat promo dibuat |
| Konkurensi stok | N request paralel untuk stok terakhir → hanya 1 berhasil, stok tidak minus (PostgreSQL) | TRX-04, TRX-05 | Saat stok dibuat |
| Order ganda | Submit ulang dengan idempotency key yang sama → satu order | TRX-07 | Saat checkout dibuat |
| Satu gudang | Keranjang yang hanya bisa dipenuhi dua gudang → ditolak, tidak di-split | TRX-11 | Saat stok dibuat |
| Webhook signature | Signature salah → diabaikan, status tidak berubah | PAY-04 | Saat Midtrans dibuat |
| Webhook amount | `gross_amount` tidak cocok → ditolak | PAY-05 | Saat Midtrans dibuat |
| Idempotensi pembayaran | Notifikasi sama dua kali → satu perubahan; urutan terbalik tidak memundurkan status | PAY-07, PAY-08 | Saat Midtrans dibuat |
| Redirect palsu | Mengakses URL finish tanpa notifikasi → order tetap pending | PAY-06 | Saat Midtrans dibuat |
| Webhook kurir | Event tidak valid/duplikat tidak mengubah status | SHP-02, SHP-03 | Saat pengiriman dibuat |
| Data sensitif | Log dan respons API tidak memuat password, secret, data kartu | DATA-06, PAY-02 | Sebagian |

## 4. Uji Manual Sebelum Rilis

- UAT oleh pemilik bisnis (MVP §5).
- Uji browser: responsif, keyboard, kontras, alur checkout lengkap.
- Uji sandbox Midtrans: sukses, gagal, kedaluwarsa, batal, notifikasi duplikat.
- Uji restore backup.
- Uji penetrasi profesional (direkomendasikan).
