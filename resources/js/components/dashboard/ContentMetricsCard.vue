<script setup>
import { computed, onMounted, ref } from 'vue';
import FaIcon from '../FaIcon.vue';
import MetricSpark from '../marketing/MetricSpark.vue';
import ViewsTrendChart from '../marketing/ViewsTrendChart.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import { LEVELS, PLATFORMS, PLATFORM_COLOR, PLATFORM_ICON, PLATFORM_SOLID, formatNumber, formatPercent, formatSigned } from '../../utils/contentMetrics';

// Kartu ringkasan performa konten (D-150) untuk Ringkasan Super Admin, Pemilik, dan Marketing: angka 7 hari terakhir,
// grafik tren ringkas, tiga konten teratas, dan pengingat bila ada konten diposting yang belum dicatat. Detail ada di halaman Performa Konten.
const auth = useAuthStore();
const canManage = computed(() => auth.can('content_metrics.manage'));

const data = ref(null);
const loading = ref(true);
const error = ref('');

const top = computed(() => (data.value?.items ?? []).slice(0, 3));
const missing = computed(() => data.value?.needs_update ?? []);
const platformNames = Object.keys(PLATFORMS);
const tiles = computed(() => [
    { icon: 'eye', label: 'Views', value: formatNumber(data.value.totals.views) },
    { icon: 'heart', label: 'Interaksi', value: formatPercent(data.value.totals.engagement_rate) },
    { icon: 'layer-group', label: 'Konten', value: formatNumber(data.value.totals.contents) },
]);

const link = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/marketing-metrics/overview', { params: { days: 7 } });
        data.value = body.data;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
  <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 300ms" aria-labelledby="metrics-card-title" :aria-busy="loading">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <h2 id="metrics-card-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="chart-line" :size="18" class="text-secondary" />Performa konten</h2>
      <span class="rounded-full border border-border bg-background px-3 py-1 text-label-md">7 hari terakhir</span>
    </header>

    <div v-if="loading" role="status" aria-label="Memuat performa konten" class="space-y-3">
      <div class="grid grid-cols-3 gap-3"><div v-for="n in 3" :key="n" class="h-20 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div></div>
      <div class="h-28 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>

    <div v-else-if="error" role="alert" class="flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <FaIcon name="warning" :size="18" />
      <span class="flex-1">{{ error }}</span>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-on-error-surface px-4 text-label-md hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="load"><FaIcon name="rotate-right" :size="14" />Muat ulang</button>
    </div>

    <template v-else>
      <p v-if="missing.length" role="status" class="mb-3 flex items-start gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
        <FaIcon name="clock" :size="14" class="mt-0.5" />
        <span>{{ missing.length }} konten yang sudah diposting belum dicatat dalam 3 hari terakhir.</span>
      </p>

      <div v-if="!data.items.length" class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border px-4 py-8 text-center">
        <span class="grid size-12 place-items-center rounded-2xl bg-accent-tint-15 text-secondary" aria-hidden="true"><FaIcon name="chart-line" :size="20" /></span>
        <p class="font-heading text-headline-sm">Belum ada catatan metrik</p>
        <p class="max-w-md text-body-sm text-secondary">
          {{ canManage ? 'Isi angka TikTok atau Instagram dari draf yang sudah diposting di Draf Konten.' : 'Tim marketing belum mencatat angka konten dalam 7 hari terakhir.' }}
        </p>
      </div>

      <template v-else>
        <dl class="grid grid-cols-3 gap-3">
          <div v-for="tile in tiles" :key="tile.label" class="rounded-2xl bg-background px-4 py-3">
            <dt class="flex items-center gap-1.5 text-body-sm text-secondary"><FaIcon :name="tile.icon" :size="12" />{{ tile.label }}</dt>
            <dd class="mt-1 font-heading text-headline-sm tabular-nums">{{ tile.value }}</dd>
          </div>
        </dl>

        <div class="mt-4">
          <ul class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-label-sm text-secondary" aria-label="Keterangan grafik">
            <li v-for="name in platformNames" :key="name" class="inline-flex items-center gap-1.5">
              <span class="size-2 rounded-full" :style="{ backgroundColor: PLATFORM_COLOR[name] }" aria-hidden="true"></span>{{ PLATFORMS[name] }}
            </li>
          </ul>
          <ViewsTrendChart :points="data.trend" :platforms="platformNames" :height="96" compact />
        </div>

        <ol class="mt-4 divide-y divide-border" aria-label="Konten teratas menurut views">
          <li v-for="(item, index) in top" :key="`${item.draft_id}-${item.platform}`" class="flex items-center gap-3 py-3">
            <span class="grid size-8 shrink-0 place-items-center rounded-xl text-surface" :class="PLATFORM_SOLID[item.platform] || 'bg-secondary'">
              <FaIcon :name="PLATFORM_ICON[item.platform]" :size="14" :label="PLATFORMS[item.platform]" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-body-md font-medium" :title="item.title"><span class="sr-only">Peringkat {{ index + 1 }}: </span>#{{ item.number }} {{ item.title }}</p>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-body-sm tabular-nums text-secondary">
                <span class="inline-flex items-center gap-1" title="Rasio interaksi"><FaIcon name="heart" :size="11" />{{ formatPercent(item.engagement_rate) }}</span>
                <span v-if="LEVELS[item.level]" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm" :class="LEVELS[item.level].cls"><FaIcon :name="item.level === 'outlier' ? 'fire' : 'arrow-down'" :size="10" />{{ LEVELS[item.level].label }}</span>
              </p>
            </div>
            <MetricSpark :values="item.spark" :height="24" class="max-sm:hidden" />
            <p class="shrink-0 text-right">
              <span class="block font-heading text-body-lg tabular-nums">{{ formatNumber(item.views) }}</span>
              <span v-if="item.views_change !== null" class="inline-flex items-center gap-0.5 text-label-sm tabular-nums" :class="item.views_change < 0 ? 'text-error' : item.views_change > 0 ? 'text-success' : 'text-secondary'">
                <FaIcon v-if="item.views_change" :name="item.views_change < 0 ? 'arrow-down' : 'arrow-up'" :size="9" />{{ formatSigned(item.views_change) }}
              </span>
            </p>
          </li>
        </ol>
      </template>

      <div class="mt-4 flex flex-wrap gap-2">
        <router-link :to="{ name: 'AdminContentMetrics' }" :class="link">Lihat detail<FaIcon name="arrow-right" :size="12" /></router-link>
        <router-link v-if="canManage" :to="{ name: 'AdminMarketingDrafts' }" :class="link"><FaIcon name="pen-to-square" :size="13" />Catat di Draf Konten</router-link>
      </div>
    </template>
  </section>
</template>
