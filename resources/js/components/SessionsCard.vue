<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { timeAgo } from '../utils/datetime';
import AdminIcon from './AdminIcon.vue';
import AppModal from './ui/AppModal.vue';

// Sesi dan perangkat (D-089): tempat akun ini sedang masuk, dan keluar dari perangkat lain.
// ID sesi asli tidak sampai ke browser; tiap sesi dikenali lewat token turunan dari server.
const items = ref([]);
const supported = ref(true);
const loading = ref(true);
const loadError = ref('');
const busy = ref('');
const actionError = ref('');
const confirmDialog = ref(null);

const others = computed(() => items.value.filter((item) => !item.current));
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/profile/sessions');
        supported.value = data.data.supported;
        items.value = data.data.items;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function revoke(item) {
    busy.value = item.token;
    actionError.value = '';
    try {
        await api.delete(`/admin/profile/sessions/${item.token}`, { toast: 'Perangkat dikeluarkan.' });
        items.value = items.value.filter((entry) => entry.token !== item.token);
    } catch (e) {
        actionError.value = apiError(e).message;
        await load();
    } finally {
        busy.value = '';
    }
}

async function askRevokeAll() {
    actionError.value = '';
    confirmDialog.value.showModal();
    await nextTick();
}

async function revokeAll() {
    busy.value = 'all';
    try {
        const { data } = await api.delete('/admin/profile/sessions', { toast: 'Semua perangkat lain dikeluarkan.' });
        items.value = items.value.filter((entry) => entry.current);
        confirmDialog.value.close();
        return data;
    } catch (e) {
        actionError.value = apiError(e).message;
        confirmDialog.value.close();
        await load();
    } finally {
        busy.value = '';
    }
}

onMounted(load);
</script>

<template>
  <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 210ms" aria-labelledby="sessions-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="shield" :size="18" /></span>
        <div><h2 id="sessions-title" class="font-heading text-headline-sm">Sesi dan perangkat</h2><p class="text-body-sm text-secondary">Tempat akun ini sedang masuk. Keluarkan perangkat yang tidak Anda kenali.</p></div>
      </div>
      <button v-if="others.length" type="button" :disabled="busy === 'all'" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-error px-4 text-label-md text-error hover:bg-error-surface ${press}`" @click="askRevokeAll">
        <AdminIcon name="logout" :size="16" />Keluar dari semua perangkat lain
      </button>
    </div>

    <div v-if="loading" role="status" aria-label="Memuat sesi" class="mt-4 space-y-2">
      <div v-for="n in 2" :key="n" class="h-16 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>

    <div v-else-if="loadError" role="alert" class="mt-4 flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <AdminIcon name="alert" />
      <span class="flex-1">{{ loadError }}</span>
      <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
    </div>

    <p v-else-if="!supported" class="mt-4 flex items-start gap-2 rounded-2xl bg-background px-4 py-3 text-body-sm text-secondary">
      <AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />Daftar perangkat hanya tersedia bila sesi disimpan di database. Lingkungan ini memakai penyimpanan sesi lain.
    </p>

    <template v-else>
      <p v-if="actionError" role="alert" class="mt-4 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ actionError }}</p>
      <p v-if="!items.length" class="mt-4 rounded-2xl border border-dashed border-border p-4 text-center text-body-sm text-secondary">Tidak ada sesi aktif yang tercatat.</p>
      <ul v-else class="mt-4 grid gap-2">
        <li v-for="item in items" :key="item.token" class="flex items-center gap-3 rounded-2xl border p-3" :class="item.current ? 'border-accent bg-accent-tint-12' : 'border-border'">
          <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-surface text-primary" aria-hidden="true">
            <svg v-if="item.mobile" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h8a1 1 0 011 1v16a1 1 0 01-1 1H8a1 1 0 01-1-1V4a1 1 0 011-1zM11 18h2" /></svg>
            <svg v-else viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H4V5zM2 19h20" /></svg>
          </span>
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2 text-label-md">{{ item.browser }} di {{ item.os }}
              <span v-if="item.current" class="rounded-full bg-accent px-2 py-0.5 text-label-sm">Perangkat ini</span>
            </p>
            <p class="text-body-sm text-secondary"><span class="font-mono">{{ item.ip || 'IP tidak tercatat' }}</span> · {{ item.current ? 'aktif sekarang' : `terakhir aktif ${timeAgo(item.last_active)}` }}</p>
          </div>
          <button v-if="!item.current" type="button" :disabled="busy === item.token" :class="`inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="revoke(item)">
            <AdminIcon name="logout" :size="16" />{{ busy === item.token ? 'Keluar...' : 'Keluarkan' }}<span class="sr-only"> {{ item.browser }} di {{ item.os }}</span>
          </button>
        </li>
      </ul>
    </template>

    <AppModal ref="confirmDialog" variant="delete" size="md" title="Keluar dari semua perangkat lain?" :subtitle="`${others.length} perangkat`">
      <p class="text-body-sm">Semua perangkat lain langsung keluar dan harus masuk lagi. Perangkat yang sedang Anda pakai tetap masuk. Ini tercatat di Audit Log.</p>
      <template #footer>
        <button type="button" :disabled="busy === 'all'" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="confirmDialog.close()">Batal</button>
        <button type="button" :disabled="busy === 'all'" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="revokeAll"><AdminIcon name="logout" :size="16" />{{ busy === 'all' ? 'Memproses...' : 'Keluarkan semua' }}</button>
      </template>
    </AppModal>
  </section>
</template>
