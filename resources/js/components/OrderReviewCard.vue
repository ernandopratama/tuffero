<script setup>
import { computed, ref } from 'vue';
import AdminIcon from './AdminIcon.vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { useAuthStore } from '../stores/auth';
import { formatDate } from '../utils/datetime';
import { rupiah } from '../utils/orderLabels';
import { customerWaLink } from '../utils/whatsapp';

// Tinjauan pesanan (D-132): menunggu persetujuan (Rp3 juta belum dibayar per HP) atau bertanda Rp1 juta ke atas.
const props = defineProps({ order: { type: Object, required: true } });
const emit = defineEmits(['updated']);
const auth = useAuthStore();

const review = computed(() => props.order.review);
const awaiting = computed(() => props.order.status === 'awaiting_approval');
const overdue = computed(() => review.value.pending && new Date(review.value.due_at) <= new Date());
const tone = computed(() => (awaiting.value || overdue.value ? 'border-error/40 bg-error-surface/40' : review.value.pending ? 'border-warning/40 bg-warning-surface/40' : 'border-border bg-background/60'));
const title = computed(() => {
    if (awaiting.value) return 'Menunggu persetujuan Anda';
    if (props.order.status === 'rejected') return 'Ditolak Admin Toko';
    if (review.value.pending) return overdue.value ? 'Lewat tenggat tinjau' : 'Perlu ditinjau';
    return 'Sudah ditinjau';
});
const lead = computed(() => {
    if (awaiting.value) return `Total belum dibayar nomor HP ini mencapai Rp3 juta. Pelanggan belum bisa membayar sampai pesanan disetujui. Bila tidak ditindak sampai ${formatDate(review.value.due_at)}, pesanan ditutup otomatis dan stok dilepas.`;
    if (review.value.pending) return `Total ${rupiah(props.order.total)} (Rp1 juta ke atas). Pesanan tetap berjalan; periksa data pemesan dan alamat sebelum ${formatDate(review.value.due_at)}.`;
    return null;
});

const checkUrl = `${window.location.origin}/cek-pesanan`;
// Pesan WhatsApp diisi otomatis; admin yang menekan kirim di WhatsApp (WhatsApp otomatis menunggu akun Meta, D-124).
const waText = computed(() => {
    const o = props.order;
    if (o.status === 'rejected') return `Halo ${o.customer_name}, kami dari Tuffero. Mohon maaf, pesanan ${o.order_number} tidak dapat kami proses dan sudah kami tutup. Tidak ada dana yang ditagih. Detail: ${checkUrl} (nomor pesanan dan nomor HP Anda).`;
    if (o.status === 'pending_payment' && review.value.reviewed_at) return `Halo ${o.customer_name}, kami dari Tuffero. Pesanan ${o.order_number} sudah disetujui dan bisa dibayar sekarang lewat halaman pesanan: ${checkUrl} (masukkan nomor pesanan dan nomor HP Anda), lalu tekan Bayar Sekarang.`;
    return null;
});
const waLink = computed(() => (waText.value ? customerWaLink(props.order.customer_phone, waText.value) : null));

const mode = ref('');
const note = ref('');
const busy = ref(false);
const error = ref('');

function start(next) {
    mode.value = next;
    note.value = '';
    error.value = '';
}

const ACTIONS = {
    approve: ['approve', 'Pesanan disetujui. Kabari pelanggan lewat WhatsApp.'],
    reject: ['reject', 'Pesanan ditolak. Kabari pelanggan lewat WhatsApp.'],
    reviewed: ['reviewed', 'Pesanan ditandai sudah ditinjau.'],
};

async function submit() {
    if (mode.value === 'reject' && !note.value.trim()) {
        error.value = 'Alasan penolakan wajib diisi.';
        return;
    }
    busy.value = true;
    error.value = '';
    const [path, message] = ACTIONS[mode.value];
    try {
        await api.post(`/admin/orders/${props.order.id}/${path}`, { note: note.value.trim() || null }, { toast: message });
        mode.value = '';
        emit('updated');
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

const BTN = 'inline-flex min-h-10 items-center gap-1.5 rounded-full px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60';
</script>

<template>
  <section class="rounded-2xl border p-4" :class="tone" aria-labelledby="od-review">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 id="od-review" class="flex items-center gap-2 font-heading text-headline-sm"><AdminIcon name="shield" :size="20" />{{ title }}</h3>
      <span v-if="review.pending" class="rounded-full px-2.5 py-1 text-label-sm" :class="overdue || awaiting ? 'bg-error-surface text-on-error-surface' : 'bg-warning-surface text-warning'">Tenggat {{ formatDate(review.due_at) }}</span>
    </div>
    <p v-if="lead" class="mt-1">{{ lead }}</p>
    <p v-if="review.reviewed_at" class="mt-1 text-secondary">{{ review.reviewer || 'Staf' }} · {{ formatDate(review.reviewed_at) }}<template v-if="review.note"> · “{{ review.note }}”</template></p>

    <div v-if="auth.can('orders.review') && review.pending && !mode" class="mt-space-sm flex flex-wrap gap-2">
      <template v-if="awaiting">
        <button type="button" :class="[BTN, 'bg-primary text-surface hover:bg-primary/90']" @click="start('approve')"><AdminIcon name="check" :size="16" />Setujui</button>
        <button type="button" :class="[BTN, 'border border-error text-error hover:bg-error-surface']" @click="start('reject')"><AdminIcon name="x" :size="16" />Tolak</button>
      </template>
      <button v-else type="button" :class="[BTN, 'bg-primary text-surface hover:bg-primary/90']" @click="start('reviewed')"><AdminIcon name="check" :size="16" />Tandai sudah ditinjau</button>
    </div>

    <form v-if="mode" class="mt-space-sm space-y-2" @submit.prevent="submit">
      <label for="od-review-note" class="block text-label-md">{{ mode === 'reject' ? 'Alasan penolakan (wajib, dicatat di riwayat)' : 'Catatan (opsional)' }}</label>
      <textarea id="od-review-note" v-model="note" rows="2" maxlength="500" class="w-full rounded-xl border border-border bg-surface px-3 py-2 focus-visible:outline-2 focus-visible:outline-primary" :aria-invalid="!!error" :aria-describedby="error ? 'od-review-error' : undefined"></textarea>
      <p v-if="error" id="od-review-error" role="alert" class="text-error">{{ error }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="submit" :disabled="busy" :aria-busy="busy" :class="[BTN, mode === 'reject' ? 'bg-error text-surface hover:bg-error/90' : 'bg-primary text-surface hover:bg-primary/90']">{{ busy ? 'Menyimpan...' : { approve: 'Setujui pesanan', reject: 'Tolak pesanan', reviewed: 'Simpan' }[mode] }}</button>
        <button type="button" :disabled="busy" :class="[BTN, 'border border-border hover:bg-accent-tint-15']" @click="mode = ''">Batal</button>
      </div>
    </form>

    <a v-if="waLink" :href="waLink" target="_blank" rel="noopener noreferrer" :class="[BTN, 'mt-space-sm border border-secondary hover:bg-accent-tint-15']"><AdminIcon name="chat" :size="16" />Kabari pelanggan lewat WhatsApp</a>
  </section>
</template>
