import { defineStore } from 'pinia';
import api from '../utils/api';

// Header, guard rute, dan checkout bisa bertanya bersamaan; cukup satu request.
let userRequest = null;

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

            const { data } = await api.post('/auth/login', credentials);

            if (data.data.user) {
                this.setUser(data.data.user);
            }

            // null = login selesai; 'setup' atau 'challenge' = masih butuh langkah MFA.
            return data.data.mfa || null;
        },

        async startMfaSetup() {
            const { data } = await api.post('/auth/mfa/setup');
            return data.data;
        },

        async confirmMfa(code) {
            const { data } = await api.post('/auth/mfa/confirm', { code });
            this.setUser(data.data.user);
            return data.data.recovery_codes;
        },

        async sendMfaEmail() {
            await api.post('/auth/mfa/email');
        },

        async verifyMfa(payload) {
            const { data } = await api.post('/auth/mfa/verify', payload);
            this.setUser(data.data.user);
        },

        async logout() {
            await api.post('/auth/logout');
            this.user = null;
            this.isAuthenticated = false;
        },

        fetchUser() {
            userRequest ??= this.loadUser().finally(() => { userRequest = null; });
            return userRequest;
        },

        async loadUser() {
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
