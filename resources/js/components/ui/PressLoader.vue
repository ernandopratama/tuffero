<script setup>
// Mesin cetak isometrik yang mengeluarkan lembaran, untuk area hasil yang sedang dibuat (dasar desain: Uiverse.io oleh preet_7613).
// Semua ukuran dalam em, jadi `size` (px per 1em) mengecilkan atau membesarkan seluruh adegan. Hanya hiasan: teks status
// disediakan pemanggil lewat `label`.
defineProps({
    size: { type: Number, default: 10 },
    height: { type: Number, default: 380 },
    label: { type: String, default: 'Memuat' },
});
</script>

<template>
  <div role="status" :aria-label="label" class="cg-press" :style="{ fontSize: `${size}px`, height: `${height}px` }">
    <div class="cg-sheet"></div>
    <div class="cg-roll"></div>
    <div class="cg-sheet"></div>
    <div class="cg-roll"></div>
    <div class="cg-sheet"></div>
    <div class="cg-roll"></div>
    <div class="cg-sheet"></div>
    <div class="cg-sheet"></div>
    <div class="cg-sheet"></div>
    <div class="cg-sheet"></div>
    <div class="cg-sheet"></div>
    <div class="cg-roll"></div>
  </div>
</template>

<style scoped>
.cg-press {
  background: #f1e7d3;
  border-radius: 1.5rem;
  color: #2b1b12;
  overflow: hidden;
  position: relative;
  width: 100%;
}
.cg-press *,
.cg-press *::before {
  box-sizing: border-box;
}
.cg-sheet { overflow: hidden; }
.cg-sheet,
.cg-sheet:before,
.cg-roll { position: absolute; }
.cg-sheet,
.cg-roll { top: 50%; left: 50%; }
.cg-sheet {
  width: 4em;
  height: 6em;
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg);
}
.cg-sheet:before {
  animation: cg-print 0.5s ease-in-out infinite;
  background:
    linear-gradient(#0000 5.2em, currentColor 5.2em 5.3em, #0000 5.3em 5.5em) 0.4em 0 / 3.2em 6em,
    linear-gradient(#0000 5.5em, currentColor 5.5em 5.7em, #0000 5.7em) 0.6em 0 / 2.8em 6em,
    linear-gradient(90deg, #fbf6ec 0.4em, #0000 0.4em 1.9em, #fbf6ec 1.9em 2.1em, #0000 2.1em 3.6em, #fbf6ec 3.6em) 0 0 / 4em 6em,
    repeating-linear-gradient(#fbf6ec 0 0.4em, #0000 0.4em 5em, #fbf6ec 5em 6em, #0000 6em) 0 0 / 4em 6em,
    linear-gradient(#0000 3.4em, currentColor 3.4em) 100% 0 / 50% 6em,
    repeating-linear-gradient(currentColor 0 0.2em, #fbf6ec 0.2em 0.4em) 0 0 / 4em 6em;
  background-repeat: repeat-y;
  color: #2b1b12;
  content: "";
  display: block;
  top: -6em;
  left: 0;
  width: 100%;
  height: calc(100% + 6em);
}
.cg-sheet:nth-child(1):before,
.cg-sheet:nth-child(5):before { color: #c9b38a; }
.cg-sheet:nth-child(n + 7):nth-child(-n + 9) { height: 0.4em; }
.cg-sheet:nth-child(1) {
  height: 42em;
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-17.4em, 9.4em) rotateX(-90deg);
}
.cg-sheet:nth-child(3) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-2.5em, -2.5em);
}
.cg-sheet:nth-child(5) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-0.5em, -0.7em) rotateX(-75deg);
}
.cg-sheet:nth-child(7) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(1.7em, -1.85em) rotateX(-15deg);
}
.cg-sheet:nth-child(7):before { background-position-y: 5.2em; }
.cg-sheet:nth-child(8) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(1.7em, -1.5em) rotateX(15deg);
}
.cg-sheet:nth-child(8):before { background-position-y: 4.8em; }
.cg-sheet:nth-child(9) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(1.6em, -1.05em) rotateX(45deg);
}
.cg-sheet:nth-child(9):before { background-position-y: 4.4em; }
.cg-sheet:nth-child(10):before,
.cg-sheet:nth-child(11):before { background-position-y: 4em; }
.cg-sheet:nth-child(10) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-0.5em, 1.95em) rotateX(75deg);
}
.cg-sheet:nth-child(11) {
  height: 29em;
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-2.5em, 19.25em);
}
.cg-roll {
  background-color: #4a2f20;
  background-image: radial-gradient(1em 2em at 0.5em 50%, #2b1b12 49%, #2b1b1200 50%);
  border-radius: 0.5em / 1em;
  width: 8em;
  height: 2em;
}
.cg-roll:nth-child(2) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-3em, -4.5em) skewX(-22.5deg);
}
.cg-roll:nth-child(4) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-2em, -0.5em) skewX(-22.5deg);
}
.cg-roll:nth-child(6) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(1em, -1em) skewX(-22.5deg);
}
.cg-roll:nth-child(12) {
  transform: translate(-50%, -50%) rotateX(-45deg) rotateZ(-45deg) translate(-2em, 4.45em) skewX(-22.5deg);
}

@keyframes cg-print {
  from { transform: translateY(0); }
  to { transform: translateY(6em); }
}

/* Gerak dimatikan bagi pengguna yang memintanya; teks status tetap menandai proses. */
@media (prefers-reduced-motion: reduce) {
  .cg-sheet:before { animation: none; }
}
</style>
