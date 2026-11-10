<script setup>
// Tombol "Tambah" untuk halaman CRUD: lingkaran emas berisi tanda plus. Saat disorot (mouse atau fokus keyboard) tombol
// melebar menampilkan teksnya, dan plus terbelah menjadi dua titik di kedua ujung.
// Di layar sentuh tidak ada hover, jadi tombol langsung terbuka; `expanded` memaksa terbuka di semua perangkat.
// Teks tetap ada di DOM (hanya tak terlihat), jadi pembaca layar membaca nama tombolnya.
defineProps({
    label: { type: String, required: true },
    expanded: { type: Boolean, default: false },
});
</script>

<template>
  <button type="button" class="add-btn" :class="{ 'is-open': expanded }">
    <span class="add-icon" aria-hidden="true"></span>
    <span class="add-txt"><span class="add-txt-inner">{{ label }}</span></span>
  </button>
</template>

<style scoped>
.add-btn {
    /* Keadaan tertutup: plus utuh. Keadaan terbuka mengganti variabel ini; semua ukuran mengikutinya. */
    --size: 2.75rem;
    --arm: 0.5rem;        /* panjang satu lengan horizontal */
    --thick: 0.1875rem;   /* tebal garis plus */
    --edge: 0.875rem;     /* jarak ujung bar dari tepi tombol */
    --h-width: var(--arm);
    --v-height: 1rem;
    --v-top: calc(50% - 0.5rem);
    --v-side: calc(50% - var(--thick) / 2);
    --text-opacity: 0;
    --columns: var(--size) 0fr;

    position: relative;
    display: inline-grid;
    grid-template-columns: var(--columns);
    align-items: center;
    height: var(--size);
    flex-shrink: 0;
    overflow: hidden;
    padding: 0;
    border: 0;
    border-radius: 9999px;
    background: var(--color-accent);
    color: var(--color-primary);
    font: inherit;
    cursor: pointer;
    transition:
        grid-template-columns 220ms cubic-bezier(0.23, 1, 0.32, 1),
        background-color 160ms ease,
        transform 160ms ease-out;
}

.add-icon {
    width: var(--size);
    height: var(--size);
}

.add-txt {
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    font-size: var(--text-label-md, 0.875rem);
    font-weight: 600;
    opacity: var(--text-opacity);
    transition: opacity 200ms ease;
}

/* Jarak teks ke titik di ujung kanan sama dengan jarak ke titik kiri. */
.add-txt-inner {
    display: block;
    padding-right: calc(var(--size) / 2 + var(--edge) / 2);
}

/* Dua lengan horizontal, dan dua separuh batang vertikal di dalam .add-icon: bersama-sama membentuk plus. */
.add-btn::before,
.add-btn::after,
.add-icon::before,
.add-icon::after {
    content: "";
    position: absolute;
    background: currentColor;
    transition: left 220ms cubic-bezier(0.23, 1, 0.32, 1), right 220ms cubic-bezier(0.23, 1, 0.32, 1), width 220ms ease, height 220ms ease, top 220ms ease, border-radius 220ms ease;
}

.add-btn::before,
.add-btn::after {
    top: calc(50% - var(--thick) / 2);
    width: var(--h-width);
    height: var(--thick);
}
.add-btn::before { left: var(--edge); border-radius: 2px 0 0 2px; }
.add-btn::after { right: var(--edge); border-radius: 0 2px 2px 0; }

.add-icon::before,
.add-icon::after {
    top: var(--v-top);
    width: calc(var(--thick) / 2);
    height: var(--v-height);
}
.add-icon::before { left: var(--v-side); border-radius: 2px 0 0 2px; }
.add-icon::after { right: var(--v-side); border-radius: 0 2px 2px 0; }

.add-btn:hover { background: var(--color-accent-hover); }
.add-btn:active { transform: scale(0.97); }
.add-btn:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

/* Terbuka: tombol selebar teksnya; plus berubah menjadi titik di kedua ujung. */
.add-btn.is-open,
.add-btn:focus-visible {
    --h-width: var(--thick);
    --v-height: var(--thick);
    --v-top: calc(50% - var(--thick) / 2);
    --v-side: var(--edge);
    --text-opacity: 1;
    --columns: var(--size) 1fr;
}

@media (hover: hover) {
    .add-btn:hover {
        --h-width: var(--thick);
        --v-height: var(--thick);
        --v-top: calc(50% - var(--thick) / 2);
        --v-side: var(--edge);
        --text-opacity: 1;
        --columns: var(--size) 1fr;
    }
}

/* Tanpa hover (layar sentuh): selalu terbuka supaya tombol tidak hanya berupa plus tanpa keterangan. */
@media (hover: none) {
    .add-btn {
        --h-width: var(--thick);
        --v-height: var(--thick);
        --v-top: calc(50% - var(--thick) / 2);
        --v-side: var(--edge);
        --text-opacity: 1;
        --columns: var(--size) 1fr;
    }
}

.add-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.add-btn:disabled:active { transform: none; }

/* Gerak dikurangi: tanpa pergeseran, hanya teks yang muncul. */
@media (prefers-reduced-motion: reduce) {
    .add-btn,
    .add-btn::before,
    .add-btn::after,
    .add-icon::before,
    .add-icon::after {
        transition-property: background-color, opacity;
    }
}
</style>
