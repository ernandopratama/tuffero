<script setup>
import { TONE_CLASS } from '../../utils/orderLabels';

// Lencana status bertitik yang dipakai semua daftar admin (satu bentuk, satu warna per tone).
// tone: ok | info | warn | bad | neutral. size: md (bawaan) atau sm. dot=false menghilangkan titik. Kelas tambahan lewat atribut class.
defineProps({
    tone: { type: String, default: 'neutral' },
    size: { type: String, default: 'md', validator: (v) => ['md', 'sm'].includes(v) },
    dot: { type: Boolean, default: true },
});
// Kelas statis (bukan hasil rakitan string) supaya Tailwind mendeteksinya.
const DOT = { ok: 'bg-success', info: 'bg-info', warn: 'bg-warning', bad: 'bg-error', neutral: 'bg-secondary' };
</script>

<template>
  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 text-label-sm" :class="[size === 'sm' ? 'py-0.5' : 'py-1', TONE_CLASS[tone] || TONE_CLASS.neutral]">
    <i v-if="dot" class="size-1.5 rounded-full" :class="DOT[tone] || DOT.neutral" aria-hidden="true"></i><slot />
  </span>
</template>
