// Teks kartu voucher dipakai bersama halaman Voucher, Voucher Saya, dan keranjang.
const money = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;
const dateText = (iso) => new Date(iso).toLocaleDateString(document.documentElement.lang === 'en' ? 'en-GB' : 'id-ID', { day: 'numeric', month: 'short', year: 'numeric' });

export const card = {
    title(words, item) {
        return item.type === 'percent' ? words.off.replace('{value}', item.value + '%') : words.offFixed.replace('{value}', money(item.value));
    },
    meta(words, item) {
        const parts = [];
        if (item.min_subtotal) parts.push(words.min.replace('{amount}', money(item.min_subtotal)));
        if (item.type === 'percent' && item.max_discount) parts.push(words.upTo.replace('{amount}', money(item.max_discount)));
        if (item.ends_at) parts.push(words.until.replace('{date}', dateText(item.ends_at)));
        return parts.join(' · ');
    },
};
