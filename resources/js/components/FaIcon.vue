<script>
// Font Awesome Free 7 (ikon CC BY 4.0, https://fontawesome.com/license/free), diambil per ikon sebagai SVG supaya bundel
// tidak membawa webfont atau seluruh koleksi. Tambah ikon baru dengan mengimpor berkasnya di bawah ini.
import tiktok from '@fortawesome/fontawesome-free/svgs/brands/tiktok.svg?raw';
import instagram from '@fortawesome/fontawesome-free/svgs/brands/instagram.svg?raw';
import eye from '@fortawesome/fontawesome-free/svgs/solid/eye.svg?raw';
import heart from '@fortawesome/fontawesome-free/svgs/solid/heart.svg?raw';
import layerGroup from '@fortawesome/fontawesome-free/svgs/solid/layer-group.svg?raw';
import calendarCheck from '@fortawesome/fontawesome-free/svgs/solid/calendar-check.svg?raw';
import clock from '@fortawesome/fontawesome-free/svgs/solid/clock.svg?raw';
import warning from '@fortawesome/fontawesome-free/svgs/solid/triangle-exclamation.svg?raw';
import chartLine from '@fortawesome/fontawesome-free/svgs/solid/chart-line.svg?raw';
import arrowUp from '@fortawesome/fontawesome-free/svgs/solid/arrow-up.svg?raw';
import arrowDown from '@fortawesome/fontawesome-free/svgs/solid/arrow-down.svg?raw';
import arrowRight from '@fortawesome/fontawesome-free/svgs/solid/arrow-right.svg?raw';
import penToSquare from '@fortawesome/fontawesome-free/svgs/solid/pen-to-square.svg?raw';
import fire from '@fortawesome/fontawesome-free/svgs/solid/fire.svg?raw';
import circleInfo from '@fortawesome/fontawesome-free/svgs/solid/circle-info.svg?raw';
import rotateRight from '@fortawesome/fontawesome-free/svgs/solid/rotate-right.svg?raw';
import trophy from '@fortawesome/fontawesome-free/svgs/solid/trophy.svg?raw';
import share from '@fortawesome/fontawesome-free/svgs/solid/share.svg?raw';
import users from '@fortawesome/fontawesome-free/svgs/solid/users.svg?raw';
import userPlus from '@fortawesome/fontawesome-free/svgs/solid/user-plus.svg?raw';
import userMinus from '@fortawesome/fontawesome-free/svgs/solid/user-minus.svg?raw';
import bullseye from '@fortawesome/fontawesome-free/svgs/solid/bullseye.svg?raw';
import link from '@fortawesome/fontawesome-free/svgs/solid/link.svg?raw';
import plus from '@fortawesome/fontawesome-free/svgs/solid/plus.svg?raw';
import trash from '@fortawesome/fontawesome-free/svgs/solid/trash.svg?raw';
import flask from '@fortawesome/fontawesome-free/svgs/solid/flask.svg?raw';
import lightbulb from '@fortawesome/fontawesome-free/svgs/solid/lightbulb.svg?raw';
import comment from '@fortawesome/fontawesome-free/svgs/solid/comment.svg?raw';
import stopwatch from '@fortawesome/fontawesome-free/svgs/solid/stopwatch.svg?raw';
import xmark from '@fortawesome/fontawesome-free/svgs/solid/xmark.svg?raw';
import check from '@fortawesome/fontawesome-free/svgs/solid/check.svg?raw';
import chartPie from '@fortawesome/fontawesome-free/svgs/solid/chart-pie.svg?raw';
import locationDot from '@fortawesome/fontawesome-free/svgs/solid/location-dot.svg?raw';
import scaleBalanced from '@fortawesome/fontawesome-free/svgs/solid/scale-balanced.svg?raw';
import handPointer from '@fortawesome/fontawesome-free/svgs/solid/hand-pointer.svg?raw';

// Ambil viewBox dan path dari berkas SVG statis, lalu digambar sebagai elemen Vue (tanpa v-html).
const parse = (raw) => ({ viewBox: raw.match(/viewBox="([^"]+)"/)[1], d: raw.match(/ d="([^"]+)"/)[1] });
const ICONS = Object.fromEntries(Object.entries({
    tiktok, instagram, eye, heart, 'layer-group': layerGroup, 'calendar-check': calendarCheck, clock, warning,
    'chart-line': chartLine, 'arrow-up': arrowUp, 'arrow-down': arrowDown, 'arrow-right': arrowRight,
    'pen-to-square': penToSquare, fire, 'circle-info': circleInfo, 'rotate-right': rotateRight, trophy, share,
    users, 'user-plus': userPlus, 'user-minus': userMinus, bullseye, link, plus, trash, flask, lightbulb, comment, stopwatch, xmark, check, 'chart-pie': chartPie, 'location-dot': locationDot, 'scale-balanced': scaleBalanced, 'hand-pointer': handPointer,
}).map(([name, raw]) => [name, parse(raw)]));
</script>

<script setup>
import { computed } from 'vue';

// Dekoratif (aria-hidden) kecuali diberi `label`. Ukuran mengikuti tinggi; lebar mengikuti rasio ikon.
const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 16 },
    label: { type: String, default: '' },
});
const icon = computed(() => ICONS[props.name]);
</script>

<template>
  <svg
    v-if="icon"
    xmlns="http://www.w3.org/2000/svg"
    :viewBox="icon.viewBox"
    :height="size"
    :width="size"
    fill="currentColor"
    class="shrink-0"
    :role="label ? 'img' : undefined"
    :aria-label="label || undefined"
    :aria-hidden="label ? undefined : 'true'"
    focusable="false"
  ><path :d="icon.d" /></svg>
</template>
