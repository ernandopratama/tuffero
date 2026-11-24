<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import FaIcon from '../../components/FaIcon.vue';
import SocialAudienceModal from '../../components/marketing/SocialAudienceModal.vue';
import SocialRecordModal from '../../components/marketing/SocialRecordModal.vue';
import ViewsTrendChart from '../../components/marketing/ViewsTrendChart.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import { PLATFORMS, PLATFORM_ICON, PLATFORM_SOLID, formatDay, formatNumber, formatPercent, formatSigned } from '../../utils/contentMetrics';
import { AUDIENCE_TITLES, GROUPS, PERIODS, formatDuration, formatMonth, periodNoun } from '../../utils/socialMetrics';

// Performa Akun (D-151 tahap 1): angka akun Instagram dan TikTok yang dicatat tim marketing dari layar insight.
// Halaman membaca; mengisi, mengubah, dan menghapus butuh `social_metrics.manage` (dijaga server).
const auth = useAuthStore();
const canManage = computed(() => auth.can('social_metrics.manage'));

const DAY_OPTIONS = [{ value: 7, label: '7 hari' }, { value: 30, label: '30 hari' }, { value: 90, label: '90 hari' }];
const PLATFORM_OPTIONS = [{ value: '', label: 'Semua akun' }, ...Object.entries(PLATFORMS).map(([value, label]) => ({ value, label }))];
const ORDER = ['tiktok', 'instagram'];

const days = ref(30);
const period = ref(1);
const platform = ref('');
const includeTest = ref(false);
const data = ref(null);
const loading = ref(true);
const refreshing = ref(false);
const error = ref('');
const recordModal = ref(null);
const audienceModal = ref(null);
const confirming = ref('');
const busy = ref(false);

const chip = 'inline-flex min-h-11 shrink-0 cursor-pointer items-center gap-2 whitespace-nowrap rounded-full border px-4 text-label-md transition-[transform,background-color,color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';
const card = 'rounded-card border border-border bg-surface p-5 shadow-card';
const button = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60';
const primary = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-accent px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';

const shown = computed(() => ORDER.filter((name) => data.value?.platforms?.[name]));
const noun = computed(() => periodNoun(period.value));
const points = (metric) => (data.value?.trend ?? []).map((point) => ({ date: point.date, ...point[metric] }));
const empty = computed(() => data.value && !data.value.records.length && shown.value.every((name) => data.value.platforms[name].followers.current === null));
const stale = computed(() => shown.value.map((name) => data.value.platforms[name]).filter((item) => item.stale));
const hasTest = computed(() => (data.value?.test_count ?? 0) > 0);
const tone = { good: 'text-success', warn: 'text-warning', neutral: 'text-secondary' };
const toneIcon = { good: 'arrow-up', warn: 'arrow-down', neutral: 'circle-info' };

// Perbandingan hanya untuk angka yang sepadan di kedua platform; angka khusus satu platform tidak dibandingkan.
const comparison = computed(() => {
    if (shown.value.length < 2) return [];
    const get = (name) => data.value.platforms[name];
    return [
        { label: 'Followers', icon: 'users', cells: shown.value.map((name) => formatNumber(get(name).followers.current)) },
        { label: 'Pertumbuhan followers', icon: 'chart-line', cells: shown.value.map((name) => growth(get(name).followers)) },
        { label: `Views ${noun.value}`, icon: 'eye', cells: shown.value.map((name) => formatNumber(get(name).totals.views)) },
        { label: 'Kunjungan profil', icon: 'users', cells: shown.value.map((name) => formatNumber(get(name).totals.profile_visits)) },
    ];
});
const growth = (followers) => (followers.change === null ? '–' : `${formatSigned(followers.change)}${followers.change_percent === null ? '' : ` (${formatPercent(followers.change_percent)})`}`);
const interactions = (row) => (row.platform === 'instagram' ? row.interactions : [row.likes, row.comments, row.shares].every((v) => v === null || v === undefined) ? null : (row.likes ?? 0) + (row.comments ?? 0) + (row.shares ?? 0));
const value = (item, record) => {
    const raw = record?.[item.key];
    if (raw === null || raw === undefined) return '–';
    return item.unit === 'time' ? formatDuration(raw) : `${formatNumber(raw)}${item.suffix ? ` ${item.suffix}` : ''}`;
};

