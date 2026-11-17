<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AdminIcon from './AdminIcon.vue';
import api from '../utils/api';
import { formatDate } from '../utils/datetime';
import { rupiah } from '../utils/orderLabels';

// Peringatan mendesak tinjauan pesanan (D-132) untuk Admin Toko, Pemilik, dan Super Admin (`orders.review_alert`).
// Selalu tampil selama ada pesanan menunggu persetujuan atau pesanan bertanda yang lewat tenggat; hanya bisa diperkecil, tidak ditutup.
const POLL_MS = 60000;
const router = useRouter();
const queue = ref(null);
const small = ref(false);
let timer;

async function load() {
    try {
        const { data } = await api.get('/admin/orders/review-queue');
        queue.value = data.data;
    } catch { /* jaringan atau sesi bermasalah: pertahankan data terakhir */ }
}

const awaiting = computed(() => queue.value?.awaiting_total || 0);
const overdue = computed(() => queue.value?.flagged_overdue || 0);
const shown = computed(() => awaiting.value > 0 || overdue.value > 0);
const title = computed(() => (awaiting.value ? `${awaiting.value} pesanan menunggu persetujuan` : `${overdue.value} pesanan lewat tenggat tinjau`));
const lead = computed(() => (awaiting.value
    ? 'Pelanggan belum bisa membayar sampai disetujui. Lewat tenggat, pesanan ditutup otomatis.'
    : 'Pesanan Rp1 juta ke atas belum ditinjau melewati 1 hari kerja.'));

function review(order) {
    router.push({ name: 'AdminOrders', query: order ? { cari: order.order_number } : { review: 'pending' } });
}

onMounted(() => {
    load();
    timer = setInterval(() => { if (document.visibilityState === 'visible') load(); }, POLL_MS);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
  <section v-if="shown" class="review-alert" :class="{ 'is-small': small }" role="alert" aria-live="assertive" aria-labelledby="review-alert-title">
    <span class="review-alert-icon" aria-hidden="true"><AdminIcon name="alert" :size="20" /></span>
    <div class="min-w-0">
      <strong id="review-alert-title" class="review-alert-title">{{ title }}</strong>
      <template v-if="!small">
        <p class="review-alert-lead">{{ lead }}</p>
        <ul v-if="queue.awaiting.length" class="review-alert-list">
          <li v-for="order in queue.awaiting.slice(0, 3)" :key="order.id">
            <button type="button" class="review-alert-row" @click="review(order)">
              <span class="font-mono">{{ order.order_number }}</span>
              <span>{{ rupiah(order.total) }}</span>
              <span class="review-alert-due">s.d. {{ formatDate(order.review_due_at) }}</span>
            </button>
          </li>
        </ul>
        <p v-if="awaiting && overdue" class="review-alert-lead">Juga {{ overdue }} pesanan Rp1 juta ke atas lewat tenggat tinjau.</p>
        <button type="button" class="review-alert-cta" @click="review()">Tinjau sekarang</button>
      </template>
    </div>
    <button type="button" class="review-alert-toggle" :aria-label="small ? 'Perbesar peringatan' : 'Perkecil peringatan'" :aria-expanded="!small" @click="small = !small">
      <AdminIcon :name="small ? 'expand' : 'collapse'" :size="16" />
    </button>
  </section>
</template>

<style>
/* Kanan bawah supaya tidak bertumpuk dengan toast biasa (kanan atas); di mobile di atas navigasi bawah. */
.review-alert { position: fixed; right: 16px; bottom: 16px; z-index: 900; display: grid; grid-template-columns: 40px 1fr 28px; align-items: start; gap: 12px; width: min(380px, calc(100vw - 32px)); padding: 14px; border: 1px solid #EBC1B7; border-radius: 12px; background: #FCF1EE; color: #2B1B14; box-shadow: 0 12px 32px -12px #2B1B1440, 0 2px 6px #2B1B1414; transition: opacity 240ms ease, transform 240ms cubic-bezier(0.23, 1, 0.32, 1); }
@starting-style { .review-alert { opacity: 0; transform: translateY(12px) scale(0.97); } }
.review-alert.is-small { align-items: center; }
.review-alert-icon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 999px; background: #A63A2B; color: #fff; }
.review-alert-title { display: block; font-size: 15px; line-height: 22px; font-weight: 700; color: #A63A2B; }
.review-alert-lead { margin: 2px 0 0; font-size: 14px; line-height: 20px; }
.review-alert-list { margin: 8px 0 0; padding: 0; list-style: none; display: grid; gap: 4px; }
.review-alert-row { display: flex; flex-wrap: wrap; gap: 4px 10px; width: 100%; min-height: 40px; align-items: center; padding: 6px 10px; border: 1px solid #EBC1B7; border-radius: 10px; background: #fff; font-size: 13px; text-align: left; cursor: pointer; transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1), background-color 150ms ease; }
.review-alert-row:active, .review-alert-cta:active, .review-alert-toggle:active { transform: scale(0.97); }
.review-alert-due { color: #A63A2B; }
.review-alert-cta { margin-top: 10px; min-height: 40px; padding: 0 16px; border: 0; border-radius: 999px; background: #A63A2B; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1), opacity 150ms ease; }
.review-alert-toggle { position: relative; display: grid; place-items: center; width: 28px; height: 28px; padding: 0; border: 0; border-radius: 999px; background: #A63A2B; color: #fff; cursor: pointer; transition: transform 140ms cubic-bezier(0.23, 1, 0.32, 1); }
.review-alert-toggle::after { content: ''; position: absolute; inset: -8px; }
.review-alert button:focus-visible { outline: 2px solid #2B1B14; outline-offset: 2px; }
@media (hover: hover) and (pointer: fine) { .review-alert-row:hover { background: #FFF8F6; } .review-alert-cta:hover { opacity: 0.9; } }
@media (max-width: 767px) { .review-alert { right: 12px; bottom: calc(88px + env(safe-area-inset-bottom)); width: calc(100vw - 24px); } }
@media (prefers-reduced-motion: reduce) { @starting-style { .review-alert { transform: none; } } }
</style>
