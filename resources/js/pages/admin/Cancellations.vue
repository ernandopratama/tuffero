<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { CANCELLATION_STATUS, PAYMENT_STATUS, rupiah } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();

// Langkah berikutnya per status; pembedaan orang pada dua ACC dijaga server.
const NEXT = {
    submitted: { path: 'validate', label: 'Validasi dan Ajukan', permission: 'orders.cancel_request' },
    validated: { path: 'approve', label: 'ACC Admin Toko', permission: 'orders.cancel' },
    store_approved: { path: 'final-approve', label: 'ACC Pemilik', permission: 'orders.cancel_final' },
};
const REJECT_PERMISSION = { submitted: 'orders.cancel_request', validated: 'orders.cancel', store_approved: 'orders.cancel_final' };

const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);
const loading = ref(true);
const error = ref('');
const actionError = ref('');
const busy = ref(false);

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

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/cancellations', { params: { page: page.value } });
        items.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function act(item, path, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;
    busy.value = true;
    actionError.value = '';
    try {
        await api.post(`/admin/cancellations/${item.id}/${path}`);
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

watch(page, load);
onMounted(load);
</script>

<template>
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pembatalan</h1>
  <p class="mt-space-sm text-secondary">CS memvalidasi, Admin Toko memberi ACC. Pesanan yang sudah dibayar butuh ACC Pemilik. Sistem hanya mengingatkan bila lewat batas, tidak menyetujui apa pun.</p>

  <p v-if="actionError" role="alert" class="mt-space-md rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ actionError }}</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pengajuan...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!items.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada pengajuan pembatalan.</p>
  <template v-else>
    <ul class="mt-space-lg grid gap-space-md">
      <li v-for="item in items" :key="item.id" class="rounded-lg border bg-surface p-space-lg text-body-sm" :class="item.is_overdue ? 'border-error' : 'border-border'">
        <div class="flex flex-wrap items-center justify-between gap-space-sm">
          <h2 class="font-mono text-label-md">{{ item.request_number }}</h2>
          <div class="flex flex-wrap gap-space-xs">
            <span v-if="item.is_overdue" class="rounded-full bg-error-surface px-2 py-0.5 text-label-sm text-on-error-surface">Lewat batas proses</span>
            <span class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm">{{ CANCELLATION_STATUS[item.status] || item.status }}</span>
          </div>
        </div>
        <p class="mt-space-sm">Pesanan <span class="font-mono">{{ item.order_number }}</span>, {{ item.customer_name }}, {{ rupiah(item.total) }}, {{ PAYMENT_STATUS[item.payment_status] || item.payment_status }}</p>
        <p class="mt-1"><span class="text-secondary">Alasan:</span> <span class="break-words">{{ item.reason }}</span></p>
        <p class="mt-1 text-secondary">Kanal: {{ item.channel === 'whatsapp' ? 'WhatsApp' : 'Email' }}. Diajukan {{ formatDate(item.created_at) }}. Batas proses {{ formatDate(item.due_at) }}.</p>
        <p v-if="item.rejection_reason" class="mt-1"><span class="text-secondary">Alasan penolakan:</span> {{ item.rejection_reason }}</p>
        <div class="mt-space-md flex flex-wrap gap-space-sm">
          <button v-if="nextStep(item)" type="button" :disabled="busy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="act(item, nextStep(item).path)">{{ nextStep(item).label }}</button>
          <button v-if="canMarkRefunded(item)" type="button" :disabled="busy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="act(item, 'refunded', 'Tandai dana sudah diterima pelanggan?')">Dana Sudah Dikembalikan</button>
          <button v-if="canReject(item)" type="button" :disabled="busy" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60" @click="openReject(item)">Tolak</button>
        </div>
      </li>
    </ul>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman pembatalan">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} pengajuan)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="rejectDialog" aria-labelledby="reject-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="submitReject">
      <h2 id="reject-title" class="font-heading text-headline-md">Tolak pengajuan</h2>
      <label class="block text-label-md">
        Alasan penolakan
        <textarea v-model="rejectReason" rows="3" required maxlength="500" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
      </label>
      <p v-if="rejectError" role="alert" class="text-body-sm text-error">{{ rejectError }}</p>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="rejectDialog.close()">Batal</button>
        <button :disabled="busy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">Tolak Pengajuan</button>
      </div>
    </form>
  </dialog>
</template>
