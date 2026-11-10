<script setup>
import { computed, ref } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import AdminIcon from '../AdminIcon.vue';

// Foto profil akun sendiri: pilih berkas langsung terunggah, dan bisa dihapus (kembali ke inisial).
// Server memotong persegi dan menyimpan WebP; di sini hanya pemeriksaan ukuran supaya pesan error cepat.
const props = defineProps({
    src: { type: String, default: null },
    name: { type: String, default: '' },
    size: { type: String, default: 'size-20' },
});
const emit = defineEmits(['changed']);

const MAX_BYTES = 2 * 1024 * 1024;
const input = ref(null);
const busy = ref(false);
const error = ref('');

const initials = computed(() => (props.name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

async function onPick(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;
    error.value = '';
    if (file.size > MAX_BYTES) {
        error.value = 'Ukuran foto maksimal 2 MB.';
        return;
    }
    busy.value = true;
    try {
        const body = new FormData();
        body.append('image', file);
        const { data } = await api.post('/admin/profile/avatar', body, { toast: 'Foto profil diganti.' });
        emit('changed', data.data);
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.image?.[0] || message;
    } finally {
        busy.value = false;
    }
}

async function remove() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await api.delete('/admin/profile/avatar', { toast: 'Foto profil dihapus.' });
        emit('changed', data.data);
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-4">
    <span class="relative shrink-0 overflow-hidden rounded-full bg-primary font-heading text-headline-sm text-accent" :class="size" aria-hidden="true">
      <img v-if="src" :src="src" alt="" class="size-full object-cover">
      <span v-else class="grid size-full place-items-center">{{ initials }}</span>
      <span v-if="busy" class="absolute inset-0 grid place-items-center bg-primary/60"><span class="size-5 animate-spin rounded-full border-2 border-accent border-t-transparent"></span></span>
    </span>
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-2">
        <label :class="`inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary ${press}`" :aria-disabled="busy">
          <AdminIcon name="plus" :size="16" />{{ src ? 'Ganti foto' : 'Unggah foto' }}
          <input ref="input" type="file" accept="image/jpeg,image/png" class="sr-only" :disabled="busy" @change="onPick">
        </label>
        <button v-if="src" type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="remove"><AdminIcon name="trash" :size="16" />Hapus foto</button>
      </div>
      <p class="mt-2 text-body-sm text-secondary">JPG, JPEG, atau PNG, maksimal 2 MB. Dipotong persegi otomatis.</p>
      <p v-if="error" role="alert" class="mt-1 flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ error }}</p>
    </div>
  </div>
</template>
