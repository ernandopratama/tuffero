<script setup>
import { ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { CANCELLATION_STATUS, PAYMENT_STATUS, TONE_CLASS, rupiah } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';

const auth = useAuthStore();

// Langkah berikutnya per status; pembedaan orang pada dua ACC dijaga server.
const NEXT = {
    submitted: { path: 'validate', label: 'Validasi dan Ajukan', permission: 'orders.cancel_request' },
    validated: { path: 'approve', label: 'ACC Admin Toko', permission: 'orders.cancel' },
    store_approved: { path: 'final-approve', label: 'ACC Pemilik', permission: 'orders.cancel_final' },
};
const STATUS_TONE = { submitted: 'warn', validated: 'info', store_approved: 'info', approved: 'ok', rejected: 'bad', refunded: 'neutral' };
const DOT = { ok: 'bg-success', warn: 'bg-warning', info: 'bg-info', bad: 'bg-error', neutral: 'bg-secondary' };
const REJECT_PERMISSION = { submitted: 'orders.cancel_request', validated: 'orders.cancel', store_approved: 'orders.cancel_final' };

const COLUMNS = [
    { key: 'request_number', label: 'Pengajuan' },
    { key: 'order_number', label: 'Pesanan' },
    { key: 'reason', label: 'Alasan' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Diajukan' },
    { key: 'actions', label: 'Aksi' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/cancellations', { filters: { status: '' } });

const actionError = ref('');
const busy = ref(false);

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const toneOf = (item) => STATUS_TONE[item.status] || 'neutral';

// Tahap persetujuan: CS memvalidasi, Admin Toko memberi ACC, Pemilik memberi ACC kedua hanya bila uang sudah masuk, lalu selesai.
// Pesanan yang belum dibayar selesai setelah ACC Admin Toko (aturan sebenarnya dijaga server; ini hanya gambaran).
const PAID = ['paid', 'refund_pending', 'refunded'];
const CURRENT = { submitted: 0, validated: 1, store_approved: 2 };
function stagesOf(item) {
    const steps = [{ label: 'CS' }, { label: 'Toko' }, ...(PAID.includes(item.payment_status) ? [{ label: 'Pemilik' }] : []), { label: 'Selesai' }];
    const current = item.status in CURRENT ? CURRENT[item.status] : steps.length;
    return steps.map((step, i) => ({ ...step, state: i < current ? 'done' : i === current ? 'now' : 'next' }));
}

// Konfirmasi sebelum ACC atau tandai dana kembali: langkah ini tidak bisa dibatalkan dari dashboard.
const confirmDialog = ref(null);
const pending = ref(null);

const rejectDialog = ref(null);
const rejecting = ref(null);
const rejectReason = ref('');
const rejectError = ref('');

const nextStep = (item) => {
    const step = NEXT[item.status];
    return step && auth.can(step.permission) ? step : null;
};
const canReject = (item) => !!REJECT_PERMISSION[item.status] && auth.can(REJECT_PERMISSION[item.status]);
const canMarkRefunded = (item) => item.status === 'approved' && item.payment_status === 'refund_pending' && auth.can('orders.cancel');

function ask(item, path, label, text) {
    pending.value = { item, path, label, text };
    actionError.value = '';
    confirmDialog.value.showModal();
}

async function confirmAct() {
    const { item, path } = pending.value;
    busy.value = true;
    actionError.value = '';
    try {
        await api.post(`/admin/cancellations/${item.id}/${path}`);
        confirmDialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        actionError.value = `${item.request_number}: ${fields.request?.[0] || message}`;
    } finally {
        busy.value = false;
    }
}

function openReject(item) {
    rejecting.value = item;
    rejectReason.value = '';
    rejectError.value = '';
    rejectDialog.value.showModal();
}

async function submitReject() {
    busy.value = true;
    rejectError.value = '';
    try {
        await api.post(`/admin/cancellations/${rejecting.value.id}/reject`, { reason: rejectReason.value });
        rejectDialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        rejectError.value = fields.reason?.[0] || fields.request?.[0] || message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-space-md">
    <PageHeader title="Pembatalan" description="CS memvalidasi, Admin Toko memberi ACC. Pesanan yang sudah dibayar butuh ACC Pemilik. Sistem hanya mengingatkan bila lewat batas, tidak menyetujui apa pun." />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari nomor pengajuan, pesanan, atau nama">
      <FilterSelect v-model="filter.status" label="Status" :options="CANCELLATION_STATUS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pengajuan pembatalan"
    empty-text="Belum ada pengajuan pembatalan."
    min-width="min-w-[1040px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-request_number="{ row }">
      <span class="block whitespace-nowrap font-mono text-label-md">{{ row.request_number }}</span>
      <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm text-secondary">
        <AdminIcon :name="row.channel === 'whatsapp' ? 'chat' : 'mail'" :size="12" />{{ row.channel === 'whatsapp' ? 'WhatsApp' : 'Email' }}
      </span>
    </template>
    <template #cell-order_number="{ row }">
      <span class="block whitespace-nowrap font-mono">{{ row.order_number }}</span>
      <span class="block text-label-md">{{ row.customer_name }}</span>
      <span class="block whitespace-nowrap text-body-sm text-secondary"><span class="tabular-nums">{{ rupiah(row.total) }}</span> · {{ PAYMENT_STATUS[row.payment_status] || row.payment_status }}</span>
    </template>
    <template #cell-reason="{ row }">
      <span class="mx-auto block max-w-xs break-words">{{ row.reason }}</span>
      <span v-if="row.rejection_reason" class="mx-auto mt-2 block max-w-xs break-words rounded-xl bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">
        <span class="block text-label-sm">Alasan penolakan</span>{{ row.rejection_reason }}
      </span>
    </template>
    <template #cell-status="{ row }">
      <div class="flex flex-col items-center gap-1.5">
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[toneOf(row)]">
          <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ CANCELLATION_STATUS[row.status] || row.status }}
        </span>
        <span v-if="row.is_overdue" class="inline-flex items-center gap-1 whitespace-nowrap text-label-sm text-error"><AdminIcon name="alert" :size="14" />Lewat batas proses</span>
        <ol v-if="row.status !== 'rejected'" class="mt-1 flex items-start" aria-hidden="true">
          <li v-for="(stage, i) in stagesOf(row)" :key="stage.label" class="flex items-start">
            <span class="flex w-11 flex-col items-center gap-1">
              <span class="grid size-5 place-items-center rounded-full border text-primary transition-colors duration-200" :class="stage.state === 'done' ? 'border-accent bg-accent' : stage.state === 'now' ? 'border-primary bg-surface ring-2 ring-accent-tint-20' : 'border-border bg-surface text-secondary'">
                <AdminIcon v-if="stage.state === 'done'" name="check" :size="12" />
                <i v-else-if="stage.state === 'now'" class="size-1.5 rounded-full bg-primary"></i>
              </span>
              <span class="text-[11px] leading-none" :class="stage.state === 'next' ? 'text-secondary' : 'text-primary'">{{ stage.label }}</span>
            </span>
            <span v-if="i < stagesOf(row).length - 1" class="mt-[9px] -mx-2.5 h-px w-5" :class="stage.state === 'done' ? 'bg-accent' : 'bg-border'"></span>
          </li>
        </ol>
      </div>
    </template>
    <template #cell-created_at="{ row }">
      <span class="block whitespace-nowrap">{{ formatDate(row.created_at) }}</span>
      <span class="mt-0.5 inline-flex items-center gap-1 whitespace-nowrap text-body-sm" :class="row.is_overdue ? 'text-error' : 'text-secondary'">
        <AdminIcon name="clock" :size="14" />Batas {{ formatDate(row.due_at) }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction v-if="nextStep(row)" icon="check" tone="primary" :label="nextStep(row).label" :subject="row.request_number" :disabled="busy" @click="ask(row, nextStep(row).path, nextStep(row).label, 'Pengajuan lanjut ke tahap berikutnya dan tercatat atas nama Anda.')" />
        <IconAction v-if="canMarkRefunded(row)" icon="wallet" tone="primary" label="Dana sudah dikembalikan" :subject="row.request_number" :disabled="busy" @click="ask(row, 'refunded', 'Dana sudah dikembalikan', 'Tandai hanya bila dana sudah diterima pelanggan.')" />
        <IconAction v-if="canReject(row)" icon="x" tone="danger" label="Tolak pengajuan" :subject="row.request_number" :disabled="busy" @click="openReject(row)" />
        <span v-if="!nextStep(row) && !canMarkRefunded(row) && !canReject(row)" class="text-secondary">-</span>
      </div>
    </template>
  </DataTable>

  <AppModal ref="confirmDialog" variant="update" size="md" :title="pending?.label || 'Konfirmasi'" :subtitle="pending?.item.request_number">
    <template v-if="pending">
      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-2xl bg-background/70 p-3 text-body-sm">
        <dt class="text-secondary">Pesanan</dt><dd class="font-mono">{{ pending.item.order_number }}</dd>
        <dt class="text-secondary">Pemesan</dt><dd>{{ pending.item.customer_name }}</dd>
        <dt class="text-secondary">Total</dt><dd class="tabular-nums">{{ rupiah(pending.item.total) }}</dd>
      </dl>
      <p class="mt-3 text-body-sm">{{ pending.text }}</p>
      <p v-if="actionError" role="alert" class="mt-3 text-body-sm text-error">{{ actionError }}</p>
    </template>
    <template #footer>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="confirmDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="confirmAct"><AdminIcon name="check" :size="16" />{{ busy ? 'Memproses...' : pending?.label }}</button>
    </template>
  </AppModal>

  <AppModal ref="rejectDialog" variant="delete" title="Tolak pengajuan" :subtitle="rejecting?.request_number" size="lg">
    <form id="reject-form" class="space-y-space-md" @submit.prevent="submitReject">
      <p v-if="rejecting" class="rounded-2xl bg-background/70 p-3 text-body-sm"><span class="text-secondary">Alasan pelanggan:</span> {{ rejecting.reason }}</p>
      <label class="block text-label-md">
        Alasan penolakan
        <textarea v-model="rejectReason" rows="3" required maxlength="500" class="mt-1 w-full resize-none rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal text-secondary">{{ rejectReason.length }}/500</span>
      </label>
      <p v-if="rejectError" role="alert" class="text-body-sm text-error">{{ rejectError }}</p>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="rejectDialog.close()">Batal</button>
      <button form="reject-form" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`"><AdminIcon name="x" :size="16" />{{ busy ? 'Menolak...' : 'Tolak pengajuan' }}</button>
    </template>
  </AppModal>
</template>
