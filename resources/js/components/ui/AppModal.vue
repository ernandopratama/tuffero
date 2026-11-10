<script setup>
import { computed, ref, useId } from 'vue';
import AdminIcon from '../AdminIcon.vue';

// Modal CRUD yang dipakai ulang. Warna dan ikon kepala menandai jenis aksi:
// create = hijau, read = biru, update = kuning, delete = merah.
const props = defineProps({
    variant: { type: String, default: 'read', validator: (v) => ['create', 'read', 'update', 'delete'].includes(v) },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    size: { type: String, default: 'lg', validator: (v) => ['md', 'lg', 'xl', '2xl'].includes(v) },
});
const emit = defineEmits(['close']);

const VARIANTS = {
    create: { icon: 'plus', head: 'bg-success-surface text-success', chip: 'bg-success text-surface' },
    read: { icon: 'eye', head: 'bg-info-surface text-info', chip: 'bg-info text-surface' },
    update: { icon: 'edit', head: 'bg-warning-surface text-warning', chip: 'bg-warning text-surface' },
    delete: { icon: 'trash', head: 'bg-error-surface text-on-error-surface', chip: 'bg-error text-surface' },
};
const SIZES = { md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl' };

const dialog = ref(null);
const titleId = useId();
const look = computed(() => VARIANTS[props.variant]);

const showModal = () => dialog.value.showModal();
const close = () => dialog.value.close();
// Klik pada latar (elemen dialog itu sendiri, bukan isinya) menutup modal.
const onBackdrop = (e) => { if (e.target === dialog.value) close(); };

defineExpose({ showModal, close });
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    class="m-auto w-[calc(100%-2rem)] overflow-hidden rounded-3xl border border-border bg-surface p-0 text-primary shadow-float backdrop:bg-primary/50 open:animate-pop"
    :class="SIZES[size]"
    @click="onBackdrop"
    @close="emit('close')"
  >
    <div class="flex max-h-[90vh] flex-col">
      <header class="flex shrink-0 items-center gap-3 px-5 py-4 sm:px-6" :class="look.head">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full" :class="look.chip"><AdminIcon :name="look.icon" /></span>
        <div class="min-w-0 flex-1">
          <h2 :id="titleId" class="truncate font-heading text-headline-sm">{{ title }}</h2>
          <p v-if="subtitle" class="truncate text-body-sm opacity-80">{{ subtitle }}</p>
        </div>
        <button
          type="button"
          class="flex size-10 shrink-0 items-center justify-center rounded-full transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
          aria-label="Tutup"
          @click="close"
        >
          <AdminIcon name="x" />
        </button>
      </header>

      <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
        <slot />
      </div>

      <footer v-if="$slots.footer" class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-border bg-background/60 px-5 py-3 sm:px-6">
        <slot name="footer" />
      </footer>
    </div>
  </dialog>
</template>
