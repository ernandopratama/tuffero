import axios from 'axios';
import router from '../router';

const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
    withCredentials: true, // Required for Sanctum cookie-based auth
});

// Response Interceptor
api.interceptors.response.use(
    (response) => response,
    (error) => {
        // Handle 401 Unauthorized globally
        // /auth/user dipanggil penjaga rute untuk memeriksa sesi; penjaga itu yang memutuskan arah. Mengalihkan di sini membuat perulangan tanpa henti.
        if (error.response && error.response.status === 401 && error.config?.url !== '/auth/user' && router.currentRoute.value.meta.requiresAuth) {
            router.push({ name: 'Login' });
        }
        return Promise.reject(error);
    }
);

export default api;
