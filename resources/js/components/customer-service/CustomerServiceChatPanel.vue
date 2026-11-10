<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TfLineIcon from '../TfLineIcon';
import { locale, t } from '../../composables/useLocale';
import { ANSWER_LINKS, QUICK_REPLIES, customerService } from '../../i18n/customerService';
import { ROUTES, openStatus } from '../../utils/site';
import { scrollRowWithWheel } from '../../utils/chatShared';
import { ai, askFaq, chooseQuickReply, endFlow, flow, form, loadFaqs, messages, products, sendMessage, submitRates, submitTrack, suggested, typing, whatsappHref } from '../../composables/useCustomerServiceChat';

defineProps({ avatarVisible: { type: Boolean, default: false } });
defineEmits(['minimize', 'close']);

const c = customerService[locale];
const isOpen = openStatus().open;

const root = ref(null);
const avatar = ref(null);
const log = ref(null);
const input = ref(null);
const draft = ref('');
// Baris tombol ringkas di atas kolom pesan: null | 'menu' | 'faq'. Tertutup lagi setelah satu tombol dipilih.
const dock = ref(null);
const toggleDock = (name) => { dock.value = dock.value === name ? null : name; };
const pick = (action) => { dock.value = null; action(); };
const canSend = computed(() => draft.value.trim().length > 0);

const scrollToEnd = () => nextTick(() => { if (log.value) log.value.scrollTop = log.value.scrollHeight; });
watch([() => messages.value.length, typing], scrollToEnd);

