<script setup>
import { tip } from '../../composables/useActionTip';

// Dipasang sekali di AdminLayout; dikendalikan oleh IconAction lewat useActionTip.
</script>

<template>
  <span
    class="pointer-events-none fixed z-[60] -translate-x-1/2 whitespace-nowrap rounded-xl bg-primary px-3 py-1.5 text-label-md text-background shadow-pop"
    :class="[
      tip.below ? 'origin-top' : '-translate-y-full origin-bottom',
      tip.show ? 'scale-100 opacity-100' : 'scale-[0.97] opacity-0',
      tip.instant ? 'transition-none' : 'transition-[opacity,scale] duration-[125ms] ease-out-strong',
    ]"
    :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
    role="presentation"
    aria-hidden="true"
  >{{ tip.label }}</span>
</template>
