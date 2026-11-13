<script setup>
import { nextTick, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';
import AppModal from './ui/AppModal.vue';

// Pencarian cepat CS: satu kolom untuk nomor, nama, email, HP, atau resi. Hasil hanya nomor, nama, dan status;
// memilih hasil membuka halaman daftar yang sudah terfilter (?cari=).
const auth = useAuthStore();
const router = useRouter();
const dialog = ref(null);
const input = ref(null);
const query = ref('');
const groups = ref([]);
const loading = ref(false);
const error = ref('');
const searched = ref(false);
let timer = null;
let seq = 0;

function open() {
    query.value = '';
    groups.value = [];
    error.value = '';
    searched.value = false;
    dialog.value.showModal();
    nextTick(() => input.value?.focus());
}

watch(query, (value) => {
    clearTimeout(timer);
    const text = value.trim();
    if (text.length < 3) {
        groups.value = [];
        searched.value = false;
        return;
    }
    timer = setTimeout(() => run(text), 350);
});

async function run(text) {
    const mine = ++seq;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/quick-search', { params: { q: text } });
        if (mine !== seq) return; // jawaban lama dari ketikan sebelumnya dibuang
        groups.value = data.data.groups;
        searched.value = true;
    } catch (e) {
        if (mine === seq) error.value = apiError(e).message;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function go(group, row) {
    dialog.value.close();
    router.push({ name: group.route, query: { cari: row.search } });
}
</script>

<template>
  <template v-if="auth.can('search.quick')">
    <button
      type="button"
      class="flex size-10 shrink-0 items-center justify-center rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
      aria-label="Pencarian cepat"
      @click="open"
    >
      <AdminIcon name="search" />
    </button>

    <AppModal ref="dialog" variant="read" size="lg" title="Pencarian cepat" subtitle="Nomor pesanan, refund, atau pembatalan, nama, email, HP, atau resi">
      <label class="block text-label-md">
        Cari
        <input ref="input" v-model="query" type="search" maxlength="60" autocomplete="off" placeholder="Minimal 3 karakter" class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
      </label>

      <p v-if="error" role="alert" class="mt-3 text-body-sm text-error">{{ error }}</p>
      <p v-else-if="loading" role="status" class="mt-3 text-body-sm text-secondary">Mencari...</p>
      <p v-else-if="searched && !groups.length" role="status" class="mt-3 text-body-sm text-secondary">Tidak ada hasil.</p>

      <div v-for="group in groups" :key="group.key" class="mt-space-md">
        <h3 class="text-label-md text-secondary">{{ group.label }}</h3>
        <ul class="mt-1 space-y-1">
          <li v-for="row in group.rows" :key="row.id">
            <button type="button" class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-2 text-left transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="go(group, row)">
              <span class="min-w-0">
                <span class="block font-mono text-label-md">{{ row.title }}</span>
                <span class="block truncate text-body-sm text-secondary">{{ row.subtitle }}</span>
              </span>
              <span class="shrink-0 rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ row.status }}</span>
            </button>
          </li>
        </ul>
      </div>

      <template #footer>
        <button type="button" class="inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15" @click="dialog.close()">Tutup</button>
      </template>
    </AppModal>
  </template>
</template>
