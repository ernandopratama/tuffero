<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

async function logout() {
    await auth.logout();
    router.push({ name: 'Login' });
}
</script>

<template>
  <div class="min-h-screen bg-background font-sans text-secondary">
    <header class="flex items-center justify-between bg-primary px-6 py-3 text-background">
      <span class="font-bold">TUFFERO Admin</span>
      <button class="text-sm text-accent" @click="logout">Keluar</button>
    </header>
    <main class="p-6">
      <router-view></router-view>
    </main>
  </div>
</template>
