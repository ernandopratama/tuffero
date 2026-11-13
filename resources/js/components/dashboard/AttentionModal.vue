<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import AdminIcon from '../AdminIcon.vue';
import AppModal from '../ui/AppModal.vue';
import { BUBBLE } from './tones';

// Rincian kartu "Perlu tindakan". Isinya persis daftar peringatan dari server untuk role yang sedang masuk,
// jadi tidak ada data di sini yang bukan hak role itu.
const props = defineProps({
    items: { type: Array, required: true },
    total: { type: Number, required: true },
});

const modal = ref(null);
const subtitle = computed(() => (props.total > 0 ? `${props.total.toLocaleString('id-ID')} hal menunggu tindakan` : 'Semua beres'));

defineExpose({ open: () => modal.value.showModal() });
</script>

<template>
  <AppModal ref="modal" variant="update" size="lg" title="Perlu tindakan" :subtitle="subtitle">
    <p v-if="!items.length" class="rounded-2xl bg-background px-4 py-8 text-center text-body-sm text-secondary">Tidak ada yang perlu ditindaklanjuti sekarang.</p>

    <ul v-else class="flex flex-col gap-2">
      <li v-for="item in items" :key="item.key">
        <RouterLink
          :to="{ name: item.to }"
          class="flex items-center gap-3 rounded-2xl border border-border p-3 transition-colors duration-150 ease-out hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          @click="modal.close()"
        >
          <span class="flex size-9 shrink-0 items-center justify-center rounded-full" :class="BUBBLE[item.tone] || BUBBLE.neutral"><AdminIcon :name="item.tone === 'bad' ? 'alert' : 'clock'" :size="16" /></span>
          <span class="min-w-0 flex-1">
            <span class="block text-label-md leading-snug">{{ item.text }}</span>
            <span v-if="item.overlap" class="block text-body-sm text-secondary">Sudah termasuk di peringatan lain, tidak dihitung dua kali di total.</span>
          </span>
          <span class="hidden shrink-0 items-center gap-1 text-label-md text-secondary sm:inline-flex">Buka<AdminIcon name="arrow-right" :size="14" /></span>
        </RouterLink>
      </li>
    </ul>

    <template #footer>
      <button
        type="button"
        class="inline-flex h-10 items-center rounded-full border border-border bg-surface px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
        @click="modal.close()"
      >
        Tutup
      </button>
    </template>
  </AppModal>
</template>
