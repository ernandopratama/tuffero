<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import OrderLabelSheets from '../../components/OrderLabelSheets.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Label resi untuk satu pesanan (/admin/label/:id) atau banyak sekaligus (/admin/label-massal?ids=1,2,3).
// ?print=1 membuka dialog cetak otomatis setelah semua label tampil. Rincian label ada di OrderLabelSheets.
const BULK_LIMIT = 50;
const route = useRoute();
const labels = ref([]);
const failed = ref([]);
const error = ref('');
const ready = ref(0);
const loaded = ref(false);

const ids = computed(() => (route.params.id
    ? [Number(route.params.id)]
    : [...new Set(String(route.query.ids || '').split(',').map(Number).filter((id) => Number.isInteger(id) && id > 0))].slice(0, BULK_LIMIT)));
const allReady = computed(() => loaded.value && ready.value >= labels.value.length);

const printLabel = () => window.print();
const closeTab = () => window.close();

async function waitForImages() {
    // Beri waktu logo dimuat supaya ikut tercetak.
    await Promise.all([...document.images].map((img) => (img.complete ? null : new Promise((ok) => { img.onload = ok; img.onerror = ok; }))));
}

onMounted(async () => {
    if (!ids.value.length) {
        error.value = 'Tidak ada pesanan yang dipilih.';
        return;
    }
    // Satu permintaan per pesanan; yang gagal (misalnya belum punya resi) dilewati dan dicatat, bukan menggagalkan semuanya.
    const results = await Promise.allSettled(ids.value.map((id) => api.get(`/admin/orders/${id}/label`)));
    results.forEach((result, i) => {
        if (result.status === 'fulfilled') labels.value.push(result.value.data.data);
        else failed.value.push({ id: ids.value[i], message: apiError(result.reason).message });
    });
    if (!labels.value.length) {
        error.value = failed.value[0]?.message || 'Label tidak dapat dimuat.';
        return;
    }
    document.title = labels.value.length === 1 ? `Label ${labels.value[0].order_number}` : `${labels.value.length} label resi`;
    loaded.value = true;
});

// Cetak otomatis hanya setelah semua label selesai dihitung ukurannya.
let printed = false;
async function onReady() {
    ready.value += 1;
    if (route.query.print === '1' && allReady.value && !printed) {
        printed = true;
        await waitForImages();
        printLabel();
    }
}
</script>

<template>
  <div class="label-screen min-h-screen overflow-x-auto bg-background px-4 py-6 text-primary">
    <div class="no-print mx-auto mb-4 flex max-w-[100mm] flex-wrap items-center justify-between gap-2">
      <h1 class="font-heading text-headline-sm">Label resi<span v-if="labels.length > 1" class="text-body-sm text-secondary"> · {{ labels.length }} pesanan</span></h1>
      <div class="flex gap-2">
        <button type="button" class="flex min-h-10 items-center gap-1.5 rounded-full bg-primary px-4 text-label-md text-surface transition-transform duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60" :disabled="!allReady" @click="printLabel">
          <AdminIcon name="printer" :size="16" />Cetak
        </button>
        <button type="button" class="min-h-10 rounded-full border border-border px-4 text-label-md transition-transform duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="closeTab">Tutup</button>
      </div>
    </div>

    <ul v-if="failed.length && labels.length" role="alert" class="no-print mx-auto mb-4 max-w-[100mm] space-y-1 rounded bg-warning-surface px-3 py-2 text-body-sm text-on-warning-surface">
      <li>{{ failed.length }} pesanan dilewati: {{ failed[0].message }}</li>
    </ul>

    <p v-if="error" role="alert" class="mx-auto max-w-[100mm] text-body-sm text-error">{{ error }}</p>
    <p v-else-if="!loaded" role="status" class="mx-auto max-w-[100mm] text-secondary">Memuat label...</p>

    <template v-else>
      <OrderLabelSheets v-for="label in labels" :key="label.order_number" :label="label" @ready="onReady" />
    </template>
  </div>
</template>
