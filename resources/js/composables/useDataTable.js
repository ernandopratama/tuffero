import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../utils/api';
import { apiError } from '../utils/apiError';

/**
 * Daftar admin dengan penomoran dari server: halaman, jumlah baris, pencarian (ditunda 350 ms), dan filter.
 * `filters` = nilai awal tiap filter; kunci filter dikirim sebagai parameter query bila tidak kosong.
 * `params` = fungsi yang mengembalikan parameter tetap tambahan (misalnya scope).
 */
export function useDataTable(url, { filters = {}, params = () => ({}), perPage = 25 } = {}) {
    const rows = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const page = ref(1);
    const pageSize = ref(perPage);
    // ?cari=... mengisi pencarian awal (tautan dari notifikasi) dan mengikuti perubahan tautan di halaman yang sama.
    const route = useRoute();
    const search = ref(String(route.query.cari || ''));
    const filter = reactive({ ...filters });
    const loading = ref(true);
    const error = ref('');
    let latest = 0;

    async function load() {
        const mine = ++latest;
        loading.value = true;
        error.value = '';
        try {
            const query = { page: page.value, per_page: pageSize.value, search: search.value.trim() || undefined, ...params() };
            for (const [key, value] of Object.entries(filter)) query[key] = value === '' || value === null ? undefined : value;
            const { data } = await api.get(url, { params: query });
            if (mine !== latest) return;
            rows.value = data.data.items;
            meta.value = data.data.meta;
        } catch (e) {
            if (mine === latest) error.value = apiError(e).message;
        } finally {
            if (mine === latest) loading.value = false;
        }
    }

    // Filter, pencarian, dan jumlah baris selalu kembali ke halaman 1.
    const reset = () => (page.value === 1 ? load() : (page.value = 1));
    let timer;
    watch(() => route.query.cari, (value) => { search.value = String(value || ''); });
    watch(search, () => { clearTimeout(timer); timer = setTimeout(reset, 350); });
    watch(filter, reset);
    watch(pageSize, reset);
    watch(page, load);
    onMounted(load);

    return { rows, meta, page, perPage: pageSize, search, filter, loading, error, load };
}
