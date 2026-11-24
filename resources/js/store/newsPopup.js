// Popup berita di beranda (D-085): kampanye berjalan dan berita manual, paling banyak 5, tampil setiap beranda dibuka (keputusan pemilik 23 Nov 2026; sebelumnya sekali per sesi browser).
// Dibuat ringan: tanpa Alpine dan tanpa pustaka carousel. Data sudah ada di halaman (satu tag JSON), DOM dan CSS popup baru
// dibuat setelah halaman selesai dimuat dan browser menganggur, dan gambar baru diunduh saat popup dibuka.
// Teks dipasang lewat textContent, tidak pernah innerHTML, jadi isi dari admin tidak bisa menyisipkan HTML.
import { pageData, reducedMotion } from './site';

const OPEN_DELAY = 1200;
const CLOSE_MS = 160;
const AUTO_MS = 5000; // jeda tiap slide sebelum pindah sendiri; setelah slide terakhir popup menutup sendiri
const CTA_MS = 440; // animasi klik tombol (420 ms di CSS) selesai dulu, baru pindah halaman

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

// Ikon garis SVG (bukan karakter × ‹ ›) supaya selalu tepat di tengah tombol, apa pun fontnya.
const icon = (d) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    svg.append(path);
    return svg;
};

// Cincin progres di sekeliling titik aktif: lingkaran latar tipis + lingkaran isi yang terisi selama AUTO_MS (CSS, .is-on).
const ring = () => {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 26 26');
    svg.setAttribute('class', 'tf-news-ring');
    svg.setAttribute('aria-hidden', 'true');
    ['tf-news-ring-track', 'tf-news-ring-fill'].forEach((cls) => {
        const c = document.createElementNS(ns, 'circle');
        c.setAttribute('class', cls);
        c.setAttribute('cx', '13');
        c.setAttribute('cy', '13');
        c.setAttribute('r', '12');
        c.setAttribute('pathLength', '100');
        svg.append(c);
    });
    return svg;
};

// Tautan dari admin hanya halaman toko ("/promo/...") atau https; server sudah memeriksa, ini lapis kedua.
const safeHref = (url) => (typeof url === 'string' && ((url.startsWith('/') && !url.startsWith('//')) || url.startsWith('https://')) ? url : null);

const loadStyles = (href) => new Promise((resolve) => {
    if (!href || document.querySelector(`link[href="${href}"]`)) return resolve();
    const link = el('link', { rel: 'stylesheet', href });
    link.addEventListener('load', resolve, { once: true });
    link.addEventListener('error', resolve, { once: true });
    setTimeout(resolve, 1500);
    document.head.append(link);
});

const slide = (item, index, total, words, lite) => {
    // Label jenis dan tanggal: di atas gambar (kotak kaca di kiri bawah) bila ada gambar, di atas judul bila tidak.
    const tag = el('div', { class: 'tf-news-tag' }, [el('span', { class: `tf-news-badge is-${item.kind}`, text: item.badge })]);
    if (item.note) tag.append(el('span', { class: 'tf-news-note', text: item.note }));

    // Tanpa gambar (atau hemat data): kotak gambar tidak dibuat, supaya judul dan tombol tidak terdorong oleh ruang kosong.
    const media = item.image && !lite
        ? el('div', { class: 'tf-news-media' }, [el('img', { src: item.image, alt: '', width: '640', height: '360', decoding: 'async', loading: index === 0 ? 'eager' : 'lazy' }), tag])
        : null;

    const body = el('div', { class: 'tf-news-body' }, [
        ...(media ? [] : [tag]),
        el('h2', { class: 'tf-news-title', text: item.title }),
        el('p', { class: 'tf-news-text', text: item.body }),
    ]);

    const href = item.cta && safeHref(item.cta.url);
    if (href) {
        const external = href.startsWith('https://');
        // Panah ke atas, diputar CSS 45° (↗) lalu 90° (→) saat diklik.
        const arrow = icon('M12 19V5M5 12l7-7 7 7');
        arrow.setAttribute('class', 'tf-news-cta-icon');
        body.append(el('a', { class: 'tf-news-cta', href, target: external ? '_blank' : null, rel: external ? 'noopener noreferrer' : null }, [
            el('span', { text: item.cta.label }),
            arrow,
        ]));
    }

    return el('li', {
        class: 'tf-news-slide',
        role: 'group',
        'aria-roledescription': 'slide',
        'aria-label': words.slide.replace(':n', index + 1).replace(':total', total),
    }, media ? [media, body] : [body]);
};

