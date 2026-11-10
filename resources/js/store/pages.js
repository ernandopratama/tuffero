import { reducedMotion } from './site';

// Peta Google dengan titik tetap di tengah: tidak bisa digeser, hanya diperbesar atau diperkecil.
// Lapisan transparan menahan geser; zoom lewat tombol + dan -, atau Ctrl/Cmd + roda mouse. Gulir halaman biasa tetap jalan.
export const pinnedMap = () => ({
    zoom: 16,
    step(delta) {
        this.zoom = Math.min(19, Math.max(12, this.zoom + delta));
        this.$refs.frame.src = `https://www.google.com/maps?q=${this.$root.dataset.lat},${this.$root.dataset.lng}&z=${this.zoom}&output=embed`;
    },
    zoomIn() { this.step(1); },
    zoomOut() { this.step(-1); },
    onWheel(e) {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        this.step(e.deltaY < 0 ? 1 : -1);
    },
});

export const contactPage = () => ({
    copied: false,
    timer: null,
    // Peta Google baru dimuat setelah diminta: halaman tetap ringan dan tidak mengirim data ke pihak ketiga tanpa aksi pengguna.
    showMap: false,
    openMap() { this.showMap = true; },
    async copyPhone() {
        try { await navigator.clipboard.writeText(this.$root.dataset.phone); } catch { return; /* izin ditolak: tautan telepon tetap tersedia */ }
        this.copied = true;
        this.$store.toast.show(this.$root.dataset.copied);
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.copied = false; }, 2000);
    },
});

// Video "Di Balik Layar" (D-059): kartu tetap tautan keluar; bila server memberi alamat embed, video diputar dalam dialog.
// Pemutar pihak ketiga baru dimuat saat kartu diklik (halaman tetap ringan, tanpa cookie atau skrip pihak ketiga saat dibuka).
// Alamat embed dari atribut HTML diperiksa lagi: hanya https dan tiga host pemutar.
const EMBED_HOSTS = ['www.youtube-nocookie.com', 'www.tiktok.com', 'www.instagram.com'];
const safeEmbed = (value) => {
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && EMBED_HOSTS.includes(url.hostname) && !url.username && !url.port ? url.href : '';
    } catch { return ''; }
};

export const reels = () => ({
    platform: '',
    open(event) {
        const card = event.currentTarget;
        const embed = safeEmbed(card.dataset.embed);
        // Tanpa embed, atau klik dengan Ctrl/Cmd/Shift/tombol tengah: biarkan tautan keluar bekerja.
        if (!embed || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        this.$refs.link.href = card.href;
        this.platform = card.dataset.platform;
        // Build CSP Alpine melarang ekspresi terikat pada iframe, jadi iframe dibuat lewat DOM (seperti peta di pinnedMap).
        const frame = document.createElement('iframe');
        frame.src = embed;
        frame.title = card.dataset.title;
        frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        this.$refs.frame.replaceChildren(frame);
        this.$refs.dialog.showModal();
    },
    // Tombol dan klik latar menghentikan video seketika; Esc lewat peristiwa `close` dialog (@close).
    close() {
        this.$refs.dialog.close();
        this.stop();
    },
    // Iframe dibuang saat dialog tertutup agar video berhenti.
    stop() { this.$refs.frame.replaceChildren(); },
    backdrop(event) { if (event.target === this.$refs.dialog) this.close(); },
    get openLabel() { return this.$root.dataset.openOn.replace(':platform', this.platform); },
});

const TAB_SPEED = 18; // px per detik

export const gallery = () => ({
    cat: 'all',
    items: [],
    init() {
        this.items = [...this.$refs.grid.children];
        this.autoScroll(this.$refs.tabs);
    },
    // Foto yang tersaring dikeluarkan dari DOM, supaya lightbox hanya menghitung foto yang terlihat.
    select(key) {
        this.cat = key;
        this.$refs.grid.replaceChildren(...this.items.filter((el) => key === 'all' || el.dataset.cat === key));
    },
    // Mobile: baris ikon lebih lebar dari layar, jadi digeser otomatis pelan-pelan (kiri lalu kanan) agar semua ikon terlihat.
    // Berhenti saat disentuh atau di-scroll pengguna dan lanjut setelah 4 detik; berhenti di luar layar; mati bila pengguna mengurangi gerak.
    autoScroll(el) {
        if (reducedMotion() || !('IntersectionObserver' in window)) return;
        let raf = 0;
        let visible = false;
        let last = 0;
        let pos = 0;
        let dir = 1;
        let holdUntil = 0;
        const hold = (ms) => { holdUntil = performance.now() + ms; };
        const frame = (now) => {
            raf = 0;
            if (!visible) return;
            const max = el.scrollWidth - el.clientWidth;
            const dt = Math.min(now - last, 64);
            last = now;
            if (max > 4 && now >= holdUntil) {
                if (now - dt < holdUntil) pos = el.scrollLeft; // lanjut dari posisi yang dipilih pengguna
                pos += dir * TAB_SPEED * dt / 1000;
                if (pos >= max) { pos = max; dir = -1; hold(1500); } else if (pos <= 0) { pos = 0; dir = 1; hold(1500); }
                el.scrollLeft = pos;
            }
            raf = requestAnimationFrame(frame);
        };
        ['pointerdown', 'touchstart', 'wheel', 'keydown', 'focusin'].forEach((type) => el.addEventListener(type, () => hold(4000), { passive: true }));
        new IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting;
            if (visible && !raf) { last = performance.now(); raf = requestAnimationFrame(frame); }
        }).observe(el);
        hold(1500);
    },
});
