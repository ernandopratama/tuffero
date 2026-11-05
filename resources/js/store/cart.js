import api from './api';

// Jumlah di header dan pemberitahuan tambah keranjang, dipakai bersama semua halaman toko lewat Alpine.store('cart').
export const cartStore = (initialCount, words) => ({
    count: initialCount,
    toast: '',
    timer: null,
    show(text) {
        this.toast = text;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.toast = ''; }, 4000);
    },
    async add(sku, quantity = 1) {
        try {
            const { data } = await api.post('/cart/items', { sku, quantity });
            this.count = data.data.count;
            this.show(words.added);
            return true;
        } catch (e) {
            this.show(e.response?.status === 422 ? words.addUnavailable : words.addFailed);
            return false;
        }
    },
});

// Tombol "tambah ke keranjang" yang dirender server: <button data-add-cart="SKU">.
export const initAddButtons = (store) => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-add-cart]');
        if (button) store.add(button.dataset.addCart);
    });
};
