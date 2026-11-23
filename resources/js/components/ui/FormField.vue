<script setup>
import { computed, nextTick, onMounted, onUpdated, ref, useId, watch } from 'vue';

// Label + kolom isian + pesan galat yang sama di semua form admin. Bungkus kolom isian biasa (input, select, atau textarea) di slot:
//   <FormField label="Kode" :error="fieldErrors.code"><input v-model="form.code" :class="field"></FormField>
// Saat ada galat, kolom pertama di slot diberi aria-invalid dan aria-describedby ke pesan galat, dan bingkainya merah (admin.css).
// Kelas tambahan (mis. sm:col-span-2) lewat atribut class. optional menambah "(opsional)"; hint tampil selama belum ada galat.
const props = defineProps({
    label: { type: String, required: true },
    error: { type: [String, Array], default: '' },
    hint: { type: String, default: '' },
    optional: { type: Boolean, default: false },
});

const root = ref(null);
const errorId = useId();
const message = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error) || '');

function wire() {
    const control = root.value?.querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), select, textarea');
    if (!control) return;
    if (message.value) {
        control.setAttribute('aria-invalid', 'true');
        control.setAttribute('aria-describedby', errorId);
    } else {
        control.removeAttribute('aria-invalid');
        control.removeAttribute('aria-describedby');
    }
}

onMounted(wire);
onUpdated(wire);
watch(message, () => nextTick(wire));
</script>

<template>
  <label ref="root" class="block text-label-md">
    {{ label }}<span v-if="optional" class="font-normal text-secondary"> (opsional)</span>
    <slot />
    <span v-if="hint && !message" class="mt-1 block text-body-sm font-normal text-secondary">{{ hint }}</span>
    <span v-if="message" :id="errorId" role="alert" class="mt-1 block text-body-sm font-normal text-error">{{ message }}</span>
  </label>
</template>
