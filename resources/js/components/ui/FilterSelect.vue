<script setup>
import { computed } from 'vue';
import AdminIcon from '../AdminIcon.vue';

// Satu filter dropdown. `options` boleh array [{ value, label }] atau objek { nilai: 'Label' }.
const props = defineProps({
    label: { type: String, required: true },
    options: { type: [Array, Object], required: true },
    allLabel: { type: String, default: 'Semua' },
});
const model = defineModel({ type: [String, Number], default: '' });

const list = computed(() => (Array.isArray(props.options)
    ? props.options
    : Object.entries(props.options).map(([value, label]) => ({ value, label }))));
</script>

<template>
  <label class="relative block">
    <span class="sr-only">{{ label }}</span>
    <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"><AdminIcon name="filter" :size="16" /></span>
    <select
      v-model="model"
      class="h-10 max-w-full cursor-pointer appearance-none rounded-full border bg-surface pl-9 pr-9 text-body-sm focus:border-primary focus:outline-2 focus:outline-offset-1 focus:outline-primary"
      :class="model === '' || model === null ? 'border-border text-secondary' : 'border-primary text-primary'"
    >
      <option value="">{{ label }}: {{ allLabel }}</option>
      <option v-for="o in list" :key="o.value" :value="o.value">{{ label }}: {{ o.label }}</option>
    </select>
    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-secondary"><AdminIcon name="chevron-down" :size="16" /></span>
  </label>
</template>
