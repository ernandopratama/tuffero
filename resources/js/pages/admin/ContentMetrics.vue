<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import FaIcon from '../../components/FaIcon.vue';
import MetricSpark from '../../components/marketing/MetricSpark.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import ViewsTrendChart from '../../components/marketing/ViewsTrendChart.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import { LEVELS, PLATFORMS, PLATFORM_COLOR, PLATFORM_ICON, PLATFORM_SOLID, formatDay, formatNumber, formatPercent, formatSigned } from '../../utils/contentMetrics';

// Performa Konten (D-150): angka TikTok dan Instagram yang dicatat tim marketing, dibaca Super Admin, Pemilik, dan Marketing.
// Hanya membaca; mengisi, mengubah, dan menghapus catatan dilakukan di modal Draf Konten (izin dijaga server).
const auth = useAuthStore();
const canManage = computed(() => auth.can('content_metrics.manage'));

const DAY_OPTIONS = [{ value: 7, label: '7 hari' }, { value: 30, label: '30 hari' }, { value: 90, label: '90 hari' }];
const PLATFORM_OPTIONS = [{ value: '', label: 'Semua platform' }, ...Object.entries(PLATFORMS).map(([value, label]) => ({ value, label }))];

const days = ref(7);
const platform = ref('');
const data = ref(null);
const loading = ref(true);
const refreshing = ref(false);
const error = ref('');
const showAllMissing = ref(false);

const chip = 'inline-flex min-h-11 shrink-0 cursor-pointer items-center gap-2 whitespace-nowrap rounded-full border px-4 text-label-md transition-[transform,background-color,color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';
const card = 'rounded-card border border-border bg-surface p-5 shadow-card';
const link = 'inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-full border border-border bg-surface px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';

// Satu kolom per platform yang punya konten; dengan filter platform hanya satu kolom penuh.
const groups = computed(() => Object.keys(PLATFORMS)
    .filter((name) => !platform.value || platform.value === name)
    .map((name) => ({ name, totals: data.value?.platforms?.[name], items: (data.value?.items ?? []).filter((item) => item.platform === name) })));
const missing = computed(() => (showAllMissing.value ? data.value?.needs_update : data.value?.needs_update?.slice(0, 3)) ?? []);
const stale = computed(() => (data.value?.items ?? []).filter((item) => item.stale).length);
// Platform yang digambar di grafik: mengikuti filter.
const chartPlatforms = computed(() => (platform.value ? [platform.value] : Object.keys(PLATFORMS)));
const interactions = computed(() => ['likes', 'comments', 'shares', 'saves'].reduce((sum, key) => sum + (data.value?.totals?.[key] ?? 0), 0));

// Angka utama: ikon, label, nilai, dan keterangan. `warn` menandai keterangan yang perlu perhatian.
const kpis = computed(() => {
    const d = data.value;
    if (!d) return [];
    return [
        { icon: 'eye', label: 'Total views', value: formatNumber(d.totals.views), note: 'Jumlah angka terakhir tiap konten' },
        { icon: 'heart', label: 'Interaksi', value: formatNumber(interactions.value), note: `${formatPercent(d.totals.engagement_rate)} dari views` },
        { icon: 'layer-group', label: 'Konten dilacak', value: formatNumber(d.totals.contents), note: `TikTok ${d.platforms.tiktok.contents} · Instagram ${d.platforms.instagram.contents}` },
        { icon: 'calendar-check', label: 'Catatan terakhir', value: formatDay(d.last_recorded_on), note: stale.value ? `${stale.value} konten belum diperbarui 3 hari` : 'Semua konten terbaru', warn: stale.value > 0 },
    ];
});

