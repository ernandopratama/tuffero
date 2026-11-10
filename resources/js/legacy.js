// Template Backeri bergantung pada jQuery global dan plugin berurutan; dimuat setelah Vue me-render DOM.
const SCRIPTS = [
    'jquery-3.7.1.min.js', 'bootstrap.min.js', 'validator.min.js',
    'swiper-bundle.min.js', 'jquery.waypoints.min.js', 'jquery.counterup.min.js',
    'jquery.magnific-popup.min.js', 'parallaxie.js', 'gsap.min.js',
    'magiccursor.js', 'SplitText.js', 'ScrollTrigger.min.js',
    'wow.min.js', 'function.js',
];

let loaded = false;

const load = (src) => new Promise((resolve, reject) => {
    const el = document.createElement('script');
    el.src = `/js/${src}`;
    el.async = false;
    el.onload = resolve;
    el.onerror = () => reject(new Error(`Gagal memuat ${src}`));
    document.body.appendChild(el);
});

export async function loadLegacyScripts() {
    if (loaded) return;
    loaded = true;
    // function.js (animasi halaman) dijalankan terakhir, setelah preloader logo selesai, supaya animasi hero terlihat.
    const last = SCRIPTS[SCRIPTS.length - 1];
    // Diunduh bersamaan; async=false menjaga urutan eksekusi sesuai urutan penyisipan.
    await Promise.all(SCRIPTS.slice(0, -1).map(load));
    window.dispatchEvent(new Event('tuffero:app-ready'));
    await (window.tufferoLoaded || Promise.resolve());
    // Jeda singkat: pudar keluar loader mulai dulu, baru function.js (SplitText, ScrollTrigger, Swiper) berjalan.
    // setTimeout, bukan requestAnimationFrame, supaya tab latar belakang tidak menahan halaman.
    await new Promise((resolve) => setTimeout(resolve, 120));
    await load(last);
}
