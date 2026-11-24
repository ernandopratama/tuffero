<script setup>
import { computed } from 'vue';
import { sparkHeights } from '../../utils/contentMetrics';

// Batang tren views dari catatan harian (kumulatif, jadi naik terus). Hiasan: angkanya selalu tertulis di sebelahnya,
// jadi disembunyikan dari pembaca layar. Batang terakhir (terbaru) diberi warna aksen.
const props = defineProps({
    values: { type: Array, default: () => [] },
    height: { type: Number, default: 28 },
});
const bars = computed(() => sparkHeights(props.values, props.height));
</script>

<template>
  <div v-if="bars.length" class="flex items-end gap-1" :style="{ height: `${height}px` }" aria-hidden="true">
    <span
      v-for="(h, i) in bars"
      :key="i"
      class="w-1.5 origin-bottom animate-grow rounded-[2px]"
      :class="i === bars.length - 1 ? 'bg-accent' : 'bg-accent-tint-20'"
      :style="{ height: `${h}px`, animationDelay: `${120 + i * 25}ms` }"
    ></span>
  </div>
</template>
