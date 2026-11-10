<script setup>
import { onMounted, ref } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { actionLabel } from '../utils/auditActions';
import { formatDate, timeAgo } from '../utils/datetime';
import AdminIcon from './AdminIcon.vue';

// Riwayat aktivitas saya (D-090): tindakan yang tercatat di Audit Log atas nama akun ini. Isi perubahan tidak ditampilkan.
const items = ref([]);
const meta = ref({ current_page: 0, last_page: 1, total: 0 });
const loading = ref(true);
const more = ref(false);
const error = ref('');

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

async function load(page = 1) {
    if (page === 1) loading.value = true;
    else more.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/profile/activity', { params: { page } });
        items.value = page === 1 ? data.data.items : [...items.value, ...data.data.items];
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
        more.value = false;
    }
}

onMounted(() => load());
</script>

<template>
  <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 260ms" aria-labelledby="activity-title">
    <div class="flex items-start gap-3">
      <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="clock" :size="18" /></span>
      <div><h2 id="activity-title" class="font-heading text-headline-sm">Riwayat aktivitas saya</h2><p class="text-body-sm text-secondary">Tindakan yang tercatat atas nama akun ini. Isi perubahan tidak ditampilkan di sini.</p></div>
    </div>

    <div v-if="loading" role="status" aria-label="Memuat riwayat" class="mt-4 space-y-2">
      <div v-for="n in 3" :key="n" class="h-12 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>
    <div v-else-if="error" role="alert" class="mt-4 flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <AdminIcon name="alert" /><span class="flex-1">{{ error }}</span>
      <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load()">Coba lagi</button>
    </div>
    <p v-else-if="!items.length" class="mt-4 rounded-2xl border border-dashed border-border p-4 text-center text-body-sm text-secondary">Belum ada aktivitas yang tercatat.</p>
    <template v-else>
      <ol class="mt-4 divide-y divide-border">
        <li v-for="item in items" :key="item.id" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2.5">
          <span class="text-label-md">{{ actionLabel(item.action) }}<span v-if="item.target" class="font-normal text-secondary"> · {{ item.target }}</span></span>
          <span class="text-body-sm text-secondary" :title="formatDate(item.at)">{{ timeAgo(item.at) }}<template v-if="item.ip"> · <span class="font-mono">{{ item.ip }}</span></template></span>
        </li>
      </ol>
      <div v-if="meta.current_page < meta.last_page" class="mt-3">
        <button type="button" :disabled="more" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="load(meta.current_page + 1)">{{ more ? 'Memuat...' : `Muat lebih banyak (${meta.total - items.length} lagi)` }}</button>
      </div>
    </template>
  </section>
</template>