const build = (data, lite) => {
    const { items, words } = data;
    const many = items.length > 1;

    const track = el('ul', { class: 'tf-news-track' }, items.map((item, i) => slide(item, i, items.length, words, lite)));
    const viewport = el('div', { class: 'tf-news-viewport', tabindex: '0', role: 'region', 'aria-roledescription': 'carousel', 'aria-label': words.heading }, [track]);
    const close = el('button', { type: 'button', class: 'tf-news-close', 'aria-label': words.close }, [icon('M6 6l12 12M18 6L6 18')]);

    const card = el('div', { class: 'tf-news-card' }, [
        // Judul popup hanya untuk pembaca layar (gambar mengisi bagian atas). Fokus awal di sini, bukan di tombol tutup,
        // supaya cincin fokus tidak muncul saat popup dibuka dengan mouse atau sentuhan.
        el('p', { class: 'tf-news-eyebrow', id: 'tf-news-title', tabindex: '-1', autofocus: true, text: words.heading }),
        viewport,
    ]);
    // Pengumuman pergantian slide untuk pembaca layar.
    const status = el('p', { class: 'tf-news-status', role: 'status' });
    card.append(status);

    let prev;
    let next;
    let dots = [];
    if (many) {
        prev = el('button', { type: 'button', class: 'tf-news-nav is-prev', 'aria-label': words.prev }, [icon('M15 6l-6 6 6 6')]);
        next = el('button', { type: 'button', class: 'tf-news-nav is-next', 'aria-label': words.next }, [icon('M9 6l6 6-6 6')]);
        // Titik hanya untuk mouse/sentuh: keyboard dan pembaca layar sudah punya panah dan pengumuman slide.
        dots = items.map(() => el('button', { type: 'button', class: 'tf-news-dot', tabindex: '-1', 'aria-hidden': 'true' }, [ring()]));
        card.append(el('footer', { class: 'tf-news-foot' }, [prev, el('div', { class: 'tf-news-dots' }, dots), next]));
    }

    // Tombol tutup di luar kartu (kartu memotong isi yang keluar), menempel di sudut kanan atas.
    const dialog = el('dialog', { class: 'tf-news', 'aria-labelledby': 'tf-news-title' }, [card, close]);
    return { dialog, viewport, close, prev, next, dots, status, slides: [...track.children], count: items.length };
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
    // Putar otomatis: pindah tiap AUTO_MS, tutup setelah slide terakhir. Berhenti selamanya begitu pengunjung menyentuh,
    // menekan tombol, atau menggulir sendiri (pointerdown/keydown/wheel; geseran otomatis tidak dihitung), supaya tidak
    // berpindah atau menutup saat sedang dibaca.
    let timer = 0;
    let auto = true;
    let close;
    const arm = () => {
        clearTimeout(timer);
        if (!auto) return;
        timer = setTimeout(() => (index >= ui.count - 1 ? close() : go(index + 1)), AUTO_MS);
    };
    ui.dialog.style.setProperty('--news-auto', `${AUTO_MS}ms`);
    // is-manual menyembunyikan cincin progres: tidak ada hitungan mundur lagi.
    const stopAuto = () => { auto = false; clearTimeout(timer); ui.dialog.classList.add('is-manual'); };
    ['pointerdown', 'keydown', 'wheel'].forEach((type) => ui.dialog.addEventListener(type, stopAuto, { passive: true, once: true }));

    let announced = 0;
    let armedFor = -1;
    const sync = () => {
        index = Math.round(ui.viewport.scrollLeft / Math.max(1, width()));
        if (index !== armedFor) { armedFor = index; arm(); }
        ui.dots.forEach((dot, i) => dot.classList.toggle('is-on', i === index));
        // Slide yang tidak terlihat tidak bisa dijangkau Tab dan tidak dibacakan pembaca layar.
        ui.slides.forEach((s, i) => { s.inert = i !== index; });
        if (ui.prev) { ui.prev.disabled = index === 0; ui.next.disabled = index === ui.count - 1; }
        if (ui.count > 1 && index !== announced) {
            announced = index;
            ui.status.textContent = ui.slides[index].getAttribute('aria-label');
        }
    };

    let frame = 0;
    ui.viewport.addEventListener('scroll', () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(sync); }, { passive: true });
    ui.prev?.addEventListener('click', () => go(index - 1));
    ui.next?.addEventListener('click', () => go(index + 1));
    ui.dots.forEach((dot, i) => dot.addEventListener('click', () => go(i)));
    ui.viewport.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); }
    });

    // Keluar cepat (160 ms), masuk lebih pelan: kelas is-closing dipasang dulu, lalu dialog ditutup.
    close = () => {
        if (ui.dialog.classList.contains('is-closing')) return;
        stopAuto();
        ui.dialog.classList.add('is-closing');
        setTimeout(() => { ui.dialog.close(); ui.dialog.remove(); }, reducedMotion() ? 0 : CLOSE_MS);
    };
    ui.close.addEventListener('click', close);
    ui.dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    ui.dialog.addEventListener('click', (e) => { if (e.target === ui.dialog) close(); });

    // Tombol tujuan: klik biasa memutar animasi isi emas dulu, lalu membuka halaman. Klik dengan Ctrl/Cmd/Shift/Alt
    // (tab baru) dan tautan luar (_blank) tidak ditahan; gerak dikurangi langsung pindah.
    ui.dialog.addEventListener('click', (e) => {
        const cta = e.target.closest('.tf-news-cta');
        if (!cta || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (cta.classList.contains('is-going') && cta.target !== '_blank') { e.preventDefault(); return; }
        cta.classList.add('is-going');
        if (cta.target === '_blank') { setTimeout(() => cta.classList.remove('is-going'), CTA_MS * 2); return; }
        if (reducedMotion()) return;
        e.preventDefault();
        setTimeout(() => location.assign(cta.href), CTA_MS);
    });
    // Kembali dengan tombol Back (bfcache): tombol kembali ke keadaan awal.
    addEventListener('pageshow', (e) => { if (e.persisted) ui.dialog.querySelectorAll('.tf-news-cta.is-going').forEach((c) => c.classList.remove('is-going')); });

    ui.dialog.showModal();
    sync();
};

const whenIdle = () => new Promise((resolve) => {
    if ('requestIdleCallback' in window) requestIdleCallback(resolve, { timeout: 4000 });
    else setTimeout(resolve, 2500);
});

export const initNewsPopup = async () => {
    const data = pageData('tf-news-data');
    if (!data || !data.items?.length) return;
    // Pengunjung yang datang ke bagian tertentu (#offers) sedang menuju tujuan; jangan ditimpa popup.
    if (location.hash) return;

    // Setelah preloader logo selesai, halaman selesai dimuat, dan browser menganggur; baru jeda singkat.
    await (window.tufferoLoaded || Promise.resolve());
    if (document.readyState !== 'complete') await new Promise((resolve) => addEventListener('load', resolve, { once: true }));
    await whenIdle();
    await new Promise((resolve) => setTimeout(resolve, OPEN_DELAY));
    // Tab yang sedang di latar belakang menunggu sampai dilihat.
    if (document.hidden) await new Promise((resolve) => document.addEventListener('visibilitychange', () => !document.hidden && resolve()));
    open(data);
};
