<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../components/ui/PageHeader.vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Search Console dan PageSpeed (D-152 Fase 2, izin reports.view). Data dibaca dari tabel lokal yang diisi sinkron harian;
// halaman ini tidak memanggil Google dan tidak memegang kunci apa pun.
const card = 'rounded-card border border-border bg-surface p-6 shadow-card';
const PERIODS = [
    { value: 7, label: '7 hari' },
    { value: 28, label: '28 hari' },
    { value: 90, label: '90 hari' },
];
const PREPARE = [
    'Properti domain tuffero.com di Search Console, diverifikasi lewat DNS.',
    'Proyek Google Cloud (gratis) dengan Search Console API aktif.',
    'Satu service account yang emailnya ditambahkan sebagai pengguna (hanya baca) di Search Console.',
    'Kunci JSON service account disimpan di server di luar folder web, tidak di Git dan tidak di dashboard.',
    'Kunci API PageSpeed Insights (opsional, untuk kartu kecepatan).',
];
const FIELD = { FAST: 'Cepat', AVERAGE: 'Sedang', SLOW: 'Lambat' };
const FIELD_TONE = { FAST: 'ok', AVERAGE: 'warn', SLOW: 'bad' };

const days = ref(28);
const data = ref(null);
const loading = ref(true);
const error = ref('');
const starting = ref(false);
const syncError = ref('');
let timer = null;

const report = computed(() => data.value?.report ?? null);
const sync = computed(() => data.value?.sync ?? { state: 'never' });
const running = computed(() => sync.value.state === 'running');
const configured = computed(() => data.value?.configured ?? { search_console: false, pagespeed: false });
const anyConfigured = computed(() => configured.value.search_console || configured.value.pagespeed);
// Tombol hanya aktif bila tidak sedang memulai atau menjalankan sinkron dan masa tunggu habis: 30 detik setelah sinkron
// selesai, atau 60 detik setelah server menolak karena terlalu sering ditekan (429).
const COOLDOWN_MS = 30000;
const now = ref(Date.now());
const lockUntil = ref(0);
let ticker = null;
const remaining = computed(() => {
    const finished = sync.value.finished_at ? Date.parse(sync.value.finished_at) + COOLDOWN_MS : 0;

    return Math.max(0, Math.ceil((Math.max(finished, lockUntil.value) - now.value) / 1000));
});
const canSync = computed(() => !starting.value && !running.value && remaining.value === 0);
const maxClicks = computed(() => Math.max(1, ...(report.value?.daily ?? []).map((day) => day.clicks)));

const num = (value) => new Intl.NumberFormat('id-ID').format(value ?? 0);
const pct = (value) => `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format((value ?? 0) * 100)}%`;
const secs = (ms) => (ms == null ? '-' : `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(ms / 1000)} dtk`);
const cls = (value) => (value == null ? '-' : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(value));
// Selisih terhadap periode sebelumnya; null bila tidak ada pembanding. Posisi: angka lebih kecil = lebih baik.
const delta = (key) => {
    const now = report.value?.totals?.[key];
    const before = report.value?.previous?.[key];
    if (now == null || before == null || before === 0) return null;
    return (now - before) / before;
};
const deltaText = (key, lowerIsBetter = false) => {
    const value = delta(key);
    if (value === null) return { text: 'Tanpa pembanding', tone: 'text-secondary' };
    const good = lowerIsBetter ? value < 0 : value > 0;
    const sign = value > 0 ? '+' : '';
    return { text: `${sign}${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value * 100)}% dari periode sebelumnya`, tone: value === 0 ? 'text-secondary' : good ? 'text-success' : 'text-error' };
};
const kpis = computed(() => {
    const totals = report.value?.totals;
    if (!totals) return [];

    return [
        { label: 'Klik', value: num(totals.clicks), ...deltaText('clicks') },
        { label: 'Tayangan', value: num(totals.impressions), ...deltaText('impressions') },
        { label: 'CTR', value: pct(totals.ctr), ...deltaText('ctr') },
        { label: 'Posisi rata-rata', value: String(totals.position).replace('.', ','), ...deltaText('position', true) },
    ];
});
const scoreTone = (score) => (score == null ? 'neutral' : score >= 90 ? 'ok' : score >= 50 ? 'warn' : 'bad');
const shortPage = (url) => {
    try {
        const { pathname } = new URL(url);
        return pathname === '/' ? '/' : pathname;
    } catch {
        return url;
    }
};

