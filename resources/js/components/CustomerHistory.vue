<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';

// Riwayat satu pelanggan (pesanan lain, refund, pembatalan dengan HP atau email yang sama), terutama untuk pelanggan tamu.
// url = endpoint riwayat untuk satu data, mis. /admin/orders/1/customer-history. Hanya nomor, tanggal, dan status.
const props = defineProps({ url: { type: String, required: true } });
// Dipanggil sebelum berpindah halaman supaya jendela rincian induk bisa ditutup.
const emit = defineEmits(['navigate']);

const auth = useAuthStore();
const router = useRouter();
const groups = ref([]);
const loading = ref(false);
const error = ref('');

const total = computed(() => groups.value.reduce((sum, group) => sum + group.rows.length, 0));

async function load() {
    if (!auth.can('search.quick')) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(props.url);
        groups.value = data.data.groups;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function go(group, row) {
    emit('navigate');
    router.push({ name: group.route, query: { cari: row.search } });
}

watch(() => props.url, load);
onMounted(load);
</script>

<template>
  <section v-if="auth.can('search.quick')" class="rounded-2xl border border-border p-4" aria-labelledby="history-title">
    <h3 id="history-title" class="flex items-center gap-2 text-label-md"><AdminIcon name="user" :size="16" />Riwayat pelanggan<span v-if="!loading && !error" class="text-label-sm text-secondary">({{ total }} data lain)</span></h3>
    <p class="mt-1 text-body-sm text-secondary">Data lain dengan nomor HP atau email yang sama. Berguna untuk pelanggan tamu yang tidak punya akun.</p>

    <p v-if="loading" role="status" class="mt-space-sm text-body-sm text-secondary">Memuat riwayat...</p>
    <p v-else-if="error" role="alert" class="mt-space-sm text-body-sm text-error">{{ error }}</p>
    <p v-else-if="!total" class="mt-space-sm text-body-sm text-secondary">Tidak ada data lain untuk pelanggan ini.</p>

    <template v-else>
      <div v-for="group in groups.filter((g) => g.rows.length)" :key="group.key" class="mt-space-sm">
        <h4 class="text-label-sm text-secondary">{{ group.label }}</h4>
        <ul class="mt-1 space-y-1">
          <li v-for="row in group.rows" :key="row.id">
            <button type="button" class="flex w-full items-center justify-between gap-3 rounded-xl px-2 py-1.5 text-left transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="go(group, row)">
              <span class="min-w-0">
                <span class="block font-mono text-label-md">{{ row.title }}</span>
                <span class="block truncate text-body-sm text-secondary">{{ row.subtitle }}</span>
              </span>
              <span class="shrink-0 rounded-full bg-secondary-tint-12 px-2.5 py-0.5 text-label-sm">{{ row.status }}</span>
            </button>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
