<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const form = reactive({ email: '', password: '' });
const error = ref('');
const loading = ref(false);

async function submit() {
    loading.value = true;
    error.value = '';
    try {
        if (await auth.login(form)) router.push({ name: 'Dashboard' });
    } catch (e) {
        error.value = e.response?.data?.message || 'Login gagal.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-background p-4 font-sans">
    <form class="w-full max-w-sm space-y-4 rounded-lg border border-border bg-surface p-6" @submit.prevent="submit">
      <h1 class="text-xl font-bold text-primary">Masuk</h1>
      <input v-model="form.email" type="email" required placeholder="Email" class="w-full rounded border border-border px-3 py-2">
      <input v-model="form.password" type="password" required placeholder="Password" class="w-full rounded border border-border px-3 py-2">
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button :disabled="loading" class="w-full rounded bg-accent px-4 py-2 font-semibold text-primary disabled:opacity-60">Masuk</button>
    </form>
  </div>
</template>
