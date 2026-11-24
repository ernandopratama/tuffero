<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Konten dan Pratinjau SEO (izin reports.view): kelengkapan tiap produk, dan tampilan halaman publik apa adanya di hasil Google.
const card = 'rounded-card border border-border bg-surface p-6 shadow-card';
const th = 'px-3 py-2 text-left text-label-md font-medium text-secondary';
const td = 'px-3 py-2 align-top text-body-sm';
const GROUPS = [
    { type: 'static', label: 'Halaman utama' },
    { type: 'category', label: 'Halaman kategori' },
    { type: 'product', label: 'Produk' },
];
const LANGS = [
    { value: 'id', label: 'Indonesia' },
    { value: 'en', label: 'Inggris' },
];

const data = ref(null);
const loading = ref(true);
const error = ref('');
const path = ref('/');
const lang = ref('id');
const preview = ref(null);
const previewLoading = ref(false);
const previewError = ref('');

const grouped = computed(() => GROUPS.map((group) => ({ ...group, pages: (data.value?.pages ?? []).filter((page) => page.type === group.type) })).filter((group) => group.pages.length));
const scoreTone = (score) => (score >= 100 ? 'ok' : score >= 60 ? 'warn' : 'bad');
const rangeTone = (length, min, max) => (length >= min && length <= max ? 'text-success' : 'text-error');
const shownUrl = computed(() => (preview.value?.canonical || preview.value?.url || '').replace(/^https?:\/\//, ''));

async function loadPreview() {
    previewLoading.value = true;
    previewError.value = '';
    try {
        const { data: body } = await api.get('/admin/seo/preview', { params: { path: path.value, lang: lang.value } });
        preview.value = body.data;
    } catch (e) {
        preview.value = null;
        previewError.value = apiError(e).message;
    } finally {
        previewLoading.value = false;
    }
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/seo/content');
        data.value = body.data;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
    if (data.value) loadPreview();
}

function showPreview(row) {
    path.value = row.path;
    document.getElementById('preview-title')?.scrollIntoView({ block: 'start' });
}

watch([path, lang], loadPreview);
onMounted(load);
</script>

<template>
  <PageHeader title="Konten dan Pratinjau" description="Kelengkapan konten tiap produk dan tampilan halaman di hasil Google. Pratinjau membaca halaman asli situs." />

  <div v-if="loading" class="mt-space-md h-96 animate-pulse-soft rounded-card bg-secondary-tint-12" role="status" aria-label="Memuat konten SEO"></div>

  <div v-else-if="error" role="alert" :class="`${card} mt-space-md`">
    <h2 class="font-heading text-headline-sm">Data belum bisa dimuat</h2>
    <p class="mt-1 text-secondary">{{ error }}</p>
    <button type="button" class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover" @click="load">Coba lagi</button>
  </div>

  <div v-else class="mt-space-md grid grid-cols-1 gap-4">
    <section :class="card" aria-labelledby="preview-title">
      <h2 id="preview-title" class="font-heading text-headline-sm">Pratinjau tampilan Google</h2>
      <p class="mb-3 text-body-sm text-secondary">Pilih halaman. Judul, deskripsi, dan gambar dibaca dari halaman yang sedang tayang, jadi sama dengan yang dilihat Google. Batas aman: judul 30 sampai 60 karakter, deskripsi 70 sampai 160.</p>

      <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <label class="text-label-md text-secondary">Halaman
          <select v-model="path" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 pr-8 text-body-sm text-primary">
            <optgroup v-for="group in grouped" :key="group.type" :label="group.label">
              <option v-for="page in group.pages" :key="page.path" :value="page.path">{{ page.label }} ({{ page.path }})</option>
            </optgroup>
          </select>
        </label>
        <SegmentedControl v-model="lang" :options="LANGS" label="Bahasa halaman" />
      </div>

      <div class="mt-4" aria-live="polite">
        <p v-if="previewLoading" class="text-body-sm text-secondary">Membaca halaman...</p>
        <p v-else-if="previewError" role="alert" class="text-body-sm text-error">{{ previewError }}</p>
        <template v-else-if="preview">
          <p v-if="!preview.available" role="alert" class="rounded-2xl bg-error-surface p-4 text-body-sm text-on-error-surface">{{ preview.issues[0] }}</p>
          <template v-else>
            <div class="rounded-2xl border border-border bg-background p-4">
              <p class="truncate text-body-sm text-secondary">{{ shownUrl }}</p>
              <p class="mt-1 break-words font-heading text-headline-sm text-info">{{ preview.title || '(tanpa judul)' }}</p>
              <p class="mt-1 break-words text-body-sm">{{ preview.description || '(tanpa deskripsi)' }}</p>
            </div>

            <dl class="mt-3 grid gap-2 text-body-sm sm:grid-cols-2">
              <div class="flex justify-between gap-3"><dt class="text-secondary">Panjang judul</dt><dd class="font-semibold tabular-nums" :class="rangeTone(preview.title_length, 30, 60)">{{ preview.title_length }} karakter</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-secondary">Panjang deskripsi</dt><dd class="font-semibold tabular-nums" :class="rangeTone(preview.description_length, 70, 160)">{{ preview.description_length }} karakter</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-secondary">Judul utama (H1)</dt><dd class="min-w-0 break-words text-right">{{ preview.h1 || '-' }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-secondary">Boleh diindeks</dt><dd><StatusBadge :tone="preview.indexable ? 'ok' : 'warn'" size="sm">{{ preview.indexable ? 'Ya' : 'Tidak (noindex)' }}</StatusBadge></dd></div>
              <div class="flex justify-between gap-3 sm:col-span-2"><dt class="shrink-0 text-secondary">Canonical</dt><dd class="min-w-0 break-all text-right">{{ preview.canonical || '-' }}</dd></div>
              <div class="flex justify-between gap-3 sm:col-span-2"><dt class="shrink-0 text-secondary">Data terstruktur</dt><dd class="min-w-0 text-right">{{ preview.json_ld.length ? preview.json_ld.join(', ') : 'Tidak ada' }}</dd></div>
            </dl>

            <div v-if="preview.og_image" class="mt-3">
              <p class="text-label-md text-secondary">Gambar saat tautan dibagikan (WhatsApp, Instagram)</p>
              <img :src="preview.og_image" alt="Gambar pratinjau halaman" width="320" height="168" loading="lazy" class="mt-1 h-auto max-h-44 w-auto max-w-full rounded-xl border border-border object-cover">
            </div>

            <ul v-if="preview.issues.length" class="mt-3 space-y-1 text-body-sm">
              <li v-for="issue in preview.issues" :key="issue" class="flex items-start gap-2"><StatusBadge tone="warn" size="sm" class="shrink-0">Perhatian</StatusBadge><span>{{ issue }}</span></li>
            </ul>
            <p v-else class="mt-3 text-body-sm text-success">Tidak ada catatan untuk halaman ini.</p>
          </template>
        </template>
      </div>
    </section>

    <section :class="card" aria-labelledby="products-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="products-title" class="font-heading text-headline-sm">Kelengkapan produk</h2>
        <p class="text-body-sm text-secondary">{{ data.summary.ready }} dari {{ data.summary.total }} produk lengkap, skor rata-rata {{ data.summary.average_score }}.</p>
      </div>
      <p class="mb-3 text-body-sm text-secondary">Diurutkan dari yang paling perlu dilengkapi. Deskripsi diisi di halaman edit produk.</p>

      <p v-if="!data.products.length" class="rounded-2xl bg-secondary-tint-12 p-4 text-body-sm text-secondary">Belum ada produk yang tayang.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <caption class="sr-only">Kelengkapan konten SEO tiap produk tayang</caption>
          <thead><tr><th scope="col" :class="th">Produk</th><th scope="col" :class="th">Skor</th><th scope="col" :class="th">Yang perlu dilengkapi</th><th scope="col" :class="`${th} text-right`"><span class="sr-only">Aksi</span></th></tr></thead>
          <tbody>
            <tr v-for="row in data.products" :key="row.id" class="border-t border-border">
              <th scope="row" :class="`${td} text-left font-medium`">{{ row.name }}<span class="block text-label-md font-normal text-secondary">{{ row.category || 'Tanpa kategori' }}, {{ row.photos }} foto</span></th>
              <td :class="td"><StatusBadge :tone="scoreTone(row.score)" size="sm">{{ row.score }}</StatusBadge></td>
              <td :class="td"><span v-if="!row.issues.length" class="text-success">Lengkap</span><ul v-else class="list-disc pl-4"><li v-for="issue in row.issues" :key="issue">{{ issue }}</li></ul></td>
              <td :class="`${td} whitespace-nowrap text-right`">
                <IconAction icon="eye" label="Pratinjau Google" :subject="row.name" @click="showPreview(row)" />
                <IconAction icon="edit" label="Ubah produk" :subject="row.name" :to="{ name: 'AdminProductEdit', params: { id: row.id } }" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
