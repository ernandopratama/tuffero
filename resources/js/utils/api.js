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
        if (error.response && error.response.status === 401 && router.currentRoute.value.name !== 'Login') {
            router.push({ name: 'Login' });
        }
        return Promise.reject(error);
    }
);

export default api;
