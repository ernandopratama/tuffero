<script setup>
// Tiga bintang yang jatuh bergantian, untuk tombol yang sedang memproses (dasar desain: Uiverse.io oleh andrew-manzyk).
// Warna bintang mengikuti warna teks (currentColor). Defs dipakai bersama oleh ketiga bintang, id berawalan "sl-".
defineProps({ size: { type: Number, default: 24 } });

const SHAPE = 'M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z';
const OVERLAYS = ['sl-g1', 'sl-g2', 'sl-g3', 'sl-g4', 'sl-g5'];
</script>

<template>
  <span class="sl" :style="{ '--sl-n': size, width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <span class="sl-scale">
      <svg v-for="n in 3" :key="n" :class="`sl-star sl-star-${n}`" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
        <defs v-if="n === 1">
          <filter id="sl-shine"><feGaussianBlur stdDeviation="3" /></filter>
          <mask id="sl-mask"><path :d="SHAPE" fill="white" /></mask>
          <radialGradient id="sl-g1" cx="50" cy="66" fx="50" fy="66" r="30" gradientTransform="translate(0 35) scale(1 0.5)" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="black" stop-opacity="0.3" />
            <stop offset="50%" stop-color="black" stop-opacity="0.1" />
            <stop offset="100%" stop-color="black" stop-opacity="0" />
          </radialGradient>
          <radialGradient id="sl-g2" cx="55" cy="20" fx="55" fy="20" r="30" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="white" stop-opacity="0.3" />
            <stop offset="50%" stop-color="white" stop-opacity="0.1" />
            <stop offset="100%" stop-color="white" stop-opacity="0" />
          </radialGradient>
          <radialGradient id="sl-g3" cx="85" cy="50" fx="85" fy="50" href="#sl-g2" />
          <radialGradient id="sl-g4" cx="50" cy="58" fx="50" fy="58" r="60" gradientTransform="translate(0 47) scale(1 0.2)" href="#sl-g3" />
          <linearGradient id="sl-g5" x1="50" y1="90" x2="50" y2="10" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="black" stop-opacity="0.2" />
            <stop offset="40%" stop-color="black" stop-opacity="0" />
          </linearGradient>
        </defs>
        <g>
          <path :d="SHAPE" fill="currentColor" />
          <path :d="SHAPE" fill="url(#sl-g1)" />
          <path :d="SHAPE" fill="none" stroke="white" opacity="0.3" stroke-width="3" filter="url(#sl-shine)" mask="url(#sl-mask)" />
          <path v-for="id in OVERLAYS.slice(1)" :key="id" :d="SHAPE" :fill="`url(#${id})`" />
        </g>
      </svg>
    </span>
  </span>
</template>

<style scoped>
/* Kotak kecil yang memotong gerak bintang; isinya digambar 100x100 lalu diperkecil, supaya gerakan asli tetap utuh. */
.sl {
  display: inline-block;
  flex: none;
  overflow: hidden;
  position: relative;
}
.sl-scale {
  display: block;
  height: 100px;
  position: absolute;
  left: 0;
  top: 0;
  transform: scale(calc(var(--sl-n) / 100));
  transform-origin: 0 0;
  width: 100px;
}
.sl-star {
  height: 100px;
  position: absolute;
  width: 100px;
}
.sl-star-1 {
  animation: sl-one 1s linear infinite;
}
.sl-star-2 {
  animation: sl-two 1s linear infinite 0.3s;
  opacity: 0;
  transform: scale(0) translateY(-200px) translateX(-100px);
}
.sl-star-3 {
  animation: sl-three 1s linear infinite 0.6s;
  opacity: 0;
  transform: scale(0) translateY(-200px) translateX(100px);
}

@keyframes sl-one {
  0% { transform: scale(0.5) translateY(-200px); opacity: 0; }
  25% { transform: scale(0.75) translateY(-100px); opacity: 1; }
  50% { transform: scale(1) translateY(0); opacity: 1; }
  75% { transform: scale(0.5) translateY(50px); opacity: 1; }
  100% { transform: scale(0) translateY(100px); opacity: 0; }
}
@keyframes sl-two {
  0% { transform: scale(0.5) rotateZ(-10deg) translateY(-200px) translateX(-100px); opacity: 0; }
  25% { transform: scale(1) rotateZ(-5deg) translateY(-100px) translateX(-50px); opacity: 1; }
  50% { transform: scale(1) rotateZ(0deg) translateY(0) translateX(-25px); opacity: 1; }
  75% { transform: scale(0.5) rotateZ(5deg) translateY(50px) translateX(0); opacity: 1; }
  100% { transform: scale(0) rotateZ(10deg) translateY(100px) translateX(25px); opacity: 0; }
}
@keyframes sl-three {
  0% { transform: scale(0.5) rotateZ(10deg) translateY(-200px) translateX(100px); opacity: 0; }
  25% { transform: scale(1) rotateZ(5deg) translateY(-100px) translateX(50px); opacity: 1; }
  50% { transform: scale(1) rotateZ(0deg) translateY(0) translateX(25px); opacity: 1; }
  75% { transform: scale(0.5) rotateZ(-5deg) translateY(50px) translateX(0); opacity: 1; }
  100% { transform: scale(0) rotateZ(-10deg) translateY(100px) translateX(-25px); opacity: 0; }
}

/* Gerak dimatikan bagi pengguna yang meminta: bintang diam di tengah, teks "Membuat caption..." tetap menandai proses. */
@media (prefers-reduced-motion: reduce) {
  .sl-star { animation: none; }
  .sl-star-1 { opacity: 1; transform: none; }
}
</style>
