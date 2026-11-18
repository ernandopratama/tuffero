<script setup>
import { hideTip, showTip } from '../../composables/useActionTip';

// Tombol unduh berkas: teks di tempat; saat disorot (mouse) atau difokus (keyboard) teks naik keluar dan ikon unduh naik masuk.
// Tooltip memakai tooltip admin bersama (useActionTip) untuk keterangan berkas, mis. format dan isi.
// Layar sentuh tidak punya hover, jadi teks selalu tampil. Teks tetap di DOM, jadi pembaca layar membaca nama tombolnya.
const props = defineProps({
    label: { type: String, default: 'Unduh xlsx' },
    hint: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const onEnter = (e) => { if (props.hint && e.pointerType !== 'touch' && !props.disabled) showTip(e.currentTarget, props.hint); };
const onFocus = (e) => { if (props.hint && e.currentTarget.matches(':focus-visible')) showTip(e.currentTarget, props.hint, { immediate: true }); };
</script>

<template>
  <button
    type="button"
    class="dl-btn"
    :class="{ 'is-loading': loading }"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
    @pointerenter="onEnter"
    @pointerleave="hideTip"
    @focus="onFocus"
    @blur="hideTip"
    @click="hideTip"
  >
    <span class="dl-text">
      <!-- Ikon lembar kerja (xlsx) di samping teks; saat disorot diganti ikon unduh. -->
      <svg v-if="!loading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" />
        <path d="M14 2v4a2 2 0 0 0 2 2h4M8 13h2m4 0h2m-8 4h2m4 0h2" />
      </svg>
      {{ loading ? 'Menyiapkan...' : label }}
    </span>
    <span class="dl-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 15V3m0 12-4-4m4 4 4-4M2 17l.621 2.485A2 2 0 0 0 4.561 21h14.878a2 2 0 0 0 1.94-1.515L22 17" />
      </svg>
    </span>
  </button>
</template>

<style scoped>
.dl-btn {
    /* Teks dan ikon bertumpuk di sel grid yang sama: lebar tombol mengikuti teks, jadi tidak bergeser saat berganti. */
    position: relative;
    display: inline-grid;
    place-items: center;
    height: 2.75rem;
    min-width: 7.5rem;
    padding: 0 1.25rem;
    overflow: hidden;
    border: 0;
    border-radius: 9999px;
    background: var(--color-accent);
    color: var(--color-primary);
    font: inherit;
    font-size: var(--text-label-md, 0.875rem);
    font-weight: 600;
    cursor: pointer;
    transition: background-color 160ms ease, transform 160ms cubic-bezier(0.23, 1, 0.32, 1);
}

.dl-text,
.dl-icon {
    grid-area: 1 / 1;
    transition: transform 200ms cubic-bezier(0.23, 1, 0.32, 1), opacity 200ms ease;
}

.dl-text {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.dl-text svg { width: 1.125rem; height: 1.125rem; flex-shrink: 0; }

.dl-icon {
    display: flex;
    transform: translateY(110%);
    opacity: 0;
}

.dl-icon svg { width: 1.25rem; height: 1.25rem; }

/* Sorot (mouse) dan fokus keyboard: teks keluar ke atas, ikon masuk dari bawah. */
.dl-btn:focus-visible .dl-text { transform: translateY(-110%); opacity: 0; }
.dl-btn:focus-visible .dl-icon { transform: translateY(0); opacity: 1; }

@media (hover: hover) and (pointer: fine) {
    .dl-btn:hover:not(:disabled) { background: var(--color-accent-hover); }
    .dl-btn:hover:not(:disabled) .dl-text { transform: translateY(-110%); opacity: 0; }
    .dl-btn:hover:not(:disabled) .dl-icon { transform: translateY(0); opacity: 1; }
}

.dl-btn:active:not(:disabled) { transform: scale(0.97); }
.dl-btn:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

/* Saat menyiapkan berkas: teks "Menyiapkan..." tetap terlihat, ikon tidak ditukar masuk. */
.dl-btn.is-loading .dl-text { transform: none; opacity: 1; }
.dl-btn.is-loading .dl-icon { transform: translateY(110%); opacity: 0; }
.dl-btn:disabled { cursor: not-allowed; }
.dl-btn:disabled:not(.is-loading) { opacity: 0.5; }

/* Gerak dikurangi: tanpa geser, hanya pudar silang. */
@media (prefers-reduced-motion: reduce) {
    .dl-btn,
    .dl-text,
    .dl-icon { transition-property: background-color, opacity; }
    .dl-icon { transform: none; }
    .dl-btn:focus-visible .dl-text { transform: none; }
    @media (hover: hover) and (pointer: fine) {
        .dl-btn:hover:not(:disabled) .dl-text { transform: none; }
    }
}
</style>
