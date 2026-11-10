<script setup>
import { computed, nextTick, ref } from 'vue';
import { useRouter } from 'vue-router';
import OrderActions from '../../components/OrderActions.vue';
import AppModal from '../../components/ui/AppModal.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { useAuthStore } from '../../stores/auth';
import { formatDate } from '../../utils/datetime';
import { SHIPPING_STATUS, TONE_CLASS } from '../../utils/orderLabels';

const COLUMNS = [
    { key: 'order_number', label: 'Nomor' },
    { key: 'recipient', label: 'Penerima' },
    { key: 'courier', label: 'Kurir' },
    { key: 'shipping_status', label: 'Status' },
    { key: 'shipped_at', label: 'Dikirim' },
    { key: 'waybill_id', label: 'Resi' },
    { key: 'actions', label: 'Aksi' },
];
const SHIPPING_TONE = { pending: 'neutral', packing: 'warn', shipped: 'info', delivered: 'ok' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/shipments', { filters: { shipping_status: '' } });

const auth = useAuthStore();
const router = useRouter();
const canManage = computed(() => auth.can('shipments.manage'));
const canFinish = computed(() => auth.can('orders.update_status'));

// Langkah berikutnya per baris menentukan ikon tombol kelola. Izin sebenarnya tetap dijaga server.
function nextStep(row) {
    if (row.status === 'processing' && canManage.value) {
        return row.shipment ? { icon: 'truck', label: 'Tandai dikirim', tone: 'primary' } : { icon: 'box', label: 'Proses pesanan', tone: 'primary' };
    }
    if (row.status === 'shipped' && (canFinish.value || canManage.value)) return { icon: 'edit', label: 'Kelola pengiriman', tone: 'default' };
    return null;
}

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
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Pengiriman" description="Kirim lewat Biteship untuk resi otomatis, atau isi nomor resi manual sebagai cadangan." />
    <TableToolbar v-model="search" placeholder="Cari nomor atau nama pemesan">
      <FilterSelect v-model="filter.shipping_status" label="Status" :options="SHIPPING_STATUS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pengiriman"
    empty-text="Belum ada pesanan untuk dikirim."
    min-width="min-w-[960px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-order_number="{ row }"><span class="whitespace-nowrap font-mono">{{ row.order_number }}</span></template>
    <template #cell-recipient="{ row }">
      <span class="block text-label-md">{{ row.recipient || '-' }}</span>
      <span class="block text-body-sm text-secondary">{{ row.city }}</span>
    </template>
    <template #cell-courier="{ row }">
      <span class="block text-label-md uppercase">{{ row.shipping_courier }}</span>
      <span class="block text-body-sm uppercase text-secondary">{{ row.shipping_service }}</span>
    </template>
    <template #cell-shipping_status="{ row }">
      <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[SHIPPING_TONE[row.shipping_status] || 'neutral']">{{ SHIPPING_STATUS[row.shipping_status] || row.shipping_status }}</span>
    </template>
    <template #cell-shipped_at="{ row }">
      <span class="whitespace-nowrap" :class="row.shipped_at ? '' : 'text-secondary'">{{ row.shipped_at ? formatDate(row.shipped_at) : 'Belum dikirim' }}</span>
    </template>
    <template #cell-waybill_id="{ row }">
      <template v-if="row.waybill_id">
        <span class="block whitespace-nowrap font-mono text-label-md">{{ row.waybill_id }}</span>
        <span class="block text-body-sm text-secondary">{{ row.shipment?.provider === 'biteship' ? 'Biteship' : 'Resi manual' }}</span>
      </template>
      <span v-else class="text-secondary">Belum ada resi</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction v-if="canManage && row.waybill_id" icon="printer" label="Cetak resi" :subject="row.order_number" @click="printLabel(row)" />
        <IconAction v-if="nextStep(row)" v-bind="nextStep(row)" :subject="row.order_number" @click="manage(row)" />
        <span v-if="!(canManage && row.waybill_id) && !nextStep(row)" class="text-secondary">-</span>
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" variant="update" size="xl" :title="active?.order_number || 'Pengiriman'" subtitle="Kelola pengiriman" @close="active = null">
    <OrderActions v-if="active" :key="active.id" :order="active" start-open @updated="onUpdated" />
  </AppModal>
</template>
