import { computed, ref, watch } from 'vue';

// Percakapan disimpan di sessionStorage karena halaman toko berpindah dengan muat ulang penuh.
// Yang disimpan hanya kunci jawaban, bukan tautan, jadi isi penyimpanan tidak bisa menjadi href.
const STORAGE_KEY = 'tuffero.cs-chat';
const REPLY_DELAY = 500;

const isValid = (m) => (m?.from === 'user' && typeof m.text === 'string') || (m?.from === 'cs' && typeof m.key === 'string');
const read = () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) ? saved.filter(isValid) : [];
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
        if (value.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));
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

export const greet = () => {
    if (!messages.value.length && !pending.value) reply({ from: 'cs', key: 'greeting' });
};

export const chooseQuickReply = (key, label) => {
    messages.value.push({ from: 'user', text: label });
    reply({ from: 'cs', key });
};

// Belum ada backend chat: pesan tidak dikirim ke mana pun, dan pengguna diberi tahu.
// Saat backend tersedia, kirim `text` lewat utils/api.js di sini dan tampilkan balasannya.
export const sendMessage = (text) => {
    messages.value.push({ from: 'user', text });
    reply({ from: 'cs', key: 'unsent', draft: text });
};

export const resetChat = () => {
    timers.forEach(clearTimeout);
    timers.clear();
    pending.value = 0;
    messages.value = [];
};
