<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import AdminIcon from './AdminIcon.vue';

// Preferensi notifikasi (D-090): jenis peristiwa yang tampil di lonceng. Yang ditampilkan hanya yang relevan untuk peran akun ini.
// Peringatan keamanan (perangkat baru) dan sebutan (@) tidak bisa dimatikan.
const events = ref([]);
const saved = ref([]);
const loading = ref(true);
const loadError = ref('');
const busy = ref(false);
const error = ref('');
const ok = ref(false);

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

const mutedNow = computed(() => events.value.filter((event) => event.muted).map((event) => event.key).sort());
const dirty = computed(() => mutedNow.value.join('|') !== [...saved.value].sort().join('|'));

function apply(list) {
    events.value = list;
    saved.value = list.filter((event) => event.muted).map((event) => event.key);
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/profile/notifications');
        apply(data.data.events);
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function save() {
    busy.value = true;
    error.value = '';
    ok.value = false;
    try {
        const { data } = await api.put('/admin/profile/notifications', { muted: mutedNow.value });
        apply(data.data.events);
        ok.value = true;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
  <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 310ms" aria-labelledby="notif-title">
    <div class="flex items-start gap-3">
      <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="bell" :size="18" /></span>
      <div><h2 id="notif-title" class="font-heading text-headline-sm">Preferensi notifikasi</h2><p class="text-body-sm text-secondary">Pilih peristiwa yang muncul di lonceng. Suara diatur dengan tombol speaker di lonceng.</p></div>
    </div>

    <div v-if="loading" role="status" aria-label="Memuat preferensi" class="mt-4 space-y-2">
      <div v-for="n in 3" :key="n" class="h-12 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    </div>
    <div v-else-if="loadError" role="alert" class="mt-4 flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
      <AdminIcon name="alert" /><span class="flex-1">{{ loadError }}</span>
      <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
    </div>
    <p v-else-if="!events.length" class="mt-4 rounded-2xl border border-dashed border-border p-4 text-center text-body-sm text-secondary">Peran akun ini tidak menerima notifikasi operasional.</p>
    <form v-else class="mt-4 space-y-2" @submit.prevent="save">
      <label v-for="event in events" :key="event.key" class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="event.muted ? 'border-border' : 'border-primary bg-accent-tint-12'">
        <input v-model="event.muted" type="checkbox" class="size-4 accent-primary" :true-value="false" :false-value="true" @change="ok = false">
        <span class="flex-1 text-label-md">{{ event.label }}</span>
        <span class="text-body-sm" :class="event.muted ? 'text-secondary' : 'text-success'">{{ event.muted ? 'Dimatikan' : 'Aktif' }}</span>
      </label>
      <p class="flex items-start gap-2 pt-1 text-body-sm text-secondary"><AdminIcon name="shield" :size="14" class="mt-0.5 shrink-0" />Peringatan keamanan, seperti masuk dari perangkat baru, dan sebutan (@) dari rekan selalu diterima.</p>
      <p v-if="error" role="alert" class="text-body-sm text-error">{{ error }}</p>
      <div class="flex flex-wrap items-center gap-3 pt-1">
        <button :disabled="busy || !dirty" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover disabled:bg-secondary-tint-12 disabled:text-secondary ${press}`"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Simpan' }}</button>
        <p v-if="ok && !dirty" role="status" class="inline-flex items-center gap-1 text-body-sm text-success"><AdminIcon name="check" :size="16" />Tersimpan.</p>
      </div>
    </form>
  </section>
</template>
