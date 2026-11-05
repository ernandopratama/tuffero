import api from './api';

// Bar utama: menu akun, panel pencarian, menu mobile. Tamu menuju /masuk lewat tautan biasa.
export default () => ({
    accountOpen: false,
    signOutFailed: false,
    searchOpen: false,
    searchQuery: '',
    menuOpen: false,

    init() {
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if (this.accountOpen) { this.accountOpen = false; this.$refs.accountToggle?.focus(); }
            if (this.menuOpen) this.closeMenu(true);
            if (this.searchOpen) { this.searchOpen = false; this.$el.querySelector('[aria-controls="tf-search"]')?.focus(); }
        });
        document.addEventListener('pointerdown', (e) => {
            if (this.accountOpen && !this.$refs.accountBox?.contains(e.target)) this.accountOpen = false;
            // Ikon cari ikut dikecualikan: kalau tidak, pointerdown menutup panel lalu klik membukanya lagi.
            if (this.searchOpen && this.$refs.searchBox && !this.$refs.searchBox.contains(e.target) && !e.target.closest('[aria-controls="tf-search"]')) this.searchOpen = false;
        });
        window.matchMedia('(min-width: 992px)').addEventListener('change', (e) => { if (e.matches) this.setMenu(false); });
        // Bayangan bar utama hanya saat bar benar-benar menempel di atas layar.
        window.addEventListener('scroll', () => {
            const bar = this.$refs.mainBar;
            bar?.classList.toggle('is-stuck', bar.getBoundingClientRect().top <= 0 && window.scrollY > 0);
        }, { passive: true });
    },

    toggleAccount() { this.accountOpen = !this.accountOpen; },

    // 401 berarti session sudah berakhir, jadi pengguna memang sudah keluar.
    async signOut() {
        this.signOutFailed = false;
        try {
            await api.post('/auth/logout');
        } catch (e) {
            if (e.response?.status !== 401) { this.signOutFailed = true; return; }
        }
        window.location.assign('/');
    },

    toggleSearch() {
        this.searchOpen = !this.searchOpen;
        if (this.searchOpen) this.$nextTick(() => this.$refs.searchInput?.focus());
    },
    closeSearch() { this.searchOpen = false; },
    submitSearch() {
        this.searchOpen = false;
        const q = this.searchQuery.trim();
        window.location.href = q ? `/produk?q=${encodeURIComponent(q)}` : '/produk';
    },

    // Menu mobile: latar halaman dibuat inert dan tidak bisa digulir selama menu terbuka.
    setMenu(open) {
        this.menuOpen = open;
        document.documentElement.classList.toggle('tf-menu-open', open);
        document.querySelectorAll('.tf > main, .tf > footer').forEach((el) => { el.inert = open; });
    },
    toggleMenu() { this.setMenu(!this.menuOpen); },
    closeMenu(refocus = false) {
        this.setMenu(false);
        if (refocus) this.$refs.menuToggle?.focus();
    },
    closeMenuLink() { this.setMenu(false); },
});
