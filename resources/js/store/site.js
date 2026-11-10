// Perilaku kecil bersama halaman toko yang tidak butuh Alpine.

// Data halaman dari server, ditanam sebagai JSON (bukan skrip yang dijalankan).
export const pageData = (id) => {
    const el = document.getElementById(id);
    return el ? JSON.parse(el.textContent) : null;
};

export const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Ganti bahasa memuat ulang halaman, karena teks dirender server dan animasi template mengubah DOM langsung.
export const setLocale = (code) => {
    if (code === document.documentElement.lang) return;
    document.cookie = `tuffero_locale=${code}; path=/; max-age=31536000; samesite=lax`;
    try { localStorage.setItem('tuffero.locale', code); } catch { /* cookie tetap menyimpan pilihan */ }
    window.location.reload();
};

// Tautan seperti /#offers: judul bagian tidak tertutup header yang menempel. #t= di halaman pesanan adalah token akses, bukan jangkar.
export const scrollToHash = () => {
    const { hash } = window.location;
    if (hash.length < 2 || hash.startsWith('#t=')) return;
    const target = document.getElementById(hash.slice(1));
    if (target) window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - 104, behavior: 'instant' });
};

const initBackToTop = () => {
    const button = document.querySelector('.tf-top');
    if (!button) return;
    const onScroll = () => {
        const show = window.scrollY > 480;
        button.classList.toggle('is-on', show);
        button.inert = !show;
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' }));
};

// Gelombang footer hanya bergerak saat terlihat (hemat CPU dan baterai).
const initFooterWave = () => {
    const wave = document.querySelector('.tf-footer-wave');
    if (!wave || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(([entry]) => wave.classList.toggle('is-paused', !entry.isIntersecting)).observe(wave);
};

// Ikon Bahasa di bar atas: tautan biasa untuk browser tanpa JavaScript, ditangani di sini agar pilihan tersimpan.
const initLocaleLinks = () => {
    document.querySelectorAll('[data-set-locale]').forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        setLocale(el.dataset.setLocale);
    }));
};

export const initSite = () => {
    initBackToTop();
    initFooterWave();
    initLocaleLinks();
    window.addEventListener('load', scrollToHash);
    window.addEventListener('hashchange', scrollToHash);
};
