import axios from 'axios';
import router from '../router';
import { toast } from '../composables/useToast';

const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
    withCredentials: true, // Required for Sanctum cookie-based auth
});

// Aksi yang tidak menampilkan toast: bukan "simpan" bagi pengguna (tandai baca, usulan terjemahan, catatan, login).
const SILENT = [/^\/auth\//, /^\/admin\/notifications/, /\/translate$/, /\/comments$/];
const WRITES = ['post', 'put', 'patch', 'delete'];

// Toast sukses untuk setiap penulisan admin yang berhasil. Per permintaan: { toast: false } mematikan, { toast: 'teks' } mengganti pesan.
function notifySuccess(response) {
    const { method, url, toast: custom } = response.config;
    if (custom === false || !WRITES.includes(method) || !url.startsWith('/admin/')) return;
    if (!custom && SILENT.some((pattern) => pattern.test(url))) return;
    toast.success(custom || (method === 'delete' ? 'Data dihapus.' : 'Perubahan tersimpan.'));
}

// Response Interceptor
api.interceptors.response.use(
    (response) => {
        notifySuccess(response);
        return response;
    },
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
