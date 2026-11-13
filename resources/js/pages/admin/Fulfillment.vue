<script setup>
import { computed, nextTick, ref } from 'vue';
import { useRouter } from 'vue-router';
import OrderActions from '../../components/OrderActions.vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import IconAction from '../../components/ui/IconAction.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { useAuthStore } from '../../stores/auth';
import { formatDate, timeAgo } from '../../utils/datetime';
import { ORDER_STATUS, ORDER_STATUS_TONE, TONE_CLASS } from '../../utils/orderLabels';
import PageHeader from '../../components/ui/PageHeader.vue';

const COLUMNS = [
    { key: 'select', label: 'Pilih', srOnly: true, class: 'w-10' },
    { key: 'order_number', label: 'Nomor' },
    { key: 'items', label: 'Item', cellClass: 'text-left' },
    { key: 'shipping_address', label: 'Penerima', cellClass: 'text-left' },
    { key: 'courier', label: 'Kurir' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const STATUS_OPTIONS = { paid: ORDER_STATUS.paid, processing: ORDER_STATUS.processing };
const COURIER_OPTIONS = { jnt: 'J&T', sicepat: 'SiCepat' };
const WAYBILL_OPTIONS = { with: 'Sudah ada resi', without: 'Belum ada resi' };
const SORT_OPTIONS = { newest: 'Terbaru dulu' };
// Sama dengan OrderController::BULK_LIMIT.
const BULK_LIMIT = 50;
const DOT = { ok: 'bg-success', warn: 'bg-warning', bad: 'bg-error', info: 'bg-info', neutral: 'bg-secondary' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/fulfillment', { filters: { status: '', courier: '', waybill: '', sort: '' } });

const auth = useAuthStore();
const router = useRouter();
const canManage = computed(() => auth.can('shipments.manage'));
const canFinish = computed(() => auth.can('orders.update_status'));
const toneOf = (row) => ORDER_STATUS_TONE[row.status] || 'neutral';

// Langkah berikutnya menentukan ikon tombol kelola. Izin sebenarnya tetap dijaga server.
function nextStep(row) {
    if (canManage.value && ['paid', 'processing'].includes(row.status) && !row.shipment) return { icon: 'box', label: 'Proses pesanan', tone: 'primary' };
    if (canManage.value && row.status === 'processing' && row.shipment) return { icon: 'truck', label: 'Tandai dikirim', tone: 'primary' };
    if (canFinish.value && row.status === 'paid' && !canManage.value) return { icon: 'box', label: 'Mulai proses', tone: 'primary' };
    return null;
}

// Pilihan massal: id pesanan -> apakah sudah punya resi. Bertahan saat pindah halaman atau mengganti filter.
const selected = ref({});
const selectedIds = computed(() => Object.keys(selected.value).map(Number));
const labelIds = computed(() => selectedIds.value.filter((id) => selected.value[id]));
const overLimit = computed(() => selectedIds.value.length > BULK_LIMIT);
const allOnPage = computed(() => rows.value.length > 0 && rows.value.every((row) => row.id in selected.value));
const someOnPage = computed(() => rows.value.some((row) => row.id in selected.value));

function toggle(row) {
    const next = { ...selected.value };
    if (row.id in next) delete next[row.id];
    else next[row.id] = !!row.waybill_id;
    selected.value = next;
}
function toggleAll() {
    const next = { ...selected.value };
    for (const row of rows.value) {
        if (allOnPage.value) delete next[row.id];
        else next[row.id] = !!row.waybill_id;
    }
    selected.value = next;
}
const clearSelection = () => { selected.value = {}; };
const openBulk = (name, ids) => window.open(`${router.resolve({ name, query: { ids: ids.join(',') } }).href}`, '_blank', 'noopener');

const printLabel = (row) => window.open(`${router.resolve({ name: 'AdminOrderLabel', params: { id: row.id } }).href}?print=1`, '_blank', 'noopener');

const dialog = ref(null);
const active = ref(null);
async function manage(row) {
    active.value = row;
    await nextTick();
    dialog.value.showModal();
}
function onUpdated() {
    dialog.value.close();
    load();
}

const itemCount = (row) => row.items.reduce((sum, item) => sum + item.quantity, 0);
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-space-md">
    <PageHeader title="Pengemasan" description="Pesanan yang sudah dibayar dan perlu dikemas. Bawaannya urut dari yang terlama. Centang beberapa pesanan untuk mencetak daftar ambil atau resi sekaligus." />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari nomor pesanan">
      <FilterSelect v-model="filter.status" label="Status" :options="STATUS_OPTIONS" />
      <FilterSelect v-model="filter.courier" label="Kurir" :options="COURIER_OPTIONS" />
      <FilterSelect v-model="filter.waybill" label="Resi" :options="WAYBILL_OPTIONS" />
      <FilterSelect v-model="filter.sort" label="Urutan" all-label="Terlama dulu" :options="SORT_OPTIONS" />
    </TableToolbar>
  </div>

  <div v-if="selectedIds.length" role="region" aria-label="Pilihan massal" class="sticky top-24 z-10 mt-space-md flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-primary bg-surface px-4 py-3 shadow-card">
    <p class="text-label-md" role="status">{{ selectedIds.length }} pesanan dipilih</p>
    <p v-if="overLimit" class="text-body-sm text-error">Maksimal {{ BULK_LIMIT }} pesanan sekali cetak. Kurangi pilihan Anda.</p>
    <div class="ml-auto flex flex-wrap items-center gap-2">
      <button type="button" :disabled="overLimit" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="openBulk('AdminPickList', selectedIds)">
        <AdminIcon name="receipt" :size="16" />Daftar ambil
      </button>
      <button v-if="canManage" type="button" :disabled="overLimit || !labelIds.length" :title="labelIds.length ? '' : 'Belum ada pesanan terpilih yang punya resi'" class="inline-flex min-h-10 items-center gap-2 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="openBulk('AdminOrderLabels', labelIds)">
        <AdminIcon name="printer" :size="16" />Cetak resi ({{ labelIds.length }})
      </button>
      <button type="button" class="inline-flex min-h-10 items-center rounded-full px-3 text-label-md text-secondary underline hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="clearSelection">Hapus pilihan</button>
    </div>
  </div>

  <label v-if="rows.length" class="mt-space-md flex min-h-10 items-center gap-3 text-label-md md:hidden">
    <input type="checkbox" class="size-5 cursor-pointer accent-primary" :checked="allOnPage" :indeterminate.prop="someOnPage && !allOnPage" @change="toggleAll">
    Pilih semua pesanan di halaman ini
  </label>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pesanan yang perlu dikemas"
    empty-text="Tidak ada pesanan yang perlu dikemas."
    min-width="min-w-[1000px]"
    stack
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #head-select>
      <input type="checkbox" class="size-5 cursor-pointer accent-primary" :checked="allOnPage" :indeterminate.prop="someOnPage && !allOnPage" aria-label="Pilih semua pesanan di halaman ini" @change="toggleAll">
    </template>
    <template #cell-select="{ row }">
      <input type="checkbox" class="size-5 cursor-pointer accent-primary" :checked="row.id in selected" :aria-label="`Pilih pesanan ${row.order_number}`" @change="toggle(row)">
    </template>
    <template #cell-order_number="{ row }">
      <span class="block whitespace-nowrap font-mono text-label-md">{{ row.order_number }}</span>
      <span class="block whitespace-nowrap text-body-sm text-secondary">Dibayar {{ formatDate(row.paid_at) }}</span>
      <span class="block whitespace-nowrap text-label-sm text-secondary">{{ timeAgo(row.paid_at) }}</span>
    </template>
    <template #cell-items="{ row }">
      <ul class="max-w-xs space-y-1.5">
        <li v-for="item in row.items" :key="item.sku" class="flex items-start gap-2">
          <span class="mt-0.5 inline-flex min-w-8 shrink-0 justify-center rounded-full bg-accent-tint-20 px-1.5 py-0.5 text-label-sm tabular-nums">{{ item.quantity }}×</span>
          <span class="min-w-0">
            <span class="block text-label-md">{{ item.product_name }}<template v-if="item.variant_name"> · {{ item.variant_name }}</template></span>
            <span class="block font-mono text-body-sm text-secondary">{{ item.sku }}</span>
          </span>
        </li>
      </ul>
      <p v-if="row.items.length > 1" class="mt-1.5 text-label-sm text-secondary">{{ itemCount(row) }} barang</p>
    </template>
    <template #cell-shipping_address="{ row }">
      <address class="max-w-xs not-italic">
        <span class="block text-label-md">{{ row.shipping_address.recipient }}</span>
        <span class="block font-mono text-body-sm text-secondary">{{ row.shipping_address.phone }}</span>
        <span class="mt-1 block line-clamp-3 text-body-sm">{{ row.shipping_address.street }}, {{ row.shipping_address.district }}, {{ row.shipping_address.city }}, {{ row.shipping_address.province }} {{ row.shipping_address.postal_code }}</span>
      </address>
    </template>
    <template #cell-courier="{ row }">
      <span class="block text-label-md uppercase">{{ row.shipping_courier }}</span>
      <span class="block text-body-sm uppercase text-secondary">{{ row.shipping_service }}</span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[toneOf(row)]">
        <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ ORDER_STATUS[row.status] }}
      </span>
      <span v-if="row.waybill_id" class="mt-1 block whitespace-nowrap font-mono text-body-sm text-secondary">{{ row.waybill_id }}</span>
      <span v-else class="mt-1 block text-body-sm text-secondary">Belum ada resi</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1 max-md:justify-start">
        <IconAction v-if="canManage && row.waybill_id" icon="printer" label="Cetak resi" :subject="row.order_number" @click="printLabel(row)" />
        <IconAction v-if="nextStep(row)" v-bind="nextStep(row)" :subject="row.order_number" @click="manage(row)" />
        <span v-if="!(canManage && row.waybill_id) && !nextStep(row)" class="text-secondary">-</span>
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" variant="update" size="xl" :title="active?.order_number || 'Pengemasan'" subtitle="Proses pesanan" @close="active = null">
    <OrderActions v-if="active" :key="active.id" :order="active" start-open @updated="onUpdated" />
  </AppModal>
</template>
