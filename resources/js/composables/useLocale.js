import { DEFAULT_LOCALE, LOCALES, messages } from '../i18n/landing';

// Bahasa: default Indonesia (D-036). Pilihan disimpan di localStorage.
const readLocale = () => {
    // ?lang=id|en untuk tautan dan pengujian; menang atas pilihan tersimpan.
    try { const q = new URLSearchParams(window.location.search).get('lang'); if (LOCALES.includes(q)) return q; } catch { /* abaikan */ }
    try { const saved = localStorage.getItem('tuffero.locale'); if (LOCALES.includes(saved)) return saved; } catch { /* penyimpanan diblokir: pakai default */ }
    return DEFAULT_LOCALE;
};

export { LOCALES };
export const locale = readLocale();
export const t = messages[locale];
document.documentElement.lang = locale;

// Ganti bahasa memuat ulang halaman: animasi template (SplitText, WOW, Swiper) mengubah DOM langsung dan tidak bisa dirender ulang Vue.
export const setLocale = (code) => {
    if (code === locale) return;
    try { localStorage.setItem('tuffero.locale', code); } catch { /* abaikan */ }
    window.location.reload();
};
