<script setup>
import { onMounted, ref, watch } from 'vue';
import AdminComments from '../../components/AdminComments.vue';
import OrderActions from '../../components/OrderActions.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { CANCELLATION_STATUS, ORDER_STATUS, PAYMENT_STATUS, SHIPPING_STATUS, rupiah } from '../../utils/orderLabels';

const HISTORY_TYPES = { order: 'Pesanan', payment: 'Pembayaran', shipping: 'Pengiriman' };
const HISTORY_LABELS = { order: ORDER_STATUS, payment: PAYMENT_STATUS, shipping: SHIPPING_STATUS };

const orders = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref('');
const status = ref('');
const page = ref(1);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const detail = ref(null);
const detailError = ref('');

const historyLabel = (history) => HISTORY_LABELS[history.status_type]?.[history.to_status] || history.to_status;

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/orders', { params: { page: page.value, search: search.value || undefined, status: status.value || undefined } });
        orders.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function applySearch() {
    if (page.value === 1) load();
    else page.value = 1;
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
    dialog.value.showModal();
    await loadDetail(order.id);
}

async function refresh() {
    await loadDetail(detail.value.id);
    await load();
}

watch(page, load);
watch(status, applySearch);
onMounted(load);
</script>

<template>
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pesanan</h1>
  <p class="mt-space-sm text-secondary">Status pembayaran berubah hanya dari notifikasi Midtrans yang terverifikasi.</p>

  <form class="mt-space-lg flex flex-wrap items-end gap-space-sm" role="search" @submit.prevent="applySearch">
    <label class="text-label-md">
      Status
      <select v-model="status" class="mt-1 block rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <option value="">Semua</option>
        <option v-for="(label, key) in ORDER_STATUS" :key="key" :value="key">{{ label }}</option>
      </select>
    </label>
    <label class="min-w-48 flex-1 text-label-md sm:max-w-sm">
      Cari nomor, nama, email, atau HP
      <input v-model="search" type="search" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
    </label>
    <button class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary">Cari</button>
  </form>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pesanan...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!orders.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada pesanan.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[820px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Nomor</th>
            <th scope="col" class="px-4 py-3">Pemesan</th>
            <th scope="col" class="px-4 py-3">Total</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3">Pembayaran</th>
            <th scope="col" class="px-4 py-3">Dibuat</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id" class="border-b border-border last:border-b-0">
            <td class="whitespace-nowrap px-4 py-3 font-mono">{{ order.order_number }}</td>
            <td class="px-4 py-3">{{ order.customer_name }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ rupiah(order.total) }}</td>
            <td class="px-4 py-3"><span class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm">{{ ORDER_STATUS[order.status] || order.status }}</span></td>
            <td class="px-4 py-3">{{ PAYMENT_STATUS[order.payment_status] || order.payment_status }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(order.created_at) }}</td>
            <td class="px-4 py-3 text-right"><button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="open(order)">Detail</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman pesanan">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} pesanan)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="dialog" aria-labelledby="order-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <div class="flex items-start justify-between gap-space-md">
      <h2 id="order-title" class="font-heading text-headline-md">{{ detail?.order_number || 'Detail pesanan' }}</h2>
      <button type="button" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Tutup</button>
    </div>
    <p v-if="detailError" role="alert" class="mt-space-md text-body-sm text-error">{{ detailError }}</p>
    <p v-else-if="!detail" role="status" class="mt-space-md text-secondary">Memuat detail...</p>
    <div v-else class="mt-space-md space-y-space-md text-body-sm">
      <dl class="grid gap-x-space-md gap-y-1 sm:grid-cols-3">
        <div><dt class="text-secondary">Status</dt><dd class="text-label-md">{{ ORDER_STATUS[detail.status] || detail.status }}</dd></div>
        <div><dt class="text-secondary">Pembayaran</dt><dd class="text-label-md">{{ PAYMENT_STATUS[detail.payment_status] || detail.payment_status }}</dd></div>
        <div><dt class="text-secondary">Pengiriman</dt><dd class="text-label-md">{{ SHIPPING_STATUS[detail.shipping_status] || detail.shipping_status }}</dd></div>
      </dl>

      <OrderActions :order="detail" @updated="refresh" />

      <section class="border-t border-border pt-space-md">
        <h3 class="font-heading text-headline-sm">Pemesan dan alamat</h3>
        <p class="mt-1">{{ detail.customer_name }}, {{ detail.customer_phone }}, <span class="break-all">{{ detail.customer_email }}</span></p>
        <p>{{ detail.shipping_address.street }}, {{ detail.shipping_address.district }}, {{ detail.shipping_address.city }}, {{ detail.shipping_address.province }} {{ detail.shipping_address.postal_code }}</p>
        <p class="mt-1 text-secondary">Kurir: {{ detail.shipping_courier.toUpperCase() }} {{ detail.shipping_service.toUpperCase() }}<template v-if="detail.waybill_id">, resi <span class="font-mono">{{ detail.waybill_id }}</span></template></p>
      </section>

      <section class="border-t border-border pt-space-md">
        <h3 class="font-heading text-headline-sm">Item</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="item in detail.items" :key="item.sku" class="flex justify-between gap-space-md">
            <span>{{ item.quantity }} × {{ item.product_name }} <span class="font-mono text-secondary">{{ item.sku }}</span></span>
            <span class="whitespace-nowrap">{{ rupiah(item.line_total) }}</span>
          </li>
        </ul>
        <dl class="mt-space-sm space-y-1 border-t border-border pt-space-sm">
          <div class="flex justify-between"><dt class="text-secondary">Subtotal</dt><dd>{{ rupiah(detail.subtotal) }}</dd></div>
          <div class="flex justify-between"><dt class="text-secondary">Ongkir</dt><dd>{{ rupiah(detail.shipping_fee) }}</dd></div>
          <div class="flex justify-between text-label-md"><dt>Total</dt><dd>{{ rupiah(detail.total) }}</dd></div>
        </dl>
      </section>

      <section v-if="detail.cancellations.length" class="border-t border-border pt-space-md">
        <h3 class="font-heading text-headline-sm">Pengajuan pembatalan</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="item in detail.cancellations" :key="item.request_number"><span class="font-mono">{{ item.request_number }}</span>: {{ CANCELLATION_STATUS[item.status] || item.status }}</li>
        </ul>
      </section>

      <section class="border-t border-border pt-space-md">
        <h3 class="font-heading text-headline-sm">Riwayat status</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="(history, i) in detail.histories" :key="i">
            <span class="text-secondary">{{ formatDate(history.created_at) }}</span>
            {{ HISTORY_TYPES[history.status_type] || history.status_type }}: {{ historyLabel(history) }}
            <span class="text-secondary">({{ history.actor || 'Sistem' }})</span>
          </li>
        </ul>
      </section>

      <AdminComments :url="`/admin/orders/${detail.id}/comments`" />
    </div>
  </dialog>
</template>
