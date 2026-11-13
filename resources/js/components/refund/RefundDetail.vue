<script setup>
import { computed, reactive, ref } from 'vue';
import AdminIcon from '../AdminIcon.vue';
import AdminComments from '../AdminComments.vue';
import CustomerHistory from '../CustomerHistory.vue';
import FollowUps from '../FollowUps.vue';
import ReplyPicker from '../ReplyPicker.vue';
import AppModal from '../ui/AppModal.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, timeAgo } from '../../utils/datetime';
import { REFUND_CHOICE, REFUND_REASON, REFUND_STATUS, REFUND_STATUS_TONE, TONE_CLASS, refundStatusLabel, rupiah } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';

// Rincian satu pengajuan refund: alur 4 langkah, data pengaju, barang, hasil pemeriksaan video, riwayat, dan tindakan menurut izin.
// Sistem tidak memindahkan uang dan tidak menyimpan nomor rekening atau video; semua itu dilakukan manual (D-031).
const emit = defineEmits(['changed']);
const auth = useAuthStore();
const dialog = ref(null);

const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const busy = ref(false);
const actionError = ref('');
const mode = ref(null);

const form = reactive({ choice: 'refund', result: 'received', channel: 'whatsapp', checks: [false, false, false, false], note: '', amount: 0, shipping: false, reason: '', reference: '' });

// Pedoman pemeriksaan video unboxing (BUSINESS_RULES): mulai dari nomor resi, paket tersegel, sampai isi paket.
const CHECKLIST = [
    'Nomor resi terlihat jelas di awal video',
    'Paket masih tersegel saat mulai direkam',
    'Isi paket dan kondisi produk terlihat jelas',
    'Video utuh, tidak terpotong',
];
const STEPS = ['Diajukan', 'Video', 'Keputusan', 'Dana'];
const CURRENT_STEP = { submitted: 1, awaiting_evidence: 1, under_review: 2, approved: 3, refunded: 4 };
const DOT = { ok: 'bg-success', warn: 'bg-warning', info: 'bg-info', bad: 'bg-error', neutral: 'bg-secondary' };
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary';

const canProcess = computed(() => auth.can('refunds.process'));
const canDecide = computed(() => auth.can('refunds.decide'));
const tone = computed(() => REFUND_STATUS_TONE[detail.value?.status] || 'neutral');
const closed = computed(() => ['rejected', 'cancelled'].includes(detail.value?.status));
const open = computed(() => ['submitted', 'awaiting_evidence', 'under_review'].includes(detail.value?.status));
const allChecked = computed(() => form.checks.every(Boolean));
const isReturn = computed(() => detail.value?.reason_type === 'return_unopened');

const steps = computed(() => {
    const current = CURRENT_STEP[detail.value?.status] ?? 0;

    return STEPS.map((label, i) => ({ label, state: i < current ? 'done' : i === current ? 'now' : 'next' }));
});

// Sisa waktu proses sampai dana diterima pelanggan (7 hari sejak pengajuan, D-043). Hanya penanda.
const sla = computed(() => {
    if (!detail.value || !['submitted', 'awaiting_evidence', 'under_review', 'approved'].includes(detail.value.status)) return null;
    const days = Math.ceil((new Date(detail.value.due_at) - Date.now()) / 86400000);

    return days < 0 ? { text: `Lewat batas ${Math.abs(days)} hari`, late: true } : { text: days === 0 ? 'Batas hari ini' : `Sisa ${days} hari`, late: false };
});

// Tautan hubungi pelanggan lewat kanal pilihannya; hanya membuka aplikasi, tidak mengirim apa pun dari sistem.
const contactLink = computed(() => {
    const d = detail.value;
    if (!d) return null;
    if (d.contact_preference === 'email') return { href: `mailto:${d.requester_email}?subject=${encodeURIComponent(`Refund ${d.refund_number}`)}`, label: 'Email pelanggan', icon: 'mail' };
    const digits = String(d.requester_phone || '').replace(/\D/g, '').replace(/^0/, '62');

    return digits ? { href: `https://wa.me/${digits}?text=${encodeURIComponent(`Halo, terkait pengajuan refund ${d.refund_number}.`)}`, label: 'WhatsApp pelanggan', icon: 'chat' } : null;
});

