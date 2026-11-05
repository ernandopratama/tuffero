<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TfLineIcon from '../TfLineIcon';
import { locale, t } from '../../composables/useLocale';
import { ANSWER_LINKS, QUICK_REPLIES, customerService } from '../../i18n/customerService';
import { openStatus, whatsappUrl } from '../../utils/site';
import { chooseQuickReply, messages, sendMessage, typing } from '../../composables/useCustomerServiceChat';

defineProps({ avatarVisible: { type: Boolean, default: false } });
defineEmits(['minimize', 'close']);

const c = customerService[locale];
const isOpen = openStatus().open;

const root = ref(null);
const avatar = ref(null);
const log = ref(null);
const input = ref(null);
const draft = ref('');
const canSend = computed(() => draft.value.trim().length > 0);

const scrollToEnd = () => nextTick(() => { if (log.value) log.value.scrollTop = log.value.scrollHeight; });
watch([() => messages.value.length, typing], scrollToEnd);

const linksFor = (message) => (ANSWER_LINKS[message.key] || []).map((link) => ({
    ...link,
    // Teks hanya masuk ke URL WhatsApp saat pengguna sendiri mengeklik tautan ini.
    href: link.key === 'whatsapp' ? `${whatsappUrl()}${message.draft ? `?text=${encodeURIComponent(message.draft)}` : ''}` : link.href,
}));

const submit = () => {
    const text = draft.value.trim();
    if (!text) return;
    sendMessage(text);
    draft.value = '';
};

const onEnter = (event) => {
    // Enter saat menyusun karakter IME bukan perintah kirim.
    if (event.shiftKey || event.isComposing) return;
    event.preventDefault();
    submit();
};

// Pada mobile keyboard mengecilkan visual viewport, bukan layout viewport: panel dinaikkan agar kolom pesan tetap terlihat.
const mobile = window.matchMedia('(max-width: 767px)');
const fitViewport = () => {
    const viewport = window.visualViewport;
    if (!viewport || !root.value) return;
    root.value.style.maxHeight = mobile.matches ? `${viewport.height}px` : '';
    root.value.style.bottom = mobile.matches ? `${Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop)}px` : '';
    scrollToEnd();
};

onMounted(() => {
    window.visualViewport?.addEventListener('resize', fitViewport);
    window.visualViewport?.addEventListener('scroll', fitViewport);
    fitViewport();
});
onBeforeUnmount(() => {
    window.visualViewport?.removeEventListener('resize', fitViewport);
    window.visualViewport?.removeEventListener('scroll', fitViewport);
});

// Di tablet, HP, dan layar sentuh fokus ke panel saja: keyboard baru muncul saat pengguna mengetuk kolom pesan.
const focusStart = () => {
    const target = window.matchMedia('(max-width: 1023px), (pointer: coarse)').matches ? root.value : input.value;
    target?.focus({ preventScroll: true });
};

defineExpose({ root, avatar, focusStart, scrollToEnd });
</script>

<template>
<section ref="root" class="cs-panel" role="dialog" aria-labelledby="cs-panel-title" tabindex="-1" @keydown.esc="$emit('minimize')">
    <svg class="cs-panel-sparks" viewBox="0 0 40 34" aria-hidden="true">
        <path d="M8 2 L13 3 L7 18 Z" /><path d="M31 9 L35 13 L19 22 Z" />
    </svg>
    <header class="cs-head">
        <img ref="avatar" class="cs-avatar" :class="{ 'is-hidden': !avatarVisible }" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
        <div class="cs-head-text">
            <h2 id="cs-panel-title" class="cs-title">{{ c.title }}</h2>
            <p class="cs-status"><span class="cs-dot" :class="{ 'is-open': isOpen }" aria-hidden="true"></span>{{ isOpen ? c.statusOpen : c.statusClosed }}</p>
        </div>
        <button type="button" class="cs-head-btn" :aria-label="c.minimize" @click="$emit('minimize')"><TfLineIcon name="minus" :size="20" /></button>
        <button type="button" class="cs-head-btn" :aria-label="c.close" @click="$emit('close')"><TfLineIcon name="close" :size="20" /></button>
    </header>

    <div ref="log" class="cs-log" role="log" aria-live="polite" :aria-label="c.log" tabindex="0">
        <template v-for="(message, index) in messages" :key="index">
            <div v-if="message.from === 'user'" class="cs-msg cs-msg-user"><div class="cs-bubble">{{ message.text }}</div></div>
            <div v-else-if="message.key === 'greeting' || c.answers[message.key]" class="cs-msg">
                <img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
                <div class="cs-bubble">
                    <p>{{ message.key === 'greeting' ? c.greeting : c.answers[message.key] }}</p>
                    <a
                        v-for="link in linksFor(message)"
                        :key="link.key"
                        class="cs-link"
                        :href="link.href"
                        :target="link.external ? '_blank' : undefined"
                        :rel="link.external ? 'noopener noreferrer' : undefined"
                    >{{ c.links[link.key] }}<span v-if="link.external" class="cs-sr"> ({{ t.aria.newTab }})</span></a>
                </div>
            </div>
        </template>
        <div v-if="typing" class="cs-msg">
            <img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
            <div class="cs-bubble cs-typing" role="status" :aria-label="c.typing"><i></i><i></i><i></i></div>
        </div>
        <div v-if="messages.length" class="cs-chips" :class="{ 'is-compact': messages.length > 1 }" role="group" :aria-label="c.quickReplies">
            <button v-for="reply in QUICK_REPLIES" :key="reply.key" type="button" class="cs-chip" :disabled="typing" @click="chooseQuickReply(reply.key, c.labels[reply.key])">
                <TfLineIcon :name="reply.icon" :size="16" />{{ c.labels[reply.key] }}
            </button>
        </div>
    </div>

    <form class="cs-form" @submit.prevent="submit">
        <textarea ref="input" v-model="draft" class="cs-input" rows="1" maxlength="500" :aria-label="c.inputLabel" :placeholder="c.placeholder" @keydown.enter="onEnter"></textarea>
        <button type="submit" class="cs-send" :class="{ 'is-ready': canSend }" :disabled="!canSend" :aria-label="c.send"><TfLineIcon name="send" :size="18" /></button>
    </form>
    <p class="cs-notice">{{ c.notice }}</p>
</section>
</template>