async function load(silent = false) {
    if (!silent) loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/seo/search', { params: { days: days.value } });
        data.value = body.data;
    } catch (e) {
        if (!silent) error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
    schedulePoll();
}

// Selama sinkron berjalan, tanya ulang tiap 4 detik; berhenti sendiri begitu selesai atau halaman ditutup.
function schedulePoll() {
    clearTimeout(timer);
    if (running.value) timer = setTimeout(() => load(true), 4000);
}

async function startSync() {
    if (!canSync.value) return;
    starting.value = true;
    syncError.value = '';
    try {
        const { data: body } = await api.post('/admin/seo/search/sync');
        data.value = { ...data.value, sync: body.data };
        schedulePoll();
    } catch (e) {
        syncError.value = apiError(e).message;
        if (e?.response?.status === 429) lockUntil.value = Date.now() + 60000;
    } finally {
        starting.value = false;
    }
}

function changeDays(value) {
    days.value = value;
    load(true);
}

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);
    load();
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    clearInterval(ticker);
});
</script>

<template>
  <PageHeader title="Search Console" description="Kinerja situs di Google dan kecepatan halaman. Data tertinggal 2 sampai 3 hari dan diperbarui otomatis tiap malam." />

  <div v-if="loading" class="mt-space-md h-96 animate-pulse-soft rounded-card bg-secondary-tint-12" role="status" aria-label="Memuat data Search Console"></div>

  <div v-else-if="error" role="alert" :class="`${card} mt-space-md`">
    <h2 class="font-heading text-headline-sm">Data belum bisa dimuat</h2>
    <p class="mt-1 text-secondary">{{ error }}</p>
    <button type="button" class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover" @click="load()">Coba lagi</button>
  </div>

  <div v-else class="mt-space-md grid grid-cols-1 gap-4">
    <!-- Status sambungan dan sinkron -->
    <section :class="card" aria-labelledby="gsc-status">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 id="gsc-status" class="font-heading text-headline-sm">{{ anyConfigured ? 'Sambungan Google' : 'Belum terhubung' }}</h2>
          <p v-if="anyConfigured" class="mt-1 flex flex-wrap items-center gap-2 text-body-sm text-secondary" aria-live="polite">
            <StatusBadge :tone="configured.search_console ? 'ok' : 'neutral'" size="sm">Search Console {{ configured.search_console ? 'aktif' : 'belum diisi' }}</StatusBadge>
            <StatusBadge :tone="configured.pagespeed ? 'ok' : 'neutral'" size="sm">PageSpeed {{ configured.pagespeed ? 'aktif' : 'belum diisi' }}</StatusBadge>
            <span v-if="data.property" class="[overflow-wrap:anywhere]">Properti {{ data.property }}</span>
          </p>
          <p v-if="anyConfigured" class="mt-2 text-body-sm" aria-live="polite">
            <template v-if="running">Sinkron berjalan...</template>
            <template v-else-if="sync.state === 'never'">Belum pernah disinkronkan.</template>
            <template v-else>Sinkron terakhir {{ formatDate(sync.finished_at) }}: <span :class="sync.state === 'ok' ? 'text-success' : sync.state === 'error' ? 'text-error' : 'text-secondary'">{{ sync.state === 'ok' ? 'berhasil' : sync.state === 'error' ? 'ada kendala' : 'belum dijalankan (kunci belum terbaca saat itu)' }}</span>.</template>
          </p>
          <p v-if="anyConfigured && sync.state === 'error'" role="alert" class="mt-1 break-words text-body-sm text-error">{{ sync.message }}</p>
          <p v-if="syncError" role="alert" class="mt-1 text-body-sm text-error">{{ syncError }}</p>
        </div>
        <button v-if="anyConfigured" type="button" class="inline-flex h-10 cursor-pointer items-center justify-center rounded-full bg-primary px-5 text-label-md text-background transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" :disabled="!canSync" @click="startSync">
          {{ running || starting ? 'Memperbarui...' : remaining > 0 ? `Tunggu ${remaining} dtk` : 'Perbarui sekarang' }}
        </button>
      </div>

      <template v-if="!anyConfigured">
        <p class="mt-1 text-body-sm text-secondary">Kunci Google belum diisi di server. Setelah diisi, klik, tayangan, kueri, dan kecepatan halaman tampil di sini.</p>
        <h3 class="mt-4 text-label-md">Yang perlu disiapkan pemilik akun Google</h3>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-body-sm">
          <li v-for="line in PREPARE" :key="line">{{ line }}</li>
        </ol>
        <p class="mt-3 text-body-sm text-secondary">Langkah verifikasi dan pengiriman sitemap ada di checklist peluncuran.</p>
        <RouterLink :to="{ name: 'AdminSeo' }" class="mt-4 inline-flex h-10 items-center rounded-full border border-border px-5 text-label-md hover:bg-secondary-tint-12">Buka checklist</RouterLink>
      </template>
    </section>

    <template v-if="anyConfigured">
      <!-- Kinerja pencarian -->
      <section :class="card" aria-labelledby="gsc-perf">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="gsc-perf" class="font-heading text-headline-sm">Kinerja pencarian</h2>
          <SegmentedControl :model-value="days" :options="PERIODS" label="Periode" @update:model-value="changeDays" />
        </div>

        <p v-if="!report.has_data" class="mt-4 rounded-2xl bg-secondary-tint-12 p-4 text-body-sm text-secondary">Belum ada data pencarian. Google baru punya data setelah situs terindeks dan tertinggal 2 sampai 3 hari; tekan <span class="font-semibold">Perbarui sekarang</span> setelah kunci terisi.</p>

        <template v-else>
          <p class="mt-1 text-body-sm text-secondary">{{ formatDate(report.range.from) }} sampai {{ formatDate(report.range.to) }}.</p>
          <dl class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl bg-secondary-tint-12 p-4">
              <dt class="text-label-md text-secondary">{{ kpi.label }}</dt>
              <dd class="font-heading text-headline-sm tabular-nums">{{ kpi.value }}</dd>
              <dd class="text-body-sm" :class="kpi.tone">{{ kpi.text }}</dd>
            </div>
          </dl>

          <h3 class="mt-6 text-label-md">Klik per hari</h3>
          <div class="mt-2 flex h-32 items-end gap-px" role="img" :aria-label="`Grafik klik per hari, ${report.daily.length} hari, tertinggi ${num(maxClicks)} klik`">
            <div v-for="day in report.daily" :key="day.date" class="min-w-px flex-1 rounded-t-sm bg-accent" :style="{ height: `${Math.max(2, (day.clicks / maxClicks) * 100)}%` }" :title="`${formatDate(day.date)}: ${num(day.clicks)} klik, ${num(day.impressions)} tayangan`"></div>
          </div>

          <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div v-for="table in [{ id: 'q', title: 'Kueri teratas', head: 'Kueri', rows: report.queries }, { id: 'p', title: 'Halaman teratas', head: 'Halaman', rows: report.pages }]" :key="table.id" class="min-w-0">
              <h3 class="text-label-md">{{ table.title }}</h3>
              <p v-if="!table.rows.length" class="mt-2 text-body-sm text-secondary">Belum ada data.</p>
              <div v-else class="mt-2 overflow-x-auto">
                <table class="w-full text-body-sm">
                  <thead>
                    <tr class="border-b border-border text-left text-secondary">
                      <th scope="col" class="py-2 pr-3 font-medium">{{ table.head }}</th>
                      <th scope="col" class="px-2 py-2 text-right font-medium">Klik</th>
                      <th scope="col" class="px-2 py-2 text-right font-medium">Tayangan</th>
                      <th scope="col" class="py-2 pl-2 text-right font-medium">Posisi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in table.rows" :key="row.key" class="border-b border-border last:border-0">
                      <td class="max-w-[16rem] py-2 pr-3 [overflow-wrap:anywhere]" :title="row.key">{{ table.id === 'p' ? shortPage(row.key) : row.key }}</td>
                      <td class="px-2 py-2 text-right tabular-nums">{{ num(row.clicks) }}</td>
                      <td class="px-2 py-2 text-right tabular-nums">{{ num(row.impressions) }}</td>
                      <td class="py-2 pl-2 text-right tabular-nums">{{ String(row.position).replace('.', ',') }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <p class="mt-4 text-body-sm text-secondary">Daftar kueri dan halaman digabung dari 50 teratas per hari, jadi kueri yang jarang muncul tidak tercatat. Total di atas memakai angka harian lengkap dari Google.</p>
        </template>
      </section>
    </template>

    <!-- Kecepatan halaman: tampil juga bila hanya kunci PageSpeed yang terisi -->
    <section v-if="configured.pagespeed || report.speed.length" :class="card" aria-labelledby="gsc-speed">
      <h2 id="gsc-speed" class="font-heading text-headline-sm">Kecepatan halaman</h2>
      <p class="mt-1 text-body-sm text-secondary">Diuji dengan PageSpeed Insights (tampilan HP). Skor lab dari simulasi; data pengguna nyata hanya ada bila Google punya cukup kunjungan.</p>
      <p v-if="!report.speed.length" class="mt-4 rounded-2xl bg-secondary-tint-12 p-4 text-body-sm text-secondary">Belum ada hasil. Tekan <span class="font-semibold">Perbarui sekarang</span>.</p>
      <ul v-else class="mt-4 grid gap-3 md:grid-cols-2">
        <li v-for="run in report.speed" :key="run.url" class="rounded-2xl border border-border p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="min-w-0 font-medium [overflow-wrap:anywhere]">{{ run.path }}</p>
            <StatusBadge :tone="scoreTone(run.score)">Skor {{ run.score ?? '-' }}</StatusBadge>
          </div>
          <p v-if="run.previous_score != null && run.score != null" class="text-body-sm text-secondary">Sebelumnya {{ run.previous_score }}.</p>
          <dl class="mt-3 grid grid-cols-3 gap-2 text-body-sm">
            <div><dt class="text-secondary">LCP (lab)</dt><dd class="tabular-nums">{{ secs(run.lcp_ms) }}</dd></div>
            <div><dt class="text-secondary">CLS (lab)</dt><dd class="tabular-nums">{{ cls(run.cls) }}</dd></div>
            <div><dt class="text-secondary">TBT (lab)</dt><dd class="tabular-nums">{{ run.tbt_ms == null ? '-' : `${num(run.tbt_ms)} ms` }}</dd></div>
          </dl>
          <div class="mt-3 border-t border-border pt-3 text-body-sm">
            <p v-if="run.field_scope === null" class="text-secondary">Belum ada data pengguna nyata dari Google.</p>
            <template v-else>
              <p class="flex flex-wrap items-center gap-2">
                <span class="text-secondary">Pengguna nyata{{ run.field_scope === 'origin' ? ' (seluruh situs)' : '' }}</span>
                <StatusBadge v-if="run.field_category" :tone="FIELD_TONE[run.field_category] ?? 'neutral'" size="sm">{{ FIELD[run.field_category] ?? run.field_category }}</StatusBadge>
              </p>
              <dl class="mt-2 grid grid-cols-3 gap-2">
                <div><dt class="text-secondary">LCP</dt><dd class="tabular-nums">{{ secs(run.field_lcp_ms) }}</dd></div>
                <div><dt class="text-secondary">INP</dt><dd class="tabular-nums">{{ run.field_inp_ms == null ? '-' : `${num(run.field_inp_ms)} ms` }}</dd></div>
                <div><dt class="text-secondary">CLS</dt><dd class="tabular-nums">{{ cls(run.field_cls) }}</dd></div>
              </dl>
            </template>
          </div>
          <p class="mt-3 text-body-sm text-secondary">Diuji {{ formatDate(run.ran_at) }}.</p>
        </li>
      </ul>
    </section>
  </div>
</template>
