<script setup>
import { RouterLink } from 'vue-router';
import AdminIcon from '../AdminIcon.vue';
import { actionLabel } from '../../utils/auditActions';
import { timeAgo } from '../../utils/datetime';
import { ORDER_STATUS, ORDER_STATUS_TONE, rupiahCompact } from '../../utils/orderLabels';
import { ALERT, BUBBLE, CHIP } from './tones';

defineProps({
    list: { type: Object, required: true },
    index: { type: Number, default: 0 },
});

const title = (row) => (row.action ? actionLabel(row.action) : row.title);
const sub = (row) => [row.subtitle, row.at ? timeAgo(row.at) : null].filter(Boolean).join(' · ');
const chip = (row) => row.chip || (row.status ? { text: ORDER_STATUS[row.status] || row.status, tone: ORDER_STATUS_TONE[row.status] || 'neutral' } : null);
const target = (row) => (row.to ? { name: row.to, query: row.query } : undefined);
</script>

<template>
  <section class="flex animate-rise flex-col gap-3 rounded-card border border-border bg-surface p-6 shadow-card" :style="{ animationDelay: `${300 + index * 50}ms` }" :aria-labelledby="`list-${list.key}`">
    <header>
      <h2 :id="`list-${list.key}`" class="font-heading text-headline-sm">{{ list.title }}</h2>
      <p v-if="list.subtitle" class="text-body-sm text-secondary">{{ list.subtitle }}</p>
    </header>

    <p v-if="!list.rows.length" class="rounded-2xl bg-background px-4 py-6 text-center text-body-sm text-secondary">{{ list.empty }}</p>

    <ul v-else class="flex flex-col gap-1.5">
      <li v-for="(row, i) in list.rows" :key="i">
        <component
          :is="row.to ? RouterLink : 'div'"
          :to="target(row)"
          class="flex items-center gap-3 rounded-2xl p-2 transition-colors duration-150 ease-out"
          :class="[row.kind === 'alert' ? ALERT[row.tone] || ALERT.warn : '', row.to ? 'hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary' : '']"
        >
          <img v-if="row.photo" :src="row.photo" alt="" class="size-10 shrink-0 rounded-[10px] object-cover" loading="lazy" width="40" height="40">
          <span v-else class="flex size-9 shrink-0 items-center justify-center rounded-full" :class="BUBBLE[row.tone] || BUBBLE.neutral"><AdminIcon :name="row.icon" :size="16" /></span>
          <span class="min-w-0 flex-1">
            <span class="block text-label-md leading-snug" :class="row.kind === 'alert' || row.wrap ? '' : 'truncate'">{{ title(row) }}</span>
            <span v-if="sub(row)" class="block text-body-sm" :class="[row.kind === 'alert' ? '' : 'text-secondary', row.wrap ? 'leading-snug' : 'truncate']">{{ sub(row) }}</span>
          </span>
          <span v-if="row.amount !== null && row.amount !== undefined" class="shrink-0 text-label-md">{{ rupiahCompact(row.amount) }}</span>
          <span v-else-if="chip(row)" class="shrink-0 rounded-full px-2.5 py-1 text-label-md" :class="CHIP[chip(row).tone] || CHIP.neutral">{{ chip(row).text }}</span>
        </component>
      </li>
    </ul>

    <RouterLink
      v-if="list.more"
      :to="{ name: list.more.to, query: list.more.query }"
      class="mt-auto inline-flex h-10 items-center justify-center rounded-full border border-border bg-surface px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
    >{{ list.more.label }}</RouterLink>

    <slot name="footer" />
  </section>
</template>
