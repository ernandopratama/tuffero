<script setup>
import { computed } from 'vue';

const props = defineProps({
    gauge: { type: Object, required: true },
});

const N = 31;
const R = 130;
const CX = 160;
const CY = 150;
const dots = Array.from({ length: N }, (_, i) => {
    const a = Math.PI * (1 - i / (N - 1));
    return { i, x: CX + R * Math.cos(a), y: CY - R * Math.sin(a) };
});

const percent = computed(() => props.gauge.percent);
const filled = computed(() => (percent.value === null ? 0 : Math.round((percent.value / 100) * N)));
const label = computed(() => (percent.value === null ? `${props.gauge.title}: belum ada data` : `${props.gauge.title}: ${percent.value} persen`));
</script>

<template>
  <div>
    <div class="relative mx-auto w-full max-w-[22rem]" role="img" :aria-label="label">
      <svg viewBox="0 0 320 170" class="block w-full" aria-hidden="true">
        <circle
          v-for="d in dots"
          :key="d.i"
          :cx="d.x"
          :cy="d.y"
          r="5.5"
          :class="[d.i < filled ? 'fill-accent' : 'fill-border', 'animate-dot']"
          :style="{ animationDelay: `${d.i * 12}ms`, transformBox: 'fill-box', transformOrigin: 'center' }"
        />
      </svg>
      <div class="absolute inset-x-0 bottom-3 text-center">
        <p class="font-heading text-[2.5rem] font-semibold leading-none tracking-tight">{{ percent === null ? '–' : `${percent}%` }}</p>
        <p class="mt-1 text-label-md text-secondary">{{ percent === null ? 'Belum ada data' : gauge.caption }}</p>
      </div>
    </div>

    <dl class="mt-2 flex items-start justify-between border-t border-border pt-3">
      <div v-for="(m, i) in gauge.metrics" :key="m.label" :class="i === 1 ? 'text-right' : ''">
        <dt class="text-body-sm text-secondary">{{ m.label }}</dt>
        <dd class="font-heading text-headline-sm">{{ m.value }}</dd>
      </div>
    </dl>
    <p class="mt-3 rounded-2xl border border-border bg-background px-4 py-2.5 text-label-md">{{ gauge.note }}</p>
  </div>
</template>
