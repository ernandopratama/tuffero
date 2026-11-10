// Logika chat yang dipakai bersama widget toko (Alpine, store/chat.js) dan widget admin (Vue, composables/useCustomerServiceChat.js),
// supaya pencocokan FAQ dan ringkasan WhatsApp tidak berbeda antara keduanya (D-056).

const ACCENTS = new RegExp('[\\u0300-\\u036f]', 'g');

// Huruf kecil, tanpa aksen dan tanda baca, spasi tunggal: "Jam-buka?" dan "jam buka" dianggap sama. "&" tetap ada untuk "J&T".
export const normalize = (text) => text.toLowerCase().normalize('NFD').replace(ACCENTS, '').replace(/[^a-z0-9&]+/g, ' ').trim();

// Selisih paling banyak satu huruf: salah ganti, kurang, lebih, atau dua huruf bertukar tempat.
const withinOneEdit = (a, b) => {
    if (a === b) return true;
    const diff = a.length - b.length;
    if (Math.abs(diff) > 1) return false;
    let i = 0;
    while (i < a.length && i < b.length && a[i] === b[i]) i += 1;
    if (diff === 0) return a.slice(i + 1) === b.slice(i + 1) || (a[i] === b[i + 1] && a[i + 1] === b[i] && a.slice(i + 2) === b.slice(i + 2));
    return diff > 0 ? a.slice(i + 1) === b.slice(i) : b.slice(i + 1) === a.slice(i);
};

// shortcut: cocok bila kata kunci muncul di mana pun dalam teks (bukan per kata utuh); skor 2 untuk kecocokan penuh dan 1 untuk salah ketik satu huruf pada kata kunci satu kata (min. 5 huruf). Ganti ke pencocokan per kata bila salah jawab sering.
export const matchFaq = (faqs, text) => {
    const clean = normalize(text);
    const words = clean.split(' ').filter((word) => word.length >= 4);
    let best = null;
    let bestScore = 0;
    for (const faq of faqs) {
        const score = faq.keywords.reduce((sum, raw) => {
            const word = normalize(raw);
            if (clean.includes(word)) return sum + 2;
            if (word.length >= 5 && !word.includes(' ') && words.some((w) => withinOneEdit(w, word))) return sum + 1;
            return sum;
        }, 0);
        if (score > bestScore) { best = faq; bestScore = score; }
    }
    return best ? { ...best, score: bestScore } : null;
};

// Pesan pembuka WhatsApp: tiga hal terakhir yang diketik atau dipilih pelanggan, dan nomor pesanan bila ia memeriksanya di chat.
// Tidak pernah memuat email, HP, atau alamat. Hanya ada di URL tautan; tidak disimpan atau dikirim ke server kami (D-080).
export const whatsappLink = (base, words, messages, orderNumber = '') => {
    const asked = messages.filter((m) => m.from === 'user').slice(-3).map((m) => `- ${m.text.slice(0, 120)}`);
    const lines = [words.hello];
    if (asked.length) lines.push(words.asked, ...asked);
    if (orderNumber) lines.push(words.order.replace(':number', orderNumber));
    return `${base}?text=${encodeURIComponent(lines.join('\n'))}`;
};

// ---- Alur form di dalam chat: cek pesanan dan cek ongkir (D-067). Hanya logika murni; tiap widget mengurus tampilan dan HTTP-nya sendiri.

export const FLOWS = ['track', 'rates'];
const TRACKING_WORDS = /resi|lacak|track|status|cek|check|waybill|sampai mana|where/i;
const fill = (text, vars) => text.replace(/:(\w+)/g, (match, key) => vars[key] ?? match);

// Form mana yang diminta teks bebas, atau null. Kata "pesanan" saja bukan permintaan cek pesanan ("pesanan saya rusak"):
// FAQ yang cocok penuh menang, kecuali ada kata pelacakan yang jelas.
export const pickFlow = (words, text, faq) => {
    // Kata kunci harus di awal kata: "resi" cocok dengan "resinya", bukan dengan "presiden".
    const flow = FLOWS.find((key) => new RegExp(`(?:^|[^a-z])(?:${words.keywords[key]})`, 'i').test(text));
    return flow && !(flow === 'track' && faq && faq.score >= 2 && !TRACKING_WORDS.test(text)) ? flow : null;
};

