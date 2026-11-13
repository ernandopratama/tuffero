<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminIcon from '../components/AdminIcon.vue';
import AttentionModal from '../components/dashboard/AttentionModal.vue';
import BarChart from '../components/dashboard/BarChart.vue';
import GaugeCard from '../components/dashboard/GaugeCard.vue';
import KpiCard from '../components/dashboard/KpiCard.vue';
import ListCard from '../components/dashboard/ListCard.vue';
import { useAuthStore } from '../stores/auth';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { ROLE_LABELS } from '../utils/adminMenu';

const auth = useAuthStore();

// Dua tombol cepat per role. Tombol hanya tampil bila akun memang boleh membuka halamannya.
const SHORTCUTS = {
    'super-admin': [['Kelola pengguna', 'user', 'AdminUsers', 'users.manage'], ['Lihat audit log', 'shield', 'AdminAudit', 'audit.view']],
    owner: [['Tinjau ACC pembatalan', 'check', 'AdminCancellations', 'orders.cancel_final'], ['Putuskan refund', 'undo', 'AdminRefunds', 'refunds.decide'], ['Lihat pesanan', 'receipt', 'AdminOrders', 'orders.view']],
    'store-admin': [['Proses pesanan', 'receipt', 'AdminOrders', 'orders.update_status'], ['ACC pembatalan', 'x', 'AdminCancellations', 'orders.cancel']],
    'customer-service': [['Validasi pengajuan', 'check', 'AdminCancellations', 'orders.cancel_request'], ['Proses refund', 'undo', 'AdminRefunds', 'refunds.process']],
    warehouse: [['Mulai pengemasan', 'box', 'AdminFulfillment', 'orders.view_fulfillment'], ['Sesuaikan stok', 'warehouse', 'AdminStock', 'stock.adjust']],
    marketing: [['Kelola voucher', 'ticket', 'AdminVouchers', 'vouchers.manage'], ['Kelola konten', 'sparkles', 'AdminContents', 'content.manage']],
};

const data = ref(null);
const loading = ref(true);
const error = ref('');

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 11 ? 'pagi' : hour < 15 ? 'siang' : hour < 19 ? 'sore' : 'malam';
    return `Selamat ${part}, ${auth.user?.name}`;
});
const today = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date());
const roleLabel = computed(() => ROLE_LABELS[auth.activeRole]);
const shortcuts = computed(() => (SHORTCUTS[auth.activeRole] || []).filter(([, , , permission]) => auth.can(permission)));

