import api from './api';
import { rupiah } from './shop';
import { pageData, reducedMotion } from './site';
import { enableLauncherDrag } from '../utils/chatDrag';
import { FLOWS, chatHistory, scrollRowWithWheel, describeOrder, failMessage, matchFaq, persistable, pickFlow, productOptions, ratesFailure, ratesText, whatsappLink } from '../utils/chatShared';

// Widget chat toko. Admin (Pemilik, Super Admin) memakai komponen Vue terpisah (D-056) dengan CSS yang sama.
// Logika chat (pencocokan FAQ, ringkasan WhatsApp, form cek pesanan dan cek ongkir) dipakai bersama lewat utils/chatShared.js;
// tampilan dan HTTP tiap widget tetap terpisah. Teks ada di lang/{id,en}/store.php (toko) dan i18n/customerService.js (admin): ubah keduanya bersama.
// Percakapan disimpan di sessionStorage karena halaman toko berpindah dengan muat ulang penuh.
// Yang disimpan hanya kunci jawaban, bukan tautan, jadi isi penyimpanan tidak bisa menjadi href.
// Hasil cek pesanan dan ongkir (m.result) tidak pernah disimpan, supaya data pesanan tidak tertinggal di browser.
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
        return Array.isArray(saved) ? persistable(saved.filter(isValid)) : [];
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
    flow: null, // null -> track | rates
    orderNumber: '',
    contact: '',
    sku: '',
    quantity: 1,
    postal: '',
    busy: false,
    formError: '',
    products: [],
    productsLoaded: false,
    faqs: [],
    faqsPromise: null,
    ai: false, // jawaban AI aktif di server (D-081)
    dock: null, // null | 'menu' | 'faq': baris tombol yang terbuka di atas kolom pesan
    lastOrder: '', // hanya di memori halaman ini; ikut ringkasan WhatsApp, tidak disimpan

    init() {
        const data = pageData('tf-chat-data');
        this.c = data.words;
        this.links = data.links;
        this.whatsapp = data.whatsapp;
        this.newTab = data.newTab;
        this.messages = read();
        this.$watch('messages', (value) => {
            try {
                const saved = persistable(value);
                if (saved.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
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
        // Maskot bisa dipindah dengan tekan tahan (utils/chatDrag.js). Menunggu tata letak siap agar posisi tersimpan terukur benar.
        this.$nextTick(() => enableLauncherDrag(this.$refs.launcher));
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
    // Setelah percakapan berjalan, menu dan FAQ pindah ke baris ringkas di atas kolom pesan.
    get isCompact() { return this.messages.length > 1; },
    toggleDock(name) { this.dock = this.dock === name ? null : name; },
    onDockWheel(event) { scrollRowWithWheel(event); },
    get canSend() { return this.draft.trim().length > 0; },
    get sendClass() { return this.canSend ? 'is-ready' : ''; },
    get visibleMessages() {
        return this.messages
            .map((m, index) => ({ ...m, index }))
            .filter((m) => m.from === 'user' || m.result || m.key === 'greeting' || this.c.answers[m.key]);
    },
    get isTrack() { return this.flow === 'track'; },
    get isRates() { return this.flow === 'rates'; },
    get submitLabel() { return this.busy ? this.c.flow.checking : this.c.flow[this.isTrack ? 'submitTrack' : 'submitRates']; },
    labelFor(key) { return this.c.labels[key]; },
    isUser(m) { return m.from === 'user'; },
    isCs(m) { return m.from !== 'user'; },
    answerText(m) {
        if (m.result) return m.text;
        return m.key === 'greeting' ? this.c.greeting : this.c.answers[m.key];
    },
    whatsappHref() { return whatsappLink(this.whatsapp, this.c.summary, this.messages, this.lastOrder); },
    linksFor(m) {
        return (this.links[m.linkKey || m.key] || []).map((link) => ({
            ...link,
            // Teks hanya masuk ke URL WhatsApp saat pengguna sendiri mengeklik tautan ini.
            href: link.key === 'whatsapp' ? this.whatsappHref() : link.href,
            label: this.c.links[link.key],
            // Tombol WhatsApp berupa ikon: nama untuk pembaca layar dan teks tooltip disiapkan di sini (build Alpine CSP tidak menerima template string).
            aria: link.key === 'whatsapp' ? `${this.c.links[link.key]} (${this.newTab})` : null,
            tip: link.key === 'whatsapp' ? this.c.links[link.key] : null,
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
        this.loadFaqs();
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
        this.dock = null;
        this.messages = [...this.messages, { from: 'user', text: label }];
        this.reply({ from: 'cs', key });
        if (FLOWS.includes(key)) this.startFlow(key);
        else this.endFlow();
    },
    // Tidak ada model AI (D-067): teks bebas dicocokkan dengan kata kunci untuk membuka form, lalu dengan FAQ dari admin (D-060).
    // Pencocokan terjadi di browser; teks yang diketik tidak dikirim ke server. Bila tidak ada yang cocok,
    // pesan tidak dikirim ke mana pun, dan pengguna diberi tahu.
    async submit() {
        const text = this.draft.trim();
        if (!text) return;
        this.messages = [...this.messages, { from: 'user', text }];
        this.draft = '';
        await this.loadFaqs();
        const faq = this.matchFaq(text);
        const flow = pickFlow(this.c, text, faq);
        if (flow) {
            this.reply({ from: 'cs', key: flow });
            this.startFlow(flow);
            return;
        }
        this.endFlow();
        if (faq) this.say(faq.answer, 'help');
        else if (this.ai) await this.askAi(text);
        else this.reply({ from: 'cs', key: 'unsent', draft: text });
    },
    // FAQ dimuat sekali saat chat dibuka. Gagal memuat tidak mengganggu: chat jatuh ke jawaban "belum bisa dijawab".
    loadFaqs() {
        this.faqsPromise ??= api.get(`/chat/faqs?lang=${encodeURIComponent(document.documentElement.lang)}`)
            .then(({ data }) => { this.faqs = data.data.items; this.ai = Boolean(data.data.ai); })
            .catch(() => { this.faqsPromise = null; });
        return this.faqsPromise;
    },
    matchFaq(text) { return matchFaq(this.faqs, text); },
    // Tombol saran: FAQ yang ditandai admin. Mengetuknya sama seperti mengetik judulnya.
    get suggested() { return this.faqs.filter((faq) => faq.suggested).slice(0, 5); },
    askFaq(faq) {
        this.dock = null;
        this.endFlow();
        this.messages = [...this.messages, { from: 'user', text: faq.title }];
        this.say(faq.answer, 'help');
    },
    // Pertanyaan yang tidak cocok dengan menu atau FAQ dijawab asisten AI (D-081). Gagal atau ditolak: jatuh ke tawaran WhatsApp.
    async askAi(text) {
        this.pending += 1;
        this.scrollToEnd();
        let reply = null;
        try {
            const { data } = await api.post('/chat/ask', { messages: chatHistory(this.messages, (m) => (m.from === 'user' ? m.text : this.answerText(m))) });
            reply = data.data.reply;
        } catch { /* batas percobaan, AI mati, atau gagal: tawarkan WhatsApp */ }
        this.pending -= 1;
        this.messages = [...this.messages, reply ? { from: 'cs', result: true, text: reply, linkKey: 'help' } : { from: 'cs', key: 'unsent', draft: text }];
    },
    say(text, linkKey) { this.reply({ from: 'cs', result: true, text, linkKey }); },

    startFlow(key) {
        this.resetForm();
        this.flow = key;
        if (key === 'rates') this.loadProducts();
        this.scrollToEnd();
    },
    // Data pelanggan dihapus dari form begitu selesai atau dibatalkan.
    resetForm() {
        Object.assign(this, { orderNumber: '', contact: '', sku: '', quantity: 1, postal: '', formError: '', busy: false });
    },
    endFlow() {
        this.flow = null;
        this.resetForm();
    },
    async loadProducts() {
        if (this.productsLoaded) return;
        try {
            const { data } = await api.get('/catalog');
            this.products = productOptions(data.data.products);
            this.productsLoaded = true;
        } catch {
            this.formError = this.c.flow.failed;
        }
    },
    async submitTrack() {
        if (this.busy) return;
        const orderNumber = this.orderNumber.trim();
        const contact = this.contact.trim();
        if (!orderNumber || !contact) { this.formError = this.c.flow.fillAll; return; }
        this.busy = true;
        this.formError = '';
        try {
            const { data } = await api.post('/chat/order-status', { order_number: orderNumber, contact });
            this.say(describeOrder(this.c.flow, data.data, document.documentElement.lang), 'found');
            this.lastOrder = data.data.order_number;
            this.endFlow();
        } catch (e) {
            this.formError = failMessage(this.c.flow, e, { 404: this.c.flow.notFound });
        } finally {
            this.busy = false;
        }
    },
    async submitRates() {
        if (this.busy) return;
        const f = this.c.flow;
        const postal = this.postal.trim();
        const quantity = Math.max(1, parseInt(this.quantity, 10) || 1);
        if (!this.sku || !postal) { this.formError = f.fillAll; return; }
        if (!/^\d{5}$/.test(postal)) { this.formError = f.postalInvalid; return; }
        this.busy = true;
        this.formError = '';
        try {
            const { data } = await api.post('/chat/shipping-rates', { sku: this.sku, quantity, postal_code: postal });
            const product = this.products.find((p) => p.sku === this.sku)?.label ?? this.sku;
            const result = ratesText(f, data.data.rates, { postal, quantity, product }, rupiah);
            this.say(result.text, result.linkKey);
            this.endFlow();
        } catch (e) {
            const failure = ratesFailure(f, e);
            if (failure.error) {
                this.formError = failure.error;
            } else {
                this.say(failure.text, failure.linkKey);
                this.endFlow();
            }
        } finally {
            this.busy = false;
        }
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
        this.lastOrder = '';
        this.endFlow();
    },
});
