import { pageData, reducedMotion } from './site';

const PER_PAGE = 12;
const PRICE_RANGES = [[0, Infinity], [0, 34999], [35000, 75000], [75001, 150000], [150001, Infinity]];
const fill = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.replace(`{${k}}`, v), text);

// Kartu dirender server supaya bisa dirayapi tanpa JavaScript. Saringan berjalan di browser: kartu yang tidak terpilih dikeluarkan dari DOM.
export const catalog = () => ({
    items: [],
    words: {},
    cards: new Map(),
    cat: 'all',
    query: '',
    type: 'all',
    priceIdx: 0,
    sortIdx: 0,
    page: 1,
    filtersOpen: false,

    init() {
        const data = pageData('tf-catalog-data');
        this.items = data.items;
        this.words = data.words;
        this.$refs.grid.querySelectorAll('.tf-cat-card').forEach((el) => { this.cards.set(el.dataset.sku, el); });
        // ?kategori= dan ?q= membuat tautan dari menu, pencarian header, dan halaman lain langsung tersaring.
        const params = new URLSearchParams(window.location.search);
        const initial = params.get('kategori');
        if (data.categories.includes(initial)) this.cat = initial;
        this.query = (params.get('q') || '').trim();
        ['cat', 'type', 'priceIdx', 'sortIdx', 'query'].forEach((key) => this.$watch(key, () => { this.page = 1; this.render(); }));
        ['cat', 'query'].forEach((key) => this.$watch(key, () => this.syncUrl()));
        this.$watch('$store.cart.count', () => {
            const badge = this.$refs.badge;
            badge.style.animation = 'none';
            void badge.offsetWidth;
            badge.style.animation = '';
        });
        this.render();
        this.hideIntroOnceScrolledPast();
    },

    // Label, judul, dan pengantar (.tf-cat-hero) hanya tampil saat halaman dibuka. Setelah seluruhnya tergulir lewat ke atas, bagian itu
    // dilepas. Isi yang sedang dilihat dijaga di tempatnya: Chrome dan Firefox melakukannya lewat scroll anchoring,
    // browser tanpa anchoring dikoreksi sebesar pergeseran yang benar-benar terjadi. Patokannya baris urutan (.tf-cat-bar),
    // bukan pencarian yang menempel, karena posisi elemen sticky tidak ikut bergeser.
    hideIntroOnceScrolledPast() {
        const hero = this.$refs.intro;
        const anchor = this.$refs.bar;
        if (!hero || !anchor || !('IntersectionObserver' in window)) return;
        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting || entry.boundingClientRect.bottom > 0) return;
            observer.disconnect();
            const before = anchor.getBoundingClientRect().top;
            hero.hidden = true;
            const shift = anchor.getBoundingClientRect().top - before;
            if (shift) window.scrollBy({ top: shift, behavior: 'instant' });
        });
        observer.observe(hero);
    },

    matches(x, q) {
        return `${x.name.id} ${x.name.en} ${this.words.cats[x.cat] || ''} ${this.words.types[x.type] || ''}`.toLowerCase().includes(q);
    },
    get matchesQuery() {
        const q = this.query.toLowerCase();
        return q ? this.items.filter((x) => this.matches(x, q)) : this.items;
    },
    countFor(key) {
        return key === 'all' ? this.matchesQuery.length : this.matchesQuery.filter((x) => x.cat === key).length;
    },
    // Jenis hanya ditampilkan bila kategori punya lebih dari satu jenis.
    get types() {
        if (this.cat === 'all') return [];
        const list = [...new Set(this.items.filter((x) => x.cat === this.cat).map((x) => x.type))];
        return list.length > 1 ? list : [];
    },
    typeLabel(key) { return this.words.types[key]; },
    get results() {
        const [min, max] = PRICE_RANGES[this.priceIdx];
        const list = this.matchesQuery.filter((x) => (this.cat === 'all' || x.cat === this.cat) && (this.type === 'all' || x.type === this.type) && x.price >= min && x.price <= max);
        if (this.sortIdx === 1) return [...list].sort((a, b) => a.price - b.price);
        if (this.sortIdx === 2) return [...list].sort((a, b) => b.price - a.price);
        return list;
    },
    get pageCount() { return Math.max(1, Math.ceil(this.results.length / PER_PAGE)); },
    get visible() { return this.results.slice((this.page - 1) * PER_PAGE, this.page * PER_PAGE); },
    get activeCount() { return [this.cat !== 'all', this.type !== 'all', this.priceIdx !== 0, !!this.query].filter(Boolean).length; },
    get pageNumbers() { return Array.from({ length: this.pageCount }, (_, i) => i + 1); },
    get showingText() { return fill(this.words.showing, { n: this.visible.length, total: this.results.length }); },
    get pageText() { return this.pageLabel(this.page); },
    pageLabel(n) { return fill(this.words.page, { p: n, pages: this.pageCount }); },
    get isEmpty() { return !this.visible.length; },

    render() {
        this.$refs.grid.replaceChildren(...this.visible.map((x) => {
            const el = this.cards.get(x.sku);
            el.hidden = false;
            return el;
        }));
    },
    syncUrl() {
        // Simpan filter di URL agar muat ulang dan bagikan tautan tetap sama.
        const next = new URLSearchParams(window.location.search);
        if (this.cat === 'all') next.delete('kategori'); else next.set('kategori', this.cat);
        if (this.query) next.set('q', this.query); else next.delete('q');
        const qs = next.toString();
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${qs ? `?${qs}` : ''}`);
    },

    setCat(key) { this.cat = key; this.type = 'all'; },
    setType(key) { this.type = key; },
    setPrice(i) { this.priceIdx = i; },
    setSort(i) { this.sortIdx = i; },
    clearQuery() { this.query = ''; },
    reset() { this.cat = 'all'; this.type = 'all'; this.priceIdx = 0; this.sortIdx = 0; this.query = ''; },
    toggleFilters() { this.filtersOpen = !this.filtersOpen; },
    goTo(n) {
        this.page = n;
        this.render();
        this.$nextTick(() => this.$refs.results.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' }));
    },
    goNext() { this.goTo(this.page + 1); },
});

export const product = () => ({
    photos: [],
    max: 50,
    active: 0,
    qtyText: '1',
    stickyOn: false,

    init() {
        const data = pageData('tf-product-data');
        this.photos = data.photos;
        this.max = data.max;
        this.sku = data.sku;
        this.path = data.path;
        this.words = data.words;
        // Bilah beli di bawah layar (mobile) muncul setelah tombol utama keluar dari layar.
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(([e]) => { this.stickyOn = !e.isIntersecting && e.boundingClientRect.top < 0; }).observe(this.$refs.cta);
        }
    },
    get photoSrc() { return this.photos[this.active]?.src; },
    get photoAlt() { return this.photos[this.active]?.alt; },
    setPhoto(i) { this.active = i; },

    // Jumlah: boleh diketik; nilai dirapikan saat fokus pindah. Batas per pesanan dari katalog (D-055); server tetap yang memutuskan.
    get atMax() { return this.qty >= this.max; },
    get qty() { return Math.min(this.max, Math.max(1, parseInt(this.qtyText, 10) || 1)); },
    onQtyInput(e) { this.qtyText = e.target.value.replace(/\D/g, '').slice(0, 3); e.target.value = this.qtyText; },
    settleQty() { this.qtyText = String(this.qty); },
    dec() { this.qtyText = String(Math.max(1, this.qty - 1)); },
    inc() { this.qtyText = String(Math.min(this.max, this.qty + 1)); },

    addCurrent() { return this.$store.cart.add(this.sku, this.qty); },
    async buyNow() { if (await this.addCurrent()) window.location.href = '/checkout'; },
    async copyLink() {
        const url = `${window.location.origin}${this.path}`;
        try { await navigator.clipboard.writeText(url); this.$store.toast.show(this.words.copied); } catch { this.$store.toast.show(url, 'info'); }
    },
});
