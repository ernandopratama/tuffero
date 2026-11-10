<script setup>
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { formatDate } from '../../utils/datetime';
import { PAYMENT_STATUS, TONE_CLASS, rupiah } from '../../utils/orderLabels';

const COLUMNS = [
    { key: 'order_number', label: 'Nomor pesanan' },
    { key: 'total', label: 'Total' },
    { key: 'payment_status', label: 'Status' },
    { key: 'paid_at', label: 'Dibayar' },
    { key: 'expires_at', label: 'Batas bayar' },
];
const PAYMENT_TONE = { paid: 'ok', pending: 'warn', refund_pending: 'warn', refunded: 'info', cancelled: 'bad', expired: 'neutral' };
const DOT = { ok: 'bg-success', warn: 'bg-warning', info: 'bg-info', bad: 'bg-error', neutral: 'bg-secondary' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/payments', { filters: { payment_status: '' } });

const toneOf = (row) => PAYMENT_TONE[row.payment_status] || 'neutral';

// Sisa waktu bayar hanya berarti selama status masih menunggu. Dihitung saat tabel dimuat, tidak berdetak.
function remaining(row) {
    if (row.payment_status !== 'pending' || !row.expires_at) return null;
    const minutes = Math.round((new Date(row.expires_at) - Date.now()) / 60000);
    if (minutes <= 0) return { text: 'Lewat batas', late: true };
    if (minutes < 60) return { text: `Sisa ${minutes} menit`, late: false };
    return { text: `Sisa ${Math.floor(minutes / 60)} jam`, late: false };
}
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Pembayaran" description="Hanya untuk dilihat. Status berubah dari notifikasi Midtrans yang terverifikasi, bukan dari dashboard." />
    <TableToolbar v-model="search" placeholder="Cari nomor pesanan">
      <FilterSelect v-model="filter.payment_status" label="Status" :options="PAYMENT_STATUS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pembayaran"
    empty-text="Belum ada pembayaran."
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-order_number="{ row }">
      <span class="block whitespace-nowrap font-mono">{{ row.order_number }}</span>
      <span class="block whitespace-nowrap text-body-sm text-secondary">Dibuat {{ formatDate(row.created_at) }}</span>
    </template>
    <template #cell-total="{ row }"><span class="whitespace-nowrap text-label-md tabular-nums">{{ rupiah(row.total) }}</span></template>
    <template #cell-payment_status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[toneOf(row)]">
        <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ PAYMENT_STATUS[row.payment_status] || row.payment_status }}
      </span>
    </template>
    <template #cell-paid_at="{ row }">
      <span class="whitespace-nowrap" :class="row.paid_at ? '' : 'text-secondary'">{{ row.paid_at ? formatDate(row.paid_at) : 'Belum dibayar' }}</span>
    </template>
    <template #cell-expires_at="{ row }">
      <span class="block whitespace-nowrap" :class="remaining(row) ? '' : 'text-secondary'">{{ row.expires_at ? formatDate(row.expires_at) : '-' }}</span>
      <span v-if="remaining(row)" class="block text-label-sm" :class="remaining(row).late ? 'text-error' : 'text-warning'">{{ remaining(row).text }}</span>
    </template>
  </DataTable>
</template>
