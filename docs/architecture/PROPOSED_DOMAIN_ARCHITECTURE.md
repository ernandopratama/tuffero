# Arsitektur Domain E-commerce — `PROPOSED`

> **Seluruh isi dokumen ini adalah usulan.** Tidak ada satu pun alur di bawah yang sudah ada di kode per 2026-10-30. Usulan diturunkan dari aturan di [../product/BUSINESS_RULES.md](../product/BUSINESS_RULES.md), PRD v0.2, MVP v0.2, dan rekomendasi arsitektur di Panduan API Pengiriman dan Panduan Payment Gateway. Usulan memerlukan persetujuan technical lead sebelum diimplementasikan. Nilai yang belum diputuskan bisnis (durasi reservasi, aturan voucher, pilihan kurir) ditandai sebagai parameter, bukan angka tetap.

## 1. Modul yang Diusulkan

| Modul | Tanggung jawab | Aturan terkait |
|---|---|---|
| Catalog | Produk, varian/SKU, foto, harga, berat/dimensi, status publikasi | BR-01, FR-01 |
| Inventory | Gudang, stok per varian per gudang, reservasi, pergerakan stok | BR-04–BR-07, BR-10 |
| Cart | Keranjang tamu berbasis sesi/token | BR-02 |
| Checkout | Hitung ulang harga/promo/ongkir/total, pilih gudang, buat order | BR-07, BR-08 |
| Order | Order, item snapshot, status, audit trail | BR-09, BR-12, BR-21 |
| Payment | Transaksi Midtrans, notifikasi, idempotensi, rekonsiliasi | BR-11, BR-12 |
| Shipping | Tarif, shipment, resi, tracking via provider yang dapat diganti; Biteship untuk MVP | BR-16, D-020 |
| Promotion | Voucher/promo dengan validasi server | BR-13–BR-15 |
| Admin | Manajemen data + RBAC + audit log | BR-17 |

## 2. Alur Browsing Produk — `PROPOSED`

```mermaid
sequenceDiagram
    participant V as Vue page Catalog
    participant API as GET /api/v1/products
    participant C as ProductController
    participant R as ProductRepository
    participant DB as PostgreSQL
    V->>API: ?page, ?category, ?q
    API->>C: index (publik, rate-limited)
    C->>R: hanya produk published + varian aktif
    R->>DB: query paginated + stok agregat per gudang aktif
    DB-->>R: rows
    R-->>C: koleksi
    C-->>V: ProductResource (harga dari server, tanpa data internal)
```

## 3. Alur Keranjang dan Checkout — `PROPOSED`

```mermaid
sequenceDiagram
    actor G as Guest
    participant FE as Vue Checkout
    participant CO as CheckoutService
    participant INV as InventoryService
    participant SH as ShippingService
    participant DB as PostgreSQL
    G->>FE: isi nama, email, HP, alamat
    FE->>CO: POST /api/v1/checkout (cart_token, alamat, kurir pilihan, voucher, Idempotency-Key)
    CO->>CO: muat item dari DB, abaikan harga dari browser
    CO->>INV: cari SATU gudang aktif yang bisa memenuhi semua item
    alt tidak ada gudang tunggal
        INV-->>CO: gagal
        CO-->>FE: 422 "Pesanan tidak dapat dipenuhi dari satu gudang"
    else ada
        CO->>SH: kutip ongkir dari gudang terpilih ke alamat
        CO->>CO: validasi voucher di server, hitung total
        CO->>DB: transaksi DB - lock stok FOR UPDATE, reservasi, buat order + item snapshot
        CO-->>FE: order_ref publik + token akses guest
    end
```

Kunci integritas:

- Harga, diskon, ongkir, dan total dihitung di server (BR-08).
- Satu gudang per order; tidak ada split (BR-06, BR-07).
- Stok dikunci dalam transaksi database; constraint `CHECK (quantity >= 0)` dan `reserved <= quantity` di level database (BR-10).
- `Idempotency-Key` mencegah order ganda saat retry.
- Durasi reservasi = parameter konfigurasi, menunggu Q-006.

## 4. Alokasi Gudang — `PROPOSED`

