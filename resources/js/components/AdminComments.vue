<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { formatDate } from '../utils/datetime';
import { useAuthStore } from '../stores/auth';

// url = endpoint komentar target, mis. /admin/vouchers/1/comments.
const props = defineProps({ url: { type: String, required: true } });

const auth = useAuthStore();
const comments = ref([]);
const body = ref('');
const loading = ref(true);
const saving = ref(false);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(props.url);
        comments.value = data.data.comments;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        const { data } = await api.post(props.url, { body: body.value });
        comments.value = [data.data, ...comments.value];
        body.value = '';
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.body?.[0] || message;
    } finally {
        saving.value = false;
    }
}

watch(() => props.url, load);
onMounted(load);
</script>

<template>
  <section class="border-t border-border pt-space-md">
    <h3 class="font-heading text-headline-sm">Catatan internal</h3>
    <p class="mt-1 text-body-sm text-secondary">Hanya untuk staf. Catatan tidak bisa diedit atau dihapus.</p>

    <form v-if="auth.can('comments.create')" class="mt-space-sm" @submit.prevent="submit">
      <label class="block text-label-md">
        Tulis catatan
        <textarea v-model="body" rows="2" required maxlength="2000" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
      </label>
      <button :disabled="saving" class="mt-space-sm rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Mengirim...' : 'Kirim catatan' }}</button>
    </form>

    <p v-if="error" role="alert" class="mt-space-sm text-body-sm text-error">{{ error }}</p>
    <p v-if="loading" role="status" class="mt-space-sm text-body-sm text-secondary">Memuat catatan...</p>
    <p v-else-if="!comments.length && !error" class="mt-space-sm text-body-sm text-secondary">Belum ada catatan.</p>
    <ul v-else class="mt-space-sm">
      <li v-for="comment in comments" :key="comment.id" class="border-t border-border py-space-sm text-body-sm first:border-t-0">
        <span class="text-label-md">{{ comment.author }}</span>
        <span class="ml-space-sm text-secondary">{{ formatDate(comment.created_at) }}</span>
        <p class="mt-1 break-words whitespace-pre-line">{{ comment.body }}</p>
      </li>
    </ul>
  </section>
</template>
