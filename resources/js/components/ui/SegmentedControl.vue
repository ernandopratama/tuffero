<script setup>
import { useId } from 'vue';
import AdminIcon from '../AdminIcon.vue';

// Pilihan tunggal berbentuk tombol (radio sungguhan, jadi papan ketik dan pembaca layar bekerja): pil kecil untuk pilihan pendek
// (platform, durasi) atau kartu besar berikon untuk pilihan yang perlu penjelasan (jenis draf, sumber visual).
// options: [{ value, label, hint?, icon? }]. Nilai dibandingkan dengan ===, jadi angka dan teks kosong dibedakan.
const model = defineModel({ type: [String, Number], default: '' });
defineProps({
    options: { type: Array, required: true },
    label: { type: String, required: true },
    variant: { type: String, default: 'pill', validator: (v) => ['pill', 'card'].includes(v) },
    disabled: { type: Boolean, default: false },
});
const name = useId();
</script>

<template>
  <div role="radiogroup" :aria-label="label" class="grid gap-2" :class="variant === 'card' ? 'sm:grid-cols-2' : 'grid-flow-col auto-cols-fr max-sm:grid-flow-row max-sm:grid-cols-2'">
    <label
      v-for="option in options"
      :key="String(option.value)"
      class="seg relative flex min-h-11 select-none items-center gap-3 border text-left"
      :class="[
        variant === 'card' ? 'rounded-2xl px-4 py-3' : 'justify-center whitespace-nowrap rounded-full px-4 py-2 text-center text-label-md',
        model === option.value ? 'seg-on border-primary bg-accent-tint-15' : 'border-border bg-surface',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
      ]"
    >
      <input v-model="model" type="radio" :name="name" :value="option.value" :disabled="disabled" class="peer sr-only">
      <span v-if="variant === 'card' && option.icon" class="seg-icon grid size-10 shrink-0 place-items-center rounded-full" :class="model === option.value ? 'bg-primary text-background' : 'bg-secondary-tint-12 text-secondary'"><AdminIcon :name="option.icon" :size="20" /></span>
      <span class="min-w-0">
        <span class="block" :class="variant === 'card' ? 'text-label-md' : ''">{{ option.label }}</span>
        <span v-if="variant === 'card' && option.hint" class="block text-body-sm font-normal text-secondary">{{ option.hint }}</span>
      </span>
      <!-- Cincin fokus papan ketik di luar kotak, supaya tidak terpotong tepi modal. -->
      <span class="pointer-events-none absolute -inset-0.5 rounded-[inherit] ring-primary peer-focus-visible:ring-2" aria-hidden="true"></span>
    </label>
  </div>
</template>

<style scoped>
/* Hanya warna latar dan garis yang bertransisi (bukan "all"), dan tekan memberi umpan balik 97%. */
.seg {
  transition: background-color 160ms cubic-bezier(0.23, 1, 0.32, 1), border-color 160ms cubic-bezier(0.23, 1, 0.32, 1), transform 160ms cubic-bezier(0.23, 1, 0.32, 1);
}
.seg-icon {
  transition: background-color 160ms cubic-bezier(0.23, 1, 0.32, 1), color 160ms cubic-bezier(0.23, 1, 0.32, 1);
}
.seg:active:not(:has(input:disabled)) {
  transform: scale(0.97);
}
@media (hover: hover) and (pointer: fine) {
  .seg:not(.seg-on):hover:not(:has(input:disabled)) {
    border-color: var(--color-secondary);
  }
}
@media (prefers-reduced-motion: reduce) {
  .seg,
  .seg-icon {
    transition: none;
  }
}
</style>
