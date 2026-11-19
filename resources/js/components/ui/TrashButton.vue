<script setup>
// Tombol hapus bundar yang melebar menjadi "Hapus" saat disorot atau difokus (dasar desain: Uiverse.io oleh vinodjangid07).
// Tombol sungguhan dengan nama aksesibel; teks label asli dipakai (bukan ::before) supaya terbaca pembaca layar.
// Di layar sentuh (tanpa hover) tombol tetap bundar dan sekali ketuk langsung menjalankan aksinya.
defineProps({
    label: { type: String, default: 'Hapus' },
    subject: { type: String, default: '' },
    // Hanya ikon: animasi sorot dan tekan tetap ada, tanpa tulisan "Hapus" (nama aksesibel tetap ada lewat aria-label).
    iconOnly: { type: Boolean, default: false },
});
defineEmits(['click']);
</script>

<template>
  <button type="button" class="tb" :class="{ 'tb--icon': iconOnly }" :aria-label="subject ? `${label} ${subject}` : label" @click="$emit('click', $event)">
    <svg class="tb-icon" viewBox="0 0 448 512" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z" /></svg>
    <span class="tb-label" aria-hidden="true">{{ label }}</span>
  </button>
</template>

<style scoped>
.tb {
  align-items: center;
  background-color: var(--color-primary);
  border: 0;
  border-radius: 9999px;
  box-shadow: 0 0 20px rgb(0 0 0 / 0.16);
  cursor: pointer;
  display: flex;
  flex: none;
  height: 44px;
  justify-content: center;
  overflow: hidden;
  position: relative;
  transition: width 0.3s, background-color 0.3s;
  width: 44px;
}
.tb:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
.tb-icon {
  transition: width 0.3s, transform 0.3s;
  width: 13px;
}
.tb-icon path {
  fill: #fff;
}
.tb-label {
  color: #fff;
  font-size: 2px;
  font-weight: 600;
  opacity: 0;
  position: absolute;
  top: -20px;
  transition: font-size 0.3s, opacity 0.3s, transform 0.3s;
}

/* Hanya perangkat yang bisa menyorot yang melebar; fokus papan ketik juga melebarkannya. */
@media (hover: hover) {
  .tb:hover,
  .tb:focus-visible {
    background-color: var(--color-error);
    width: 128px;
  }
  .tb:hover .tb-icon,
  .tb:focus-visible .tb-icon {
    transform: translateY(60%);
    width: 50px;
  }
  .tb:hover .tb-label,
  .tb:focus-visible .tb-label {
    font-size: 14px;
    opacity: 1;
    transform: translateY(32px);
  }
}
@media (hover: none) {
  .tb:active {
    background-color: var(--color-error);
  }
}

/* Mode hanya ikon: animasi melebar dan ikon yang turun tetap sama; hanya tulisan "Hapus" yang tidak ditampilkan. Ditekan: tombol mengecil sedikit. */
.tb--icon {
  transition: width 0.3s, background-color 0.3s, transform 0.16s ease-out;
}
.tb.tb--icon:active {
  background-color: var(--color-error);
  transform: scale(0.95);
}
.tb--icon .tb-label,
.tb.tb--icon:hover .tb-label,
.tb.tb--icon:focus-visible .tb-label {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .tb,
  .tb-icon,
  .tb-label {
    transition: none;
  }
}
</style>
