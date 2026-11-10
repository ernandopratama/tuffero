<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../AdminIcon.vue';
import { pageMeta } from '../../utils/adminMenu';

// Judul halaman admin: ubin ikon + grup menu + judul + deskripsi. Ikon dan grup diambil dari menu menurut nama rute;
// halaman di luar menu (detail, akses ditolak) mengisinya lewat props. Sengaja tanpa animasi masuk: dilihat puluhan kali sehari.
const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: '' },
    group: { type: String, default: '' },
});

const route = useRoute();
const meta = computed(() => pageMeta(route.name));
const icon = computed(() => props.icon || meta.value?.icon || 'home');
const group = computed(() => props.group || meta.value?.group || '');
</script>

<template>
  <header class="flex min-w-0 items-start gap-space-md">
    <span class="relative grid size-12 shrink-0 place-items-center rounded-2xl border border-border bg-surface text-primary shadow-card lg:size-14" aria-hidden="true">
      <span class="absolute inset-1 rounded-xl bg-gradient-to-br from-accent-tint-20 to-transparent"></span>
      <AdminIcon :name="icon" :size="22" class="relative" />
    </span>
    <div class="min-w-0">
      <p v-if="group" class="flex items-center gap-2 text-label-sm uppercase tracking-wide text-secondary">
        <span class="h-px w-5 bg-accent" aria-hidden="true"></span>{{ group }}
      </p>
      <h1 class="break-words font-heading text-headline-lg-mobile lg:text-headline-lg">{{ title }}</h1>
      <p v-if="description || $slots.default" class="mt-1 max-w-3xl text-secondary"><slot>{{ description }}</slot></p>
    </div>
  </header>
</template>
