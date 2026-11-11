export const ROLE_LABELS = {
    'super-admin': 'Super Admin',
    owner: 'Pemilik',
    'store-admin': 'Admin Toko',
    'customer-service': 'Customer Service',
    warehouse: 'Gudang',
    marketing: 'Marketing',
};

// `short`: label pendek untuk tab navbar bawah smartphone.
// Menu tanpa `to` belum dibangun dan tampil sebagai "Segera". Ini hanya tampilan; izin sebenarnya dijaga di server.
const MENU = [
    {
        label: 'Operasional',
        items: [
            { label: 'Pesanan', icon: 'receipt', any: ['orders.view'], to: { name: 'AdminOrders' } },
            { label: 'Pengemasan', icon: 'box', any: ['orders.view_fulfillment'], to: { name: 'AdminFulfillment' } },
            { label: 'Pengiriman', icon: 'truck', any: ['shipments.view'], to: { name: 'AdminShipments' } },
            { label: 'Pembayaran', icon: 'wallet', any: ['payments.view'], to: { name: 'AdminPayments' } },
            { label: 'Pelanggan', icon: 'user', any: ['customers.view'], to: { name: 'AdminCustomers' } },
            { label: 'Pembatalan', icon: 'x', any: ['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'], to: { name: 'AdminCancellations' } },
            { label: 'Refund', icon: 'undo', any: ['refunds.view'] },
            { label: 'FAQ Chat', icon: 'chat', any: ['faqs.manage'], to: { name: 'AdminFaqs' } },
        ],
    },
    {
        label: 'Katalog dan Promo',
        items: [
            { label: 'Produk dan Harga', short: 'Produk', icon: 'tag', any: ['products.view'], to: { name: 'AdminProducts' } },
            { label: 'Kategori', icon: 'layers', any: ['products.view'], to: { name: 'AdminCategories' } },
            { label: 'Stok', icon: 'warehouse', any: ['stock.view'], to: { name: 'AdminStock' } },
            { label: 'Kampanye', icon: 'megaphone', any: ['products.view'], to: { name: 'AdminCampaigns' } },
            { label: 'Voucher', icon: 'ticket', any: ['vouchers.view'], to: { name: 'AdminVouchers' } },
            { label: 'Konten Promo', short: 'Konten', icon: 'image', any: ['content.view'], to: { name: 'AdminContents' } },
            { label: 'Galeri Website', short: 'Galeri', icon: 'photos', any: ['content.view'], to: { name: 'AdminGallery' } },
            { label: 'Ulasan Toko', short: 'Ulasan', icon: 'sparkles', any: ['reviews.manage'], to: { name: 'AdminReviews' } },
            { label: 'Ulasan Produk', short: 'Ulasan', icon: 'sparkles', any: ['product_reviews.view'], to: { name: 'AdminProductReviews' } },
            { label: 'Berita Popup', short: 'Berita', icon: 'bell', any: ['news.view'], to: { name: 'AdminNews' } },
        ],
    },
    {
        label: 'Laporan',
        items: [
            { label: 'Laporan', icon: 'chart', any: ['reports.view', 'reports.view_summary'] },
            { label: 'Audit Log', icon: 'shield', any: ['audit.view'], to: { name: 'AdminAudit' } },
        ],
    },
    {
        label: 'Sistem',
        items: [
            { label: 'Pengguna dan Role', short: 'Pengguna', icon: 'users', any: ['users.manage'], to: { name: 'AdminUsers' } },
            { label: 'Pengaturan', icon: 'sliders', any: ['settings.manage', 'settings.manage_contact'], to: { name: 'AdminSettings' } },
        ],
    },
];

export const OVERVIEW = { label: 'Ringkasan', icon: 'home', to: { name: 'Dashboard' } };

export function menuFor(permissions) {
    return MENU
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.any.some((permission) => permissions.includes(permission))),
        }))
        .filter((group) => group.items.length > 0);
}

// Ikon dan grup menu untuk judul halaman (PageHeader), dicari dari nama rute.
export function pageMeta(routeName) {
    for (const group of MENU) {
        const item = group.items.find((entry) => entry.to?.name === routeName);
        if (item) return { group: group.label, icon: item.icon };
    }
    return null;
}
