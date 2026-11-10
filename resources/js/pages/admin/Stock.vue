<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { TONE_CLASS } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';

const auth = useAuthStore();
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const ACTIVE = { 1: 'Aktif', 0: 'Nonaktif' };

// Jenis perubahan di riwayat: label, ikon, dan warna lencana.
const MOVEMENTS = {
    restock: { label: 'Stok masuk', icon: 'plus', tone: 'ok' },
    adjustment: { label: 'Penyesuaian', icon: 'sliders', tone: 'warn' },
    reserve: { label: 'Dipesan', icon: 'clock', tone: 'info' },
    release: { label: 'Dilepas', icon: 'undo', tone: 'neutral' },
    sale: { label: 'Terjual', icon: 'receipt', tone: 'bad' },
    return: { label: 'Retur', icon: 'undo', tone: 'ok' },
};

// Batas "menipis" sama dengan dashboard (D-055): tersedia di bawah 10.
const LOW_STOCK = 10;
const DOT = { ok: 'bg-success', warn: 'bg-warning', bad: 'bg-error' };
const level = (row) => {
    if (row.available <= 0) return { label: 'Habis', tone: 'bad' };
    if (row.available < LOW_STOCK) return { label: 'Menipis', tone: 'warn' };
    return { label: 'Aman', tone: 'ok' };
};

const COLUMNS = [
    { key: 'product', label: 'Produk' },
    { key: 'sku', label: 'SKU' },
    { key: 'quantity', label: 'Stok' },
    { key: 'reserved', label: 'Dipesan' },
    { key: 'available', label: 'Tersedia' },
    { key: 'actions', label: 'Aksi' },
];

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';
const TYPE_CHOICES = [
    { key: 'restock', title: 'Stok masuk', text: 'Menambah stok. Jumlah harus lebih dari 0.' },
    { key: 'adjustment', title: 'Penyesuaian', text: 'Koreksi hasil hitung fisik. Boleh minus, misalnya -3.' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/stock', { filters: { warehouse_id: '', active: '' } });

// Tanpa pilihan, server memakai gudang aktif pertama; daftar gudang diambil sekali untuk pilihan filter.
const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map((w) => ({ value: w.id, label: w.name })));
const warehouseId = computed(() => Number(filter.warehouse_id) || warehouses.value[0]?.id || null);

const adjustDialog = ref(null);
const selected = ref(null);
const form = reactive({ type: 'restock', quantity_change: '', note: '' });
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});

// Pratinjau stok setelah perubahan; hanya tampilan, server tetap memeriksa dan menolak stok minus.
const change = computed(() => (form.quantity_change === '' ? null : Number(form.quantity_change)));
const stockAfter = computed(() => (selected.value && change.value !== null && Number.isFinite(change.value) ? selected.value.quantity + change.value : null));

