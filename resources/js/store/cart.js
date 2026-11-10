import api from './api';

// Jumlah di header dan pemberitahuan tambah keranjang, dipakai bersama semua halaman toko lewat Alpine.store('cart').
// items: jumlah per SKU agar kartu produk bisa menampilkan stepper bila barang sudah di keranjang.
// toast: Alpine.store('toast') (versi reaktif), lihat store/toast.js.
export const cartStore = (initialCount, words, toast) => ({
    count: initialCount,
    items: {},
    busy: false,
    loading: null,
    sync(cart) {
        this.count = cart.count;
        this.items = Object.fromEntries(cart.items.map((item) => [item.sku, { quantity: item.quantity, max: item.max_quantity || 50 }]));
    },
    qty(sku) { return this.items[sku]?.quantity || 0; },
    max(sku) { return this.items[sku]?.max || 50; },
    // Sekali per halaman, dan hanya bila keranjang tidak kosong.
    load() {
        if (!this.loading) {
            this.loading = this.count
                ? api.get('/cart').then(({ data }) => this.sync(data.data)).catch(() => {})
                : Promise.resolve();
        }
        return this.loading;
    },
    async add(sku, quantity = 1) {
        try {
            const { data } = await api.post('/cart/items', { sku, quantity });
            this.sync(data.data);
            toast.show(words.added);
            return true;
        } catch (e) {
            toast.show(e.response?.status === 422 ? words.addUnavailable : words.addFailed, 'error');
            return false;
        }
    },
    async change(request, done) {
        this.busy = true;
        try {
            const { data } = await request();
            this.sync(data.data);
            if (done) toast.show(done);
        } catch {
            toast.show(words.updateFailed, 'error');
        } finally {
            this.busy = false;
        }
    },
    set(sku, quantity) { return this.change(() => api.patch(`/cart/items/${encodeURIComponent(sku)}`, { quantity })); },
    remove(sku) { return this.change(() => api.delete(`/cart/items/${encodeURIComponent(sku)}`), words.removed); },
});

// Tombol keranjang di kartu produk: <div x-data="cartButton" data-sku="SKU">, berubah jadi stepper + hapus bila SKU ada di keranjang.
export const cartButton = () => ({
    sku: '',
    init() {
        this.sku = this.$el.dataset.sku;
        this.$store.cart.load();
    },
    get qty() { return this.$store.cart.qty(this.sku); },
    get inCart() { return this.qty > 0; },
    get busy() { return this.$store.cart.busy; },
    get cannotDec() { return this.busy || this.qty <= 1; },
    get cannotInc() { return this.busy || this.qty >= this.$store.cart.max(this.sku); },
    inc() { return this.$store.cart.set(this.sku, this.qty + 1); },
    dec() { return this.$store.cart.set(this.sku, this.qty - 1); },
    remove() { return this.$store.cart.remove(this.sku); },
});

// Tombol "tambah ke keranjang" yang dirender server: <button data-add-cart="SKU">.
export const initAddButtons = (store) => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-add-cart]');
        if (button) store.add(button.dataset.addCart);
    });
};
