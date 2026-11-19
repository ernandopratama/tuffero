import { reducedMotion } from './site';

// Pustaka IFrame API YouTube dimuat sekali, hanya saat latar video memang akan diputar.
const loadYouTube = () => new Promise((resolve, reject) => {
    if (window.YT?.Player) return resolve(window.YT);
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { prev?.(); resolve(window.YT); };
    const script = document.createElement('script');
    script.src = 'https://www.youtube.com/iframe_api';
    script.onerror = reject;
    document.head.append(script);
});

// Latar hero: dua video YouTube diputar bergantian tanpa suara dan diulang terus.
// Tidak dimuat bila pengguna mematikan animasi, memakai Save-Data, atau koneksi lambat; foto tetap tampil sebagai latar.
// Pemutar hanya terlihat saat benar-benar berputar (kelas is-live), jadi ikon jeda, putaran memuat, dan layar akhir YouTube
// tidak pernah tampil; selama itu foto yang terlihat. Video berikutnya diputar sebelum yang aktif habis, lalu keduanya bersilang pudar.
const PREROLL = 1.5; // detik sebelum akhir video saat video berikutnya mulai diputar
const initHero = async () => {
    const box = document.querySelector('.tf-hero-box');
    if (!box) return;
    const slots = [...box.querySelectorAll('.tf-hero-yt')];
    const players = [];
    const ready = [];
    let index = 0;
    let pending = null;
    let visible = true;

    const setActive = (next) => {
        index = next;
        slots.forEach((slot, i) => slot.classList.toggle('is-on', i === next));
    };
    const switchTo = (next) => {
        if (pending !== null || !ready[next]) return;
        pending = next;
        players[next].seekTo(0, true);
        players[next].playVideo();
    };

    // Tombol TikTok bergetar terus (filter SVG): jeda saat hero di luar layar.
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => box.classList.toggle('is-away', !entry.isIntersecting)).observe(box);
    }
    const conn = navigator.connection;
    // Video latar tidak dimuat bila hemat data atau koneksi lambat (2G/3G); foto tetap tampil.
    if (reducedMotion() || (conn && (conn.saveData || /(^|-)(2g|3g)$/.test(conn.effectiveType || ''))) || !slots.length) return;
    // Video baru dimuat setelah preloader logo selesai.
    await (window.tufferoLoaded || Promise.resolve());
    // Jeda singkat: pemuatan pemutar tidak bersaing dengan pudar keluar loader dan animasi hero.
    await new Promise((resolve) => setTimeout(resolve, 600));
    let YT;
    try { YT = await loadYouTube(); } catch { return; /* YouTube diblokir: foto tetap tampil */ }

    const make = (i) => new Promise((resolve) => {
        // Iframe dibuat sendiri, bukan oleh YT.Player: pustaka YouTube menambah `web-share` ke atribut allow (peringatan
        // "Unrecognized feature" di konsol) dan memberi `origin` yang tidak cocok saat postMessage. YT.Player hanya
        // dipasang ke iframe yang sudah ada (enablejsapi=1) untuk kontrol putar.
        const params = new URLSearchParams({ enablejsapi: '1', origin: location.origin, autoplay: '0', mute: '1', controls: '0', disablekb: '1', fs: '0', iv_load_policy: '3', playsinline: '1', rel: '0' });
        const frame = document.createElement('iframe');
        frame.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(slots[i].dataset.id)}?${params}`;
        frame.allow = 'autoplay; encrypted-media';
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        frame.title = '';
        // YT.Player baru dipasang setelah iframe selesai dimuat: sebelum itu jendelanya masih beralamat lokal dan
        // postMessage ke youtube-nocookie.com ditolak (peringatan origin tidak cocok di konsol).
        frame.addEventListener('load', () => { players[i] = new YT.Player(frame, {
            events: {
                onReady: ({ target }) => { target.mute(); target.getIframe().tabIndex = -1; ready[i] = true; resolve(); },
                onStateChange: ({ data }) => {
                    slots[i].classList.toggle('is-live', data === YT.PlayerState.PLAYING);
                    // Pemanasan pemutar tersembunyi (lihat di bawah): begitu berputar, jeda di detik 0 yang sudah termuat.
                    if (data === YT.PlayerState.PLAYING && i !== index && pending !== i) {
                        players[i].pauseVideo();
                        players[i].seekTo(0, true);
                        return;
                    }
                    if (data === YT.PlayerState.PLAYING && pending === i) {
                        // Video berikutnya sudah berputar: baru sekarang ditukar. Yang lama dijeda setelah pudar selesai
                        // dan dikembalikan ke awal, supaya giliran berikutnya mulai dari detik 0 yang sudah termuat.
                        const prev = index;
                        pending = null;
                        setActive(i);
                        if (prev !== i) setTimeout(() => { if (index !== prev) { players[prev].pauseVideo(); players[prev].seekTo(0, true); } }, 600);
                    }
                    // Cadangan bila video berikutnya belum siap saat jeda awal: tukar begitu video aktif habis.
                    if (data === YT.PlayerState.ENDED && i === index) switchTo((i + 1) % slots.length);
                },
            },
        }); }, { once: true });
        slots[i].firstElementChild.replaceWith(frame);
    });
    await make(0);
    players[0].playVideo();
    // Pemutar kedua baru dibuat setelah yang pertama siap.
    await Promise.all(slots.slice(1).map((_, i) => make(i + 1)));
    // Video lain diputar sebentar tanpa terlihat agar awalnya sudah termuat saat giliran pertama (tanpa jeda foto).
    players.slice(1).forEach((player) => player.playVideo());

    setInterval(() => {
        const player = players[index];
        if (!visible || pending !== null || player.getPlayerState() !== YT.PlayerState.PLAYING) return;
        const left = player.getDuration() - player.getCurrentTime();
        if (left > 0 && left < PREROLL) switchTo((index + 1) % slots.length);
    }, 250);

    // Hemat baterai dan data: jeda saat hero keluar dari layar (pemutar tersembunyi selama jeda, foto yang tampil).
    new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        if (visible) return players[index].playVideo();
        pending = null;
        players.forEach((player) => player.pauseVideo());
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
