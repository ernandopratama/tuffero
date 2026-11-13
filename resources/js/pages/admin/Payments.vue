<script setup>
import { computed, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import DataTable from '../../components/ui/DataTable.vue';
import IconAction from '../../components/ui/IconAction.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { PAYMENT_STATUS, TONE_CLASS, rupiah } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
// Kolom Aksi hanya untuk yang boleh menandai dana dikembalikan (Admin Toko); server tetap memeriksa izinnya.
const canRefund = auth.can('orders.cancel');
const COLUMNS = computed(() => [
    { key: 'order_number', label: 'Nomor pesanan' },
    { key: 'total', label: 'Total' },
    { key: 'payment_status', label: 'Status' },
    { key: 'paid_at', label: 'Dibayar' },
    { key: 'expires_at', label: 'Batas bayar' },
    ...(canRefund ? [{ key: 'actions', label: 'Aksi' }] : []),
]);
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const PAYMENT_TONE = { paid: 'ok', pending: 'warn', refund_pending: 'warn', refunded: 'info', cancelled: 'bad', expired: 'neutral' };
const DOT = { ok: 'bg-success', warn: 'bg-warning', info: 'bg-info', bad: 'bg-error', neutral: 'bg-secondary' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/payments', { filters: { payment_status: '' } });

const toneOf = (row) => PAYMENT_TONE[row.payment_status] || 'neutral';

// Dana masuk untuk pesanan yang sudah ditutup: dikembalikan manual, lalu Admin Toko menandainya di sini dengan catatan (mis. referensi transfer).
const refundDialog = ref(null);
const refunding = ref(null);
const refundNote = ref('');
const refundError = ref('');
const busy = ref(false);

function openRefund(row) {
    refunding.value = row;
    refundNote.value = '';
    refundError.value = '';
    refundDialog.value.showModal();
}

async function submitRefund() {
    busy.value = true;
    refundError.value = '';
    try {
        await api.post(`/admin/orders/${refunding.value.id}/late-payment-refunded`, { note: refundNote.value }, { toast: 'Pengembalian dana dicatat.' });
        refundDialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        refundError.value = fields.note?.[0] || fields.order?.[0] || message;
    } finally {
        busy.value = false;
    }
}

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
    <PageHeader title="Pembayaran" description="Status berubah dari notifikasi Midtrans yang terverifikasi, bukan dari dashboard. Satu pengecualian: Admin Toko menandai dana yang sudah dikembalikan untuk pesanan yang sudah ditutup." />
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
      <span v-if="row.needs_refund" class="mt-1 block text-label-sm text-error">Dana masuk setelah pesanan ditutup. Perlu dikembalikan.</span>
    </template>
    <template #cell-paid_at="{ row }">
      <span class="whitespace-nowrap" :class="row.paid_at ? '' : 'text-secondary'">{{ row.paid_at ? formatDate(row.paid_at) : 'Belum dibayar' }}</span>
    </template>
    <template #cell-expires_at="{ row }">
      <span class="block whitespace-nowrap" :class="remaining(row) ? '' : 'text-secondary'">{{ row.expires_at ? formatDate(row.expires_at) : '-' }}</span>
      <span v-if="remaining(row)" class="block text-label-sm" :class="remaining(row).late ? 'text-error' : 'text-warning'">{{ remaining(row).text }}</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center gap-1">
        <IconAction v-if="row.needs_refund" icon="wallet" tone="primary" label="Dana sudah dikembalikan" :subject="row.order_number" :disabled="busy" @click="openRefund(row)" />
        <span v-else class="text-secondary">-</span>
      </div>
    </template>
  </DataTable>

  <AppModal ref="refundDialog" variant="update" size="lg" title="Dana sudah dikembalikan" :subtitle="refunding?.order_number">
    <form id="late-refund-form" class="space-y-space-md" @submit.prevent="submitRefund">
      <dl v-if="refunding" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-2xl bg-background/70 p-3 text-body-sm">
        <dt class="text-secondary">Pesanan</dt><dd class="font-mono">{{ refunding.order_number }}</dd>
        <dt class="text-secondary">Total</dt><dd class="tabular-nums">{{ rupiah(refunding.total) }}</dd>
      </dl>
      <p class="text-body-sm">Pesanan ini sudah ditutup, tetapi dana dari pelanggan sudah masuk lewat Midtrans. Tandai hanya bila dana <strong>sudah dikembalikan</strong> ke pelanggan lewat transfer manual. Tindakan ini tercatat atas nama Anda dan tidak bisa dibatalkan dari dashboard.</p>
      <label class="block text-label-md">
        Catatan (wajib)
        <textarea v-model="refundNote" rows="3" required maxlength="500" placeholder="Contoh: transfer ke rekening atas nama pemesan, referensi 123456, 13 Nov 2026" class="mt-1 w-full resize-none rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal text-secondary">{{ refundNote.length }}/500</span>
      </label>
      <p v-if="refundError" role="alert" class="text-body-sm text-error">{{ refundError }}</p>
    </form>
    <template #footer>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="refundDialog.close()">Batal</button>
      <button form="late-refund-form" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Dana sudah dikembalikan' }}</button>
    </template>
  </AppModal>
</template>
