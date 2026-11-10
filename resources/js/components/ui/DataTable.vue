<script setup>
import { computed } from 'vue';
import AdminIcon from '../AdminIcon.vue';

// Tabel admin yang dipakai ulang: kartu modern, kerangka saat memuat, keadaan error dan kosong, serta penomoran halaman.
// Isi sel dapat diganti lewat slot `cell-<key>` (props: row). Kolom: { key, label, class?, cellClass?, srOnly? }.
// `class` berlaku untuk judul dan sel; `cellClass` (bila ada) menggantikannya khusus untuk sel isi.
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    meta: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0 }) },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    caption: { type: String, required: true },
    emptyText: { type: String, default: 'Belum ada data.' },
    rowKey: { type: String, default: 'id' },
    minWidth: { type: String, default: 'min-w-[720px]' },
    pageSizes: { type: Array, default: () => [10, 25, 50, 75, 100] },
});
const page = defineModel('page', { type: Number, default: 1 });
const perPage = defineModel('perPage', { type: Number, default: 25 });
const emit = defineEmits(['retry']);

const from = computed(() => (props.meta.total ? (props.meta.current_page - 1) * perPage.value + 1 : 0));
const to = computed(() => Math.min(props.meta.total, props.meta.current_page * perPage.value));

// Nomor halaman: awal, akhir, dan dua di sekitar halaman aktif; celah ditandai null.
const pages = computed(() => {
    const last = props.meta.last_page;
    const keep = new Set([1, last, page.value - 1, page.value, page.value + 1].filter((n) => n >= 1 && n <= last));
    const sorted = [...keep].sort((a, b) => a - b);
    return sorted.flatMap((n, i) => (i && n - sorted[i - 1] > 1 ? [null, n] : [n]));
});
</script>

<template>
  <div class="overflow-hidden rounded-3xl border border-border bg-surface shadow-card">
    <div v-if="error" role="alert" class="m-4 flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <AdminIcon name="alert" />
      <span class="flex-1">{{ error }}</span>
      <button type="button" class="rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-primary" @click="emit('retry')">Coba lagi</button>
    </div>

    <template v-else>
      <div class="relative overflow-x-auto" :aria-busy="loading">
        <table class="w-full text-center text-body-sm" :class="minWidth">
          <caption class="sr-only">{{ caption }}</caption>
          <thead class="bg-background/70 text-label-sm uppercase tracking-wide text-secondary">
            <tr>
              <th v-for="c in columns" :key="c.key" scope="col" class="whitespace-nowrap px-5 py-3.5" :class="c.class">
                <span :class="c.srOnly ? 'sr-only' : ''">{{ c.label }}</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <template v-if="loading">
              <tr v-for="n in 5" :key="n" aria-hidden="true">
                <td v-for="c in columns" :key="c.key" class="px-5 py-4"><span class="block h-4 animate-pulse-soft rounded-full bg-secondary-tint-12" :class="c.srOnly ? 'mx-auto w-12' : 'mx-auto w-4/5'"></span></td>
              </tr>
            </template>
            <tr v-else-if="!rows.length">
              <td :colspan="columns.length" class="px-5 py-12 text-center text-secondary">{{ emptyText }}</td>
            </tr>
            <template v-else>
              <tr v-for="row in rows" :key="row[rowKey]" class="transition-colors duration-150 hover:bg-accent-tint-12">
                <td v-for="c in columns" :key="c.key" class="px-5 py-3.5" :class="c.cellClass ?? c.class">
                  <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] }}</slot>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-body-sm text-secondary">
        <p role="status">
          <template v-if="loading">Memuat data...</template>
          <template v-else>Menampilkan {{ from }}–{{ to }} dari {{ meta.total }}</template>
        </p>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
          <label class="flex items-center gap-2">
            Baris
            <select v-model.number="perPage" class="h-9 cursor-pointer rounded-full border border-border bg-surface px-3 text-body-sm text-primary focus:border-primary focus:outline-2 focus:outline-offset-1 focus:outline-primary">
              <option v-for="n in pageSizes" :key="n" :value="n">{{ n }}</option>
            </select>
          </label>

          <nav class="flex items-center gap-1" aria-label="Halaman">
            <button type="button" :disabled="page <= 1" class="flex size-9 items-center justify-center rounded-full border border-border text-primary transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40 disabled:hover:bg-transparent" aria-label="Halaman sebelumnya" @click="page -= 1">
              <AdminIcon name="chevron-down" :size="16" class="rotate-90" />
            </button>
            <template v-for="(n, i) in pages" :key="i">
              <span v-if="n === null" class="px-1" aria-hidden="true">…</span>
              <button
                v-else
                type="button"
                class="h-9 min-w-9 rounded-full px-3 text-label-md transition-colors focus-visible:outline-2 focus-visible:outline-primary"
                :class="n === page ? 'bg-accent text-primary' : 'text-primary hover:bg-accent-tint-15'"
                :aria-current="n === page ? 'page' : undefined"
                @click="page = n"
              >{{ n }}</button>
            </template>
            <button type="button" :disabled="page >= meta.last_page" class="flex size-9 items-center justify-center rounded-full border border-border text-primary transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40 disabled:hover:bg-transparent" aria-label="Halaman berikutnya" @click="page += 1">
              <AdminIcon name="chevron-down" :size="16" class="-rotate-90" />
            </button>
          </nav>
        </div>
      </div>
    </template>
  </div>
</template>
