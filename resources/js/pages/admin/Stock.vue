<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const TYPES = { restock: 'Stok masuk', adjustment: 'Penyesuaian', reserve: 'Dipesan', release: 'Dilepas', sale: 'Terjual', return: 'Retur' };

const items = ref([]);
const warehouses = ref([]);
const warehouseId = ref(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref('');
const page = ref(1);
const loading = ref(true);
const error = ref('');
const notice = ref('');

const adjustDialog = ref(null);
const selected = ref(null);
const form = reactive({ type: 'restock', quantity_change: '', note: '' });
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});

const historyDialog = ref(null);
const history = ref([]);
const historyLoading = ref(false);
const historyError = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/stock', { params: { page: page.value, search: search.value || undefined, warehouse_id: warehouseId.value || undefined } });
        items.value = data.data.items;
        warehouses.value = data.data.warehouses;
        warehouseId.value = data.data.warehouse_id;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function applySearch() {
    if (page.value === 1) load();
    else page.value = 1;
}

function openAdjust(item) {
    selected.value = item;
    Object.assign(form, { type: 'restock', quantity_change: '', note: '' });
    formError.value = '';
    fieldErrors.value = {};
    adjustDialog.value.showModal();
}

async function submitAdjust() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    notice.value = '';
    try {
        await api.post('/admin/stock/adjust', {
            warehouse_id: warehouseId.value,
            product_variant_id: selected.value.variant_id,
            type: form.type,
            quantity_change: form.quantity_change,
            note: form.note,
        });
        adjustDialog.value.close();
        notice.value = `Stok ${selected.value.sku} diperbarui.`;
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

async function openHistory(item) {
    selected.value = item;
    history.value = [];
    historyError.value = '';
    historyLoading.value = true;
    historyDialog.value.showModal();
    try {
        const { data } = await api.get('/admin/stock/movements', { params: { warehouse_id: warehouseId.value, variant_id: item.variant_id } });
        history.value = data.data.items;
    } catch (e) {
        historyError.value = apiError(e).message;
    } finally {
        historyLoading.value = false;
    }
}

watch(page, load);
onMounted(load);
</script>

<template>
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Stok</h1>
  <p class="mt-space-sm text-secondary">Tersedia = stok fisik dikurangi yang sedang dipesan. Setiap perubahan wajib memakai alasan dan tercatat.</p>

  <form class="mt-space-lg flex flex-wrap items-end gap-space-sm" role="search" @submit.prevent="applySearch">
    <label class="text-label-md">
      Gudang
      <select v-model="warehouseId" class="mt-1 block rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary" @change="applySearch">
        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
      </select>
    </label>
    <label class="min-w-48 flex-1 text-label-md sm:max-w-sm">
      Cari produk atau SKU
      <input v-model="search" type="search" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
    </label>
    <button class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary">Cari</button>
  </form>

  <p v-if="notice" role="status" class="mt-space-md rounded bg-success-surface px-3 py-2 text-body-sm text-success">{{ notice }}</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat stok...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!items.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada varian produk.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[720px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Produk</th>
            <th scope="col" class="px-4 py-3">SKU</th>
            <th scope="col" class="px-4 py-3 text-right">Stok</th>
            <th scope="col" class="px-4 py-3 text-right">Dipesan</th>
            <th scope="col" class="px-4 py-3 text-right">Tersedia</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.variant_id" class="border-b border-border last:border-b-0">
            <td class="px-4 py-3">
              <span class="block text-label-md">{{ item.product }}</span>
              <span class="text-secondary">{{ item.variant }}<template v-if="!item.is_active"> (nonaktif)</template></span>
            </td>
            <td class="px-4 py-3 font-mono">{{ item.sku }}</td>
            <td class="px-4 py-3 text-right">{{ item.quantity }}</td>
            <td class="px-4 py-3 text-right">{{ item.reserved }}</td>
            <td class="px-4 py-3 text-right text-label-md">{{ item.available }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-right">
              <button v-if="auth.can('stock.adjust')" type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="openAdjust(item)">Ubah stok</button>
              <button type="button" class="ml-space-md text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="openHistory(item)">Riwayat</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman stok">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} varian)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="adjustDialog" aria-labelledby="adjust-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="submitAdjust">
      <div>
        <h2 id="adjust-title" class="font-heading text-headline-md">Ubah stok</h2>
        <p class="mt-1 text-body-sm text-secondary">{{ selected?.product }}, {{ selected?.sku }}. Stok saat ini {{ selected?.quantity }}.</p>
      </div>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Jenis
        <select v-model="form.type" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <option value="restock">Stok masuk</option>
          <option value="adjustment">Penyesuaian (boleh minus)</option>
        </select>
      </label>
      <label class="block text-label-md">
        Jumlah perubahan
        <input v-model.number="form.quantity_change" type="number" required :min="form.type === 'restock' ? 1 : undefined" step="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span class="mt-1 block text-body-sm font-normal text-secondary">Contoh penyesuaian: -3 untuk mengurangi 3.</span>
        <span v-if="fieldErrors.quantity_change" class="mt-1 block text-body-sm text-error">{{ fieldErrors.quantity_change[0] }}</span>
      </label>
      <label class="block text-label-md">
        Alasan
        <input v-model="form.note" required maxlength="255" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.note" class="mt-1 block text-body-sm text-error">{{ fieldErrors.note[0] }}</span>
      </label>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="adjustDialog.close()">Batal</button>
        <button :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </dialog>

  <dialog ref="historyDialog" aria-labelledby="history-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <h2 id="history-title" class="font-heading text-headline-md">Riwayat stok</h2>
    <p class="mt-1 text-body-sm text-secondary">{{ selected?.product }}, {{ selected?.sku }}. 50 perubahan terakhir.</p>
    <p v-if="historyLoading" role="status" class="mt-space-md text-secondary">Memuat riwayat...</p>
    <p v-else-if="historyError" role="alert" class="mt-space-md text-body-sm text-error">{{ historyError }}</p>
    <p v-else-if="!history.length" class="mt-space-md text-secondary">Belum ada perubahan.</p>
    <ul v-else class="mt-space-md">
      <li v-for="movement in history" :key="movement.id" class="border-b border-border py-space-sm text-body-sm last:border-b-0">
        <span class="text-label-md">{{ TYPES[movement.type] || movement.type }} {{ movement.quantity_change > 0 ? '+' : '' }}{{ movement.quantity_change }}</span>, menjadi {{ movement.quantity_after }}
        <span class="block text-secondary">{{ movement.note || '-' }}</span>
        <span class="block text-secondary">{{ movement.actor || 'Sistem' }}, {{ dateFormat.format(new Date(movement.created_at)) }}</span>
      </li>
    </ul>
    <div class="mt-space-md flex justify-end">
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="historyDialog.close()">Tutup</button>
    </div>
  </dialog>
</template>
