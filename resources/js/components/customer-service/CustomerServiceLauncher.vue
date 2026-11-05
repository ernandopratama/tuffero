<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { t } from '../../composables/useLocale';

const props = defineProps({ status: { type: String, required: true } });
defineEmits(['activate', 'prefetch']);

// Sapaan hanya beberapa kali per muat halaman, dan tidak saat tab tersembunyi.
const FIRST_ATTENTION = 8000;
const NEXT_ATTENTION = 40000;
const ATTENTION_LENGTH = 2200;
const MAX_ATTENTION = 3;

const button = ref(null);
const mascot = ref(null);
const attention = ref(false);
let timer;
let shown = 0;

const schedule = (delay) => {
    clearTimeout(timer);
    if (shown >= MAX_ATTENTION) return;
    timer = setTimeout(() => {
        if (document.hidden || props.status !== 'idle') return schedule(NEXT_ATTENTION);
        shown += 1;
        attention.value = true;
        timer = setTimeout(() => {
            attention.value = false;
            schedule(NEXT_ATTENTION);
        }, ATTENTION_LENGTH);
    }, delay);
};

watch(() => props.status, (status) => {
    if (status !== 'idle') attention.value = false;
});

onMounted(() => {
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) schedule(FIRST_ATTENTION);
});
onBeforeUnmount(() => clearTimeout(timer));

defineExpose({ button, mascot });
</script>

<template>
<button
    ref="button"
    type="button"
    class="cs-launcher"
    :data-status="status"
    :data-attention="attention || undefined"
    :aria-label="t.aria.chat"
    aria-haspopup="dialog"
    :aria-expanded="status === 'open'"
    :inert="status === 'open'"
    @click="$emit('activate', $event)"
    @pointerenter.once="$emit('prefetch')"
    @focus.once="$emit('prefetch')"
>
    <span class="cs-floor" aria-hidden="true"></span>
    <span ref="mascot" class="cs-mascot" aria-hidden="true">
        <span class="cs-react">
            <span class="cs-glow"></span>
            <span class="cs-ripple"></span>
            <span class="cs-float">
                <img src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="" decoding="async" draggable="false">
                <!-- Koordinat mengikuti gambar sumber maskot (915 x 1079), jadi ekspresi menempel di wajah pada ukuran apa pun. -->
                <svg class="cs-face" viewBox="0 0 915 1079" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <g class="cs-eye cs-eye-left">
                        <ellipse cx="430" cy="372" rx="86" ry="120" transform="rotate(-16 430 372)" fill="#F2882E" />
                        <path d="M362 402 Q430 318 498 390" stroke="#2B1410" stroke-width="24" />
                    </g>
                    <g class="cs-eye cs-eye-right">
                        <ellipse cx="602" cy="340" rx="80" ry="110" transform="rotate(-18 602 340)" fill="#F2882E" />
                        <path d="M538 366 Q602 284 664 352" stroke="#2B1410" stroke-width="24" />
                    </g>
                    <g class="cs-mouth">
                        <path d="M506 524 Q554 546 602 518 Q610 604 556 610 Q502 610 506 524 Z" fill="#2B1410" stroke="#2B1410" stroke-width="10" />
                        <ellipse cx="556" cy="586" rx="26" ry="16" fill="#E8583F" />
                    </g>
                    <g class="cs-waves" stroke="#C6A15B" stroke-width="22">
                        <path d="M6 760 Q-34 820 -6 884" />
                        <path d="M-58 744 Q-104 820 -66 900" />
                    </g>
                    <g class="cs-sparks">
                        <path class="cs-spark" d="M748 78 L788 88 L724 196 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
                        <path class="cs-spark" d="M856 148 L888 182 L778 244 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
                        <path class="cs-spark" d="M884 284 L888 318 L796 304 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
                    </g>
                </svg>
            </span>
        </span>
    </span>
</button>
</template>
