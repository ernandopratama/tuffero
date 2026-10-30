import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const routes = [
    {
        path: '/',
        name: 'Home',
        component: () => import('./pages/Home.vue'),
        meta: { public: true },
    },
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
    if (to.meta.public) {
        return true;
    }

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

    if (to.meta.permission && !authStore.can(to.meta.permission)) {
        return { name: 'AccessDenied' };
    }

    if (to.meta.role && !authStore.hasRole(to.meta.role)) {
        return { name: 'AccessDenied' };
    }

    return true;
});

export default router;
