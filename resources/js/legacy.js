// Template Backeri bergantung pada jQuery global dan plugin berurutan; dimuat setelah Vue me-render DOM.
const SCRIPTS = [
    'jquery-3.7.1.min.js', 'bootstrap.min.js', 'validator.min.js', 'jquery.slicknav.js',
    'swiper-bundle.min.js', 'jquery.waypoints.min.js', 'jquery.counterup.min.js',
    'jquery.magnific-popup.min.js', 'SmoothScroll.js', 'parallaxie.js', 'gsap.min.js',
    'magiccursor.js', 'SplitText.js', 'ScrollTrigger.min.js', 'jquery.mb.YTPlayer.min.js',
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
    for (const src of SCRIPTS) await load(src);
    // function.js memasang preloader pada event window 'load' yang sudah lewat.
    window.jQuery('.preloader').fadeOut(600);
}
