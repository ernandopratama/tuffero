<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { PAYMENT_STATUS, rupiah } from '../../utils/orderLabels';

const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/payments', { params: { page: page.value } });
        items.value = data.data.items;
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
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pembayaran</h1>
  <p class="mt-space-sm text-secondary">Hanya untuk dilihat. Status berubah dari notifikasi Midtrans yang terverifikasi, bukan dari dashboard.</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pembayaran...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!items.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada pembayaran.</p>
  <template v-else>
    <div class="mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[640px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Nomor pesanan</th>
            <th scope="col" class="px-4 py-3">Total</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3">Dibayar</th>
            <th scope="col" class="px-4 py-3">Batas bayar</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" class="border-b border-border last:border-b-0">
            <td class="whitespace-nowrap px-4 py-3 font-mono">{{ item.order_number }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ rupiah(item.total) }}</td>
            <td class="px-4 py-3">{{ PAYMENT_STATUS[item.payment_status] || item.payment_status }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(item.paid_at) }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(item.expires_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman pembayaran">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} pesanan)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>
</template>
