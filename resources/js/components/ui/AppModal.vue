<script setup>
import { computed, nextTick, ref, useId } from 'vue';
import AdminIcon from '../AdminIcon.vue';

// Modal CRUD yang dipakai ulang. Warna dan ikon kepala menandai jenis aksi:
// create = hijau, read = biru, update = kuning, delete = merah.
const props = defineProps({
    variant: { type: String, default: 'read', validator: (v) => ['create', 'read', 'update', 'delete'].includes(v) },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    size: { type: String, default: 'lg', validator: (v) => ['md', 'lg', 'xl', '2xl', '3xl', '4xl'].includes(v) },
    // Modal Tambah dan Ubah bertanya dulu sebelum membuang isian yang sudah diubah (tombol Tutup, klik latar, atau Esc). Matikan dengan :guard="false".
    guard: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const VARIANTS = {
    create: { icon: 'plus', head: 'bg-success-surface text-success', chip: 'bg-success text-surface' },
    read: { icon: 'eye', head: 'bg-info-surface text-info', chip: 'bg-info text-surface' },
    update: { icon: 'edit', head: 'bg-warning-surface text-warning', chip: 'bg-warning text-surface' },
    delete: { icon: 'trash', head: 'bg-error-surface text-on-error-surface', chip: 'bg-error text-surface' },
};
const SIZES = { md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-6xl' };

const dialog = ref(null);
const stay = ref(null);
const titleId = useId();
const look = computed(() => VARIANTS[props.variant]);

// touched: pengguna sudah mengetik atau memilih sesuatu di dalam modal. asking: pertanyaan "buang isian?" sedang tampil.
const touched = ref(false);
const asking = ref(false);
const guarded = computed(() => props.guard && ['create', 'update'].includes(props.variant));

const showModal = () => {
    touched.value = false;
    asking.value = false;
    dialog.value.showModal();
};
// close() dipakai halaman setelah simpan berhasil atau tombol Batal, jadi tidak pernah bertanya.
const close = () => dialog.value.close();
// Tombol X, klik latar, dan Esc lewat sini: bertanya bila ada isian yang diubah.
const requestClose = () => {
    if (guarded.value && touched.value) {
        asking.value = true;
        nextTick(() => stay.value?.focus());
        return;
    }
    close();
};
const onCancel = (e) => {
    if (!guarded.value || !touched.value) return;
    e.preventDefault();
    requestClose();
};
// Menyeleksi teks di kolom isian lalu melepas mouse di luar dialog juga memicu klik pada latar:
// klik latar hanya dihitung bila tombol mouse ditekan di latar juga.
let downOnBackdrop = false;
const onDown = (e) => { downOnBackdrop = e.target === dialog.value; };
const onBackdrop = (e) => { if (e.target === dialog.value && downOnBackdrop) requestClose(); };
const discard = () => {
    asking.value = false;
    close();
};
const onClosed = () => {
    touched.value = false;
    asking.value = false;
    emit('close');
};

defineExpose({ showModal, close });
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    class="m-auto w-[calc(100%-1rem)] sm:w-[calc(100%-2rem)] overflow-hidden rounded-3xl border border-border bg-surface p-0 text-primary shadow-float backdrop:bg-primary/50 open:animate-pop"
    :class="SIZES[size]"
    @mousedown="onDown"
    @click="onBackdrop"
    @cancel="onCancel"
    @close="onClosed"
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
          @click="requestClose"
        >
          <AdminIcon name="x" />
        </button>
      </header>

      <div v-if="asking" role="alert" class="flex shrink-0 flex-wrap items-center gap-2 border-b border-warning/30 bg-warning-surface px-5 py-3 text-warning motion-safe:animate-pop sm:px-6">
        <p class="min-w-0 flex-1 text-body-sm">Isian belum disimpan. Tutup dan buang perubahan?</p>
        <button ref="stay" type="button" class="min-h-10 rounded-full border border-warning px-4 text-label-md hover:bg-warning/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="asking = false">Lanjut mengisi</button>
        <button type="button" class="min-h-10 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="discard">Buang dan tutup</button>
      </div>

      <!-- relative: elemen absolut di dalam (mis. input file sr-only) berpatokan pada area gulir ini, bukan pada dialog. Tanpanya, memfokuskan input yang letaknya jauh di bawah menggulir dialog (overflow hidden) dan modal tampak putih di Firefox. -->
      <div class="relative min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6" @input="touched = true" @change="touched = true">
        <slot />
      </div>

      <footer v-if="$slots.footer" class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-border bg-background/60 px-5 py-3 sm:px-6">
        <slot name="footer" />
      </footer>
    </div>
  </dialog>
</template>
