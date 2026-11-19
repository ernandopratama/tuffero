<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminComments from '../../components/AdminComments.vue';
import CustomerHistory from '../../components/CustomerHistory.vue';
import FollowUps from '../../components/FollowUps.vue';
import ReplyPicker from '../../components/ReplyPicker.vue';
import { customerWaLink } from '../../utils/whatsapp';
import AdminIcon from '../../components/AdminIcon.vue';
import OrderActions from '../../components/OrderActions.vue';
import OrderReviewCard from '../../components/OrderReviewCard.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import { formatDate } from '../../utils/datetime';
import { CANCELLATION_STATUS, CARRIER_STATUS, ORDER_STATUS, ORDER_STATUS_TONE, PAYMENT_STATUS, SHIPPING_STATUS, TONE_CLASS, rupiah } from '../../utils/orderLabels';
import PageHeader from '../../components/ui/PageHeader.vue';

// Lencana status di ringkasan detail: warna penuh dan tegas (bukan pastel) supaya status terbaca sekilas.
const SOLID_TONE = { ok: 'bg-success text-surface', info: 'bg-info text-surface', warn: 'bg-warning text-surface', bad: 'bg-error text-surface', neutral: 'bg-secondary text-surface' };
// Satu warna per status: tidak ada dua status berbeda yang sama warnanya. Hanya kata yang sama di dua kolom (Kedaluwarsa, Dibatalkan, Menunggu) berbagi warna.
const BADGE = 'text-surface';
const ORDER_COLOR = {
    awaiting_approval: 'bg-[#A16207]', rejected: 'bg-[#9D174D]', pending_payment: 'bg-[#C2410C]', paid: 'bg-[#2E6B3E]', processing: 'bg-[#1D4ED8]',
    shipped: 'bg-[#6D28D9]', completed: 'bg-[#0F766E]', expired: 'bg-[#6D4C3D]', cancelled: 'bg-[#BA1A1A]', refunded: 'bg-[#475569]',
};
const PAYMENT_COLOR = { pending: 'bg-[#C2410C]', paid: 'bg-[#4D7C0F]', expired: 'bg-[#6D4C3D]', cancelled: 'bg-[#BA1A1A]', refund_pending: 'bg-[#0E7490]', refunded: 'bg-[#86198F]' };
// Warna badge pengiriman per label (status internal dan status paket Biteship), semuanya berbeda.
const SHIPPING_COLOR = {
    'Belum diproses': 'bg-[#64748B]', Dikemas: 'bg-[#047857]', Dikirim: 'bg-[#5B21B6]', Diterima: 'bg-[#4D7C0F]',
    'Menunggu kurir': 'bg-[#0369A1]', 'Kurir ditugaskan': 'bg-[#115E59]', 'Kurir menjemput': 'bg-[#1E3A8A]', 'Paket diambil': 'bg-[#4F46E5]',
    'Dalam pengiriman': 'bg-[#2563EB]', 'Sedang diantar': 'bg-[#9333EA]', Terkirim: 'bg-[#166534]', Tertahan: 'bg-[#854D0E]',
    'Kurir belum ditemukan': 'bg-[#C2410C]', 'Dalam pengembalian': 'bg-[#DB2777]', Dikembalikan: 'bg-[#86198F]', 'Ditolak kurir': 'bg-[#4A044E]',
    'Dibatalkan kurir': 'bg-[#991B1B]', Dimusnahkan: 'bg-[#1F2937]',
};
const orderBadge = (status) => `${BADGE} ${ORDER_COLOR[status] || 'bg-secondary'}`;
const paymentBadge = (status) => `${BADGE} ${PAYMENT_COLOR[status] || 'bg-secondary'}`;
const HISTORY_TYPES = { order: 'Pesanan', payment: 'Pembayaran', shipping: 'Pengiriman' };
const HISTORY_LABELS = { order: ORDER_STATUS, payment: PAYMENT_STATUS, shipping: SHIPPING_STATUS };

const COLUMNS = [
    { key: 'order_number', label: 'Nomor' },
    { key: 'customer_name', label: 'Pemesan' },
    { key: 'total', label: 'Total' },
    { key: 'status', label: 'Status' },
    { key: 'payment_status', label: 'Pembayaran' },
    { key: 'created_at', label: 'Dibuat' },
    { key: 'actions', label: 'Aksi' },
];

