import { reducedMotion } from './site';

// Latar hero: dua video lokal diputar bergantian tanpa suara dan diulang terus (tanpa YouTube).
// Tidak dimuat bila pengguna mematikan animasi atau memakai Save-Data; foto tetap tampil sebagai latar.
const initHero = async () => {
    const box = document.querySelector('.tf-hero-box');
    if (!box) return;
    const wrap = box.querySelector('.tf-hero-video');
    const videos = [...box.querySelectorAll('.tf-hero-video video')];
    let index = 0;

    const setActive = (next) => {
        index = next;
        videos.forEach((video, i) => video.classList.toggle('is-on', i === next));
    };
    videos.forEach((video, i) => {
        video.addEventListener('playing', () => wrap.classList.add('is-playing'));
        video.addEventListener('ended', async () => {
            const next = (i + 1) % videos.length;
            videos[next].currentTime = 0;
            try { await videos[next].play(); setActive(next); } catch { /* autoplay ditolak: foto tetap tampil */ }
        });
    });

    // Tombol TikTok bergetar terus (filter SVG): jeda saat hero di luar layar.
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => box.classList.toggle('is-away', !entry.isIntersecting)).observe(box);
    }
    const conn = navigator.connection;
    // Video latar tidak dimuat bila hemat data atau koneksi lambat (2G/3G); foto tetap tampil.
    if (reducedMotion() || (conn && (conn.saveData || /(^|-)(2g|3g)$/.test(conn.effectiveType || ''))) || !videos.length) return;
    // Video baru diputar setelah preloader logo selesai.
    await (window.tufferoLoaded || Promise.resolve());
    // Jeda singkat: dekode video tidak bersaing dengan pudar keluar loader dan animasi hero.
    await new Promise((resolve) => setTimeout(resolve, 600));
    try {
        await videos[0].play();
        wrap.classList.add('is-playing');
        // Video kedua mulai diunduh setelah yang pertama berjalan.
        if (videos[1]) videos[1].preload = 'auto';
    } catch { return; }
    // Hemat baterai dan data: jeda saat hero keluar dari layar.
    new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) videos[index].play().catch(() => {}); else videos[index].pause();
    }).observe(box);
};

const initServices = () => {
    const cards = [...document.querySelectorAll('.tf-service')];
    cards.forEach((card) => card.addEventListener('mouseenter', () => cards.forEach((c) => c.classList.toggle('is-active', c === card))));
};

export const initHome = () => {
    if (!document.querySelector('.tf-hero-box')) return;
    initServices();
    initHero();
};