const historyDialog = ref(null);
const history = ref([]);
const historyLoading = ref(false);
const historyError = ref('');

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
    try {
        await api.post('/admin/stock/adjust', {
            warehouse_id: warehouseId.value,
            product_variant_id: selected.value.variant_id,
            type: form.type,
            quantity_change: form.quantity_change,
            note: form.note,
        }, { toast: `Stok ${selected.value.sku} diperbarui.` });
        adjustDialog.value.close();
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

onMounted(async () => {
    try {
        warehouses.value = (await api.get('/admin/stock', { params: { per_page: 10 } })).data.data.warehouses;
    } catch {
        warehouses.value = [];
    }
});
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Stok" description="Tersedia = stok fisik dikurangi yang sedang dipesan. Setiap perubahan wajib memakai alasan dan tercatat." />
    <TableToolbar v-model="search" placeholder="Cari produk atau SKU">
      <FilterSelect v-model="filter.warehouse_id" label="Gudang" all-label="Utama" :options="warehouseOptions" />
      <FilterSelect v-model="filter.active" label="Varian" :options="ACTIVE" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar stok varian"
    empty-text="Belum ada varian produk."
    min-width="min-w-[860px]"
    row-key="variant_id"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-product="{ row }">
      <span class="block text-label-md" :class="row.is_active ? '' : 'text-secondary'">{{ row.product }}</span>
      <span class="text-body-sm text-secondary">{{ row.variant }}</span>
      <span v-if="!row.is_active" class="ml-1.5 rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm text-secondary">Nonaktif</span>
    </template>
    <template #cell-sku="{ row }"><span class="whitespace-nowrap font-mono">{{ row.sku }}</span></template>
    <template #cell-quantity="{ row }"><span class="tabular-nums">{{ row.quantity }}</span></template>
    <template #cell-reserved="{ row }"><span class="tabular-nums" :class="row.reserved ? '' : 'text-secondary'">{{ row.reserved }}</span></template>
    <template #cell-available="{ row }">
      <span class="block text-label-md tabular-nums">{{ row.available }}</span>
      <span class="mt-1 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-label-sm" :class="TONE_CLASS[level(row).tone]">
        <i class="size-1.5 rounded-full" :class="DOT[level(row).tone]" aria-hidden="true"></i>{{ level(row).label }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction v-if="auth.can('stock.adjust')" icon="sliders" label="Ubah stok" :subject="row.sku" @click="openAdjust(row)" />
        <IconAction icon="clock" label="Riwayat stok" :subject="row.sku" @click="openHistory(row)" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="adjustDialog" variant="update" size="lg" title="Ubah stok" :subtitle="`${selected?.product}, ${selected?.sku}`">
    <form v-if="selected" id="adjust-form" class="space-y-space-md" @submit.prevent="submitAdjust">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>

      <dl class="grid grid-cols-3 gap-2 text-center">
        <div class="rounded-2xl bg-background/70 p-3"><dt class="text-body-sm text-secondary">Stok</dt><dd class="font-heading text-headline-sm tabular-nums">{{ selected.quantity }}</dd></div>
        <div class="rounded-2xl bg-background/70 p-3"><dt class="text-body-sm text-secondary">Dipesan</dt><dd class="font-heading text-headline-sm tabular-nums">{{ selected.reserved }}</dd></div>
        <div class="rounded-2xl bg-background/70 p-3"><dt class="text-body-sm text-secondary">Tersedia</dt><dd class="font-heading text-headline-sm tabular-nums">{{ selected.available }}</dd></div>
      </dl>

      <fieldset class="grid gap-2 sm:grid-cols-2">
        <legend class="mb-2 text-label-md">Jenis perubahan</legend>
        <label v-for="choice in TYPE_CHOICES" :key="choice.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="form.type === choice.key ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
          <input v-model="form.type" type="radio" :value="choice.key" class="mt-1 size-4 accent-primary">
          <span><span class="block text-label-md">{{ choice.title }}</span><span class="block text-body-sm text-secondary">{{ choice.text }}</span></span>
        </label>
      </fieldset>

      <label class="block text-label-md">
        Jumlah perubahan
        <input v-model.number="form.quantity_change" type="number" required :min="form.type === 'restock' ? 1 : undefined" step="1" :class="field">
        <span v-if="fieldErrors.quantity_change" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.quantity_change[0] }}</span>
      </label>

      <p v-if="stockAfter !== null" role="status" class="flex items-center gap-2 rounded-2xl px-4 py-3 text-body-sm" :class="stockAfter < 0 ? 'bg-error-surface text-on-error-surface' : 'bg-accent-tint-15'">
        <AdminIcon :name="stockAfter < 0 ? 'alert' : 'warehouse'" :size="16" />
        <span v-if="stockAfter < 0">Stok akan menjadi <strong class="tabular-nums">{{ stockAfter }}</strong>. Stok tidak boleh minus, jadi perubahan ini ditolak.</span>
        <span v-else>Stok menjadi <strong class="tabular-nums">{{ stockAfter }}</strong> (sekarang {{ selected.quantity }}).</span>
      </p>

      <label class="block text-label-md">
        Alasan
        <input v-model="form.note" required maxlength="255" placeholder="Contoh: Hitung ulang gudang" :class="field">
        <span v-if="fieldErrors.note" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.note[0] }}</span>
      </label>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="adjustDialog.close()">Batal</button>
      <button form="adjust-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal ref="historyDialog" variant="read" size="2xl" title="Riwayat stok" :subtitle="`${selected?.product}, ${selected?.sku}. 50 perubahan terakhir.`">
    <div v-if="historyLoading" role="status" aria-label="Memuat riwayat" class="space-y-3">
      <div v-for="n in 4" :key="n" class="h-16 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>
    <p v-else-if="historyError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ historyError }}</p>
    <p v-else-if="!history.length" class="rounded-2xl border border-dashed border-border p-6 text-center text-body-sm text-secondary">Belum ada perubahan.</p>
    <ul v-else class="space-y-2">
      <li v-for="movement in history" :key="movement.id" class="flex items-start gap-3 rounded-2xl border border-border p-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-full" :class="TONE_CLASS[(MOVEMENTS[movement.type] || {}).tone || 'neutral']" aria-hidden="true">
          <AdminIcon :name="(MOVEMENTS[movement.type] || {}).icon || 'box'" :size="18" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-label-md">{{ (MOVEMENTS[movement.type] || {}).label || movement.type }}</p>
          <p class="break-words text-body-sm">{{ movement.note || '-' }}</p>
          <p class="text-body-sm text-secondary">{{ movement.actor || 'Sistem' }} · {{ dateFormat.format(new Date(movement.created_at)) }}</p>
        </div>
        <div class="shrink-0 text-right">
          <p class="font-heading text-headline-sm tabular-nums" :class="movement.quantity_change > 0 ? 'text-success' : movement.quantity_change < 0 ? 'text-error' : ''">{{ movement.quantity_change > 0 ? '+' : '' }}{{ movement.quantity_change }}</p>
          <p class="text-body-sm text-secondary">jadi <span class="tabular-nums">{{ movement.quantity_after }}</span></p>
        </div>
      </li>
    </ul>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="historyDialog.close()">Tutup</button>
    </template>
  </AppModal>
</template>
