<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { formatDate } from '../utils/datetime';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';

// Penanda tindak lanjut CS pada satu data ("hubungi lagi besok"). Hanya pengingat internal, bukan tiket.
// url = endpoint penanda untuk satu data, mis. /admin/refunds/1/follow-ups.
const props = defineProps({ url: { type: String, required: true } });

const auth = useAuthStore();
const items = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const note = ref('');
const tomorrow = () => new Date(Date.now() + 86400000 + 7 * 3600000).toISOString().slice(0, 10);
const dueOn = ref(tomorrow());

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60';
const open = computed(() => items.value.filter((item) => !item.done_at));
const done = computed(() => items.value.filter((item) => item.done_at));

async function load() {
    if (!auth.can('followups.manage')) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(props.url);
        items.value = data.data.items;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function add() {
    saving.value = true;
    error.value = '';
    try {
        await api.post(props.url, { note: note.value, due_on: dueOn.value });
        note.value = '';
        dueOn.value = tomorrow();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.note?.[0] || fields.due_on?.[0] || message;
    } finally {
        saving.value = false;
    }
}

async function finish(item) {
    error.value = '';
    try {
        await api.post(`/admin/follow-ups/${item.id}/done`);
        await load();
    } catch (e) {
        error.value = apiError(e).message;
    }
}

watch(() => props.url, load);
onMounted(load);
</script>

<template>
  <section v-if="auth.can('followups.manage')" class="rounded-2xl border border-border p-4" aria-labelledby="followup-title">
    <h3 id="followup-title" class="flex items-center gap-2 text-label-md"><AdminIcon name="clock" :size="16" />Tindak lanjut<span v-if="open.length" class="text-label-sm text-secondary">({{ open.length }} belum selesai)</span></h3>
    <p class="mt-1 text-body-sm text-secondary">Pengingat untuk tim CS, muncul di Ringkasan. Tidak bisa diedit atau dihapus; tandai selesai bila sudah ditangani.</p>

    <ul v-if="open.length" class="mt-space-sm space-y-2">
      <li v-for="item in open" :key="item.id" class="flex items-start justify-between gap-3 rounded-xl bg-background/70 p-3 text-body-sm">
        <span class="min-w-0">
          <span class="block break-words">{{ item.note }}</span>
          <span class="block text-secondary" :class="item.is_overdue ? 'text-error' : ''">{{ item.is_overdue ? 'Lewat · ' : '' }}Tanggal {{ formatDate(item.due_on) }} · {{ item.created_by }}</span>
        </span>
        <button type="button" :class="`inline-flex min-h-9 shrink-0 items-center gap-1 rounded-full border border-secondary px-3 text-label-md hover:bg-accent-tint-15 ${press}`" @click="finish(item)"><AdminIcon name="check" :size="14" />Selesai</button>
      </li>
    </ul>
    <p v-else-if="!loading" class="mt-space-sm text-body-sm text-secondary">Belum ada tindak lanjut.</p>

    <form class="mt-space-sm grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end" @submit.prevent="add">
      <label class="block text-label-md">
        Catatan
        <input v-model="note" required maxlength="200" placeholder="Mis. hubungi lagi soal video unboxing" class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
      </label>
      <label class="block text-label-md">
        Tanggal
        <input v-model="dueOn" type="date" required class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
      </label>
      <button :disabled="saving" :class="`inline-flex min-h-10 items-center justify-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`">{{ saving ? 'Menyimpan...' : 'Tambah' }}</button>
    </form>
    <p v-if="error" role="alert" class="mt-2 text-body-sm text-error">{{ error }}</p>

    <details v-if="done.length" class="mt-space-sm text-body-sm text-secondary">
      <summary class="cursor-pointer">Selesai baru-baru ini ({{ done.length }})</summary>
      <ul class="mt-2 space-y-1">
        <li v-for="item in done" :key="item.id" class="break-words">{{ item.note }} · selesai {{ formatDate(item.done_at) }}</li>
      </ul>
    </details>
  </section>
</template>