async function load() {
    // Ganti filter memuat ulang diam-diam: daftar lama tetap tampil (agak redup) supaya halaman tidak berkedip.
    if (data.value) refreshing.value = true;
    else loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/marketing-metrics/overview', { params: { days: days.value, platform: platform.value || undefined } });
        data.value = body.data;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

watch([days, platform], load);
onMounted(load);
</script>

<template>
  <div class="space-y-space-lg">
    <PageHeader title="Performa Konten" description="Angka TikTok dan Instagram yang dicatat tim marketing dari insight posting. Angka adalah total sampai tanggal catatan." />

    <!-- Ponsel: tiap kelompok chip satu baris yang bisa digeser (tinggi sentuh 44 px), bukan beberapa baris -->
    <div class="flex flex-wrap items-center gap-x-space-md gap-y-2">
      <div class="flex gap-2 max-sm:-mx-3 max-sm:w-[calc(100%+1.5rem)] max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none]" role="group" aria-label="Rentang waktu">
        <button v-for="option in DAY_OPTIONS" :key="option.value" type="button" :aria-pressed="days === option.value" :class="[chip, days === option.value ? 'border-primary bg-primary text-surface' : 'border-border bg-surface hover:bg-accent-tint-12']" @click="days = option.value">{{ option.label }}</button>
      </div>
      <div class="flex gap-2 max-sm:-mx-3 max-sm:w-[calc(100%+1.5rem)] max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none]" role="group" aria-label="Platform">
        <button v-for="option in PLATFORM_OPTIONS" :key="option.value" type="button" :aria-pressed="platform === option.value" :class="[chip, platform === option.value ? 'border-primary bg-primary text-surface' : 'border-border bg-surface hover:bg-accent-tint-12']" @click="platform = option.value">
          <FaIcon v-if="option.value" :name="PLATFORM_ICON[option.value]" :size="13" />{{ option.label }}
        </button>
      </div>
    </div>

    <div v-if="loading" role="status" aria-label="Memuat performa konten" class="space-y-space-md">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4"><div v-for="n in 4" :key="n" class="h-32 animate-pulse-soft rounded-card bg-secondary-tint-12"></div></div>
      <div class="h-80 animate-pulse-soft rounded-card bg-secondary-tint-12"></div>
      <div class="grid gap-space-md lg:grid-cols-2"><div v-for="n in 2" :key="n" class="h-72 animate-pulse-soft rounded-card bg-secondary-tint-12"></div></div>
    </div>

    <div v-else-if="error && !data" role="alert" class="flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <FaIcon name="warning" :size="18" />
      <span class="flex-1">{{ error }}</span>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-on-error-surface px-4 text-label-md hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="load"><FaIcon name="rotate-right" :size="14" />Muat ulang</button>
    </div>

    <div v-else class="space-y-space-lg transition-opacity duration-150 ease-out" :class="refreshing ? 'opacity-60' : ''" :aria-busy="refreshing">
      <p v-if="error" role="alert" class="flex items-center gap-2 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface"><FaIcon name="warning" :size="14" />{{ error }}</p>

      <!-- Pengingat: konten diposting yang belum dicatat 3 hari terakhir -->
      <section v-if="data.needs_update.length" class="flex flex-col gap-3 rounded-card border border-warning/30 bg-warning-surface p-4 text-warning sm:flex-row sm:items-start" aria-labelledby="missing-title">
        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-surface/70" aria-hidden="true"><FaIcon name="clock" :size="18" /></span>
        <div class="min-w-0 flex-1">
          <h2 id="missing-title" class="text-label-md">{{ data.needs_update.length }} konten diposting belum dicatat dalam 3 hari terakhir</h2>
          <ul class="mt-2 flex flex-wrap gap-2">
            <li v-for="item in missing" :key="item.draft_id" class="min-w-0 max-w-full">
              <router-link :to="{ name: 'AdminMarketingDrafts', query: { draf: item.draft_id } }" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border border-warning/30 bg-surface px-3 text-body-sm text-primary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]">
                <FaIcon name="pen-to-square" :size="12" class="text-secondary" /><span class="truncate">#{{ item.number }} {{ item.title }}</span>
              </router-link>
            </li>
            <li v-if="!showAllMissing && data.needs_update.length > 3">
              <button type="button" class="min-h-11 cursor-pointer rounded-full px-3 text-label-md underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="showAllMissing = true">Tampilkan semua</button>
            </li>
          </ul>
        </div>
      </section>

      <!-- Angka utama -->
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan angka">
        <div v-for="(kpi, i) in kpis" :key="kpi.label" :class="card" class="animate-rise" :style="{ animationDelay: `${i * 40}ms` }">
          <dt class="text-body-sm text-secondary">
            <span class="mb-3 grid size-9 place-items-center rounded-xl bg-accent-tint-15 text-secondary" aria-hidden="true"><FaIcon :name="kpi.icon" :size="15" /></span>{{ kpi.label }}
          </dt>
          <dd class="mt-1 font-heading text-[1.5rem] font-semibold leading-none tabular-nums sm:text-[1.75rem]">{{ kpi.value }}</dd>
          <dd class="mt-2 flex items-center gap-1.5 text-body-sm" :class="kpi.warn ? 'text-warning' : 'text-secondary'"><FaIcon v-if="kpi.warn" name="clock" :size="12" />{{ kpi.note }}</dd>
        </div>
      </dl>

      <!-- Kosong -->
      <div v-if="!data.items.length" class="flex flex-col items-center gap-3 rounded-card border border-dashed border-border bg-surface px-6 py-14 text-center">
        <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-secondary" aria-hidden="true"><FaIcon name="chart-line" :size="24" /></span>
        <p class="font-heading text-headline-sm">Belum ada catatan dalam {{ days }} hari terakhir</p>
        <p class="max-w-md text-body-sm text-secondary">
          {{ canManage ? 'Buka draf yang sudah diposting di Draf Konten, lalu isi angka dari insight TikTok atau Instagram.' : 'Tim marketing belum mencatat angka konten. Halaman ini terisi setelah catatan pertama masuk.' }}
        </p>
        <router-link v-if="canManage" :to="{ name: 'AdminMarketingDrafts' }" :class="link"><FaIcon name="pen-to-square" :size="14" />Buka Draf Konten</router-link>
      </div>

      <template v-else>
        <!-- Tren views harian -->
        <section :class="card" class="animate-rise" style="animation-delay: 160ms" aria-labelledby="trend-title">
          <header class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 id="trend-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="chart-line" :size="18" class="text-secondary" />Tren views</h2>
              <p class="mt-1 text-body-sm text-secondary">Total views tercatat per hari selama {{ days }} hari terakhir.</p>
            </div>
            <ul class="flex flex-wrap gap-2" aria-label="Keterangan grafik">
              <li v-for="name in chartPlatforms" :key="name" class="inline-flex min-h-9 items-center gap-2 rounded-full border border-border bg-background px-3 text-label-md">
                <span class="size-2.5 rounded-full" :style="{ backgroundColor: PLATFORM_COLOR[name] }" aria-hidden="true"></span>
                <FaIcon :name="PLATFORM_ICON[name]" :size="13" />{{ PLATFORMS[name] }}
                <span class="tabular-nums text-secondary">{{ formatNumber(data.platforms[name].views) }}</span>
              </li>
            </ul>
          </header>
          <ViewsTrendChart :points="data.trend" :platforms="chartPlatforms" :height="220" />
        </section>

        <!-- Peringkat per platform -->
        <div class="grid gap-space-md" :class="groups.length > 1 ? 'lg:grid-cols-2' : ''">
          <section v-for="(group, g) in groups" :key="group.name" :class="card" class="animate-rise min-w-0" :style="{ animationDelay: `${200 + g * 50}ms` }" :aria-labelledby="`rank-${group.name}`">
            <header class="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 :id="`rank-${group.name}`" class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl text-surface" :class="PLATFORM_SOLID[group.name]" aria-hidden="true"><FaIcon :name="PLATFORM_ICON[group.name]" :size="18" /></span>
                <span class="leading-tight">
                  <span class="block font-heading text-headline-sm">{{ PLATFORMS[group.name] }}</span>
                  <span class="block text-body-sm text-secondary">Peringkat konten</span>
                </span>
              </h2>
              <p class="inline-flex items-center gap-1.5 text-body-sm tabular-nums text-secondary"><FaIcon name="eye" :size="12" />{{ formatNumber(group.totals.views) }} views · {{ group.totals.contents }} konten</p>
            </header>

            <p v-if="!group.items.length" class="rounded-2xl bg-background px-4 py-6 text-center text-body-sm text-secondary">Belum ada catatan {{ PLATFORMS[group.name] }} dalam rentang ini.</p>

            <ol v-else class="divide-y divide-border">
              <li v-for="(item, index) in group.items" :key="item.draft_id">
                <router-link
                  :to="{ name: 'AdminMarketingDrafts', query: { draf: item.draft_id } }"
                  class="group -mx-2 flex min-h-11 items-center gap-3 rounded-2xl px-2 py-3 transition-[background-color] duration-[160ms] ease-out hover:bg-accent-tint-12 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  :aria-label="`Peringkat ${index + 1}: ${item.title}, ${formatNumber(item.views)} views. ${canManage ? 'Buka untuk mencatat' : 'Buka draf'}`"
                >
                  <span class="grid size-8 shrink-0 place-items-center rounded-full text-label-md tabular-nums" :class="index === 0 ? 'bg-accent text-primary' : 'bg-background text-secondary'" aria-hidden="true">
                    <FaIcon v-if="index === 0" name="trophy" :size="13" /><template v-else>{{ index + 1 }}</template>
                  </span>
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-body-md font-medium" :title="item.title">#{{ item.number }} {{ item.title }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-body-sm tabular-nums text-secondary">
                      <span class="inline-flex items-center gap-1" title="Rasio interaksi"><FaIcon name="heart" :size="11" />{{ formatPercent(item.engagement_rate) }}</span>
                      <span class="inline-flex items-center gap-1" title="Dibagikan"><FaIcon name="share" :size="11" />{{ formatNumber(item.shares) }}</span>
                      <span v-if="LEVELS[item.level]" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm" :class="LEVELS[item.level].cls"><FaIcon :name="item.level === 'outlier' ? 'fire' : 'arrow-down'" :size="10" />{{ LEVELS[item.level].label }}</span>
                      <span v-if="item.stale" class="inline-flex items-center gap-1 rounded-full bg-warning-surface px-2 py-0.5 text-label-sm text-warning"><FaIcon name="clock" :size="10" />Belum diperbarui</span>
                    </p>
                  </div>
                  <MetricSpark :values="item.spark" :height="28" class="max-sm:hidden" />
                  <p class="shrink-0 text-right">
                    <span class="block font-heading text-body-lg tabular-nums">{{ formatNumber(item.views) }}</span>
                    <span v-if="item.views_change !== null" class="inline-flex items-center gap-0.5 text-label-sm tabular-nums" :class="item.views_change < 0 ? 'text-error' : item.views_change > 0 ? 'text-success' : 'text-secondary'">
                      <FaIcon v-if="item.views_change" :name="item.views_change < 0 ? 'arrow-down' : 'arrow-up'" :size="9" />{{ formatSigned(item.views_change) }}
                    </span>
                  </p>
                  <FaIcon name="arrow-right" :size="12" class="text-secondary opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 max-sm:hidden" />
                </router-link>
              </li>
            </ol>
          </section>
        </div>

        <p class="flex items-start gap-2 text-body-sm text-secondary">
          <FaIcon name="circle-info" :size="14" class="mt-0.5" />
          <span>Selisih dihitung dari catatan sebelumnya. Lencana Menonjol berarti views minimal 2 kali median konten sendiri di platform itu, Di bawah normal kurang dari setengahnya; butuh minimal 3 konten. Garis tren naik juga saat konten baru mulai dicatat.</span>
        </p>
      </template>
    </div>
  </div>
</template>
