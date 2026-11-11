<script setup>
import { computed, ref } from 'vue';

// Kode sekali pakai: enam kotak terpisah yang diisi satu per satu. Satu <input> transparan di atasnya menangani ketikan,
// tempel, dan pengisi otomatis (kode SMS atau email) sehingga tetap berupa satu kolom bagi pembaca layar dan password manager.
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    length: { type: Number, default: 6 },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
});

const input = ref(null);
const focused = ref(false);
const digits = computed(() => Array.from({ length: props.length }, (_, i) => model.value[i] || ''));
const active = computed(() => Math.min(model.value.length, props.length - 1));

function onInput(event) {
    // Tempelan "123 456" atau "123-456" tetap diterima: hanya angka yang dipakai.
    model.value = event.target.value.replace(/\D/g, '').slice(0, props.length);
    event.target.value = model.value;
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
  <div class="relative" @click="input.focus()">
    <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${length}, minmax(0, 1fr))` }" aria-hidden="true">
      <span
        v-for="(digit, i) in digits"
        :key="i"
        class="grid h-14 place-items-center rounded-2xl border bg-surface font-mono text-headline-sm transition-[border-color,box-shadow,background-color] duration-150"
        :class="[
          invalid ? 'border-error bg-error-surface/40' : focused && i === active && !disabled ? 'border-primary shadow-[0_0_0_3px_var(--color-accent-tint-20)]' : digit ? 'border-secondary' : 'border-border',
          disabled ? 'opacity-60' : '',
        ]"
      >
        <template v-if="digit">{{ digit }}</template>
        <i v-else-if="focused && i === active && !disabled" class="h-6 w-px bg-primary motion-safe:animate-pulse"></i>
      </span>
    </div>
    <input
      ref="input"
      :value="model"
      :disabled="disabled"
      :aria-label="label"
      :aria-invalid="invalid || undefined"
      inputmode="numeric"
      autocomplete="one-time-code"
      class="absolute inset-0 size-full cursor-text opacity-0"
      @input="onInput"
      @focus="focused = true"
      @blur="focused = false"
    >
  </div>
</template>
