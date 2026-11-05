<script setup>
import { onMounted, ref, watch } from 'vue';
import OrderActions from '../../components/OrderActions.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { ORDER_STATUS } from '../../utils/orderLabels';

const orders = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/fulfillment', { params: { page: page.value } });
        orders.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

watch(page, load);
onMounted(load);
</script>

<template>
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pengemasan</h1>
  <p class="mt-space-sm text-secondary">Pesanan yang sudah dibayar dan perlu dikemas, urut dari yang terlama.</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pesanan...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!orders.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Tidak ada pesanan yang perlu dikemas.</p>
  <template v-else>
    <ul class="mt-space-lg grid gap-space-md lg:grid-cols-2">
      <li v-for="order in orders" :key="order.id" class="rounded-lg border border-border bg-surface p-space-lg text-body-sm">
        <div class="flex flex-wrap items-center justify-between gap-space-sm">
          <h2 class="font-mono text-label-md">{{ order.order_number }}</h2>
          <span class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm">{{ ORDER_STATUS[order.status] }}</span>
        </div>
        <p class="mt-1 text-secondary">Dibayar {{ formatDate(order.paid_at) }}</p>
        <ul class="mt-space-sm space-y-1 border-t border-border pt-space-sm">
          <li v-for="item in order.items" :key="item.sku" class="text-label-md">{{ item.quantity }} × {{ item.product_name }} <span class="font-mono font-normal text-secondary">{{ item.sku }}</span></li>
        </ul>
        <address class="mt-space-sm border-t border-border pt-space-sm not-italic">
          <span class="block text-label-md">{{ order.shipping_address.recipient }} ({{ order.shipping_address.phone }})</span>
          {{ order.shipping_address.street }}, {{ order.shipping_address.district }}, {{ order.shipping_address.city }}, {{ order.shipping_address.province }} {{ order.shipping_address.postal_code }}
        </address>
        <p class="mt-1 text-secondary">Kurir: {{ order.shipping_courier.toUpperCase() }} {{ order.shipping_service.toUpperCase() }}</p>
        <OrderActions class="mt-space-md" :order="order" @updated="load" />
      </li>
    </ul>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman pengemasan">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} pesanan)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>
</template>
