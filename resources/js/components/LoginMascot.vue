<script setup>
// Maskot login. Gambar dasar (tanpa mata) diturunkan dari "Tuffero Icon - NoBckgrnd.webp";
// mata dan tangan adalah layer SVG di atasnya, jadi semua gerak hanya transform dan opacity.
// Koordinat mengikuti gambar yang dipotong (680 x 555); tepi bawahnya rata dengan sisi atas kartu login.
defineProps({
    // idle | username | password | success
    state: { type: String, default: 'idle' },
});
</script>

<template>
  <svg class="mascot" :class="`is-${state}`" viewBox="0 0 680 555" aria-hidden="true" focusable="false">
    <defs>
      <!-- Tangan terbuka (saat menutup mata) dan kepalan (saat diam). Garis tepi digambar dulu, lalu isi putih. -->
      <g id="mascot-open-parts">
        <ellipse cx="0" cy="22" rx="76" ry="62" />
        <rect x="-70" y="-112" width="42" height="104" rx="21" transform="rotate(-6 -49 -60)" />
        <rect x="-21" y="-128" width="42" height="116" rx="21" />
        <rect x="28" y="-112" width="42" height="104" rx="21" transform="rotate(6 49 -60)" />
        <rect x="52" y="-14" width="40" height="78" rx="20" transform="rotate(-28 72 25)" />
      </g>
      <g id="mascot-open">
        <use href="#mascot-open-parts" fill="#5C3A21" stroke="#5C3A21" stroke-width="14" stroke-linejoin="round" />
        <use href="#mascot-open-parts" fill="#fff" />
      </g>
      <rect id="mascot-fist-body" x="-74" y="-60" width="148" height="120" rx="48" />
      <g id="mascot-fist">
        <use href="#mascot-fist-body" fill="#5C3A21" stroke="#5C3A21" stroke-width="14" stroke-linejoin="round" />
        <use href="#mascot-fist-body" fill="#fff" />
        <path d="M-36 -54V-14M0 -56V-12M36 -54V-14" fill="none" stroke="#5C3A21" stroke-width="6" stroke-linecap="round" />
        <ellipse cx="-4" cy="30" rx="54" ry="26" transform="rotate(-8 -4 30)" fill="#fff" stroke="#5C3A21" stroke-width="7" />
      </g>
    </defs>

    <image href="/images/mascot/tuffero-login.webp" width="680" height="555" />

    <!-- Mata terbuka: ring hitam, putih, pupil dan kilau. Pupil turun saat kolom username aktif. -->
    <g class="eyes">
      <g class="eye">
        <ellipse cx="342" cy="350" rx="66" ry="91" transform="rotate(-17 342 350)" fill="#000" />
        <ellipse cx="342" cy="350" rx="52" ry="77" transform="rotate(-17 342 350)" fill="#fff" />
        <g class="pupil">
          <ellipse cx="366" cy="348" rx="37" ry="53" transform="rotate(-17 366 348)" fill="#000" />
          <circle cx="354" cy="325" r="14.5" fill="#fff" />
        </g>
      </g>
      <g class="eye">
        <ellipse cx="505" cy="325" rx="61" ry="86" transform="rotate(-16 505 325)" fill="#000" />
        <ellipse cx="505" cy="325" rx="47" ry="72" transform="rotate(-16 505 325)" fill="#fff" />
        <g class="pupil">
          <ellipse cx="522" cy="324" rx="30" ry="49" transform="rotate(-16 522 324)" fill="#000" />
          <circle cx="516" cy="299" r="13.5" fill="#fff" />
        </g>
      </g>
    </g>

    <!-- Mata senang saat berhasil. -->
    <g class="happy" fill="none" stroke="#000" stroke-width="20" stroke-linecap="round">
      <path d="M292 372Q342 312 392 372" />
      <path d="M458 346Q508 286 558 346" />
    </g>

    <g class="hand hand-left">
      <use class="fist" href="#mascot-fist" />
      <use class="open" href="#mascot-open" />
    </g>
    <g class="hand hand-right">
      <g transform="scale(-1 1)">
        <use class="fist" href="#mascot-fist" />
        <use class="open" href="#mascot-open" />
      </g>
    </g>
  </svg>
</template>

<style scoped>
.mascot {
    --ease: cubic-bezier(0.22, 1, 0.36, 1);
    display: block;
    width: 100%;
    height: auto;
    overflow: visible;
}

.eye {
    transform-box: fill-box;
    transform-origin: center;
    animation: blink 6s var(--ease) infinite;
}

.pupil {
    transition: transform 220ms var(--ease);
}
.is-username .pupil {
    transform: translateY(28px);
}

.happy {
    opacity: 0;
    transition: opacity 150ms var(--ease);
}
.is-success .happy { opacity: 1; }
.eyes {
    transition: opacity 150ms var(--ease);
}
.is-success .eyes { opacity: 0; }
.is-success { animation: hop 350ms var(--ease); }

/* Tangan diam di tepi kartu; saat password aktif naik dan menutup mata. */
.hand {
    transition: transform 300ms var(--ease);
}
.hand-left { transform: translate(150px, 536px) rotate(-8deg); }
.hand-right { transform: translate(540px, 536px) rotate(8deg); }
.is-password .hand-left { transform: translate(338px, 372px) rotate(12deg); }
.is-password .hand-right { transform: translate(508px, 346px) rotate(-12deg); }
/* Kepalan berganti tangan terbuka sambil naik. */
.open { opacity: 0; }
.fist, .open { transition: opacity 140ms var(--ease); }
.is-password .open { opacity: 1; }
.is-password .fist { opacity: 0; }
/* Selama tangan menutup, mata tidak perlu berkedip. */
.is-password .eye,
.is-username .eye,
.is-success .eye { animation: none; }

@keyframes blink {
    0%, 94%, 100% { transform: scaleY(1); }
    97% { transform: scaleY(0.08); }
}
@keyframes hop {
    0% { transform: translateY(0); }
    40% { transform: translateY(-14px); }
    100% { transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    .eye, .is-success { animation: none; }
    .pupil, .hand, .happy, .eyes, .fist, .open { transition-duration: 1ms; }
}
</style>