async function show(id) {
    detail.value = null;
    loadError.value = '';
    mode.value = null;
    dialog.value.showModal();
    await load(id);
}

async function load(id = detail.value?.id) {
    loading.value = true;
    try {
        const { data } = await api.get(`/admin/refunds/${id}`);
        detail.value = data.data;
        form.channel = data.data.contact_preference;
        form.amount = data.data.requested_amount;
        form.shipping = false;
        form.choice = data.data.customer_choice;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function choose(next) {
    mode.value = mode.value === next ? null : next;
    actionError.value = '';
}

// Satu pintu untuk semua tindakan: kirim, muat ulang rincian dan daftar, tutup panel. Pesan galat tampil di panelnya.
async function act(path, body, toast) {
    busy.value = true;
    actionError.value = '';
    try {
        await api.post(`/admin/refunds/${detail.value.id}/${path}`, body, { toast });
        mode.value = null;
        form.note = '';
        form.reason = '';
        form.reference = '';
        form.shipping = false;
        form.checks = [false, false, false, false];
        await load();
        emit('changed');
    } catch (e) {
        const { message, fields } = apiError(e);
        actionError.value = Object.values(fields)[0]?.[0] || message;
    } finally {
        busy.value = false;
    }
}

const contacted = () => act('contacted', { note: form.note || null }, 'Pelanggan ditandai sudah dihubungi.');
const evidence = (result) => act('evidence', { result, channel: form.channel, note: form.note }, result === 'received' ? 'Video lengkap, diteruskan ke Pemilik.' : 'Video ditandai kurang lengkap.');
const approve = () => act('approve', { approved_amount: Number(form.amount), include_shipping: form.shipping, note: form.note || null }, 'Refund disetujui.');
const reject = () => act('reject', { reason: form.reason }, 'Refund ditolak.');
const cancel = () => act('cancel', { note: form.note }, 'Pengajuan dibatalkan.');
// Pilihan pelanggan (dicatat CS setelah video lengkap): dana kembali, kirim ulang, atau voucher. Pemilik tetap memutuskan.
const saveChoice = () => act('choice', { choice: form.choice, note: form.note || null }, 'Pilihan pelanggan dicatat.');

// Cara menyelesaikan menurut pilihan pelanggan: kata tombol dan isian referensi menyesuaikan.
const FINISH = {
    refund: { button: 'Dana sudah dikembalikan', field: 'Referensi transfer (wajib)', hint: 'Bukan nomor rekening. Rekening tidak disimpan di sistem.', placeholder: 'Nomor referensi bukti transfer' },
    resend: { button: 'Kirim ulang sudah dikirim', field: 'Nomor resi kirim ulang (wajib)', hint: 'Barang dikirim ulang tanpa transfer dana.', placeholder: 'Nomor resi pengiriman ulang' },
    voucher: { button: 'Voucher sudah diberikan', field: 'Kode voucher (wajib)', hint: 'Voucher dibuat di halaman Voucher; tanpa transfer dana.', placeholder: 'Kode voucher pengganti' },
};
const finish = computed(() => FINISH[detail.value?.customer_choice] || FINISH.refund);

const refunded = () => act('refunded', { reference: form.reference, note: form.note || null }, 'Pengembalian dana dicatat.');

defineExpose({ show });
</script>

<template>
  <AppModal ref="dialog" variant="read" size="2xl" :title="detail?.refund_number || 'Rincian refund'" :subtitle="detail ? `Pesanan ${detail.order_number}` : ''">
    <div v-if="loadError" role="alert" class="rounded-2xl bg-error-surface p-4 text-on-error-surface">{{ loadError }}</div>

    <!-- memuat: kerangka seukuran isi supaya modal tidak melompat -->
    <div v-else-if="!detail" role="status" aria-label="Memuat rincian" class="space-y-3">
      <div class="h-12 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
      <div class="h-40 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
      <div class="h-28 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>

    <div v-else class="space-y-5" :aria-busy="loading">
      <!-- status dan batas proses -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1 text-label-md" :class="TONE_CLASS[tone]">
          <i class="size-1.5 rounded-full" :class="DOT[tone]" aria-hidden="true"></i>{{ refundStatusLabel(detail.status, detail.refund_method) }}
        </span>
        <span class="rounded-full border border-border px-3 py-1 text-label-md">{{ isReturn ? 'Retur produk utuh' : 'Klaim barang bermasalah' }}</span>
        <span v-if="sla" class="text-label-md" :class="sla.late ? 'text-error' : 'text-secondary'">{{ sla.text }}</span>
      </div>

      <!-- alur: diajukan, video, keputusan, dana -->
      <ol v-if="!closed" class="grid grid-cols-4 gap-2" aria-label="Tahapan refund">
        <li v-for="(step, i) in steps" :key="step.label" class="flex flex-col items-center gap-1.5 text-center" :aria-current="step.state === 'now' ? 'step' : undefined">
          <span
            class="flex size-8 items-center justify-center rounded-full border text-label-md transition-colors duration-150 ease-out"
            :class="step.state === 'done' ? 'border-success bg-success text-surface' : step.state === 'now' ? 'border-primary bg-accent text-primary' : 'border-border bg-surface text-secondary'"
          >
            <AdminIcon v-if="step.state === 'done'" name="check" :size="16" />
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="text-label-sm" :class="step.state === 'next' ? 'text-secondary' : ''">{{ step.label }}</span>
        </li>
      </ol>
      <p v-else class="rounded-2xl p-3 text-body-sm" :class="TONE_CLASS[tone]">
        <strong>{{ refundStatusLabel(detail.status, detail.refund_method) }}.</strong> {{ detail.rejection_reason || detail.admin_note }}
      </p>

      <div class="grid gap-5 md:grid-cols-2">
        <!-- pengaju dan masalah -->
        <section class="space-y-2 rounded-2xl border border-border p-4" aria-labelledby="rf-who">
          <h3 id="rf-who" class="text-label-md">Pengaju dan masalah</h3>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-body-sm">
            <dt class="text-secondary">Pemesan</dt><dd>{{ detail.customer_name }}</dd>
            <dt class="text-secondary">Email</dt><dd class="break-all">{{ detail.requester_email }}</dd>
            <dt class="text-secondary">HP</dt><dd>{{ detail.requester_phone || '-' }}</dd>
            <dt class="text-secondary">Kanal pilihan</dt><dd>{{ detail.contact_preference === 'email' ? 'Email' : 'WhatsApp' }}</dd>
            <dt class="text-secondary">Masalah</dt><dd>{{ REFUND_REASON[detail.reason_type] || detail.reason_type }}</dd>
            <dt class="text-secondary">Pilihan pelanggan</dt><dd>{{ REFUND_CHOICE[detail.customer_choice] }}</dd>
            <dt class="text-secondary">Diterima</dt><dd>{{ detail.received_at ? formatDate(detail.received_at) : 'Tidak tercatat' }}</dd>
            <dt class="text-secondary">Diajukan</dt><dd>{{ formatDate(detail.created_at) }}</dd>
            <dt class="text-secondary">S&amp;K</dt><dd>Versi {{ detail.terms_version }}</dd>
          </dl>
          <p class="whitespace-pre-line rounded-xl bg-background/70 p-3 text-body-sm">{{ detail.description }}</p>
          <a v-if="contactLink && (canProcess || canDecide)" :href="contactLink.href" target="_blank" rel="noopener noreferrer" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`">
            <AdminIcon :name="contactLink.icon" :size="16" />{{ contactLink.label }}
          </a>
        </section>

        <!-- barang -->
        <section class="space-y-2 rounded-2xl border border-border p-4" aria-labelledby="rf-items">
          <h3 id="rf-items" class="text-label-md">Barang diajukan</h3>
          <ul class="divide-y divide-border text-body-sm">
            <li v-for="line in detail.items" :key="line.name + line.variant" class="flex items-start justify-between gap-3 py-2">
              <span class="min-w-0"><span class="block">{{ line.name }}</span><span class="block text-secondary">{{ line.variant }} · {{ line.quantity }} × {{ rupiah(line.unit_price) }}</span></span>
              <span class="shrink-0 tabular-nums">{{ rupiah(line.line_amount) }}</span>
            </li>
          </ul>
          <dl class="grid grid-cols-[1fr_auto] gap-y-1 border-t border-border pt-2 text-body-sm">
            <dt class="text-secondary">Diminta</dt><dd class="tabular-nums">{{ rupiah(detail.requested_amount) }}</dd>
            <template v-if="detail.approved_amount !== null"><dt class="text-secondary">Disetujui (barang)</dt><dd class="text-label-md tabular-nums">{{ rupiah(detail.approved_amount) }}</dd></template>
            <template v-if="detail.shipping_amount > 0"><dt class="text-secondary">Ongkir ikut dikembalikan</dt><dd class="text-label-md tabular-nums">{{ rupiah(detail.shipping_amount) }}</dd></template>
            <template v-if="detail.approved_amount !== null"><dt class="font-semibold">Total ditransfer</dt><dd class="text-label-md font-semibold tabular-nums">{{ rupiah(detail.total_refund) }}</dd></template>
          </dl>
          <p class="text-label-sm text-secondary">Nilai = yang dibayar pelanggan untuk barang ini, setelah bagian potongan voucher. Ongkir ({{ rupiah(detail.order_shipping_fee) }}) ikut dikembalikan hanya bila kesalahan ada di Tuffero (salah kirim atau barang kurang), dipilih Pemilik, dan sekali per pesanan. Pemilik bisa menyetujui nilai barang lebih kecil, tidak lebih besar.</p>
        </section>
      </div>

      <!-- video unboxing -->
      <section class="rounded-2xl border border-border p-4" aria-labelledby="rf-evidence">
        <h3 id="rf-evidence" class="flex items-center gap-2 text-label-md"><AdminIcon name="video" :size="16" />Video unboxing</h3>
        <p class="mt-1 text-body-sm">
          <template v-if="detail.evidence_status === 'received'">Lengkap, diterima lewat {{ detail.evidence_channel === 'email' ? 'email' : 'WhatsApp' }} {{ timeAgo(detail.evidence_received_at) }}. {{ detail.evidence_note }}</template>
          <template v-else-if="detail.evidence_status === 'insufficient'">Kurang lengkap ({{ detail.evidence_channel === 'email' ? 'email' : 'WhatsApp' }}): {{ detail.evidence_note }}</template>
          <template v-else>Belum diterima. Pelanggan mengirim ke WhatsApp atau email toko dengan menyebut {{ detail.refund_number }}. Video tidak diunggah ke sistem.</template>
        </p>
      </section>

      <!-- tindakan menurut status dan izin -->
      <section v-if="open || detail.status === 'approved'" class="space-y-3 rounded-2xl bg-background/70 p-4" aria-labelledby="rf-actions">
        <h3 id="rf-actions" class="text-label-md">Tindakan</h3>

        <div class="flex flex-wrap gap-2">
          <button v-if="detail.status === 'submitted' && canProcess" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="choose('contacted')"><AdminIcon name="chat" :size="16" />Sudah hubungi pelanggan</button>
          <button v-if="['submitted', 'awaiting_evidence'].includes(detail.status) && canProcess" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full px-4 text-label-md ${detail.status === 'submitted' ? 'border border-secondary hover:bg-accent-tint-15' : 'bg-accent hover:bg-accent-hover'} ${press}`" @click="choose('evidence')"><AdminIcon name="video" :size="16" />Periksa video</button>
          <button v-if="detail.status === 'under_review' && canDecide" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="choose('approve')"><AdminIcon name="check" :size="16" />Setujui</button>
          <button v-if="detail.status === 'approved' && canProcess" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="choose('refunded')"><AdminIcon name="wallet" :size="16" />{{ finish.button }}</button>
          <button v-if="['under_review', 'approved'].includes(detail.status) && canProcess" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="choose('choice')"><AdminIcon name="chat" :size="16" />Catat pilihan pelanggan</button>
          <button v-if="open && canDecide" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-error px-4 text-label-md text-error hover:bg-error-surface ${press}`" @click="choose('reject')"><AdminIcon name="x" :size="16" />Tolak</button>
          <button v-if="open && canProcess" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="choose('cancel')">Batalkan pengajuan</button>
        </div>
        <p v-if="!(canProcess || canDecide)" class="text-body-sm text-secondary">Akun ini hanya boleh melihat.</p>

        <!-- panel isian per tindakan: satu terbuka sekali waktu -->
        <form v-if="mode" class="space-y-3 rounded-2xl border border-border bg-surface p-4" @submit.prevent>
          <template v-if="mode === 'contacted'">
            <p class="text-body-sm">{{ isReturn ? 'Untuk retur, konfirmasi ke CS wajib sebelum barang dikembalikan (D-097).' : 'Tandai setelah Anda menghubungi pelanggan dan menjelaskan cara mengirim video unboxing.' }}</p>
            <label class="block text-label-md">Catatan (opsional)<input v-model="form.note" maxlength="300" :class="field"></label>
            <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="contacted"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Tandai sudah dihubungi' }}</button>
          </template>

          <template v-else-if="mode === 'evidence'">
            <fieldset>
              <legend class="text-label-md">Pemeriksaan video (centang yang terpenuhi)</legend>
              <label v-for="(item, i) in CHECKLIST" :key="item" class="mt-1 flex min-h-10 cursor-pointer items-center gap-2 text-body-sm"><input v-model="form.checks[i]" type="checkbox" class="size-4 accent-[var(--color-primary)]">{{ item }}</label>
            </fieldset>
            <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
              <label class="block text-label-md">Diterima lewat<select v-model="form.channel" :class="field"><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></label>
              <label class="block text-label-md">Catatan (wajib)<input v-model="form.note" required maxlength="200" placeholder="Contoh: resi terlihat, segel utuh, kue hancur" :class="field"></label>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" :disabled="busy || !allChecked || !form.note.trim()" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="evidence('received')"><AdminIcon name="check" :size="16" />Video lengkap, teruskan ke Pemilik</button>
              <button type="button" :disabled="busy || !form.note.trim()" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="evidence('insufficient')">Video kurang lengkap</button>
            </div>
            <p v-if="!allChecked" class="text-label-sm text-secondary">"Video lengkap" aktif setelah keempat poin dicentang. Bila ada yang kurang, pilih "Video kurang lengkap" dan tulis kekurangannya.</p>
          </template>

          <template v-else-if="mode === 'approve'">
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block text-label-md">Nilai disetujui (Rp)<input v-model="form.amount" type="number" min="1" :max="detail.requested_amount" step="1" required :class="field"><span class="mt-1 block text-label-sm font-normal text-secondary">Paling banyak {{ rupiah(detail.requested_amount) }}</span></label>
              <label class="block text-label-md">Catatan (opsional)<input v-model="form.note" maxlength="300" :class="field"></label>
            </div>
            <label v-if="detail.shipping_eligible" class="flex min-h-10 cursor-pointer items-center gap-2 text-body-sm"><input v-model="form.shipping" type="checkbox" class="size-4 accent-[var(--color-primary)]">Ikut kembalikan ongkir {{ rupiah(detail.order_shipping_fee) }} (kesalahan ada di Tuffero)</label>
            <p v-else class="text-label-sm text-secondary">Ongkir tidak ikut: hanya untuk salah kirim atau barang kurang, dan sekali per pesanan.</p>
            <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="approve"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Setujui refund' }}</button>
          </template>

          <template v-else-if="mode === 'reject'">
            <label class="block text-label-md">Alasan penolakan (wajib)<textarea v-model="form.reason" rows="3" required maxlength="255" :class="field + ' resize-none'"></textarea><span class="mt-1 block text-right text-label-sm font-normal text-secondary">{{ form.reason.length }}/255</span></label>
            <button type="button" :disabled="busy || !form.reason.trim()" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="reject"><AdminIcon name="x" :size="16" />{{ busy ? 'Menolak...' : 'Tolak refund' }}</button>
          </template>

          <template v-else-if="mode === 'cancel'">
            <label class="block text-label-md">Alasan pembatalan (wajib)<input v-model="form.note" required maxlength="300" placeholder="Contoh: pelanggan menarik pengajuan" :class="field"></label>
            <button type="button" :disabled="busy || !form.note.trim()" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="cancel">{{ busy ? 'Menyimpan...' : 'Batalkan pengajuan' }}</button>
          </template>

          <template v-else-if="mode === 'choice'">
            <p class="text-body-sm">Setelah video dinyatakan lengkap, CS dapat menawarkan kirim ulang atau voucher sebagai pengganti. Pelanggan yang memilih; Pemilik tetap memutuskan. Ongkir hanya untuk pengembalian dana.</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block text-label-md">Pilihan pelanggan<select v-model="form.choice" :class="field"><option v-for="(label, value) in REFUND_CHOICE" :key="value" :value="value">{{ label }}</option></select></label>
              <label class="block text-label-md">Catatan (opsional)<input v-model="form.note" maxlength="300" :class="field"></label>
            </div>
            <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="saveChoice"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Catat pilihan' }}</button>
          </template>

          <template v-else-if="mode === 'refunded'">
            <p v-if="detail.customer_choice === 'refund'" class="text-body-sm">Transfer <strong>{{ rupiah(detail.total_refund) }}</strong>{{ detail.shipping_amount > 0 ? ' (termasuk ongkir)' : '' }} secara manual ke rekening atas nama pemesan (minta datanya lewat {{ detail.contact_preference === 'email' ? 'email' : 'WhatsApp' }}). Tandai hanya setelah dana dikirim. Tindakan ini tidak bisa dibatalkan dari dashboard.</p>
            <p v-else class="text-body-sm">Pelanggan memilih <strong>{{ REFUND_CHOICE[detail.customer_choice].toLowerCase() }}</strong> senilai {{ rupiah(detail.approved_amount) }}, tanpa transfer dana. Tandai hanya setelah diberikan. Tindakan ini tidak bisa dibatalkan dari dashboard.</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block text-label-md">{{ finish.field }}<input v-model="form.reference" required maxlength="100" :placeholder="finish.placeholder" :class="field"><span class="mt-1 block text-label-sm font-normal text-secondary">{{ finish.hint }}</span></label>
              <label class="block text-label-md">Catatan (opsional)<input v-model="form.note" maxlength="300" :class="field"></label>
            </div>
            <button type="button" :disabled="busy || !form.reference.trim()" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="refunded"><AdminIcon name="wallet" :size="16" />{{ busy ? 'Menyimpan...' : finish.button }}</button>
          </template>

          <p v-if="actionError" role="alert" class="flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ actionError }}</p>
        </form>
      </section>

      <p v-if="detail.status === 'refunded'" class="flex items-start gap-2 rounded-2xl bg-success-surface p-3 text-body-sm text-success"><AdminIcon name="check" :size="16" class="mt-0.5 shrink-0" />{{ detail.refund_method === 'manual_transfer' ? `Dana ${rupiah(detail.total_refund)} dikembalikan` : detail.refund_method === 'resend' ? `Kirim ulang senilai ${rupiah(detail.approved_amount)} dikirim` : `Voucher senilai ${rupiah(detail.approved_amount)} diberikan` }} {{ formatDate(detail.refunded_at) }}. Referensi: {{ detail.refund_reference }}.</p>

      <ReplyPicker :url="`/admin/refunds/${detail.id}/reply-templates`" />
      <FollowUps :url="`/admin/refunds/${detail.id}/follow-ups`" />
      <CustomerHistory :url="`/admin/refunds/${detail.id}/customer-history`" @navigate="dialog.close()" />

      <!-- catatan internal (termasuk catatan khusus CS) -->
      <AdminComments :url="`/admin/refunds/${detail.id}/comments`" />

      <!-- riwayat -->
      <section aria-labelledby="rf-history">
        <h3 id="rf-history" class="text-label-md">Riwayat</h3>
        <ol class="mt-2 space-y-2 border-l border-border pl-4">
          <li v-for="(entry, i) in detail.history" :key="i" class="relative text-body-sm">
            <i class="absolute -left-[1.4rem] top-1.5 size-2 rounded-full" :class="DOT[REFUND_STATUS_TONE[entry.to] || 'neutral']" aria-hidden="true"></i>
            <span class="text-label-md">{{ REFUND_STATUS[entry.to] || entry.to }}</span>
            <span class="text-secondary"> · {{ entry.by || (entry.source === 'customer' ? 'Pelanggan' : 'Sistem') }} · {{ formatDate(entry.at) }}</span>
            <span v-if="entry.reason" class="block text-secondary">{{ entry.reason }}</span>
          </li>
        </ol>
      </section>
    </div>

    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Tutup</button>
    </template>
  </AppModal>
</template>
