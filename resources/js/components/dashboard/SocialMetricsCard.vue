<script setup>
import { computed, onMounted, ref } from 'vue';
import FaIcon from '../FaIcon.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import { PLATFORMS, PLATFORM_ICON, PLATFORM_SOLID, formatDay, formatNumber, formatPercent, formatSigned } from '../../utils/contentMetrics';

// Kartu Performa akun (D-151) di Ringkasan: followers dan pertumbuhan 30 hari tiap akun, satu rangkuman, dan pengingat
// bila akun belum dicatat. Detail dan grafik ada di halaman Performa Akun.
const auth = useAuthStore();
const canManage = computed(() => auth.can('social_metrics.manage'));

const data = ref(null);
const loading = ref(true);
const error = ref('');

const accounts = computed(() => ['tiktok', 'instagram'].map((name) => data.value.platforms[name]).filter(Boolean));
const stale = computed(() => accounts.value.filter((item) => item.stale));
// Satu rangkuman paling berguna: pengingat dan rangkuman bukan pertumbuhan sudah ada di halaman; kartu hanya menampilkan perbandingan bila ada.
const highlight = computed(() => (data.value?.insights ?? []).find((item) => item.type === 'compare') ?? null);
const link = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/social-metrics/overview', { params: { days: 30 } });
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
  <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 340ms" aria-labelledby="social-card-title" :aria-busy="loading">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <h2 id="social-card-title" class="flex items-center gap-2 font-heading text-headline-sm"><FaIcon name="users" :size="18" class="text-secondary" />Performa akun</h2>
      <span class="rounded-full border border-border bg-background px-3 py-1 text-label-md">30 hari terakhir</span>
    </header>

    <div v-if="loading" role="status" aria-label="Memuat performa akun" class="grid gap-3 sm:grid-cols-2">
      <div v-for="n in 2" :key="n" class="h-28 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>

    <div v-else-if="error" role="alert" class="flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <FaIcon name="warning" :size="18" />
      <span class="flex-1">{{ error }}</span>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-on-error-surface px-4 text-label-md hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="load"><FaIcon name="rotate-right" :size="14" />Muat ulang</button>
    </div>

    <template v-else>
      <p v-if="stale.length" role="status" class="mb-3 flex items-start gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
        <FaIcon name="clock" :size="14" class="mt-0.5" />
        <span>{{ stale.map((item) => PLATFORMS[item.platform]).join(' dan ') }} belum dicatat lebih dari 2 hari.</span>
      </p>

      <div class="grid gap-3 sm:grid-cols-2">
        <article v-for="item in accounts" :key="item.platform" class="rounded-2xl bg-background px-4 py-4">
          <p class="flex items-center gap-2 text-body-sm text-secondary">
            <span class="grid size-7 place-items-center rounded-lg text-surface" :class="PLATFORM_SOLID[item.platform]"><FaIcon :name="PLATFORM_ICON[item.platform]" :size="13" :label="PLATFORMS[item.platform]" /></span>{{ PLATFORMS[item.platform] }}
          </p>
          <template v-if="item.followers.current !== null">
            <p class="mt-2 font-heading text-headline-sm tabular-nums">{{ formatNumber(item.followers.current) }} <span class="font-body text-body-sm font-normal text-secondary">followers</span></p>
            <p v-if="item.followers.change !== null" class="mt-1 inline-flex items-center gap-1.5 text-body-sm font-medium tabular-nums" :class="item.followers.change < 0 ? 'text-error' : item.followers.change > 0 ? 'text-success' : 'text-secondary'">
              <FaIcon v-if="item.followers.change" :name="item.followers.change < 0 ? 'arrow-down' : 'arrow-up'" :size="10" />{{ formatSigned(item.followers.change) }}<template v-if="item.followers.change_percent !== null"> ({{ formatPercent(item.followers.change_percent) }})</template>
              <span class="font-normal text-secondary">sejak {{ formatDay(item.followers.since) }}</span>
            </p>
            <p v-else class="mt-1 text-body-sm text-secondary">Pertumbuhan terlihat setelah dua catatan.</p>
          </template>
          <p v-else class="mt-2 text-body-sm text-secondary">Belum ada catatan.</p>
        </article>
      </div>

      <p v-if="highlight" class="mt-3 flex items-start gap-2 text-body-sm text-secondary"><FaIcon name="scale-balanced" :size="13" class="mt-1" />{{ highlight.text }}</p>

      <div class="mt-4 flex flex-wrap gap-2">
        <router-link :to="{ name: 'AdminSocialMetrics' }" :class="link">Lihat detail<FaIcon name="arrow-right" :size="12" /></router-link>
        <router-link v-if="canManage" :to="{ name: 'AdminSocialMetrics' }" :class="link"><FaIcon name="plus" :size="13" />Catat performa akun</router-link>
      </div>
    </template>
  </section>
</template>