async function load() {
    // Ganti filter memuat ulang diam-diam: isi lama tetap tampil (agak redup) supaya halaman tidak berkedip.
    if (data.value) refreshing.value = true;
    else loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/social-metrics/overview', { params: { days: days.value, period: period.value, platform: platform.value || undefined, include_test: includeTest.value ? 1 : undefined } });
        data.value = body.data;
        if (!body.data.test_count) includeTest.value = false;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

// Catatan mingguan dan bulanan jarang: rentang pendek hampir kosong, jadi dinaikkan.
watch(period, (next) => {
    if (next === 30 && days.value < 90) days.value = 90;
    else if (next === 7 && days.value < 30) days.value = 30;
});
watch([days, period, platform, includeTest], load);
onMounted(load);

const record = (existing = null, name = '') => recordModal.value.open(existing, { platform: name || platform.value || 'instagram', period: period.value });

async function remove(row) {
    busy.value = true;
    try {
        await api.delete(`/admin/social-metrics/${row.id}`, { toast: 'Catatan akun dihapus.' });
        confirming.value = '';
        await load();
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}
async function removeTests() {
    busy.value = true;
    try {
        await api.delete('/admin/social-metrics', { toast: 'Data tes dihapus.' });
        confirming.value = '';
        await load();
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}
async function removeAudience(snapshot) {
    busy.value = true;
    try {
        await api.delete(`/admin/social-audience/${snapshot.id}`, { toast: 'Demografi dihapus.' });
        confirming.value = '';
        await load();
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
  <div class="space-y-space-lg">
    <PageHeader title="Performa Akun" description="Angka akun Instagram dan TikTok dari layar insight, dicatat manual oleh tim marketing. Bukan angka per posting." />

    <!-- Filter. Ponsel: tiap kelompok satu baris yang bisa digeser (tinggi sentuh 44 px) -->
    <div class="flex flex-wrap items-center gap-x-space-md gap-y-2">
      <div class="flex gap-2 max-sm:-mx-3 max-sm:w-[calc(100%+1.5rem)] max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none]" role="group" aria-label="Akun">
        <button v-for="option in PLATFORM_OPTIONS" :key="option.value" type="button" :aria-pressed="platform === option.value" :class="[chip, platform === option.value ? 'border-primary bg-primary text-surface' : 'border-border bg-surface hover:bg-accent-tint-12']" @click="platform = option.value">
          <FaIcon v-if="option.value" :name="PLATFORM_ICON[option.value]" :size="13" />{{ option.label }}
        </button>
      </div>
      <div class="flex gap-2 max-sm:-mx-3 max-sm:w-[calc(100%+1.5rem)] max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none]" role="group" aria-label="Rentang waktu">
        <button v-for="option in DAY_OPTIONS" :key="option.value" type="button" :aria-pressed="days === option.value" :class="[chip, days === option.value ? 'border-primary bg-primary text-surface' : 'border-border bg-surface hover:bg-accent-tint-12']" @click="days = option.value">{{ option.label }}</button>
      </div>
      <div class="flex gap-2 max-sm:-mx-3 max-sm:w-[calc(100%+1.5rem)] max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none]" role="group" aria-label="Jenis catatan">
        <button v-for="option in PERIODS" :key="option.value" type="button" :aria-pressed="period === option.value" :class="[chip, period === option.value ? 'border-primary bg-primary text-surface' : 'border-border bg-surface hover:bg-accent-tint-12']" @click="period = option.value">{{ option.label }}</button>
      </div>
      <div v-if="canManage" class="flex flex-wrap gap-2 sm:ml-auto">
        <button type="button" :class="button" @click="audienceModal.open(null, platform || 'instagram')"><FaIcon name="chart-pie" :size="14" />Catat demografi</button>
        <button type="button" :class="primary" @click="record()"><FaIcon name="plus" :size="14" />Catat performa</button>
      </div>
    </div>

    <div v-if="loading" role="status" aria-label="Memuat performa akun" class="space-y-space-md">
      <div class="grid gap-space-md lg:grid-cols-2"><div v-for="n in 2" :key="n" class="h-72 animate-pulse-soft rounded-card bg-secondary-tint-12"></div></div>
      <div class="h-64 animate-pulse-soft rounded-card bg-secondary-tint-12"></div>
    </div>

    <div v-else-if="error && !data" role="alert" class="flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <FaIcon name="warning" :size="18" />
      <span class="flex-1">{{ error }}</span>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-on-error-surface px-4 text-label-md hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="load"><FaIcon name="rotate-right" :size="14" />Muat ulang</button>
    </div>

    <div v-else class="space-y-space-lg transition-opacity duration-150 ease-out" :class="refreshing ? 'opacity-60' : ''" :aria-busy="refreshing">
      <p v-if="error" role="alert" class="flex items-center gap-2 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface"><FaIcon name="warning" :size="14" />{{ error }}</p>

      <!-- Kosong -->
      <div v-if="empty" class="flex flex-col items-center gap-3 rounded-card border border-dashed border-border bg-surface px-6 py-14 text-center">
        <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-secondary" aria-hidden="true"><FaIcon name="users" :size="24" /></span>
        <p class="font-heading text-headline-sm">Belum ada catatan akun</p>
        <p class="max-w-md text-body-sm text-secondary">
          {{ canManage ? 'Buka insight Instagram atau TikTok, lalu catat total followers dan angka periode yang sama.' : 'Tim marketing belum mencatat angka akun. Halaman ini terisi setelah catatan pertama masuk.' }}
        </p>
        <button v-if="canManage" type="button" :class="primary" @click="record()"><FaIcon name="plus" :size="14" />Catat performa pertama</button>
      </div>

      <template v-else>
        <!-- Pengingat: akun yang belum dicatat beberapa hari -->
        <section v-if="stale.length" class="flex flex-col gap-3 rounded-card border border-warning/30 bg-warning-surface p-4 text-warning sm:flex-row sm:items-center" aria-labelledby="stale-title">
          <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-surface/70" aria-hidden="true"><FaIcon name="clock" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 id="stale-title" class="text-label-md">{{ stale.map((item) => PLATFORMS[item.platform]).join(' dan ') }} belum dicatat lebih dari 2 hari</h2>
            <p class="text-body-sm">{{ stale.map((item) => item.last_recorded_on ? `${PLATFORMS[item.platform]}: terakhir ${formatDay(item.last_recorded_on)}` : `${PLATFORMS[item.platform]}: belum pernah dicatat`).join('. ') }}.</p>
          </div>
          <button v-if="canManage" type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-warning/40 bg-surface px-4 text-label-md text-primary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="record(null, stale[0].platform)"><FaIcon name="pen-to-square" :size="13" />Catat sekarang</button>
        </section>

        <!-- Followers: satu kartu per akun, sumbu grafik dipotong supaya pertumbuhan pelan tetap terlihat -->
        <div class="grid gap-space-md" :class="shown.length > 1 ? 'lg:grid-cols-2' : ''">
          <article v-for="(name, i) in shown" :key="name" :class="card" class="min-w-0 animate-rise" :style="{ animationDelay: `${i * 60}ms` }" :aria-labelledby="`followers-${name}`">
            <header class="flex items-center gap-3">
              <span class="grid size-10 place-items-center rounded-xl text-surface" :class="PLATFORM_SOLID[name]" aria-hidden="true"><FaIcon :name="PLATFORM_ICON[name]" :size="18" /></span>
              <h2 :id="`followers-${name}`" class="font-heading text-headline-sm">{{ PLATFORMS[name] }}</h2>
              <span v-if="data.platforms[name].stale" class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-warning-surface px-2.5 py-1 text-label-sm text-warning"><FaIcon name="clock" :size="10" />Belum diperbarui</span>
            </header>

            <template v-if="data.platforms[name].followers.current !== null">
              <p class="mt-4 font-heading text-[2.5rem] font-semibold leading-none tabular-nums">{{ formatNumber(data.platforms[name].followers.current) }}</p>
              <p class="mt-1 text-body-sm text-secondary">followers</p>
              <p v-if="data.platforms[name].followers.change !== null" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-md">
                <span class="inline-flex items-center gap-1.5 font-medium tabular-nums" :class="data.platforms[name].followers.change < 0 ? 'text-error' : data.platforms[name].followers.change > 0 ? 'text-success' : 'text-secondary'">
                  <FaIcon v-if="data.platforms[name].followers.change" :name="data.platforms[name].followers.change < 0 ? 'arrow-down' : 'arrow-up'" :size="12" />{{ growth(data.platforms[name].followers) }}
                </span>
                <span class="text-body-sm text-secondary">sejak {{ formatDay(data.platforms[name].followers.since) }}</span>
              </p>
              <p v-else class="mt-3 text-body-sm text-secondary">Pertumbuhan terlihat setelah ada dua catatan.</p>
              <div class="mt-5">
                <ViewsTrendChart :points="points('followers')" :platforms="[name]" :height="150" fit :title="`followers ${PLATFORMS[name]}`" empty-text="Belum ada catatan followers dalam rentang ini." />
              </div>
            </template>
            <p v-else class="mt-4 rounded-2xl bg-background px-4 py-8 text-center text-body-sm text-secondary">Belum ada catatan {{ PLATFORMS[name] }} dalam rentang ini.</p>
          </article>
        </div>

        <!-- Rangkuman aturan tetap dan perbandingan -->
        <div class="grid gap-space-md" :class="comparison.length ? 'lg:grid-cols-2' : ''">
          <section :class="card" aria-labelledby="insight-title">
            <h2 id="insight-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="lightbulb" :size="16" class="text-secondary" />Rangkuman</h2>
            <p class="mt-1 text-body-sm text-secondary">Dihitung dari aturan tetap, bukan kesimpulan. Catatan masih sedikit, jadi anggap sebagai dugaan.</p>
            <ul class="mt-4 space-y-3">
              <li v-for="(insight, i) in data.insights" :key="i" class="flex items-start gap-3">
                <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-background" :class="tone[insight.tone]" aria-hidden="true"><FaIcon :name="insight.type === 'reminder' ? 'clock' : insight.type === 'compare' ? 'scale-balanced' : insight.type === 'conversion' ? 'link' : toneIcon[insight.tone]" :size="11" /></span>
                <p class="text-body-md">{{ insight.text }}</p>
              </li>
            </ul>
          </section>

          <section v-if="comparison.length" :class="card" aria-labelledby="compare-title">
            <h2 id="compare-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="scale-balanced" :size="16" class="text-secondary" />Perbandingan</h2>
            <p class="mt-1 text-body-sm text-secondary">Hanya angka yang sepadan di kedua akun.</p>
            <table class="mt-4 w-full text-body-sm">
              <caption class="sr-only">Perbandingan Instagram dan TikTok</caption>
              <thead>
                <tr class="text-left">
                  <th scope="col" class="pb-2 font-normal"><span class="sr-only">Angka</span></th>
                  <th v-for="name in shown" :key="name" scope="col" class="pb-2 text-right font-medium"><span class="inline-flex items-center gap-1.5"><FaIcon :name="PLATFORM_ICON[name]" :size="12" />{{ PLATFORMS[name] }}</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="row in comparison" :key="row.label">
                  <th scope="row" class="py-3 text-left font-normal text-secondary"><span class="inline-flex items-center gap-2"><FaIcon :name="row.icon" :size="12" />{{ row.label }}</span></th>
                  <td v-for="(cell, i) in row.cells" :key="i" class="py-3 text-right font-medium tabular-nums">{{ cell }}</td>
                </tr>
              </tbody>
            </table>
          </section>
        </div>

        <!-- Tren views dan interaksi -->
        <div class="grid gap-space-md lg:grid-cols-2">
          <section v-for="chart in [{ id: 'views', title: 'Views', icon: 'eye', note: 'Instagram: views. TikTok: video views.' }, { id: 'interactions', title: 'Interaksi', icon: 'heart', note: 'Instagram: interactions. TikTok: likes, comments, dan shares.' }]" :key="chart.id" :class="card" class="min-w-0" :aria-labelledby="`trend-${chart.id}`">
            <h2 :id="`trend-${chart.id}`" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon :name="chart.icon" :size="16" class="text-secondary" />{{ chart.title }}</h2>
            <p class="mb-5 mt-1 text-body-sm text-secondary">Catatan {{ noun }} selama {{ days }} hari terakhir. {{ chart.note }}</p>
            <ViewsTrendChart :points="points(chart.id)" :platforms="shown" :height="180" :title="`${chart.title.toLowerCase()} ${noun}`" :empty-text="`Belum ada catatan ${noun} dengan angka ${chart.title.toLowerCase()}.`" />
          </section>
        </div>

        <!-- Angka terakhir per akun, sesuai isian insight -->
        <div class="grid gap-space-md" :class="shown.length > 1 ? 'lg:grid-cols-2' : ''">
          <section v-for="name in shown" :key="name" :class="card" class="min-w-0" :aria-labelledby="`latest-${name}`">
            <header class="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 :id="`latest-${name}`" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon :name="PLATFORM_ICON[name]" :size="16" />Angka {{ PLATFORMS[name] }} terakhir</h2>
              <p v-if="data.platforms[name].latest" class="text-body-sm text-secondary">Catatan {{ noun }} sampai {{ formatDay(data.platforms[name].latest.period_end) }}</p>
            </header>
            <p v-if="!data.platforms[name].latest" class="rounded-2xl bg-background px-4 py-6 text-center text-body-sm text-secondary">Belum ada catatan {{ noun }} {{ PLATFORMS[name] }} dalam rentang ini.</p>
            <div v-else class="space-y-4">
              <section v-for="group in GROUPS[name]" :key="group.title">
                <h3 class="mb-1 text-label-md text-secondary">{{ group.title }}</h3>
                <dl class="divide-y divide-border">
                  <div v-for="item in group.fields" :key="item.key" class="flex items-center justify-between gap-3 py-2">
                    <dt class="flex items-center gap-2 text-body-sm"><FaIcon :name="item.icon" :size="12" class="text-secondary" />{{ item.label }}</dt>
                    <dd class="text-body-md font-medium tabular-nums" :class="data.platforms[name].latest[item.key] === null ? 'text-secondary' : ''">{{ value(item, data.platforms[name].latest) }}</dd>
                  </div>
                </dl>
              </section>
              <p v-if="data.platforms[name].latest.note" class="flex items-start gap-2 rounded-2xl bg-background px-3 py-2 text-body-sm"><FaIcon name="comment" :size="12" class="mt-1 text-secondary" />{{ data.platforms[name].latest.note }}</p>
            </div>
          </section>
        </div>

        <!-- Demografi bulanan -->
        <section :class="card" aria-labelledby="audience-title">
          <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 id="audience-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="chart-pie" :size="16" class="text-secondary" />Demografi followers</h2>
              <p class="mt-1 text-body-sm text-secondary">Catatan bulanan terbaru dari insight. Persen dari total followers.</p>
            </div>
          </header>
          <div class="grid gap-space-md" :class="shown.length > 1 ? 'lg:grid-cols-2' : ''">
            <div v-for="name in shown" :key="name" class="min-w-0">
              <div class="mb-3 flex flex-wrap items-center gap-2">
                <h3 class="flex items-center gap-2 text-label-md"><FaIcon :name="PLATFORM_ICON[name]" :size="13" />{{ PLATFORMS[name] }}</h3>
                <template v-if="data.audience[name]">
                  <span class="text-body-sm text-secondary">{{ formatMonth(data.audience[name].month) }}</span>
                  <span v-if="data.audience[name].is_test" class="inline-flex items-center gap-1 rounded-full bg-info-surface px-2 py-0.5 text-label-sm text-info"><FaIcon name="flask" :size="10" />TES</span>
                  <span v-if="data.audience[name].data.tag" class="rounded-full bg-background px-2 py-0.5 text-label-sm text-secondary">{{ data.audience[name].data.tag }}</span>
                  <span v-if="canManage" class="ml-auto flex items-center gap-1">
                    <template v-if="confirming === data.audience[name].id">
                      <button type="button" :disabled="busy" class="min-h-10 cursor-pointer rounded-full bg-error px-3 text-label-md text-surface hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="removeAudience(data.audience[name])">Hapus</button>
                      <button type="button" class="min-h-10 cursor-pointer rounded-full px-3 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="confirming = ''">Batal</button>
                    </template>
                    <template v-else>
                      <button type="button" class="min-h-10 cursor-pointer rounded-full px-3 text-label-md text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="audienceModal.open(data.audience[name])">Ubah</button>
                      <button type="button" class="min-h-10 cursor-pointer rounded-full px-3 text-label-md text-error transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-error-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="confirming = data.audience[name].id">Hapus</button>
                    </template>
                  </span>
                </template>
              </div>
              <p v-if="!data.audience[name]" class="rounded-2xl bg-background px-4 py-6 text-center text-body-sm text-secondary">Belum ada catatan demografi {{ PLATFORMS[name] }}.</p>
              <div v-else class="space-y-4">
                <template v-for="(title, group) in AUDIENCE_TITLES" :key="group">
                  <div v-if="data.audience[name].data[group]?.length">
                    <h4 class="mb-2 text-body-sm text-secondary">{{ title }}</h4>
                    <ul class="space-y-2">
                      <li v-for="row in data.audience[name].data[group]" :key="row.label" class="grid grid-cols-[6.5rem_1fr_3.5rem] items-center gap-3 text-body-sm">
                        <span class="truncate" :title="row.label">{{ row.label }}</span>
                        <span class="h-2 overflow-hidden rounded-full bg-background" aria-hidden="true"><span class="block h-full origin-left rounded-full animate-grow" :style="{ width: `${Math.min(row.percent, 100)}%`, backgroundColor: name === 'tiktok' ? '#111827' : '#C13584' }"></span></span>
                        <span class="text-right tabular-nums">{{ formatPercent(row.percent) }}</span>
                      </li>
                    </ul>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </section>

        <!-- Riwayat catatan -->
        <section :class="card" class="min-w-0" aria-labelledby="history-title">
          <h2 id="history-title" class="mb-3 flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="calendar-check" :size="16" class="text-secondary" />Riwayat catatan {{ noun }}</h2>
          <p v-if="!data.records.length" class="rounded-2xl bg-background px-4 py-6 text-center text-body-sm text-secondary">Belum ada catatan {{ noun }} dalam {{ days }} hari terakhir.</p>
          <div v-else class="relative -mx-2 overflow-x-auto px-2">
            <table class="w-full min-w-[640px] text-body-sm">
              <caption class="sr-only">Riwayat catatan akun</caption>
              <thead class="text-left text-secondary">
                <tr>
                  <th scope="col" class="py-2 pr-3 font-normal">Sampai</th>
                  <th scope="col" class="px-3 py-2 font-normal">Akun</th>
                  <th scope="col" class="px-3 py-2 text-right font-normal">Followers</th>
                  <th scope="col" class="px-3 py-2 text-right font-normal">Views</th>
                  <th scope="col" class="px-3 py-2 text-right font-normal">Interaksi</th>
                  <th scope="col" class="px-3 py-2 text-right font-normal">Kunjungan profil</th>
                  <th v-if="canManage" scope="col" class="py-2 pl-3"><span class="sr-only">Aksi</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="row in data.records" :key="row.id">
                  <td class="whitespace-nowrap py-3 pr-3">
                    {{ formatDay(row.period_end) }}
                    <span v-if="row.is_test" class="ml-1 inline-flex items-center gap-1 rounded-full bg-info-surface px-2 py-0.5 text-label-sm text-info"><FaIcon name="flask" :size="10" />TES</span>
                    <span v-if="row.note" class="ml-1 inline-block max-w-40 truncate rounded-full bg-background px-2 py-0.5 align-middle text-label-sm text-secondary" :title="row.note">{{ row.note }}</span>
                  </td>
                  <td class="px-3 py-3"><span class="inline-flex items-center gap-1.5"><FaIcon :name="PLATFORM_ICON[row.platform]" :size="12" />{{ PLATFORMS[row.platform] }}</span></td>
                  <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(row.followers_total) }}</td>
                  <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(row.views) }}</td>
                  <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(interactions(row)) }}</td>
                  <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(row.profile_visits) }}</td>
                  <td v-if="canManage" class="whitespace-nowrap py-1 pl-3 text-right">
                    <template v-if="confirming === row.id">
                      <button type="button" :disabled="busy" class="min-h-10 cursor-pointer rounded-full bg-error px-3 text-label-md text-surface hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="remove(row)">Hapus</button>
                      <button type="button" class="min-h-10 cursor-pointer rounded-full px-3 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="confirming = ''">Batal</button>
                    </template>
                    <template v-else>
                      <button type="button" class="size-10 cursor-pointer rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" :aria-label="`Ubah catatan ${PLATFORMS[row.platform]} ${formatDay(row.period_end)}`" @click="record(row)"><FaIcon name="pen-to-square" :size="14" class="mx-auto" /></button>
                      <button type="button" class="size-10 cursor-pointer rounded-full text-error transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-error-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" :aria-label="`Hapus catatan ${PLATFORMS[row.platform]} ${formatDay(row.period_end)}`" @click="confirming = row.id"><FaIcon name="trash" :size="14" class="mx-auto" /></button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <!-- Data tes -->
      <section v-if="hasTest" class="flex flex-col gap-3 rounded-card border border-info/30 bg-info-surface p-4 text-info sm:flex-row sm:items-center" aria-label="Data tes">
        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-surface/70" aria-hidden="true"><FaIcon name="flask" :size="16" /></span>
        <p class="min-w-0 flex-1 text-body-sm"><span class="font-medium">{{ data.test_count }} data tes tersimpan.</span> Data tes tidak ikut angka, grafik, dan rangkuman kecuali ditampilkan.</p>
        <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-label-md"><input v-model="includeTest" type="checkbox" class="size-5 accent-primary">Tampilkan data tes</label>
        <template v-if="canManage">
          <span v-if="confirming === 'tests'" class="flex gap-1">
            <button type="button" :disabled="busy" class="min-h-11 cursor-pointer rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="removeTests">Hapus semua data tes</button>
            <button type="button" class="min-h-11 cursor-pointer rounded-full px-4 text-label-md hover:bg-info/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="confirming = ''">Batal</button>
          </span>
          <button v-else type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-info/40 bg-surface px-4 text-label-md text-primary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-error-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="confirming = 'tests'"><FaIcon name="trash" :size="13" />Hapus data tes</button>
        </template>
      </section>
    </div>

    <SocialRecordModal ref="recordModal" @saved="load" />
    <SocialAudienceModal ref="audienceModal" @saved="load" />
  </div>
</template>