export const failMessage = (f, e, extra = {}) => {
    const status = e.response?.status;
    if (status === 429) return f.tooMany;
    return extra[status] || f.failed;
};

const courierName = (f, code) => f.couriers[code] || String(code).toUpperCase();

export const describeOrder = (f, order, lang) => {
    const parts = [fill(f.orderLine, { number: order.order_number, status: f.status[order.status] || order.status })];
    if (order.courier) parts.push(fill(f.courierLine, { courier: `${courierName(f, order.courier)}${order.service ? ` ${String(order.service).toUpperCase()}` : ''}` }));
    if (order.waybill) parts.push(fill(f.waybillLine, { waybill: order.waybill }));
    if (order.expires_at) {
        const time = new Date(order.expires_at).toLocaleString(lang === 'en' ? 'en-GB' : 'id-ID', { timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        parts.push(fill(f.payBefore, { time }));
    }
    return parts.join(' ');
};

export const productOptions = (products) => products.flatMap((product) => product.variants.map((variant) => ({
    sku: variant.sku,
    label: product.variants.length > 1 ? `${product.name} ${variant.name}` : product.name,
})));

// Jawaban cek ongkir: { text, linkKey? }. Tanpa tarif, pelanggan diarahkan ke WhatsApp.
export const ratesText = (f, rates, context, format) => (rates.length
    ? { text: [fill(f.ratesIntro, context), ...rates.map((rate) => `${rate.label}: ${format(rate.fee)}`)].join('\n') }
    : { text: f.ratesUnavailable, linkKey: 'help' });

// Galat cek ongkir: { text, linkKey } bila alurnya selesai dengan jawaban, atau { error } bila form tetap terbuka.
export const ratesFailure = (f, e) => {
    const errors = e.response?.data?.errors || {};
    if (e.response?.status === 422 && errors.shipping) return { text: f.ratesUnavailable, linkKey: 'help' };
    if (e.response?.status === 422 && errors.sku) return { error: f.productUnavailable };
    if (e.response?.status === 422 && errors.postal_code) return { error: f.postalInvalid };
    return { error: failMessage(f, e) };
};

// ---- Asisten AI (D-081): riwayat yang dikirim ke server untuk pertanyaan bebas yang tidak cocok dengan menu atau FAQ.
// Hanya teks percakapan di layar; tanpa nomor pesanan atau kontak dari form. Server membatasi jumlah dan panjangnya lagi.
export const AI_HISTORY = 10;
export const chatHistory = (messages, textOf) => messages
    .map((m) => ({ role: m.from === 'user' ? 'user' : 'assistant', text: String(textOf(m) ?? '').slice(0, 1200) }))
    .filter((m) => m.text.trim() !== '')
    .slice(-AI_HISTORY);

// ---- Penyimpanan percakapan di sessionStorage.
// Jawaban berbentuk teks (FAQ, AI, hasil cek pesanan dan ongkir) tidak disimpan. Supaya setelah muat ulang tidak ada
// pertanyaan pelanggan yang tertinggal tanpa jawaban, pertanyaan itu ikut tidak disimpan: pesan pelanggan hanya disimpan
// bila balasan tepat sesudahnya juga disimpan.
export const persistable = (messages) => messages.filter((m, i) => {
    if (m.result) return false;
    if (m.from !== 'user') return true;
    const next = messages[i + 1];
    return Boolean(next && next.from !== 'user' && !next.result);
});

// Roda mouse vertikal menggeser baris tombol ke samping (trackpad dan layar sentuh sudah bisa menggeser sendiri).
export const scrollRowWithWheel = (event) => {
    const row = event.currentTarget;
    if (Math.abs(event.deltaY) <= Math.abs(event.deltaX) || row.scrollWidth <= row.clientWidth) return;
    row.scrollLeft += event.deltaY;
    event.preventDefault();
};
