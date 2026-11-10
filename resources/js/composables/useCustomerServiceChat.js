import { computed, reactive, ref, watch } from 'vue';
import api from '../utils/api';
import { customerService } from '../i18n/customerService';
import { FLOWS, chatHistory, describeOrder, failMessage, matchFaq, persistable, pickFlow, productOptions, ratesFailure, ratesText, whatsappLink } from '../utils/chatShared';
import { rupiah } from '../utils/orderLabels';
import { whatsappUrl } from '../utils/site';
import { locale } from './useLocale';

// Widget chat admin (Pemilik, Super Admin). Perilakunya sama dengan widget toko (store/chat.js); logika murninya ada di utils/chatShared.js.
// Percakapan disimpan di sessionStorage karena halaman toko berpindah dengan muat ulang penuh.
// Yang disimpan hanya kunci jawaban, bukan tautan, jadi isi penyimpanan tidak bisa menjadi href.
// Jawaban FAQ, hasil cek pesanan, dan hasil cek ongkir (m.result) tidak disimpan, supaya data pesanan tidak tertinggal di browser.
const STORAGE_KEY = 'tuffero.cs-chat';
const REPLY_DELAY = 500;
const words = customerService[locale];

const isValid = (m) => (m?.from === 'user' && typeof m.text === 'string') || (m?.from === 'cs' && typeof m.key === 'string');
const read = () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) ? persistable(saved.filter(isValid)) : [];
    } catch {
        return [];
    }
};

export const messages = ref(read());
const pending = ref(0);
export const typing = computed(() => pending.value > 0);
const timers = new Set();

watch(messages, (value) => {
    try {
        const saved = persistable(value);
        if (saved.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
        else sessionStorage.removeItem(STORAGE_KEY);
    } catch { /* penyimpanan diblokir: percakapan hanya bertahan di halaman ini */ }
}, { deep: true });

const reply = (message) => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        messages.value.push(message);
        return;
    }
    pending.value += 1;
    const timer = setTimeout(() => {
        timers.delete(timer);
        pending.value -= 1;
        messages.value.push(message);
    }, REPLY_DELAY);
    timers.add(timer);
};
const say = (text, linkKey) => reply({ from: 'cs', result: true, text, linkKey });

export const greet = () => {
    if (!messages.value.length && !pending.value) reply({ from: 'cs', key: 'greeting' });
};

// ---- FAQ dari admin (D-060), dimuat sekali. Pencocokan terjadi di browser; teks yang diketik tidak dikirim ke server.
export const faqs = ref([]);
export const ai = ref(false); // jawaban AI aktif di server (D-081)
let faqsPromise = null;
export const loadFaqs = () => {
    faqsPromise ??= api.get('/chat/faqs', { params: { lang: locale } })
        .then(({ data }) => { faqs.value = data.data.items; ai.value = Boolean(data.data.ai); })
        .catch(() => { faqsPromise = null; });
    return faqsPromise;
};
export const suggested = computed(() => faqs.value.filter((faq) => faq.suggested).slice(0, 5));

// ---- Form cek pesanan dan cek ongkir (D-067). Data pelanggan dihapus dari form begitu selesai atau dibatalkan.
export const flow = ref(null);
export const form = reactive({ orderNumber: '', contact: '', sku: '', quantity: 1, postal: '', formError: '', busy: false });
export const products = ref([]);
let productsLoaded = false;
const lastOrder = ref(''); // hanya di memori; ikut ringkasan WhatsApp, tidak disimpan

const resetForm = () => Object.assign(form, { orderNumber: '', contact: '', sku: '', quantity: 1, postal: '', formError: '', busy: false });

const loadProducts = async () => {
    if (productsLoaded) return;
    try {
        const { data } = await api.get('/catalog');
        products.value = productOptions(data.data.products);
        productsLoaded = true;
    } catch {
        form.formError = words.flow.failed;
    }
};

export const startFlow = (key) => {
    resetForm();
    flow.value = key;
    if (key === 'rates') loadProducts();
};

export const endFlow = () => {
    flow.value = null;
    resetForm();
};

