<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Ringkasan SEO (izin reports.view: Super Admin dan Pemilik). Data dari /admin/seo/overview. Google belum terhubung (Fase 2).
const card = 'rounded-card border border-border bg-surface p-6 shadow-card';
const TONE = { ok: 'ok', warn: 'warn', bad: 'bad', unknown: 'neutral' };
const LABEL = { ok: 'Baik', warn: 'Perlu perhatian', bad: 'Bermasalah', unknown: 'Belum diketahui' };

const data = ref(null);
const loading = ref(true);
const error = ref('');
const checking = ref(false);
const checkError = ref('');
const busyKey = ref('');
const checklistError = ref('');

const technical = computed(() => data.value?.technical ?? null);
const checklist = computed(() => data.value?.checklist ?? []);
const doneCount = computed(() => checklist.value.filter((item) => item.done).length);
const content = computed(() => data.value?.content ?? null);
const google = computed(() => data.value?.google ?? { connected: false, pagespeed: false, sync: null, totals: null });
const percent = (done) => (content.value?.total ? Math.round((done / content.value.total) * 100) : 0);

async function load(silent = false) {
    if (!silent) loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/seo/overview');
        data.value = body.data;
    } catch (e) {
        if (!silent) error.value = apiError(e).message;
        else checklistError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function runCheck() {
    checking.value = true;
    checkError.value = '';
    try {
        const { data: body } = await api.post('/admin/seo/technical/check');
        data.value = { ...data.value, technical: body.data };
    } catch (e) {
        checkError.value = apiError(e).message;
    } finally {
        checking.value = false;
    }
}

async function toggle(item) {
    if (item.auto || busyKey.value) return;
    busyKey.value = item.key;
    checklistError.value = '';
    try {
        await api.put(`/admin/seo/checklist/${item.key}`, { done: !item.done });
        await load(true);
    } catch (e) {
        checklistError.value = apiError(e).message;
    } finally {
        busyKey.value = '';
    }
}

onMounted(() => load());
</script>

<template>
  <PageHeader title="Ringkasan SEO" description="Kesehatan SEO situs, kelengkapan konten, dan langkah peluncuran. Dibaca dari situs sendiri; data Google tampil setelah Search Console terhubung." />

  <div v-if="loading" class="mt-space-md h-96 animate-pulse-soft rounded-card bg-secondary-tint-12" role="status" aria-label="Memuat ringkasan SEO"></div>

  <div v-else-if="error" role="alert" :class="`${card} mt-space-md`">
    <h2 class="font-heading text-headline-sm">Ringkasan SEO belum bisa dimuat</h2>
    <p class="mt-1 text-secondary">{{ error }}</p>
    <button type="button" class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover" @click="load()">Coba lagi</button>
  </div>

  <div v-else class="mt-space-md grid grid-cols-1 gap-4">
    <section :class="card" aria-labelledby="tech-title">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 id="tech-title" class="font-heading text-headline-sm">Status teknis situs</h2>
          <p class="text-body-sm text-secondary">Lingkungan <span class="font-semibold text-primary">{{ data.environment }}</span>, alamat situs <span class="font-semibold text-primary [overflow-wrap:anywhere]">{{ data.base_url }}</span>. Pemeriksaan membuka halaman situs ini dari server.</p>
        </div>
        <button type="button" class="inline-flex h-10 cursor-pointer items-center justify-center rounded-full bg-primary px-5 text-label-md text-background transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" :disabled="checking" @click="runCheck">
          {{ checking ? 'Memeriksa...' : 'Cek sekarang' }}
        </button>
      </div>
      <p v-if="checkError" role="alert" class="mt-3 text-body-sm text-error">{{ checkError }}</p>

      <p v-if="!technical" class="mt-4 rounded-2xl bg-secondary-tint-12 p-4 text-body-sm text-secondary">Belum pernah diperiksa. Tekan <span class="font-semibold">Cek sekarang</span> untuk memeriksa robots.txt, sitemap, canonical, dan pengalihan.</p>
      <template v-else>
        <p class="mt-3 flex flex-wrap items-center gap-2 text-body-sm" aria-live="polite">
          <StatusBadge :tone="TONE[technical.status]">{{ LABEL[technical.status] }}</StatusBadge>
          <span class="text-secondary">Diperiksa {{ formatDate(technical.checked_at) }}</span>
        </p>
        <ul class="mt-3 divide-y divide-border">
          <li v-for="check in technical.checks" :key="check.key" class="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:gap-4">
            <StatusBadge :tone="TONE[check.status]" size="sm" class="self-start sm:w-40 sm:justify-start">{{ LABEL[check.status] }}</StatusBadge>
            <div class="min-w-0">
              <p class="font-medium">{{ check.title }}</p>
              <p class="break-words text-body-sm text-secondary">{{ check.message }}</p>
            </div>
          </li>
        </ul>
      </template>
    </section>

    <section :class="card" aria-labelledby="content-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="content-title" class="font-heading text-headline-sm">Kelengkapan konten produk</h2>
        <RouterLink :to="{ name: 'AdminSeoContent' }" class="inline-flex h-10 items-center rounded-full border border-border px-5 text-label-md hover:bg-secondary-tint-12">Lihat dan lengkapi produk</RouterLink>
      </div>
      <p class="mt-1 text-body-sm text-secondary">Tiap produk dinilai dari lima butir (20 poin per butir). Produk tanpa deskripsi memakai teks otomatis yang sama untuk semua, jadi kurang menarik bagi Google.</p>

      <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
        <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Produk tayang</dt><dd class="font-heading text-headline-sm tabular-nums">{{ content.total }}</dd></div>
        <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Lengkap (100)</dt><dd class="font-heading text-headline-sm tabular-nums">{{ content.ready }}</dd></div>
        <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Skor rata-rata</dt><dd class="font-heading text-headline-sm tabular-nums">{{ content.average_score }}</dd></div>
      </dl>

      <ul class="mt-4 grid gap-3">
        <li v-for="check in content.checks" :key="check.key">
          <div class="flex items-baseline justify-between gap-3 text-body-sm">
            <span>{{ check.label }}</span>
            <span class="tabular-nums text-secondary">{{ check.done }} dari {{ content.total }}</span>
          </div>
          <div class="mt-1 h-2 overflow-hidden rounded-full bg-secondary-tint-12" role="progressbar" :aria-label="check.label" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent(check.done)">
            <div class="h-full rounded-full bg-accent" :style="{ width: `${percent(check.done)}%` }"></div>
          </div>
        </li>
      </ul>
    </section>

    <section :class="card" aria-labelledby="check-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="check-title" class="font-heading text-headline-sm">Checklist peluncuran SEO</h2>
        <StatusBadge :tone="doneCount === checklist.length ? 'ok' : 'info'">{{ doneCount }} dari {{ checklist.length }} selesai</StatusBadge>
      </div>
      <p class="mt-1 text-body-sm text-secondary">Langkah di luar aplikasi dicentang tangan oleh Super Admin atau Pemilik. Langkah bertanda Otomatis dihitung dari data.</p>
      <p v-if="checklistError" role="alert" class="mt-3 text-body-sm text-error">{{ checklistError }}</p>

      <ul class="mt-3 divide-y divide-border">
        <li v-for="item in checklist" :key="item.key" class="py-3">
          <component
            :is="item.auto ? 'div' : 'button'"
            :type="item.auto ? undefined : 'button'"
            :role="item.auto ? undefined : 'checkbox'"
            :aria-checked="item.auto ? undefined : item.done"
            :disabled="item.auto ? undefined : busyKey === item.key"
            class="flex w-full items-start gap-3 rounded-xl p-1 text-left"
            :class="item.auto ? '' : 'cursor-pointer hover:bg-secondary-tint-12 disabled:cursor-wait disabled:opacity-60'"
            @click="toggle(item)"
          >
            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md border" :class="item.done ? 'border-primary bg-primary text-background' : 'border-border bg-surface'" aria-hidden="true">
              <AdminIcon v-if="item.done" name="check" :size="14" />
            </span>
            <span class="min-w-0">
              <span class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ item.title }}</span>
                <StatusBadge v-if="item.auto" tone="info" size="sm" :dot="false">Otomatis</StatusBadge>
                <StatusBadge v-else-if="item.confirmed" tone="ok" size="sm" :dot="false">Terkonfirmasi Google</StatusBadge>
              </span>
              <span class="block text-body-sm text-secondary">{{ item.detail }}</span>
              <span v-if="item.done && item.done_at" class="block text-body-sm text-secondary">Dicentang oleh {{ item.done_by }}, {{ formatDate(item.done_at) }}.</span>
            </span>
          </component>
        </li>
      </ul>
    </section>

    <section :class="card" aria-labelledby="google-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="google-title" class="font-heading text-headline-sm">Data Google</h2>
        <StatusBadge :tone="google.connected || google.pagespeed ? (google.sync?.state === 'error' ? 'warn' : 'ok') : 'neutral'">{{ google.connected || google.pagespeed ? (google.sync?.state === 'error' ? 'Ada kendala' : 'Terhubung') : 'Belum terhubung' }}</StatusBadge>
      </div>
      <template v-if="google.totals">
        <p class="mt-1 text-body-sm text-secondary">28 hari terakhir dari data Google.</p>
        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Klik</dt><dd class="font-heading text-headline-sm tabular-nums">{{ google.totals.clicks.toLocaleString('id-ID') }}</dd></div>
          <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Tayangan</dt><dd class="font-heading text-headline-sm tabular-nums">{{ google.totals.impressions.toLocaleString('id-ID') }}</dd></div>
          <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">CTR</dt><dd class="font-heading text-headline-sm tabular-nums">{{ (google.totals.ctr * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 }) }}%</dd></div>
          <div class="rounded-2xl bg-secondary-tint-12 p-3"><dt class="text-label-md text-secondary">Posisi</dt><dd class="font-heading text-headline-sm tabular-nums">{{ String(google.totals.position).replace('.', ',') }}</dd></div>
        </dl>
      </template>
      <p v-else-if="google.connected" class="mt-1 text-body-sm text-secondary">Tersambung, tetapi belum ada data pencarian. Google tertinggal 2 sampai 3 hari setelah situs terindeks.</p>
      <p v-else class="mt-1 text-body-sm text-secondary">Klik, tayangan, posisi, dan kecepatan halaman dari Google tampil di sini setelah kunci Google diisi di server.</p>
      <p v-if="google.sync?.state === 'error'" role="alert" class="mt-2 break-words text-body-sm text-error">{{ google.sync.message }}</p>
      <RouterLink :to="{ name: 'AdminSeoGoogle' }" class="mt-4 inline-flex h-10 items-center rounded-full border border-border px-5 text-label-md hover:bg-secondary-tint-12">{{ google.connected || google.pagespeed ? 'Buka Search Console' : 'Lihat yang perlu disiapkan' }}</RouterLink>
    </section>
  </div>
</template>
