import { pageData, reducedMotion } from './site';

// Widget chat toko. Admin (Pemilik, Super Admin) memakai komponen Vue yang sama perilakunya dan CSS yang sama.
// Percakapan disimpan di sessionStorage karena halaman toko berpindah dengan muat ulang penuh.
// Yang disimpan hanya kunci jawaban, bukan tautan, jadi isi penyimpanan tidak bisa menjadi href.
const STORAGE_KEY = 'tuffero.cs-chat';
const REPLY_DELAY = 500;
const OPEN_MS = 360;
const CLOSE_MS = 220;
const PRESS_MS = 170;
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';
const FIRST_ATTENTION = 8000;
const NEXT_ATTENTION = 40000;
const ATTENTION_LENGTH = 2200;
const MAX_ATTENTION = 3;

const isValid = (m) => (m?.from === 'user' && typeof m.text === 'string') || (m?.from === 'cs' && typeof m.key === 'string');
const read = () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) ? saved.filter(isValid) : [];
    } catch {
        return [];
    }
};
const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

export const chatWidget = () => ({
    c: {},
    links: {},
    whatsapp: '',
    newTab: '',
    messages: [],
    pending: 0,
    timers: new Set(),
    status: 'idle', // idle -> press -> opening -> open -> closing -> idle
    panelVisible: false,
    attention: false,
    draft: '',
    attentionTimer: null,
    shown: 0,

    init() {
        const data = pageData('tf-chat-data');
        this.c = data.words;
        this.links = data.links;
        this.whatsapp = data.whatsapp;
        this.newTab = data.newTab;
        this.messages = read();
        this.$watch('messages', (value) => {
            try {
                if (value.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));
                else sessionStorage.removeItem(STORAGE_KEY);
            } catch { /* penyimpanan diblokir: percakapan hanya bertahan di halaman ini */ }
            this.scrollToEnd();
        });
        this.$watch('status', (value) => { if (value !== 'idle') this.attention = false; });
        if (!reducedMotion()) this.schedule(FIRST_ATTENTION);
        // Pada mobile keyboard mengecilkan visual viewport, bukan layout viewport: panel dinaikkan agar kolom pesan tetap terlihat.
        window.visualViewport?.addEventListener('resize', () => this.fitViewport());
        window.visualViewport?.addEventListener('scroll', () => this.fitViewport());
        this.fitViewport();
    },

    // Sapaan hanya beberapa kali per muat halaman, dan tidak saat tab tersembunyi.
    schedule(delay) {
        clearTimeout(this.attentionTimer);
        if (this.shown >= MAX_ATTENTION) return;
        this.attentionTimer = setTimeout(() => {
            if (document.hidden || this.status !== 'idle') { this.schedule(NEXT_ATTENTION); return; }
            this.shown += 1;
            this.attention = true;
            this.attentionTimer = setTimeout(() => {
                this.attention = false;
                this.schedule(NEXT_ATTENTION);
            }, ATTENTION_LENGTH);
        }, delay);
    },
    fitViewport() {
        const viewport = window.visualViewport;
        const root = this.$refs.panel;
        if (!viewport || !root) return;
        const mobile = window.matchMedia('(max-width: 767px)').matches;
        root.style.maxHeight = mobile ? `${viewport.height}px` : '';
        root.style.bottom = mobile ? `${Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop)}px` : '';
        this.scrollToEnd();
    },

    get typing() { return this.pending > 0; },
    get isOpen() { return this.status === 'open'; },
    get backdropClass() { return this.status === 'opening' || this.status === 'open' ? 'is-on' : ''; },
    get attentionValue() { return this.attention ? 'true' : null; },
    get avatarClass() { return this.status === 'open' ? '' : 'is-hidden'; },
    get hasMessages() { return this.messages.length > 0; },
    get chipsClass() { return this.messages.length > 1 ? 'is-compact' : ''; },
    get canSend() { return this.draft.trim().length > 0; },
    get sendClass() { return this.canSend ? 'is-ready' : ''; },
    get visibleMessages() {
        return this.messages
            .map((m, index) => ({ ...m, index }))
            .filter((m) => m.from === 'user' || m.key === 'greeting' || this.c.answers[m.key]);
    },
    labelFor(key) { return this.c.labels[key]; },
    isUser(m) { return m.from === 'user'; },
    isCs(m) { return m.from !== 'user'; },
    answerText(m) { return m.key === 'greeting' ? this.c.greeting : this.c.answers[m.key]; },
    linksFor(m) {
        return (this.links[m.key] || []).map((link) => ({
            ...link,
            // Teks hanya masuk ke URL WhatsApp saat pengguna sendiri mengeklik tautan ini.
            href: link.key === 'whatsapp' ? `${this.whatsapp}${m.draft ? `?text=${encodeURIComponent(m.draft)}` : ''}` : link.href,
            label: this.c.links[link.key],
            target: link.external ? '_blank' : null,
            rel: link.external ? 'noopener noreferrer' : null,
        }));
    },

    panelFrames() {
        if (reducedMotion()) return [{ opacity: 0 }, { opacity: 1 }];
        const hidden = window.matchMedia('(max-width: 767px)').matches ? 'translateY(100%)' : 'translateY(20px) scale(0.97)';
        return [{ opacity: 0, transform: hidden }, { opacity: 1, transform: 'none' }];
    },
    // Maskot terbang antara tombol dan header panel. Posisi diukur sebelum animasi panel dimulai.
    mascotFrames() {
        if (reducedMotion()) return null;
        const from = this.$refs.mascot.getBoundingClientRect();
        const to = this.$refs.avatar.getBoundingClientRect();
        const x = to.left + to.width / 2 - (from.left + from.width / 2);
        const y = to.top + to.height / 2 - (from.top + from.height / 2);
        return [{ transform: 'none' }, { transform: `translate(${x}px, ${y}px) scale(${to.width / from.width})` }];
    },
    play(duration, reverse) {
        const options = { duration: reducedMotion() ? 120 : duration, easing: EASE, direction: reverse ? 'reverse' : 'normal', fill: 'both' };
        const frames = this.mascotFrames();
        const animations = [this.$refs.panel.animate(this.panelFrames(), options)];
        if (frames) animations.push(this.$refs.mascot.animate(frames, options));
        return animations;
    },
    async settle(animations) {
        await Promise.all(animations.map((animation) => animation.finished));
        return () => animations.forEach((animation) => animation.cancel());
    },
    // event.detail 0 = dibuka dari keyboard: tanpa jeda tekan.
    async open(event) {
        if (this.status !== 'idle') return;
        const usePress = event.detail > 0 && !reducedMotion();
        if (usePress) { this.status = 'press'; await wait(PRESS_MS); }
        this.status = 'opening';
        this.panelVisible = true;
        await this.$nextTick();
        const finish = await this.settle(this.play(OPEN_MS, false));
        this.status = 'open';
        await this.$nextTick();
        finish();
        const target = window.matchMedia('(max-width: 1023px), (pointer: coarse)').matches ? this.$refs.panel : this.$refs.input;
        target?.focus({ preventScroll: true });
        this.scrollToEnd();
        this.greet();
    },
    async close(reset) {
        if (this.status !== 'open') return;
        const animations = this.play(CLOSE_MS, true);
        this.status = 'closing';
        const finish = await this.settle(animations);
        this.panelVisible = false;
        this.status = 'idle';
        await this.$nextTick();
        finish();
        if (reset) this.resetChat();
        this.$refs.launcher.focus({ preventScroll: true });
    },
    minimize() { return this.close(false); },
    closeAndReset() { return this.close(true); },

    scrollToEnd() {
        this.$nextTick(() => { const log = this.$refs.log; if (log) log.scrollTop = log.scrollHeight; });
    },
    reply(message) {
        if (reducedMotion()) { this.messages = [...this.messages, message]; return; }
        this.pending += 1;
        this.scrollToEnd();
        const timer = setTimeout(() => {
            this.timers.delete(timer);
            this.pending -= 1;
            this.messages = [...this.messages, message];
        }, REPLY_DELAY);
        this.timers.add(timer);
    },
    greet() {
        if (!this.messages.length && !this.pending) this.reply({ from: 'cs', key: 'greeting' });
    },
    choose(key, label) {
        this.messages = [...this.messages, { from: 'user', text: label }];
        this.reply({ from: 'cs', key });
    },
    // Belum ada backend chat: pesan tidak dikirim ke mana pun, dan pengguna diberi tahu.
    submit() {
        const text = this.draft.trim();
        if (!text) return;
        this.messages = [...this.messages, { from: 'user', text }];
        this.reply({ from: 'cs', key: 'unsent', draft: text });
        this.draft = '';
    },
    // Enter saat menyusun karakter IME bukan perintah kirim.
    onEnter(event) {
        if (event.shiftKey || event.isComposing) return;
        event.preventDefault();
        this.submit();
    },
    resetChat() {
        this.timers.forEach(clearTimeout);
        this.timers.clear();
        this.pending = 0;
        this.messages = [];
    },
});
