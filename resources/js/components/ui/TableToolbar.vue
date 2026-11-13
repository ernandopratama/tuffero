<script setup>
import AdminIcon from '../AdminIcon.vue';

// Pencarian di pojok kanan, filter di kirinya (isi lewat slot, biasanya FilterSelect).
defineProps({
    placeholder: { type: String, default: 'Cari...' },
});
const search = defineModel({ type: String, default: '' });
</script>

<template>
  <div class="ml-auto flex max-w-full min-w-0 flex-row-reverse flex-wrap items-center gap-2 max-sm:ml-0 max-sm:w-full max-sm:flex-row max-sm:*:min-w-[calc(50%-0.25rem)] max-sm:*:flex-1" role="search">
    <label class="relative block w-full sm:w-64 max-sm:order-first max-sm:min-w-full">
      <span class="sr-only">{{ placeholder }}</span>
      <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"><AdminIcon name="search" :size="18" /></span>
      <input
        v-model="search"
        type="search"
        :placeholder="placeholder"
        class="h-10 w-full rounded-full border border-border bg-surface pl-10 pr-4 text-body-sm placeholder:text-secondary/70 focus:border-primary focus:outline-2 focus:outline-offset-1 focus:outline-primary"
      >
    </label>
    <slot />
  </div>
</template>
