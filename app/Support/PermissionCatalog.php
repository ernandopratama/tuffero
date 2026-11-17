<?php

namespace App\Support;

/**
 * Daftar izin bawaan yang benar-benar diperiksa kode (D-129). Izin bawaan tidak bisa dihapus atau diubah namanya dari dashboard,
 * karena rute dan layanan memeriksanya dengan nama tetap. Izin buatan Super Admin (kustom) tersimpan di tabel `permissions`
 * dengan `label` dan `description` sendiri; izin kustom tidak membuka fitur apa pun sampai ada kode yang memeriksanya.
 */
class PermissionCatalog
{
    /** Hanya Super Admin; tidak bisa diberikan ke role lain (mencegah eskalasi hak akses). */
    public const SUPER_ADMIN_ONLY = ['users.manage', 'roles.manage', 'users.manage_mfa', 'settings.manage', 'product_reviews.manage'];

    public const SUPER_ADMIN = 'super-admin';

    /** Role bawaan: tidak bisa dihapus atau diganti nama. Izin role bawaan (selain Super Admin) boleh diubah. */
    public const SYSTEM_ROLES = [
        'super-admin' => 'Super Admin',
        'owner' => 'Pemilik',
        'store-admin' => 'Admin Toko',
        'customer-service' => 'Customer Service',
        'warehouse' => 'Gudang',
        'marketing' => 'Marketing',
    ];

    public const CUSTOM_GROUP = 'Kustom';

    /** @var array<string, array{0: string, 1: string}> nama izin => [kelompok, label] */
    public const PERMISSIONS = [
        'users.manage' => ['Pengguna dan keamanan', 'Kelola akun staf'],
        'roles.manage' => ['Pengguna dan keamanan', 'Kelola role dan izin'],
        'users.manage_mfa' => ['Pengguna dan keamanan', 'Reset MFA staf'],
        'settings.manage' => ['Pengguna dan keamanan', 'Ubah semua pengaturan'],
        'audit.view' => ['Pengguna dan keamanan', 'Lihat audit log'],
        'settings.manage_contact' => ['Pengguna dan keamanan', 'Ubah kontak, ulasan toko, dan batas diskon'],

        'orders.view' => ['Pesanan', 'Lihat pesanan'],
        'orders.view_fulfillment' => ['Pesanan', 'Lihat antrean kemas (tanpa harga)'],
        'orders.update_status' => ['Pesanan', 'Ubah status pesanan'],
        'orders.complete' => ['Pesanan', 'Tandai pesanan selesai (Admin Toko)'],
        'orders.review' => ['Pesanan', 'Setujui atau tolak pesanan bernilai besar'],
        'orders.review_alert' => ['Pesanan', 'Peringatan tinjau pesanan'],
        'orders.cancel_request' => ['Pesanan', 'Validasi pengajuan pembatalan'],
        'orders.cancel' => ['Pesanan', 'ACC pembatalan (Admin Toko)'],
        'cancellations.view' => ['Pesanan', 'Lihat pembatalan (hanya baca)'],
        'shipments.view' => ['Pesanan', 'Lihat pengiriman'],
        'shipments.manage' => ['Pesanan', 'Kelola pengiriman dan resi'],

        'payments.view' => ['Pembayaran dan refund', 'Lihat pembayaran'],
        'refunds.view' => ['Pembayaran dan refund', 'Lihat refund'],
        'refunds.process' => ['Pembayaran dan refund', 'Proses refund'],
        'refunds.decide' => ['Pembayaran dan refund', 'Putuskan refund'],

        'customers.view' => ['Pelanggan', 'Lihat pelanggan'],
        'customers.deactivate' => ['Pelanggan', 'Nonaktifkan atau hapus pelanggan'],
        'customer_blocks.view' => ['Pelanggan', 'Lihat blokir pelanggan'],
        'customer_blocks.manage' => ['Pelanggan', 'Kelola blokir pelanggan'],
        'customer_blocks.approve' => ['Pelanggan', 'Setujui menyalakan blokir otomatis (Pemilik)'],

        'products.view' => ['Katalog dan promo', 'Lihat produk'],
        'products.manage' => ['Katalog dan promo', 'Kelola produk dan harga'],
        'categories.manage' => ['Katalog dan promo', 'Kelola kategori'],
        'campaigns.manage' => ['Katalog dan promo', 'Kelola kampanye'],
        'vouchers.view' => ['Katalog dan promo', 'Lihat voucher'],
        'vouchers.manage' => ['Katalog dan promo', 'Kelola voucher'],
        'reviews.manage' => ['Katalog dan promo', 'Kelola ulasan toko'],
        'product_reviews.view' => ['Katalog dan promo', 'Lihat ulasan produk'],
        'product_reviews.manage' => ['Katalog dan promo', 'Kelola ulasan produk'],

        'stock.view' => ['Stok dan gudang', 'Lihat stok'],
        'stock.adjust' => ['Stok dan gudang', 'Ubah stok'],
        'warehouses.view' => ['Stok dan gudang', 'Lihat gudang'],
        'warehouses.manage' => ['Stok dan gudang', 'Kelola gudang'],

        'content.view' => ['Konten dan marketing', 'Lihat konten promo'],
        'content.manage' => ['Konten dan marketing', 'Kelola konten promo dan galeri'],
        'news.view' => ['Konten dan marketing', 'Lihat berita popup'],
        'news.manage' => ['Konten dan marketing', 'Kelola berita popup'],
        'marketing_drafts.view' => ['Konten dan marketing', 'Lihat draf konten'],
        'marketing_drafts.manage' => ['Konten dan marketing', 'Susun draf konten'],
        'marketing_drafts.approve' => ['Konten dan marketing', 'Putuskan draf konten'],
        'terms.manage' => ['Konten dan marketing', 'Kelola Syarat dan Ketentuan'],

        'faqs.manage' => ['Customer Service', 'Kelola FAQ chat'],
        'templates.manage' => ['Customer Service', 'Kelola template balasan'],
        'search.quick' => ['Customer Service', 'Pencarian cepat'],
        'followups.manage' => ['Customer Service', 'Kelola tindak lanjut'],
        'emails.send' => ['Customer Service', 'Kirim email ke pelanggan'],
        'comments.create' => ['Customer Service', 'Tulis catatan internal'],
        'comments.cs' => ['Customer Service', 'Catatan khusus CS'],

        'reports.view' => ['Laporan', 'Lihat laporan lengkap'],
        'reports.view_summary' => ['Laporan', 'Lihat ringkasan laporan'],
    ];

    public static function isSystem(string $permission): bool
    {
        return array_key_exists($permission, self::PERMISSIONS);
    }

    public static function isSystemRole(string $role): bool
    {
        return array_key_exists($role, self::SYSTEM_ROLES);
    }
}
