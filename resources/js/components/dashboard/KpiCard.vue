<script setup>
import { computed } from 'vue';
import AdminIcon from '../AdminIcon.vue';
import { rupiahCompact } from '../../utils/orderLabels';
import { BUBBLE } from './tones';

const props = defineProps({
    kpi: { type: Object, required: true },
    index: { type: Number, default: 0 },
});

const value = computed(() => (props.kpi.format === 'rupiah' ? rupiahCompact(props.kpi.value) : Number(props.kpi.value).toLocaleString('id-ID')));
const delta = computed(() => {
    const d = props.kpi.delta;
    if (d === null || d === undefined) return null;
    return { text: `${d > 0 ? '+' : ''}${d.toLocaleString('id-ID')}%`, cls: d >= 0 ? 'text-success' : 'text-error' };
});
// Nada peringatan hanya bermakna bila ada yang perlu ditangani; nol berarti tenang.
const tone = computed(() => (['bad', 'warn'].includes(props.kpi.tone) && Number(props.kpi.value) === 0 ? 'neutral' : props.kpi.tone));
const bars = computed(() => {
    const spark = props.kpi.spark;
    if (!spark?.length) return [];
    const max = Math.max(...spark, 1);
    return spark.map((v) => Math.max(4, Math.round((v / max) * 28)));
});
</script>

<template>
  <article class="flex animate-rise flex-col sm:min-h-[11rem] gap-2.5 rounded-card border border-border bg-surface p-5 shadow-card" :style="{ animationDelay: `${60 + index * 50}ms` }" :aria-label="kpi.label">
    <div class="flex items-start justify-between gap-2">
      <h3 class="text-body-sm font-medium text-secondary">{{ kpi.label }}</h3>
      <span class="flex size-8 shrink-0 items-center justify-center rounded-full" :class="BUBBLE[tone] || BUBBLE.neutral"><AdminIcon :name="kpi.icon" :size="16" /></span>
    </div>
    <p class="flex flex-wrap items-baseline gap-x-2 font-heading text-[2.25rem] font-semibold leading-none tracking-tight">
      {{ value }}
      <span v-if="delta" class="font-sans text-label-md tracking-normal" :class="delta.cls">{{ delta.text }}</span>
    </p>
    <div v-if="bars.length" class="flex h-7 items-end gap-1.5" aria-hidden="true">
      <span
        v-for="(h, i) in bars"
        :key="i"
        class="w-2 origin-bottom animate-grow rounded-[3px]"
        :class="i === bars.length - 1 ? 'bg-accent' : 'bg-accent-tint-20'"
        :style="{ height: `${h}px`, animationDelay: `${200 + i * 30}ms` }"
      ></span>
    </div>
    <p class="mt-auto text-body-sm text-secondary">{{ kpi.foot }}</p>
  </article>
</template>
