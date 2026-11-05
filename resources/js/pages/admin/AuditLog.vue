<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../../utils/api';
import { ACTIONS } from '../../utils/auditActions';
import { apiError } from '../../utils/apiError';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const action = ref('');
const page = ref(1);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/audit-logs', { params: { page: page.value, action: action.value || undefined } });
        items.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

watch(page, load);
watch(action, () => {
    if (page.value === 1) load();
    else page.value = 1;
});
onMounted(load);
</script>

<template>
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Audit Log</h1>
  <p class="mt-space-sm text-secondary">Riwayat perubahan penting. Catatan ini tidak bisa diubah dari dashboard.</p>

  <label class="mt-space-lg block max-w-xs text-label-md">
    Jenis aksi
    <select v-model="action" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
      <option value="">Semua</option>
      <option v-for="(label, key) in ACTIONS" :key="key" :value="key">{{ label }}</option>
    </select>
  </label>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat audit log...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!items.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada catatan.</p>
  <template v-else>
    <div class="mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[720px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Waktu</th>
            <th scope="col" class="px-4 py-3">Pelaku</th>
            <th scope="col" class="px-4 py-3">Aksi</th>
            <th scope="col" class="px-4 py-3">Objek</th>
            <th scope="col" class="px-4 py-3">Perubahan</th>
            <th scope="col" class="px-4 py-3">IP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="log in items" :key="log.id" class="border-b border-border align-top last:border-b-0">
            <td class="whitespace-nowrap px-4 py-3">{{ dateFormat.format(new Date(log.created_at)) }}</td>
            <td class="px-4 py-3">{{ log.actor || 'Sistem' }}</td>
            <td class="px-4 py-3">{{ ACTIONS[log.action] || log.action }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ log.target }}</td>
            <td class="max-w-xs px-4 py-3 break-words font-mono text-secondary">{{ log.changes ? JSON.stringify(log.changes) : '-' }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ log.ip_address || '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman audit log">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} catatan)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>
</template>
