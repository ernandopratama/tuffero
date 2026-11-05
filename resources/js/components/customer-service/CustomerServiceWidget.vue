<script setup>
import { nextTick, ref, shallowRef } from 'vue';
import CustomerServiceLauncher from './CustomerServiceLauncher.vue';
import { ROUTES } from '../../utils/site';

// Tombol ikut bundel halaman; panel (dan komposabel chat) baru diunduh saat tombol didekati atau ditekan.
const loadPanel = () => import('./CustomerServiceChatPanel.vue');
const loadChat = () => import('../../composables/useCustomerServiceChat');

const OPEN_MS = 360;
const CLOSE_MS = 220;
const PRESS_MS = 170;
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';

// idle -> press -> opening -> open -> closing -> idle
const status = ref('idle');
const Panel = shallowRef(null);
const panelVisible = ref(false);
const launcher = ref(null);
const panel = ref(null);
let chat;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

const prefetch = () => { loadPanel().catch(() => {}); };

const panelFrames = () => {
    if (reducedMotion()) return [{ opacity: 0 }, { opacity: 1 }];
    const hidden = window.matchMedia('(max-width: 767px)').matches ? 'translateY(100%)' : 'translateY(20px) scale(0.97)';
    return [{ opacity: 0, transform: hidden }, { opacity: 1, transform: 'none' }];
};

// Maskot terbang antara tombol dan header panel. Posisi diukur sebelum animasi panel dimulai.
const mascotFrames = () => {
    if (reducedMotion()) return null;
    const from = launcher.value.mascot.getBoundingClientRect();
    const to = panel.value.avatar.getBoundingClientRect();
    const x = to.left + to.width / 2 - (from.left + from.width / 2);
    const y = to.top + to.height / 2 - (from.top + from.height / 2);
    return [{ transform: 'none' }, { transform: `translate(${x}px, ${y}px) scale(${to.width / from.width})` }];
};

const play = async (duration, reverse) => {
    const direction = reverse ? 'reverse' : 'normal';
    const options = { duration: reducedMotion() ? 120 : duration, easing: EASE, direction, fill: 'both' };
    const frames = mascotFrames();
    const animations = [panel.value.root.animate(panelFrames(), options)];
    if (frames) animations.push(launcher.value.mascot.animate(frames, options));
    return animations;
};

const settle = async (animations) => {
    await Promise.all(animations.map((animation) => animation.finished));
    return () => animations.forEach((animation) => animation.cancel());
};

const open = async (event) => {
    if (status.value !== 'idle') return;
    // event.detail 0 = dibuka dari keyboard: tanpa jeda tekan.
    const usePress = event.detail > 0 && !reducedMotion();
    if (usePress) status.value = 'press';
    try {
        const [panelModule, chatModule] = await Promise.all([loadPanel(), loadChat(), usePress ? wait(PRESS_MS) : null]);
        Panel.value = panelModule.default;
        chat = chatModule;
    } catch {
        // Berkas panel gagal diunduh (misalnya jaringan putus): arahkan ke halaman kontak.
        status.value = 'idle';
        window.location.href = ROUTES.contact;
        return;
    }
    status.value = 'opening';
    panelVisible.value = true;
    await nextTick();
    const finish = await settle(await play(OPEN_MS, false));
    status.value = 'open';
    await nextTick();
    finish();
    panel.value.focusStart();
    panel.value.scrollToEnd();
    chat.greet();
};

const close = async (reset) => {
    if (status.value !== 'open') return;
    const animations = await play(CLOSE_MS, true);
    status.value = 'closing';
    const finish = await settle(animations);
    panelVisible.value = false;
    status.value = 'idle';
    await nextTick();
    finish();
    if (reset) chat.resetChat();
    launcher.value.button.focus({ preventScroll: true });
};
</script>

<template>
<!-- Latar gelap berbur hanya tampil di layar HP (CSS). Mengetuknya memperkecil chat, sama seperti tombol Perkecil. -->
<div class="cs-backdrop" :class="{ 'is-on': status === 'opening' || status === 'open' }" aria-hidden="true" @click="close(false)"></div>
<CustomerServiceLauncher ref="launcher" :status="status" @activate="open" @prefetch="prefetch" />
<component
    :is="Panel"
    v-if="Panel"
    v-show="panelVisible"
    ref="panel"
    :avatar-visible="status === 'open'"
    @minimize="close(false)"
    @close="close(true)"
/>
</template>