// ?review=pending (dari peringatan tinjau) membuka daftar pesanan yang belum ditinjau (D-132).
const route = useRoute();
const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/orders', { filters: { status: '', payment_status: '', review: route.query.review === 'pending' ? 'pending' : '' } });
watch(() => route.query.review, (value) => { filter.review = value === 'pending' ? 'pending' : ''; });
const REVIEW_FILTER = { pending: 'Belum ditinjau' };
const reviewOverdue = (row) => !!row.review?.pending && new Date(row.review.due_at) <= new Date();

// Cetak Resi: resi sudah ada dan paket belum sampai (Diproses/Dikirim). Selesai/dibatalkan tidak perlu label baru.
const PRINTABLE = ['processing', 'shipped'];
const auth = useAuthStore();
const router = useRouter();
const canPrint = (row) => auth.can('shipments.manage') && !!row.waybill_id && PRINTABLE.includes(row.status);
const printLabel = (row) => window.open(`${router.resolve({ name: 'AdminOrderLabel', params: { id: row.id } }).href}?print=1`, '_blank', 'noopener');

const dialog = ref(null);
const detail = ref(null);
const detailError = ref('');

const historyLabel = (history) => HISTORY_LABELS[history.status_type]?.[history.to_status] || history.to_status;
const HISTORY_TONE = { order: 'bg-info-surface text-info', payment: 'bg-success-surface text-success', shipping: 'bg-warning-surface text-warning' };
const SOURCE_NAME = { biteship_webhook: 'Biteship', midtrans: 'Midtrans' };
const historyActor = (history) => history.actor || SOURCE_NAME[history.source] || 'Sistem';

const PAYMENT_TONE = { pending: 'warn', paid: 'ok', expired: 'neutral', cancelled: 'bad', refund_pending: 'warn', refunded: 'neutral' };
const orderTone = (status) => ORDER_STATUS_TONE[status] || 'neutral';
const DOT_CLASS = { ok: 'bg-success', info: 'bg-info', warn: 'bg-warning', bad: 'bg-error', neutral: 'bg-secondary' };
const carrierOf = (status) => CARRIER_STATUS[status] || null;

// Ringkasan pengiriman: status paket dari Biteship bila ada, kalau tidak status internal.
const shippingBadge = computed(() => {
    const carrier = carrierOf(detail.value?.shipment?.status);
    if (detail.value?.shipment?.provider === 'biteship' && carrier) return carrier;
    return { label: SHIPPING_STATUS[detail.value?.shipping_status] || detail.value?.shipping_status, tone: detail.value?.shipping_status === 'delivered' ? 'ok' : 'neutral' };
});
const shipment = computed(() => detail.value?.shipment || null);
const courierName = computed(() => `${detail.value.shipping_courier.toUpperCase()} ${detail.value.shipping_service.toUpperCase()}`);
const timeline = computed(() => [...(detail.value?.histories || [])].reverse());

const copied = ref(false);
let copyTimer;
async function copyWaybill() {
    try {
        await navigator.clipboard.writeText(detail.value.waybill_id);
        copied.value = true;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(() => { copied.value = false; }, 1600);
    } catch { /* clipboard ditolak browser: nomor tetap bisa disalin manual */ }
}

// Tab Catatan internal dan Riwayat status. Lencana menghitung yang baru sejak tab itu terakhir dibuka; kunjungan pertama jadi titik awal.
const tab = ref('notes');
const TABS = [{ key: 'notes', label: 'Catatan internal' }, { key: 'history', label: 'Riwayat status' }];
const seen = ref({ notes: undefined, history: undefined });
const counts = ref({ notes: undefined, history: undefined });
const seenKey = () => `tuffero:order-seen:${detail.value?.id}`;

function loadSeen() {
    try { seen.value = { notes: undefined, history: undefined, ...JSON.parse(localStorage.getItem(seenKey()) || '{}') }; } catch { seen.value = { notes: undefined, history: undefined }; }
}
function markSeen(key) {
    if (counts.value[key] === undefined) return;
    seen.value = { ...seen.value, [key]: counts.value[key] };
    try { localStorage.setItem(seenKey(), JSON.stringify(seen.value)); } catch { /* penyimpanan penuh atau diblokir: lencana hanya tidak diingat */ }
}
function setCount(key, total) {
    counts.value = { ...counts.value, [key]: total };
    if (seen.value[key] === undefined || tab.value === key) markSeen(key);
}
const unseen = (key) => (counts.value[key] === undefined || seen.value[key] === undefined ? 0 : Math.max(0, counts.value[key] - seen.value[key]));