export const submitTrack = async () => {
    if (form.busy) return;
    const f = words.flow;
    const orderNumber = form.orderNumber.trim();
    const contact = form.contact.trim();
    if (!orderNumber || !contact) { form.formError = f.fillAll; return; }
    form.busy = true;
    form.formError = '';
    try {
        const { data } = await api.post('/chat/order-status', { order_number: orderNumber, contact });
        say(describeOrder(f, data.data, locale), 'found');
        lastOrder.value = data.data.order_number;
        endFlow();
    } catch (e) {
        form.formError = failMessage(f, e, { 404: f.notFound });
    } finally {
        form.busy = false;
    }
};

export const submitRates = async () => {
    if (form.busy) return;
    const f = words.flow;
    const postal = form.postal.trim();
    const quantity = Math.max(1, parseInt(form.quantity, 10) || 1);
    if (!form.sku || !postal) { form.formError = f.fillAll; return; }
    if (!/^\d{5}$/.test(postal)) { form.formError = f.postalInvalid; return; }
    form.busy = true;
    form.formError = '';
    try {
        const { data } = await api.post('/chat/shipping-rates', { sku: form.sku, quantity, postal_code: postal });
        const product = products.value.find((p) => p.sku === form.sku)?.label ?? form.sku;
        const result = ratesText(f, data.data.rates, { postal, quantity, product }, rupiah);
        say(result.text, result.linkKey);
        endFlow();
    } catch (e) {
        const failure = ratesFailure(f, e);
        if (failure.error) {
            form.formError = failure.error;
        } else {
            say(failure.text, failure.linkKey);
            endFlow();
        }
    } finally {
        form.busy = false;
    }
};

// ---- Pesan
export const chooseQuickReply = (key, label) => {
    messages.value.push({ from: 'user', text: label });
    reply({ from: 'cs', key });
    if (FLOWS.includes(key)) startFlow(key);
    else endFlow();
};

// Tidak ada model AI (D-067) dan tidak ada tiket (D-080): teks bebas dicocokkan dengan kata kunci form, lalu dengan FAQ.
// Bila tidak ada yang cocok, pesan tidak dikirim ke mana pun dan pengguna diberi tahu.
export const sendMessage = async (text) => {
    messages.value.push({ from: 'user', text });
    await loadFaqs();
    const faq = matchFaq(faqs.value, text);
    const picked = pickFlow(words, text, faq);
    if (picked) {
        reply({ from: 'cs', key: picked });
        startFlow(picked);
        return;
    }
    endFlow();
    if (faq) say(faq.answer, 'help');
    else if (ai.value) await askAi(text);
    else reply({ from: 'cs', key: 'unsent', draft: text });
};

const answerText = (m) => {
    if (m.from === 'user' || m.result) return m.text;
    return m.key === 'greeting' ? words.greeting : words.answers[m.key];
};

// Pertanyaan yang tidak cocok dengan menu atau FAQ dijawab asisten AI (D-081). Gagal atau ditolak: jatuh ke tawaran WhatsApp.
const askAi = async (text) => {
    pending.value += 1;
    let answer = null;
    try {
        const { data } = await api.post('/chat/ask', { messages: chatHistory(messages.value, answerText) });
        answer = data.data.reply;
    } catch { /* batas percobaan, AI mati, atau gagal: tawarkan WhatsApp */ }
    pending.value -= 1;
    messages.value.push(answer ? { from: 'cs', result: true, text: answer, linkKey: 'help' } : { from: 'cs', key: 'unsent', draft: text });
};

export const askFaq = (faq) => {
    endFlow();
    messages.value.push({ from: 'user', text: faq.title });
    say(faq.answer, 'help');
};

// Tombol WhatsApp membawa ringkasan percakapan (D-080).
export const whatsappHref = () => whatsappLink(whatsappUrl(), words.summary, messages.value, lastOrder.value);

export const resetChat = () => {
    timers.forEach(clearTimeout);
    timers.clear();
    pending.value = 0;
    messages.value = [];
    lastOrder.value = '';
    endFlow();
};
