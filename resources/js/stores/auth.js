import { defineStore } from 'pinia';
import api from '../utils/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        isAuthenticated: false,
    }),
    getters: {
        activeRole: (state) => state.user?.role || state.user?.roles?.[0] || null,
        permissions: (state) => state.user?.permissions || [],
        can: (state) => (permission) => state.user?.permissions?.includes(permission) === true,
        hasRole: (state) => (role) => {
            const roles = state.user?.roles || (state.user?.role ? [state.user.role] : []);
            return roles.includes(role);
        },
    },
    actions: {
        setUser(user) {
            this.user = user
                ? {
                    ...user,
                    roles: user.roles || (user.role ? [user.role] : []),
                    permissions: user.permissions || [],
                }
                : null;
            this.isAuthenticated = this.user !== null;
        },

        async login(credentials) {
            // Get CSRF cookie first for Sanctum SPA auth (must use same origin with credentials)
            await api.get('/sanctum/csrf-cookie', { baseURL: '/' });

            const response = await api.post('/auth/login', credentials);

            if (response.data.success) {
                this.setUser(response.data.data.user);
                return true;
            }
            return false;
        },

        async logout() {
            await api.post('/auth/logout');
            this.user = null;
            this.isAuthenticated = false;
        },

        async fetchUser() {
            try {
                const response = await api.get('/auth/user');
                if (response.data.success) {
                    this.setUser(response.data.data.user);
                    return true;
                }
            } catch (error) {
                this.setUser(null);
            }

            return false;
        },
    },
});
