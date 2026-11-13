<script setup>
import { computed, nextTick, ref } from 'vue';

// Kolom nominal rupiah: awalan "Rp" tetap di kiri, angka diberi titik ribuan saat diketik (40500 menjadi 40.500), dan huruf
// atau simbol lain langsung dibuang. Nilai yang dikirim ke v-model tetap angka biasa (40500), atau '' bila kosong.
// Atribut lain (required, id, class, aria-*) diteruskan ke <input>.
defineOptions({ inheritAttrs: false });
const model = defineModel({ type: [Number, String], default: '' });
const props = defineProps({ max: { type: Number, default: 999999999999 } });

const input = ref(null);
const format = (value) => (value === '' || value === null || value === undefined ? '' : Number(value).toLocaleString('id-ID'));
const shown = computed(() => format(model.value));

function onInput(event) {
    const el = event.target;
    // Posisi kursor diukur dalam jumlah angka di sebelah kirinya, supaya mengetik di tengah tidak melompat ke ujung.
    const digitsBefore = el.value.slice(0, el.selectionStart).replace(/\D/g, '').length;
    const digits = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, String(props.max).length);
    const next = digits === '' ? '' : Math.min(Number(digits), props.max);
    model.value = next;

    const text = format(next);
    el.value = text;
    nextTick(() => {
        let seen = 0;
        let pos = 0;
        while (pos < text.length && seen < digitsBefore) {
            if (/\d/.test(text[pos])) seen += 1;
            pos += 1;
        }
        el.setSelectionRange(pos, pos);
    });
}

// Angka murni yang sudah ditempel dari "Rp 40.500" atau "40,500" diterima karena hanya digit yang dipakai.
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
  <div class="relative mt-1">
    <span class="pointer-events-none absolute inset-y-0 left-0 grid w-12 place-items-center rounded-l-2xl border-r border-border bg-background/70 text-label-md text-secondary" aria-hidden="true">Rp</span>
    <input
      v-bind="$attrs"
      ref="input"
      :value="shown"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      placeholder="0"
      class="block w-full rounded-2xl border border-secondary bg-surface py-2 pl-16 pr-3 text-right text-body-md font-normal tabular-nums focus:outline-2 focus:outline-primary disabled:opacity-70"
      @input="onInput"
    >
  </div>
</template>
