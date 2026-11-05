export const ROLE_LABELS = {
    'super-admin': 'Super Admin',
    owner: 'Pemilik',
    'store-admin': 'Admin Toko',
    'customer-service': 'Customer Service',
    warehouse: 'Gudang',
    marketing: 'Marketing',
};

// Menu tanpa `to` belum dibangun dan tampil sebagai "Segera". Ini hanya tampilan; izin sebenarnya dijaga di server.
const MENU = [
    {
        label: 'Operasional',
        items: [
            { label: 'Pesanan', icon: 'receipt', any: ['orders.view'], to: { name: 'AdminOrders' } },
            { label: 'Pengemasan', icon: 'box', any: ['orders.view_fulfillment'], to: { name: 'AdminFulfillment' } },
            { label: 'Pengiriman', icon: 'truck', any: ['shipments.view'], to: { name: 'AdminShipments' } },
            { label: 'Pembayaran', icon: 'wallet', any: ['payments.view'], to: { name: 'AdminPayments' } },
            { label: 'Pembatalan', icon: 'x', any: ['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'], to: { name: 'AdminCancellations' } },
            { label: 'Refund', icon: 'undo', any: ['refunds.view'] },
        ],
    },
    {
        label: 'Katalog dan Promo',
        items: [
            { label: 'Produk dan Harga', icon: 'tag', any: ['products.view'], to: { name: 'AdminProducts' } },
            { label: 'Stok', icon: 'warehouse', any: ['stock.view'], to: { name: 'AdminStock' } },
            { label: 'Voucher', icon: 'ticket', any: ['vouchers.view'], to: { name: 'AdminVouchers' } },
            { label: 'Konten Promo', icon: 'sparkles', any: ['content.view'], to: { name: 'AdminContents' } },
            { label: 'Ulasan Toko', icon: 'sparkles', any: ['reviews.manage'], to: { name: 'AdminReviews' } },
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
            { label: 'Pengguna dan Role', icon: 'user', any: ['users.manage'], to: { name: 'AdminUsers' } },
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