async function load(quiet = false) {
    // Muat ulang diam-diam (tanpa kerangka) dipakai setelah cek integrasi supaya halaman tidak berkedip.
    if (!quiet) loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/dashboard');
        data.value = body.data;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

// Empat kartu (role biasa) muat lima kolom hanya di layar lebar; di bawahnya sapaan satu baris penuh dan kartu 2 kolom.
const crowded = computed(() => (data.value?.kpis.length ?? 0) > 3);
const kpiGrid = computed(() => (crowded.value ? 'sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))]' : 'sm:grid-cols-3 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]'));
const heroSpan = computed(() => (crowded.value ? 'sm:col-span-2 xl:col-span-1' : 'sm:col-span-3 lg:col-span-1'));

const attentionModal = ref(null);
const attentionTotal = computed(() => data.value?.kpis.find((kpi) => kpi.key === 'attention_total')?.value ?? 0);
const openKpi = (action) => {
    if (action === 'attention') attentionModal.value?.open();
};

const checking = ref(false);
const checkError = ref('');

// Menguji kunci Midtrans dan Biteship langsung ke penyedia (dibatasi 6 kali per menit di server), lalu memuat ulang ringkasan.
async function checkIntegrations() {
    checking.value = true;
    checkError.value = '';
    try {
        await api.post('/admin/integrations/check');
        await load(true);
    } catch (e) {
        checkError.value = apiError(e).message;
    } finally {
        checking.value = false;
    }
}

onMounted(() => load());
</script>

<template>
  <div :aria-busy="loading">
    <h1 class="sr-only">Ringkasan {{ roleLabel }}</h1>

    <!-- memuat: kerangka dengan ukuran sama supaya halaman tidak melompat -->
    <div v-if="loading" class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]" role="status" aria-label="Memuat ringkasan">
      <div class="h-56 animate-pulse-soft rounded-card bg-secondary-tint-12 sm:col-span-3 lg:col-span-1 lg:h-[14.5rem]"></div>
      <div v-for="n in 3" :key="n" class="h-32 animate-pulse-soft rounded-card bg-secondary-tint-12 sm:h-44 lg:h-[14.5rem]"></div>
      <div class="h-96 animate-pulse-soft rounded-card bg-secondary-tint-12 sm:col-span-3 lg:col-span-3"></div>
      <div class="h-96 animate-pulse-soft rounded-card bg-secondary-tint-12 sm:col-span-3 lg:col-span-1"></div>
    </div>

    <div v-else-if="error" role="alert" class="rounded-card border border-border bg-surface p-6 shadow-card">
      <h2 class="font-heading text-headline-sm">Ringkasan belum bisa dimuat</h2>
      <p class="mt-1 text-secondary">{{ error }}</p>
      <button
        type="button"
        class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
        @click="load()"
      >
        Coba lagi
      </button>
    </div>

    <div v-else class="grid grid-cols-1 gap-4">
      <div class="grid grid-cols-1 gap-4" :class="kpiGrid">
        <section class="flex animate-rise flex-col justify-between gap-5 rounded-card border border-border bg-surface p-6 shadow-card lg:min-h-[14.5rem]" :class="heroSpan" aria-labelledby="hero-title">
          <div>
            <div class="flex flex-wrap gap-2">
              <p class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-label-md text-secondary">
                <span class="size-2 rounded-full bg-success" aria-hidden="true"></span>{{ today }}
              </p>
              <p v-if="data.store_hours" class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-label-md text-secondary" role="status">
                <span class="size-2 rounded-full" :class="data.store_hours.open ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></span>{{ data.store_hours.text }}
              </p>
            </div>
            <h2 id="hero-title" class="mt-3 font-heading text-[1.75rem] font-medium leading-tight tracking-tight lg:text-[1.875rem]">{{ greeting }}</h2>
            <p class="mt-2 text-body-sm text-secondary">{{ data.summary }}</p>
          </div>
          <div v-if="shortcuts.length" class="flex flex-wrap gap-2.5">
            <router-link
              v-for="([label, icon, to], i) in shortcuts"
              :key="to"
              :to="{ name: to }"
              class="inline-flex h-10 items-center gap-2 rounded-full px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
              :class="i === 0 ? 'bg-accent hover:bg-accent-hover' : 'border border-border bg-surface hover:bg-accent-tint-15'"
            >
              <AdminIcon :name="icon" :size="18" />{{ label }}
            </router-link>
          </div>
        </section>

        <KpiCard v-for="(kpi, i) in data.kpis" :key="kpi.key" :kpi="kpi" :index="i" @open="openKpi" />
      </div>

      <AttentionModal ref="attentionModal" :items="data.attention" :total="attentionTotal" />

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section v-if="data.chart" class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 240ms" aria-labelledby="chart-title">
          <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="chart-title" class="font-heading text-headline-sm">{{ data.chart.title }}</h2>
            <span class="rounded-full border border-border bg-background px-3 py-1 text-label-md">{{ data.chart.range }}</span>
          </header>
          <BarChart :chart="data.chart" />
        </section>

        <section v-if="data.gauge" class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 290ms" aria-labelledby="gauge-title">
          <h2 id="gauge-title" class="mb-3 font-heading text-headline-sm">{{ data.gauge.title }}</h2>
          <GaugeCard :gauge="data.gauge" />
        </section>
      </div>

      <section v-if="data.service_stats?.length" class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 320ms" aria-labelledby="stats-title">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h2 id="stats-title" class="font-heading text-headline-sm">Angka layanan</h2>
          <span class="rounded-full border border-border bg-background px-3 py-1 text-label-md">30 hari terakhir</span>
        </header>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="tile in data.service_stats" :key="tile.key" class="rounded-2xl bg-background px-4 py-3">
            <dt class="text-body-sm text-secondary">{{ tile.label }}</dt>
            <dd class="font-heading text-headline-sm tabular-nums">{{ tile.value }}</dd>
            <dd class="text-body-sm text-secondary">{{ tile.foot }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="data.business_stats?.length" class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 320ms" aria-labelledby="business-title">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h2 id="business-title" class="font-heading text-headline-sm">Angka bisnis</h2>
          <span class="rounded-full border border-border bg-background px-3 py-1 text-label-md">Bulan ini</span>
        </header>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="tile in data.business_stats" :key="tile.key" class="rounded-2xl bg-background px-4 py-3">
            <dt class="text-body-sm text-secondary">{{ tile.label }}</dt>
            <dd class="font-heading text-headline-sm tabular-nums">{{ tile.value }}</dd>
            <dd class="text-body-sm text-secondary">{{ tile.foot }}</dd>
          </div>
        </dl>
      </section>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3" :class="data.lists.length > 3 ? 'xl:grid-cols-4' : ''">
        <ListCard v-for="(list, i) in data.lists" :key="list.key" :list="list" :index="i">
          <template v-if="list.key === 'integrations'" #footer>
            <button
              type="button"
              class="mt-auto inline-flex h-10 items-center justify-center rounded-full border border-border bg-surface px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60"
              :disabled="checking"
              @click="checkIntegrations"
            >
              {{ checking ? 'Memeriksa…' : 'Cek ke API sekarang' }}
            </button>
            <p v-if="checkError" role="alert" class="text-body-sm text-on-error-surface">{{ checkError }}</p>
          </template>
        </ListCard>
      </div>
    </div>
  </div>
</template>
