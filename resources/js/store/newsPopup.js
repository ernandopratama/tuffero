// Popup berita di beranda (D-085): kampanye berjalan dan berita manual, paling banyak 5, tampil sekali per sesi browser.
// Dibuat ringan: tanpa Alpine dan tanpa pustaka carousel. Data sudah ada di halaman (satu tag JSON), DOM dan CSS popup baru
// dibuat setelah halaman selesai dimuat dan browser menganggur, dan gambar baru diunduh saat popup dibuka.
// Teks dipasang lewat textContent, tidak pernah innerHTML, jadi isi dari admin tidak bisa menyisipkan HTML.
import { pageData, reducedMotion } from './site';

const SEEN_KEY = 'tf-news-seen';
const OPEN_DELAY = 1200;
const CLOSE_MS = 160;

const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) return;
        if (key === 'text') node.textContent = value;
        else node.setAttribute(key, value === true ? '' : value);
    });
    children.forEach((child) => node.append(child));
    return node;
};

// Tautan dari admin hanya halaman toko ("/promo/...") atau https; server sudah memeriksa, ini lapis kedua.
const safeHref = (url) => (typeof url === 'string' && ((url.startsWith('/') && !url.startsWith('//')) || url.startsWith('https://')) ? url : null);

const seen = (version) => {
    try { return sessionStorage.getItem(SEEN_KEY) === version; } catch { return false; }
};
const markSeen = (version) => {
    try { sessionStorage.setItem(SEEN_KEY, version); } catch { /* penyimpanan diblokir: popup tampil lagi saat dimuat ulang */ }
};

const loadStyles = (href) => new Promise((resolve) => {
    if (!href || document.querySelector(`link[href="${href}"]`)) return resolve();
    const link = el('link', { rel: 'stylesheet', href });
    link.addEventListener('load', resolve, { once: true });
    link.addEventListener('error', resolve, { once: true });
    setTimeout(resolve, 1500);
    document.head.append(link);
});

const slide = (item, index, total, words, lite) => {
    const media = el('div', { class: 'tf-news-media' });
    if (item.image && !lite) {
        media.append(el('img', { src: item.image, alt: '', width: '640', height: '360', decoding: 'async', loading: index === 0 ? 'eager' : 'lazy' }));
    } else {
        media.classList.add('is-blank');
    }

    const body = el('div', { class: 'tf-news-body' }, [
        el('span', { class: `tf-news-badge is-${item.kind}`, text: item.badge }),
        el('h2', { class: 'tf-news-title', text: item.title }),
        el('p', { class: 'tf-news-text', text: item.body }),
    ]);
    if (item.note) body.append(el('p', { class: 'tf-news-note', text: item.note }));

    const href = item.cta && safeHref(item.cta.url);
    if (href) {
        const external = href.startsWith('https://');
        body.append(el('a', { class: 'tf-news-cta', href, target: external ? '_blank' : null, rel: external ? 'noopener noreferrer' : null, text: item.cta.label }));
    }

    return el('li', {
        class: 'tf-news-slide',
        role: 'group',
        'aria-roledescription': 'slide',
        'aria-label': words.slide.replace(':n', index + 1).replace(':total', total),
    }, [media, body]);
};

const build = (data, lite) => {
    const { items, words } = data;
    const many = items.length > 1;

    const track = el('ul', { class: 'tf-news-track' }, items.map((item, i) => slide(item, i, items.length, words, lite)));
    const viewport = el('div', { class: 'tf-news-viewport', tabindex: '0', role: 'region', 'aria-roledescription': 'carousel', 'aria-label': words.heading }, [track]);
    const close = el('button', { type: 'button', class: 'tf-news-close', 'aria-label': words.close, text: '×' });

    const card = el('div', { class: 'tf-news-card' }, [
        el('header', { class: 'tf-news-head' }, [el('p', { class: 'tf-news-eyebrow', id: 'tf-news-title', text: words.heading }), close]),
        viewport,
    ]);

    let prev;
    let next;
    let dots = [];
    if (many) {
        prev = el('button', { type: 'button', class: 'tf-news-nav is-prev', 'aria-label': words.prev, text: '‹' });
        next = el('button', { type: 'button', class: 'tf-news-nav is-next', 'aria-label': words.next, text: '›' });
        dots = items.map((_, i) => el('span', { class: 'tf-news-dot', 'aria-hidden': 'true', 'data-i': String(i) }));
        card.append(el('footer', { class: 'tf-news-foot' }, [prev, el('div', { class: 'tf-news-dots' }, dots), next]));
    }

    const dialog = el('dialog', { class: 'tf-news', 'aria-labelledby': 'tf-news-title' }, [card]);
    return { dialog, viewport, close, prev, next, dots, count: items.length };
};

const open = async (data) => {
    if (document.querySelector('dialog.tf-news')) return;
    const conn = navigator.connection;
    const lite = !!(conn && conn.saveData);

    await loadStyles(data.css);
    const ui = build(data, lite);
    document.body.append(ui.dialog);

    let index = 0;
    const width = () => ui.viewport.clientWidth;
    const go = (i) => {
        const target = Math.max(0, Math.min(ui.count - 1, i));
        ui.viewport.scrollTo({ left: target * width(), behavior: reducedMotion() ? 'auto' : 'smooth' });
    };
    const sync = () => {
        index = Math.round(ui.viewport.scrollLeft / Math.max(1, width()));
        ui.dots.forEach((dot, i) => dot.classList.toggle('is-on', i === index));
        if (ui.prev) { ui.prev.disabled = index === 0; ui.next.disabled = index === ui.count - 1; }
    };

    let frame = 0;
    ui.viewport.addEventListener('scroll', () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(sync); }, { passive: true });
    ui.prev?.addEventListener('click', () => go(index - 1));
    ui.next?.addEventListener('click', () => go(index + 1));
    ui.viewport.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); }
    });

    // Keluar cepat (160 ms), masuk lebih pelan: kelas is-closing dipasang dulu, lalu dialog ditutup.
    const close = () => {
        if (ui.dialog.classList.contains('is-closing')) return;
        ui.dialog.classList.add('is-closing');
        setTimeout(() => { ui.dialog.close(); ui.dialog.remove(); }, reducedMotion() ? 0 : CLOSE_MS);
    };
    ui.close.addEventListener('click', close);
    ui.dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    ui.dialog.addEventListener('click', (e) => { if (e.target === ui.dialog) close(); });

    markSeen(data.version);
    ui.dialog.showModal();
    sync();
};

const whenIdle = () => new Promise((resolve) => {
    if ('requestIdleCallback' in window) requestIdleCallback(resolve, { timeout: 4000 });
    else setTimeout(resolve, 2500);
});

export const initNewsPopup = async () => {
    const data = pageData('tf-news-data');
    if (!data || !data.items?.length || seen(data.version)) return;
    // Pengunjung yang datang ke bagian tertentu (#offers) sedang menuju tujuan; jangan ditimpa popup.
    if (location.hash) return;

    // Setelah preloader logo selesai, halaman selesai dimuat, dan browser menganggur; baru jeda singkat.
    await (window.tufferoLoaded || Promise.resolve());
    if (document.readyState !== 'complete') await new Promise((resolve) => addEventListener('load', resolve, { once: true }));
    await whenIdle();
    await new Promise((resolve) => setTimeout(resolve, OPEN_DELAY));
    // Tab yang sedang di latar belakang menunggu sampai dilihat.
    if (document.hidden) await new Promise((resolve) => document.addEventListener('visibilitychange', () => !document.hidden && resolve()));
    if (seen(data.version)) return;
    open(data);
};