const linksFor = (message) => (ANSWER_LINKS[message.linkKey || message.key] || []).map((link) => ({
    ...link,
    // Ringkasan percakapan hanya masuk ke URL WhatsApp saat pengguna sendiri mengeklik tautan ini.
    href: link.key === 'whatsapp' ? whatsappHref() : link.href,
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
    loadFaqs();
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
            <div v-else-if="message.result || message.key === 'greeting' || c.answers[message.key]" class="cs-msg">
                <img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
                <div class="cs-bubble">
                    <p>{{ message.result ? message.text : message.key === 'greeting' ? c.greeting : c.answers[message.key] }}</p>
                    <a
                        v-for="link in linksFor(message)"
                        :key="link.key"
                        class="cs-link"
                        :class="{ 'cs-wa': link.key === 'whatsapp' }"
                        :href="link.href"
                        :target="link.external ? '_blank' : undefined"
                        :rel="link.external ? 'noopener noreferrer' : undefined"
                        :aria-label="link.key === 'whatsapp' ? `${c.links.whatsapp} (${t.aria.newTab})` : undefined"
                        :data-tip="link.key === 'whatsapp' ? c.links.whatsapp : undefined"
                    ><template v-if="link.key === 'whatsapp'"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg></template><template v-else>{{ c.links[link.key] }}<span v-if="link.external" class="cs-sr"> ({{ t.aria.newTab }})</span></template></a>
                </div>
            </div>
        </template>
        <div v-if="typing" class="cs-msg">
            <img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
            <div class="cs-bubble cs-typing" role="status" :aria-label="c.typing"><i></i><i></i><i></i></div>
        </div>
        <div v-if="messages.length === 1" class="cs-chips" role="group" :aria-label="c.quickReplies">
            <button v-for="reply in QUICK_REPLIES" :key="reply.key" type="button" class="cs-chip" :disabled="typing" @click="chooseQuickReply(reply.key, c.labels[reply.key])">
                <TfLineIcon :name="reply.icon" :size="16" />{{ c.labels[reply.key] }}
            </button>
            <p v-if="suggested.length" class="cs-chips-label">{{ c.faqHeading }}</p>
            <button v-for="faq in suggested" :key="faq.id" type="button" class="cs-chip cs-chip-faq" :disabled="typing" @click="askFaq(faq)">{{ faq.title }}</button>
        </div>
        <form v-if="flow === 'track'" class="cs-flow" novalidate @submit.prevent="submitTrack">
            <label class="cs-field"><span>{{ c.flow.orderNumber }}</span>
                <input v-model="form.orderNumber" type="text" maxlength="20" autocomplete="off" autocapitalize="characters" :placeholder="c.flow.orderNumberHint">
            </label>
            <label class="cs-field"><span>{{ c.flow.contact }}</span>
                <input v-model="form.contact" type="text" maxlength="190" autocomplete="off" inputmode="email">
            </label>
            <p v-if="form.formError" class="cs-flow-error" role="alert">{{ form.formError }}</p>
            <div class="cs-flow-actions">
                <button type="submit" class="cs-flow-submit" :disabled="form.busy">{{ form.busy ? c.flow.checking : c.flow.submitTrack }}</button>
                <button type="button" class="cs-flow-cancel" @click="endFlow">{{ c.flow.cancel }}</button>
            </div>
        </form>
        <form v-if="flow === 'rates'" class="cs-flow" novalidate @submit.prevent="submitRates">
            <label class="cs-field"><span>{{ c.flow.product }}</span>
                <select v-model="form.sku">
                    <option value="">{{ c.flow.chooseProduct }}</option>
                    <option v-for="product in products" :key="product.sku" :value="product.sku">{{ product.label }}</option>
                </select>
            </label>
            <div class="cs-field-row">
                <label class="cs-field"><span>{{ c.flow.quantity }}</span>
                    <input v-model="form.quantity" type="number" min="1" max="999" inputmode="numeric">
                </label>
                <label class="cs-field"><span>{{ c.flow.postal }}</span>
                    <input v-model="form.postal" type="text" maxlength="5" inputmode="numeric" autocomplete="postal-code">
                </label>
            </div>
            <p v-if="form.formError" class="cs-flow-error" role="alert">{{ form.formError }}</p>
            <div class="cs-flow-actions">
                <button type="submit" class="cs-flow-submit" :disabled="form.busy">{{ form.busy ? c.flow.checking : c.flow.submitRates }}</button>
                <button type="button" class="cs-flow-cancel" @click="endFlow">{{ c.flow.cancel }}</button>
            </div>
        </form>
    </div>

    <!-- Setelah percakapan berjalan, menu dan FAQ dipindah ke sini dan dirapatkan: dua tombol, masing-masing membuka satu baris tombol yang bisa digeser. -->
    <div v-if="messages.length > 1" class="cs-dock">
        <Transition name="cs-dock">
            <div v-if="dock" id="cs-dock-row" class="cs-dock-row" role="group" :aria-label="dock === 'faq' ? c.faqHeading : c.quickReplies" @wheel="scrollRowWithWheel">
                <div class="cs-dock-chips">
                    <template v-if="dock === 'menu'">
                        <button v-for="reply in QUICK_REPLIES" :key="reply.key" type="button" class="cs-chip" :disabled="typing" @click="pick(() => chooseQuickReply(reply.key, c.labels[reply.key]))">
                            <TfLineIcon :name="reply.icon" :size="16" />{{ c.labels[reply.key] }}
                        </button>
                    </template>
                    <template v-else>
                        <button v-for="faq in suggested" :key="faq.id" type="button" class="cs-chip cs-chip-faq" :disabled="typing" @click="pick(() => askFaq(faq))">{{ faq.title }}</button>
                    </template>
                </div>
            </div>
        </Transition>
        <div class="cs-dock-tabs">
            <button type="button" class="cs-dock-tab" aria-controls="cs-dock-row" :aria-expanded="dock === 'menu'" @click="toggleDock('menu')">{{ c.quickReplies }}</button>
            <button v-if="suggested.length" type="button" class="cs-dock-tab" aria-controls="cs-dock-row" :aria-expanded="dock === 'faq'" @click="toggleDock('faq')">{{ c.faqShort }}</button>
        </div>
    </div>
    <form class="cs-form" @submit.prevent="submit">
        <textarea ref="input" v-model="draft" class="cs-input" rows="1" maxlength="500" :aria-label="c.inputLabel" :placeholder="c.placeholder" @keydown.enter="onEnter"></textarea>
        <button type="submit" class="cs-send" :class="{ 'is-ready': canSend }" :disabled="!canSend" :aria-label="c.send"><TfLineIcon name="send" :size="18" /></button>
    </form>
    <p class="cs-notice">{{ ai ? c.noticeAi : c.notice }}<template v-if="ai"> <a class="cs-notice-link" :href="`${ROUTES.contact}#privasi`">{{ c.privacyLink }}</a></template></p>
</section>
</template>
