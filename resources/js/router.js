import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const routes = [
    {
        path: '/login',
        name: 'Login',
        component: () => import('./pages/Login.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/admin',
        component: () => import('./layouts/AdminLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'Dashboard',
                component: () => import('./pages/Dashboard.vue'),
            },
            {
                path: 'produk',
                name: 'AdminProducts',
                component: () => import('./pages/admin/Products.vue'),
                meta: { permission: 'products.view' },
            },
            {
                path: 'produk/:id(\\d+)',
                name: 'AdminProductEdit',
                component: () => import('./pages/admin/ProductEdit.vue'),
                meta: { permission: 'products.view', menu: 'AdminProducts' },
            },
            {
                path: 'stok',
                name: 'AdminStock',
                component: () => import('./pages/admin/Stock.vue'),
                meta: { permission: 'stock.view' },
            },
            {
                path: 'pesanan',
                name: 'AdminOrders',
                component: () => import('./pages/admin/Orders.vue'),
                meta: { permission: 'orders.view' },
            },
            {
                path: 'pengemasan',
                name: 'AdminFulfillment',
                component: () => import('./pages/admin/Fulfillment.vue'),
                meta: { permission: 'orders.view_fulfillment' },
            },
            {
                path: 'pengiriman',
                name: 'AdminShipments',
                component: () => import('./pages/admin/Shipments.vue'),
                meta: { permission: 'shipments.view' },
            },
            {
                path: 'pembayaran',
                name: 'AdminPayments',
                component: () => import('./pages/admin/Payments.vue'),
                meta: { permission: 'payments.view' },
            },
            {
                path: 'pembatalan',
                name: 'AdminCancellations',
                component: () => import('./pages/admin/Cancellations.vue'),
                meta: { anyPermission: ['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'] },
            },
            {
                path: 'voucher',
                name: 'AdminVouchers',
                component: () => import('./pages/admin/Vouchers.vue'),
                meta: { permission: 'vouchers.view' },
            },
            {
                path: 'konten',
                name: 'AdminContents',
                component: () => import('./pages/admin/Contents.vue'),
                meta: { permission: 'content.view' },
            },
            {
                path: 'ulasan',
                name: 'AdminReviews',
                component: () => import('./pages/admin/Reviews.vue'),
                meta: { permission: 'reviews.manage' },
            },
            {
                path: 'pengguna',
                name: 'AdminUsers',
                component: () => import('./pages/admin/Users.vue'),
                meta: { permission: 'users.manage' },
            },
            {
                path: 'pengaturan',
                name: 'AdminSettings',
                component: () => import('./pages/admin/Settings.vue'),
                meta: { anyPermission: ['settings.manage', 'settings.manage_contact'] },
            },
            {
                path: 'audit-log',
                name: 'AdminAudit',
                component: () => import('./pages/admin/AuditLog.vue'),
                meta: { permission: 'audit.view' },
            },
            {
                path: 'profil',
                name: 'AdminProfile',
                component: () => import('./pages/admin/Profile.vue'),
                meta: { crumb: ['Akun', 'Profil'] },
            },
            {
                path: 'forbidden',
                name: 'AccessDenied',
                component: () => import('./pages/AccessDenied.vue'),
            },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const authStore = useAuthStore();

    // Check initial user state if not loaded for protected routes only
    if (!authStore.isAuthenticated && !authStore.user) {
        await authStore.fetchUser();
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return { name: 'Login' };
    }

    if (to.meta.guestOnly && authStore.isAuthenticated) {
        return { name: 'Dashboard' };
    }

    // Akun tanpa role bukan staf. Ini hanya tampilan; endpoint admin dijaga permission di server.
    if (to.meta.requiresAuth && to.name !== 'AccessDenied' && !authStore.activeRole) {
        return { name: 'AccessDenied' };
    }

    if (to.meta.permission && !authStore.can(to.meta.permission)) {
        return { name: 'AccessDenied' };
    }

    if (to.meta.anyPermission && !to.meta.anyPermission.some((permission) => authStore.can(permission))) {
        return { name: 'AccessDenied' };
    }

    if (to.meta.role && !authStore.hasRole(to.meta.role)) {
        return { name: 'AccessDenied' };
    }

    return true;
});

export default router;