function selectTab(key) {
    tab.value = key;
    markSeen(key);
}
function tabKeys(event) {
    const dir = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!dir) return;
    event.preventDefault();
    const next = TABS[(TABS.findIndex((t) => t.key === tab.value) + dir + TABS.length) % TABS.length].key;
    selectTab(next);
    nextTick(() => document.getElementById(`order-tab-${next}`)?.focus());
}

async function loadDetail(id) {
    detailError.value = '';
    try {
        const { data } = await api.get(`/admin/orders/${id}`);
        detail.value = data.data;
    } catch (e) {
        detailError.value = apiError(e).message;
    }
}

async function open(order) {
    detail.value = null;
    counts.value = { notes: undefined, history: undefined };
    tab.value = 'notes';
    dialog.value.showModal();
    await loadDetail(order.id);
    loadSeen();
    setCount('history', detail.value?.histories?.length ?? 0);
}

async function refresh() {
    await loadDetail(detail.value.id);
    setCount('history', detail.value?.histories?.length ?? 0);
    await load();
}

</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Pesanan" description="Status pembayaran berubah hanya dari notifikasi Midtrans yang terverifikasi." />
    <TableToolbar v-model="search" placeholder="Cari nomor, nama, email, atau HP">
      <FilterSelect v-model="filter.status" label="Status" :options="ORDER_STATUS" />
      <FilterSelect v-model="filter.payment_status" label="Pembayaran" :options="PAYMENT_STATUS" />
      <FilterSelect v-if="auth.can('orders.review_alert')" v-model="filter.review" label="Tinjauan" :options="REVIEW_FILTER" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pesanan"
    empty-text="Belum ada pesanan."
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-order_number="{ row }"><span class="whitespace-nowrap font-mono">{{ row.order_number }}</span></template>
    <template #cell-customer_name="{ row }">
      <span class="block">{{ row.customer_name }}</span>
      <span v-if="row.repeat_order" class="mt-1 inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-success-surface px-2 py-0.5 text-label-sm text-success" :title="`${row.completed_orders} pesanan selesai atas nomor WhatsApp ini`"><AdminIcon name="sparkles" :size="12" />Repeat order</span>
    </template>
    <template #cell-total="{ row }">
      <span class="whitespace-nowrap">{{ rupiah(row.total) }}</span>
      <!-- Penanda Rp1 juta ke atas yang belum ditinjau (D-132); merah dan teks berbeda bila lewat tenggat. -->
      <span v-if="row.review?.pending" class="mt-1 flex items-center justify-center gap-1 whitespace-nowrap text-label-sm" :class="reviewOverdue(row) ? 'text-error' : 'text-warning'" :title="`Tenggat tinjau ${formatDate(row.review.due_at)}`">
        <AdminIcon name="alert" :size="14" />{{ reviewOverdue(row) ? 'Lewat tenggat' : 'Perlu ditinjau' }}
      </span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex rounded-full px-3 py-1 text-label-sm" :class="orderBadge(row.status)">{{ ORDER_STATUS[row.status] || row.status }}</span>
      <span v-if="carrierOf(row.carrier_status)" class="mt-1 flex items-center justify-center gap-1.5 text-label-sm text-secondary" title="Status paket dari Biteship">
        <i class="size-1.5 rounded-full" :class="DOT_CLASS[carrierOf(row.carrier_status).tone]" aria-hidden="true"></i>{{ carrierOf(row.carrier_status).label }}
      </span>
    </template>
    <template #cell-payment_status="{ row }"><span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-label-sm" :class="paymentBadge(row.payment_status)">{{ PAYMENT_STATUS[row.payment_status] || row.payment_status }}</span></template>
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDate(row.created_at) }}</span></template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1"><IconAction v-if="canPrint(row)" icon="printer" label="Cetak resi" :subject="row.order_number" @click="printLabel(row)" /><IconAction icon="eye" label="Lihat detail" :subject="row.order_number" @click="open(row)" /></div>
    </template>
  </DataTable>

  <AppModal ref="dialog" variant="read" size="4xl" :title="detail?.order_number || 'Detail pesanan'" subtitle="Detail pesanan">
    <p v-if="detailError" role="alert" class="text-body-sm text-error">{{ detailError }}</p>
    <p v-else-if="!detail" role="status" class="text-secondary">Memuat detail...</p>
    <div v-else class="space-y-space-md text-body-sm">
      <!-- Ringkasan: total besar di kiri, tiga status di kanan -->
      <div class="grid gap-space-md rounded-3xl border border-border bg-gradient-to-br from-accent-tint-12 to-background/60 p-4 sm:p-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center">
        <div class="min-w-0">
          <p class="text-label-sm uppercase tracking-wide text-secondary">Total pesanan</p>
          <p class="mt-1 truncate font-heading text-headline-md tabular-nums">{{ rupiah(detail.total) }}</p>
          <p class="mt-1 text-secondary">{{ formatDate(detail.created_at) }} · {{ detail.items.length }} jenis item</p>
        </div>
        <dl class="grid gap-3 sm:grid-cols-3">
          <div class="max-sm:flex max-sm:items-center max-sm:justify-between max-sm:gap-3 rounded-2xl border border-primary bg-info-surface p-3 shadow-[4px_4px_0_0_var(--color-primary)] transition-[transform,box-shadow] duration-150 ease-out-strong [@media(hover:hover)]:hover:-translate-x-px [@media(hover:hover)]:hover:-translate-y-px [@media(hover:hover)]:hover:shadow-[6px_6px_0_0_var(--color-primary)]">
            <dt class="text-label-sm uppercase tracking-wide text-info">Pesanan</dt>
            <dd class="mt-1.5 max-sm:mt-0"><span class="inline-flex rounded-full px-3 py-1 text-label-md" :class="orderBadge(detail.status)">{{ ORDER_STATUS[detail.status] || detail.status }}</span></dd>
          </div>
          <div class="max-sm:flex max-sm:items-center max-sm:justify-between max-sm:gap-3 rounded-2xl border border-primary bg-accent-tint-20 p-3 shadow-[4px_4px_0_0_var(--color-primary)] transition-[transform,box-shadow] duration-150 ease-out-strong [@media(hover:hover)]:hover:-translate-x-px [@media(hover:hover)]:hover:-translate-y-px [@media(hover:hover)]:hover:shadow-[6px_6px_0_0_var(--color-primary)]">
            <dt class="text-label-sm uppercase tracking-wide text-primary">Pembayaran</dt>
            <dd class="mt-1.5 max-sm:mt-0"><span class="inline-flex rounded-full px-3 py-1 text-label-md" :class="paymentBadge(detail.payment_status)">{{ PAYMENT_STATUS[detail.payment_status] || detail.payment_status }}</span></dd>
          </div>
          <div class="max-sm:flex max-sm:items-center max-sm:justify-between max-sm:gap-3 rounded-2xl border border-primary bg-success-surface p-3 shadow-[4px_4px_0_0_var(--color-primary)] transition-[transform,box-shadow] duration-150 ease-out-strong [@media(hover:hover)]:hover:-translate-x-px [@media(hover:hover)]:hover:-translate-y-px [@media(hover:hover)]:hover:shadow-[6px_6px_0_0_var(--color-primary)]">
            <dt class="text-label-sm uppercase tracking-wide text-success">Pengiriman<template v-if="shipment?.provider === 'biteship'"> · Biteship</template></dt>
            <dd class="mt-1.5 max-sm:mt-0"><span class="inline-flex rounded-full px-3 py-1 text-label-md" :class="`${BADGE} ${SHIPPING_COLOR[shippingBadge.label] || 'bg-secondary'}`">{{ shippingBadge.label }}</span></dd>
          </div>
        </dl>
      </div>

      <!-- Dua kolom di layar lebar: pekerjaan (pengiriman, item) di kiri, konteks pelanggan dan riwayat di kanan -->
      <div class="grid items-start gap-space-md lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-space-md animate-rise">
      <OrderReviewCard v-if="detail.review" :order="detail" @updated="refresh" />
      <!-- Kartu pengiriman: kurir, resi, waktu, lalu aksi -->
      <section class="rounded-2xl border border-border p-4" aria-labelledby="od-ship">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 id="od-ship" class="flex items-center gap-2 font-heading text-headline-sm"><AdminIcon name="truck" :size="20" />Pengiriman</h3>
          <span class="rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ courierName }}</span>
        </div>
        <div class="mt-space-sm flex flex-wrap items-center gap-2 rounded-xl bg-background/60 px-3 py-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-label-sm uppercase tracking-wide text-secondary">Nomor resi</p>
            <p v-if="detail.waybill_id" class="truncate font-mono text-body-md">{{ detail.waybill_id }}</p>
            <p v-else class="text-secondary">Belum ada. Muncul setelah pesanan diproses.</p>
          </div>
          <button v-if="detail.waybill_id" type="button" class="flex min-h-10 items-center gap-1.5 rounded-full border border-border px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="copyWaybill">
            <AdminIcon :name="copied ? 'check' : 'copy'" :size="16" />{{ copied ? 'Tersalin' : 'Salin' }}
          </button>
        </div>
        <dl v-if="shipment?.shipped_at || shipment?.delivered_at" class="mt-space-sm grid gap-2 sm:grid-cols-2">
          <div v-if="shipment.shipped_at"><dt class="text-secondary">Dikirim</dt><dd class="text-label-md">{{ formatDate(shipment.shipped_at) }}</dd></div>
          <div v-if="shipment.delivered_at"><dt class="text-secondary">Diterima</dt><dd class="text-label-md">{{ formatDate(shipment.delivered_at) }}</dd></div>
        </dl>
        <div class="mt-space-sm"><OrderActions :order="detail" :show-waybill="false" @updated="refresh" /></div>
      </section>
      <section class="rounded-2xl border border-border p-4">
        <h3 class="font-heading text-headline-sm">Item</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="item in detail.items" :key="item.sku" class="flex justify-between gap-space-md">
            <span>{{ item.quantity }} × {{ item.product_name }} <span class="font-mono text-secondary">{{ item.sku }}</span></span>
            <span class="whitespace-nowrap">{{ rupiah(item.line_total) }}</span>
          </li>
        </ul>
        <dl class="mt-space-sm space-y-1 border-t border-border pt-space-sm">
          <div class="flex justify-between"><dt class="text-secondary">Subtotal</dt><dd>{{ rupiah(detail.subtotal) }}</dd></div>
          <div v-if="detail.discount_total" class="flex justify-between"><dt class="text-secondary">Diskon</dt><dd>-{{ rupiah(detail.discount_total) }}</dd></div>
          <div class="flex justify-between"><dt class="text-secondary">Ongkir</dt><dd>{{ rupiah(detail.shipping_fee) }}</dd></div>
          <div v-if="detail.shipping_discount" class="flex justify-between text-success"><dt>Diskon ongkir <span class="font-mono">{{ detail.shipping_voucher_code }}</span></dt><dd>-{{ rupiah(detail.shipping_discount) }}</dd></div>
          <div class="flex justify-between text-label-md"><dt>Total</dt><dd>{{ rupiah(detail.total) }}</dd></div>
        </dl>
      </section>
      <CustomerHistory :url="`/admin/orders/${detail.id}/customer-history`" @navigate="dialog.close()" />
      <section v-if="detail.cancellations.length" class="rounded-2xl border border-border p-4">
        <h3 class="font-heading text-headline-sm">Pengajuan pembatalan</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="item in detail.cancellations" :key="item.request_number"><span class="font-mono">{{ item.request_number }}</span>: {{ CANCELLATION_STATUS[item.status] || item.status }}</li>
        </ul>
      </section>
        </div>

        <div class="min-w-0 space-y-space-md animate-rise" style="animation-delay: 60ms">
          <section class="grid gap-space-md rounded-2xl border border-border p-4">
            <div class="flex items-start gap-3">
              <span class="grid size-11 shrink-0 place-items-center rounded-full bg-accent-tint-20 text-label-md text-primary" aria-hidden="true">{{ detail.customer_name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span>
              <div class="min-w-0">
                <h3 class="font-heading text-headline-sm">Pemesan</h3>
                <p class="text-label-md">{{ detail.customer_name }}</p>
                <span v-if="detail.repeat_order" class="mt-1 inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-success-surface px-2 py-0.5 text-label-sm text-success" :title="`${detail.completed_orders} pesanan selesai atas nomor WhatsApp ini`"><AdminIcon name="sparkles" :size="12" />Repeat order</span>
                <p>{{ detail.customer_phone }}</p>
                <p class="break-all text-secondary">{{ detail.customer_email }}</p>
              </div>
            </div>
            <a
              v-if="auth.can('templates.manage') && customerWaLink(detail.customer_phone, `Halo ${detail.customer_name}, kami dari Tuffero terkait pesanan ${detail.order_number}.`)"
              :href="customerWaLink(detail.customer_phone, `Halo ${detail.customer_name}, kami dari Tuffero terkait pesanan ${detail.order_number}.`)"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-full border border-secondary px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
            ><AdminIcon name="chat" :size="16" />Hubungi pelanggan</a>
            <div class="border-t border-border pt-space-md">
              <h3 class="flex items-center gap-2 font-heading text-headline-sm"><AdminIcon name="pin" :size="18" />Alamat tujuan</h3>
              <p class="mt-1">{{ detail.shipping_address.street }}, {{ detail.shipping_address.district }}, {{ detail.shipping_address.city }}, {{ detail.shipping_address.province }} {{ detail.shipping_address.postal_code }}</p>
              <a v-if="detail.shipping_address.latitude != null" :href="`https://www.google.com/maps?q=${Number(detail.shipping_address.latitude)},${Number(detail.shipping_address.longitude)}`" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex min-h-10 items-center gap-1 text-label-md underline focus-visible:outline-2 focus-visible:outline-primary">Lihat titik rumah di peta</a>
            </div>
          </section>
      <ReplyPicker :url="`/admin/orders/${detail.id}/reply-templates`" />
      <FollowUps :url="`/admin/orders/${detail.id}/follow-ups`" />
        </div>
      </div>

      <!-- Dua tab: catatan staf dan riwayat. Panel memakai hidden supaya jumlah catatan tetap termuat untuk lencana -->
      <section aria-label="Catatan dan riwayat">
        <div class="relative grid grid-cols-2 rounded-full bg-secondary-tint-12 p-1" role="tablist" aria-label="Catatan dan riwayat" @keydown="tabKeys">
          <span class="pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-surface shadow-sm transition-transform duration-200 ease-out-strong motion-reduce:transition-none" :style="{ transform: tab === 'history' ? 'translateX(100%)' : 'translateX(0)' }" aria-hidden="true"></span>
          <button
            v-for="t in TABS"
            :id="`order-tab-${t.key}`"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="tab === t.key"
            :aria-controls="`order-panel-${t.key}`"
            :tabindex="tab === t.key ? 0 : -1"
            class="relative flex min-h-10 items-center justify-center gap-2 rounded-full px-3 text-label-md transition-colors duration-[160ms] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            :class="tab === t.key ? 'text-primary' : 'text-secondary hover:text-primary'"
            @click="selectTab(t.key)"
          >
            {{ t.label }}
            <span v-if="unseen(t.key) && tab !== t.key" class="flex min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-label-sm text-primary motion-safe:animate-pop" :aria-label="`${unseen(t.key)} baru`">{{ unseen(t.key) }}</span>
          </button>
        </div>

        <div id="order-panel-notes" role="tabpanel" aria-labelledby="order-tab-notes" class="mt-space-sm" :hidden="tab !== 'notes'">
          <AdminComments :url="`/admin/orders/${detail.id}/comments`" @count="setCount('notes', $event)" />
        </div>

        <div id="order-panel-history" role="tabpanel" aria-labelledby="order-tab-history" class="mt-space-sm" :hidden="tab !== 'history'">
          <ol v-if="timeline.length" class="relative space-y-3 border-l border-border pl-5">
            <li v-for="(history, i) in timeline" :key="timeline.length - i" class="relative">
              <span class="absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface" :class="i === 0 ? 'bg-accent' : 'bg-border'" aria-hidden="true"></span>
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="rounded-full px-2 py-0.5 text-label-sm" :class="HISTORY_TONE[history.status_type] || TONE_CLASS.neutral">{{ HISTORY_TYPES[history.status_type] || history.status_type }}</span>
                <span class="text-label-md">{{ historyLabel(history) }}</span>
              </div>
              <p class="text-secondary">{{ formatDate(history.created_at) }} · {{ historyActor(history) }}</p>
              <p v-if="history.reason" class="mt-0.5">{{ history.reason }}</p>
            </li>
          </ol>
          <p v-else class="text-secondary">Belum ada riwayat.</p>
        </div>
      </section>
    </div>
  </AppModal>
</template>
