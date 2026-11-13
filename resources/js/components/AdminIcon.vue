<script setup>
import { computed } from 'vue';

// Ikon garis 24x24 dengan satu kosakata, supaya tebal garis dan sudutnya seragam. Dekoratif kecuali diberi label.
const PATHS = {
    home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6',
    box: 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10',
    truck: 'M3 6h11v10H3zM14 9h4l3 3v4h-7M5 17.5a1.75 1.75 0 103.5 0 1.75 1.75 0 10-3.5 0M15.5 17.5a1.75 1.75 0 103.5 0 1.75 1.75 0 10-3.5 0',
    wallet: 'M3 7a2 2 0 012-2h13v4M3 7v10a2 2 0 002 2h15V9H5a2 2 0 01-2-2zM16 14h2',
    x: 'M6 6l12 12M18 6L6 18',
    undo: 'M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3',
    tag: 'M3 12V4h8l10 10-8 8L3 12zM7.5 8.5h.01',
    warehouse: 'M3 21V9l9-6 9 6v12M7 21v-7h10v7M7 17h10',
    ticket: 'M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4V8zM14 6v12',
    megaphone: 'M3 10v4a1 1 0 001 1h2l6 4V5L6 9H4a1 1 0 00-1 1zM16 9a4 4 0 010 6M19 6a8 8 0 010 12',
    image: 'M4 5h16v14H4V5zM4 16l4.5-4.5L13 16l3-3 4 4M9 9h.01',
    photos: 'M3 8h14v11H3V8zM7 8V5h14v11h-4M3 16l4-4 4 4 2-2 4 4',
    users: 'M9 11a3 3 0 100-6 3 3 0 000 6zM3 19a6 6 0 0112 0M16 6a3 3 0 010 6M17 19a5 5 0 00-2-4',
    star: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z',
    'chevron-left': 'M15 6l-6 6 6 6',
    sparkles: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z',
    store: 'M4 9l1.5-5h13L20 9M4 9v1a2 2 0 004 0 2 2 0 004 0 2 2 0 004 0 2 2 0 004 0V9M5 13v7h14v-7M10 20v-4h4v4',
    star: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z',
    chart: 'M4 20V10M10 20V4M16 20v-8M22 20H2',
    shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4',
    user: 'M8 8a4 4 0 108 0 4 4 0 10-8 0M4 21a8 8 0 0116 0',
    sliders: 'M4 7h10M18 7h2M4 17h2M10 17h10M14 4v6M6 14v6',
    search: 'M4 11a7 7 0 1014 0 7 7 0 10-14 0M21 21l-4.3-4.3',
    menu: 'M4 6h16M4 12h16M4 18h16',
    logout: 'M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9',
    check: 'M5 12l5 5 9-10',
    alert: 'M12 4l9 16H3L12 4zM12 10v4M12 17.5v.01',
    clock: 'M3 12a9 9 0 1018 0 9 9 0 10-18 0M12 7v5l3 2',
    info: 'M3 12a9 9 0 1018 0 9 9 0 10-18 0M12 11v5M12 8v.01',
    chat: 'M4 5h16v11H9l-5 4V5z',
    mail: 'M3 6h18v12H3V6zM3 7l9 7 9-7',
    'chevron-up': 'M6 15l6-6 6 6',
    document: 'M7 3h7l5 5v13H7V3zM14 3v5h5M10 13h6M10 17h6',
    'chevron-down': 'M6 9l6 6 6-6',
    'chevron-right': 'M9 6l6 6-6 6',
    expand: 'M7 6l6 6-6 6M13 6l6 6-6 6',
    collapse: 'M17 6l-6 6 6 6M11 6l-6 6 6 6',
    'arrow-right': 'M5 12h14M13 6l6 6-6 6',
    plus: 'M12 5v14M5 12h14',
    bell: 'M6 16v-5a6 6 0 1112 0v5l2 2H4l2-2zM10 21h4',
    volume: 'M4 9v6h4l5 4V5L8 9H4zM16.5 9a4 4 0 010 6M19 6.5a8 8 0 010 11',
    'volume-off': 'M4 9v6h4l5 4V5L8 9H4zM17 9l5 6M22 9l-5 6',
    play: 'M8 5v14l11-7L8 5z',
    video: 'M3 7a2 2 0 012-2h9a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7zM16 10l5-3v10l-5-3',
    layers:'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5M3 17.5l9 5 9-5',
    edit: 'M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4',
    trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    filter: 'M4 5h16l-6 8v6l-4-2v-4L4 5z',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM9 12a3 3 0 106 0 3 3 0 10-6 0',
    copy: 'M9 9h11v12H9zM5 15V3h11',
    pin: 'M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11zM9.5 10a2.5 2.5 0 105 0 2.5 2.5 0 10-5 0',
    printer: 'M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z',
    'eye-off': 'M3 3l18 18M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4M6.6 6.6C3.7 8.5 2 12 2 12s3.5 7 10 7a9.7 9.7 0 004.4-1M9.9 9.9a3 3 0 004.2 4.2',
    circle: 'M4 12a8 8 0 1016 0 8 8 0 10-16 0',
    sun: 'M8 12a4 4 0 108 0 4 4 0 10-8 0M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
};

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 20 },
    label: { type: String, default: '' },
});

const d = computed(() => PATHS[props.name] || PATHS.info);
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="label ? 'img' : undefined"
    :aria-label="label || undefined"
    :aria-hidden="label ? undefined : 'true'"
    class="pointer-events-none shrink-0"
  >
    <path :d="d" />
  </svg>
</template>