```mermaid
flowchart TD
    A[Item keranjang + jumlah] --> B[Ambil gudang berstatus aktif]
    B --> C{Ada gudang dengan<br/>available ≥ qty untuk SEMUA item?}
    C -- tidak --> D[Tolak checkout dengan pesan jelas<br/>tanpa split order]
    C -- satu --> E[Pilih gudang itu]
    C -- lebih dari satu --> F[Pilih berdasarkan aturan<br/>yang belum diputuskan<br/>mis. prioritas / jarak / ongkir]
    E & F --> G[Reservasi stok di gudang terpilih<br/>dalam transaksi + row lock]
```

Saat peluncuran hanya Malang aktif, jadi cabang "lebih dari satu" belum terjadi. Aturan pemilihan untuk banyak gudang belum diputuskan.

## 5. Siklus Hidup Order — `PROPOSED`

```mermaid
stateDiagram-v2
    [*] --> pending_payment: order dibuat + stok direservasi
    pending_payment --> paid: notifikasi Midtrans settlement/capture terverifikasi
    pending_payment --> expired: batas waktu reservasi/pembayaran lewat
    pending_payment --> cancelled: dibatalkan (aturan Q-007)
    expired --> [*]: reservasi dilepas
    cancelled --> [*]: reservasi dilepas
    paid --> processing: admin memproses di gudang terpilih
    processing --> shipped: shipment dibuat, resi tersimpan
    shipped --> delivered: tracking/webhook kurir terverifikasi
    paid --> refunded: kebijakan refund (Q-007)
    delivered --> [*]
```

Status order, pembayaran, dan pengiriman disimpan di kolom/tabel terpisah (BR-21). Transisi hanya lewat service yang memvalidasi status asal; setiap transisi dicatat di audit trail.

## 6. Siklus Pembayaran dan Webhook Aman (Midtrans) — `PROPOSED`

```mermaid
sequenceDiagram
    participant FE as Browser
    participant L as Laravel PaymentService
    participant M as Midtrans
    participant DB as PostgreSQL
    FE->>L: minta pembayaran untuk order_ref
    L->>DB: ambil total dari order (bukan dari browser)
    L->>M: buat transaksi Snap (server key hanya di server)
    M-->>L: snap token / redirect_url
    L-->>FE: token
    FE->>M: bayar di halaman Midtrans
    M-->>FE: redirect finish (BUKAN bukti bayar)
    M->>L: POST /api/v1/webhooks/midtrans (notifikasi)
    L->>L: verifikasi signature_key = SHA512(order_id+status_code+gross_amount+ServerKey)
    L->>L: cocokkan order_id dan gross_amount dengan DB
    opt verifikasi tambahan
        L->>M: GET status transaksi
    end
    L->>DB: transaksi DB - simpan event unik per transaction_id+status, transisi status bila valid
    L-->>M: 200 OK (juga untuk duplikat)
```

Ketentuan:

- Signature tidak cocok → abaikan dan catat sebagai event mencurigakan.
- Notifikasi bisa datang tidak berurutan; status tidak boleh mundur (mis. `settlement` tidak tertimpa `pending`).
- Event duplikat tidak mengubah status atau stok dua kali.
- Formula signature berdasarkan dokumentasi resmi Midtrans ([Handle Notifications](https://docs.midtrans.com/reference/handle-notifications)); verifikasi ulang terhadap dokumentasi terbaru saat implementasi.

## 7. Alur Pengiriman — `PROPOSED`

Mengikuti Panduan API Pengiriman §8: `ShippingService` dengan antarmuka provider, sehingga penyedia (Biteship, J&T, SiCepat, Anteraja) dapat diganti tanpa menulis ulang checkout. Provider MVP: Biteship (D-020).

```mermaid
flowchart LR
    A[Checkout: alamat + item] --> B[Gudang asal ditentukan<br/>oleh alokasi]
    B --> C[ShippingService::quote<br/>provider terpilih]
    C --> D[Pelanggan memilih layanan<br/>atau sistem memilih - Q-003]
    D --> E[Snapshot kurir, layanan, ongkir<br/>di order]
    E --> F{Order paid?}
    F -- ya --> G[ShippingService::createShipment<br/>idempotent per order]
    G --> H[Simpan resi + status]
    H --> I[Webhook/tracking<br/>verifikasi + idempoten + histori]
```

## 8. ERD yang Diusulkan

Lihat [../database/DATABASE.md](../database/DATABASE.md) bagian 4.
