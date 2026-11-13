<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { TONE_CLASS } from '../../utils/orderLabels';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

const MOVEMENTS = {
    restock: { label: 'Stok masuk', tone: 'ok' },
    adjustment: { label: 'Penyesuaian', tone: 'warn' },
    reserve: { label: 'Dipesan', tone: 'info' },
    release: { label: 'Dilepas', tone: 'neutral' },
    sale: { label: 'Terjual', tone: 'bad' },
    return: { label: 'Retur', tone: 'ok' },
};
const TYPE_OPTIONS = Object.fromEntries(Object.entries(MOVEMENTS).map(([key, value]) => [key, value.label]));

const COLUMNS = [
    { key: 'created_at', label: 'Waktu' },
    { key: 'product', label: 'Produk' },
    { key: 'type', label: 'Jenis' },
    { key: 'quantity_change', label: 'Stok' },
    { key: 'reserved_change', label: 'Dipesan' },
    { key: 'actor', label: 'Oleh' },
    { key: 'note', label: 'Catatan' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/stock/history', { filters: { warehouse_id: '', type: '', from: '', to: '' } });

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map((w) => ({ value: w.id, label: w.name })));
const signed = (n) => (n > 0 ? `+${n}` : String(n));

const exporting = ref(false);
const exportError = ref('');

// Unduhan lewat axios (bukan tautan) supaya sesi, CSRF, dan pesan galat tetap lewat jalur yang sama.
async function exportCsv() {
    exporting.value = true;
    exportError.value = '';
    try {
        const params = { search: search.value.trim() || undefined };
        for (const [key, value] of Object.entries(filter)) params[key] = value === '' ? undefined : value;
        const response = await api.get('/admin/stock/history/export', { params, responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `riwayat-stok-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Firefox bisa membatalkan unduhan bila alamat dicabut seketika.
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        exportError.value = e.response?.data instanceof Blob ? 'Gagal mengunduh. Periksa tanggal dan coba lagi.' : apiError(e).message;
    } finally {
        exporting.value = false;
    }
}

const dateField = 'h-10 w-full min-w-0 flex-1 rounded-full border border-border bg-surface px-3 text-body-sm focus:border-primary focus:outline-2 focus:outline-offset-1 focus:outline-primary';

onMounted(async () => {
    try {
        warehouses.value = (await api.get('/admin/stock', { params: { per_page: 1 } })).data.data.warehouses;
    } catch {
        warehouses.value = [];
    }
});
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Riwayat Stok" description="Semua gerakan stok: masuk, penyesuaian, dipesan, dilepas, terjual, dan retur. Unduhan CSV memuat maksimal 10.000 baris terbaru sesuai filter." />
    <div class="flex flex-wrap items-center gap-space-sm">
      <router-link :to="{ name: 'AdminStock' }" class="inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Kembali ke Stok</router-link>
      <button type="button" :disabled="exporting" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="exportCsv">
        <AdminIcon name="document" :size="16" />{{ exporting ? 'Menyiapkan...' : 'Unduh CSV' }}
      </button>
    </div>
  </div>

  <TableToolbar v-model="search" placeholder="Cari produk atau SKU">
    <FilterSelect v-model="filter.warehouse_id" label="Gudang" :options="warehouseOptions" />
    <FilterSelect v-model="filter.type" label="Jenis" :options="TYPE_OPTIONS" />
    <label class="flex min-w-0 items-center gap-2 text-body-sm text-secondary max-sm:flex-col max-sm:items-stretch max-sm:gap-1">Dari <input v-model="filter.from" type="date" :max="filter.to || undefined" :class="dateField"></label>
    <label class="flex min-w-0 items-center gap-2 text-body-sm text-secondary max-sm:flex-col max-sm:items-stretch max-sm:gap-1">Sampai <input v-model="filter.to" type="date" :min="filter.from || undefined" :class="dateField"></label>
  </TableToolbar>
  <p v-if="exportError" role="alert" class="mt-space-sm rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ exportError }}</p>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Riwayat gerakan stok"
    empty-text="Belum ada gerakan stok yang cocok."
    min-width="min-w-[900px]"
    stack
    row-key="id"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-body-sm">{{ dateFormat.format(new Date(row.created_at)) }}</span></template>
    <template #cell-product="{ row }">
      <span class="block text-label-md">{{ row.product }}</span>
      <span class="text-body-sm text-secondary">{{ row.variant }} · <span class="font-mono">{{ row.sku }}</span> · {{ row.warehouse }}</span>
    </template>
    <template #cell-type="{ row }">
      <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-label-sm" :class="TONE_CLASS[MOVEMENTS[row.type]?.tone || 'neutral']">{{ MOVEMENTS[row.type]?.label || row.type }}</span>
      <span v-if="row.order_number" class="mt-1 block text-body-sm text-secondary">{{ row.order_number }}</span>
    </template>
    <template #cell-quantity_change="{ row }">
      <span class="block tabular-nums" :class="row.quantity_change ? 'text-label-md' : 'text-secondary'">{{ signed(row.quantity_change) }}</span>
      <span class="text-body-sm text-secondary tabular-nums">jadi {{ row.quantity_after }}</span>
    </template>
    <template #cell-reserved_change="{ row }">
      <span class="block tabular-nums" :class="row.reserved_change ? 'text-label-md' : 'text-secondary'">{{ signed(row.reserved_change) }}</span>
      <span class="text-body-sm text-secondary tabular-nums">jadi {{ row.reserved_after }}</span>
    </template>
    <template #cell-actor="{ row }"><span class="text-body-sm">{{ row.actor || 'Sistem' }}</span></template>
    <template #cell-note="{ row }"><span class="text-body-sm">{{ row.note || '-' }}</span></template>
  </DataTable>
</template>
