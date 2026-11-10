import { requestSignOut } from './header';

// Navbar bawah smartphone. Tombol bulat membuka menu cepat; latar halaman dibuat inert dan tidak bisa digulir selama terbuka.
export default () => ({
    open: false,
    signOutFailed: false,

    init() {
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.open) this.close(true); });
        window.matchMedia('(min-width: 768px)').addEventListener('change', (e) => { if (e.matches) this.set(false); });
        // Kembali lewat tombol Back (bfcache): halaman dipulihkan apa adanya, jadi menu ditutup.
        window.addEventListener('pageshow', (e) => { if (e.persisted) this.set(false); });
    },

    set(open) {
        this.open = open;
        document.documentElement.classList.toggle('tf-menu-open', open);
        document.querySelectorAll('.tf > header, .tf > main, .tf > footer').forEach((el) => { el.inert = open; });
    },
    // Fokus ke panel, bukan ke kolom cari, agar keyboard HP tidak langsung muncul.
    toggle() {
        this.set(!this.open);
        if (this.open) this.$nextTick(() => this.$refs.panel.focus({ preventScroll: true }));
    },
    close(refocus = false) {
        this.set(false);
        if (refocus === true) this.$refs.fab.focus();
    },
    openChat() {
        this.set(false);
        document.querySelector('.cs-launcher')?.click();
    },
    async signOut() {
        this.signOutFailed = false;
        this.signOutFailed = !(await requestSignOut());
    },
});
