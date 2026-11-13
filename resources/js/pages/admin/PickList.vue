<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Daftar ambil: rekap barang dari rak untuk beberapa pesanan sekaligus, untuk dicetak A4. Tanpa harga, pembayaran, atau data pembeli.
const route = useRoute();
const data = ref(null);
const error = ref('');
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const stamp = computed(() => (data.value ? dateFormat.format(new Date(data.value.generated_at)) : ''));

const printPage = () => window.print();
const closeTab = () => window.close();

onMounted(async () => {
    try {
        const { data: body } = await api.get('/admin/fulfillment/pick-list', { params: { ids: String(route.query.ids || '') } });
        data.value = body.data;
        document.title = `Daftar ambil ${dateFormat.format(new Date(body.data.generated_at))}`;
    } catch (e) {
        error.value = apiError(e).message;
    }
});
</script>

<template>
  <div class="pick-screen min-h-screen bg-background px-4 py-6 text-primary">
    <div class="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-2">
      <h1 class="font-heading text-headline-sm">Daftar ambil</h1>
      <div class="flex gap-2">
        <button type="button" :disabled="!data" class="flex min-h-10 items-center gap-1.5 rounded-full bg-primary px-4 text-label-md text-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="printPage">
          <AdminIcon name="printer" :size="16" />Cetak
        </button>
        <button type="button" class="min-h-10 rounded-full border border-border px-4 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="closeTab">Tutup</button>
      </div>
    </div>

    <p v-if="error" role="alert" class="mx-auto max-w-3xl rounded bg-error-surface p-space-md text-on-error-surface">{{ error }}</p>
    <p v-else-if="!data" role="status" class="mx-auto max-w-3xl text-secondary">Memuat daftar ambil...</p>

    <article v-else class="pick-sheet mx-auto max-w-3xl rounded-3xl border border-border bg-surface p-5 shadow-card sm:p-8">
      <header class="border-b border-border pb-4">
        <h2 class="font-heading text-headline-md">Daftar ambil Tuffero</h2>
        <p class="mt-1 text-body-sm text-secondary">Dibuat {{ stamp }} · {{ data.orders.length }} pesanan · {{ data.total_quantity }} barang</p>
        <p v-if="data.skipped" class="mt-2 rounded bg-warning-surface px-3 py-2 text-body-sm text-on-warning-surface">{{ data.skipped }} pesanan dilewati karena statusnya bukan lagi "Dibayar" atau "Diproses".</p>
      </header>

      <section class="mt-5" aria-labelledby="pick-total">
        <h3 id="pick-total" class="text-label-md uppercase tracking-wide text-secondary">Barang yang diambil</h3>
        <ul class="mt-2 divide-y divide-border">
          <li v-for="line in data.lines" :key="line.sku" class="flex items-start gap-3 py-2.5">
            <span class="pick-box mt-1 size-5 shrink-0 rounded border-2 border-primary" aria-hidden="true"></span>
            <span class="min-w-0 flex-1">
              <span class="block text-label-md">{{ line.product_name }}<template v-if="line.variant_name"> · {{ line.variant_name }}</template></span>
              <span class="block font-mono text-body-sm text-secondary">{{ line.sku }}<template v-if="line.orders > 1"> · {{ line.orders }} pesanan</template></span>
            </span>
            <span class="shrink-0 font-heading text-headline-sm tabular-nums">{{ line.quantity }}×</span>
          </li>
        </ul>
      </section>

      <section class="pick-orders mt-6 border-t border-border pt-4" aria-labelledby="pick-orders">
        <h3 id="pick-orders" class="text-label-md uppercase tracking-wide text-secondary">Per pesanan</h3>
        <ul class="mt-2 grid gap-3 sm:grid-cols-2">
          <li v-for="order in data.orders" :key="order.order_number" class="pick-order rounded-2xl border border-border p-3">
            <p class="flex flex-wrap items-center justify-between gap-x-2 text-label-md"><span class="font-mono">{{ order.order_number }}</span><span class="text-body-sm uppercase text-secondary">{{ order.courier }}</span></p>
            <ul class="mt-1.5 space-y-0.5 text-body-sm">
              <li v-for="item in order.items" :key="item.sku">{{ item.quantity }}× {{ item.name }}<template v-if="item.variant"> · {{ item.variant }}</template></li>
            </ul>
          </li>
        </ul>
      </section>
    </article>
  </div>
</template>

<style>
@media print {
    @page { size: A4; margin: 12mm; }
    html, body { background: #fff !important; }
    .no-print { display: none !important; }
    .pick-screen { padding: 0 !important; min-height: 0 !important; background: #fff !important; }
    .pick-sheet { max-width: none !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    .pick-order { break-inside: avoid; }
    .pick-box { border-color: #000 !important; }
}
</style>
