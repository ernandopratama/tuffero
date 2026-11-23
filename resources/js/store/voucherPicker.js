import api from './api';
import { card } from './voucherText';

// Pilihan voucher (maksimal 2) untuk keranjang dan checkout (D-078). Server yang memutuskan boleh tidaknya
// dan menghitung potongan; di sini hanya pilihan dan tampilan. Digabung ke komponen halaman lewat mix().
// Komponen induk menyediakan: words (words.voucher, words.errors, words.tooMany), $refs.voucherDialog, dan opsional form.customer_phone.
const STORAGE_KEY = 'tf-vouchers';
export const MAX_VOUCHERS = 2;

const rp = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;
const readStored = () => {
    try {
        const codes = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        return Array.isArray(codes) ? codes.filter((code) => typeof code === 'string').slice(0, MAX_VOUCHERS) : [];
    } catch { return []; }
};
const writeStored = (codes) => {
    try {
        if (codes.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(codes));
        else sessionStorage.removeItem(STORAGE_KEY);
    } catch { /* penyimpanan sesi dimatikan: pilihan hanya berlaku di halaman ini */ }
};
export const clearStoredVouchers = () => writeStored([]);

export const voucherPicker = () => ({
    vouchers: [],
    vouchersLoaded: false,
    picked: [],
    applied: [],
    voucherTotal: 0,
    // Potongan gratis ongkir (D-126), terpisah dari voucherTotal yang memotong subtotal.
    shippingDiscount: 0,
    voucherBusy: false,
    voucherError: '',
    voucherCode: '',

    get vw() { return this.words.voucher; },
    get hasVoucher() { return this.applied.length > 0; },
    get discount() { return this.voucherTotal; },
    get appliedCodes() { return this.applied.map((item) => item.code); },
    get pickCountText() { return this.vw.pickCount.replace('{n}', this.picked.length); },
    get applyLabel() {
        if (this.voucherBusy) return this.vw.applying;
        return this.picked.length ? this.vw.applyCount.replace('{n}', this.picked.length) : (this.hasVoucher ? this.vw.clear : this.vw.apply);
    },
    get voucherButtonText() { return this.hasVoucher ? this.vw.change : this.vw.choose; },

    voucherTitle(item) { return card.title(this.vw, item); },
    voucherMeta(item) { return card.meta(this.vw, item); },
    voucherDescription(item) { return item.description || ''; },
    isShippingVoucher(item) { return item.kind === 'shipping'; },
    appliedLine(item) { return `${this.isShippingVoucher(item) ? this.vw.shippingTag : this.vw.title} ${item.code}`; },
    appliedText(item) { return '-' + rp(item.discount); },
    // Konteks tambahan untuk pratinjau gratis ongkir (ongkir dan provinsi). Checkout menimpanya; keranjang belum tahu ongkir.
    voucherContext() { return {}; },
    // Nomor, jalan, dan kode pos pelanggan hanya lewat badan POST (bukan URL), untuk batas pakai per nomor dan per alamat (D-147).
    voucherIdentity() {
        return { customer_phone: this.form?.customer_phone || undefined, street: this.form?.street || undefined, postal_code: this.form?.postal_code || undefined };
    },

    // Status kartu di pilihan: on = terpilih, off = belum memenuhi minimum, full = sudah 2, nostack = tidak bisa digabung, ok = bisa dipilih.
    // D-126: maksimal 1 gratis ongkir; dua voucher biasa harus sama-sama bisa digabung; gratis ongkir + 1 voucher biasa selalu boleh.
    pickState(item) {
        if (this.picked.includes(item.code)) return 'on';
        if (!item.eligible) return 'off';
        if (this.picked.length >= MAX_VOUCHERS) return 'full';
        const chosen = this.vouchers.filter((other) => this.picked.includes(other.code));
        if (this.isShippingVoucher(item)) return chosen.some((other) => this.isShippingVoucher(other)) ? 'oneship' : 'ok';
        const regular = chosen.filter((other) => !this.isShippingVoucher(other));
        if (regular.length && (!item.stackable || regular.some((other) => !other.stackable))) return 'nostack';
        return 'ok';
    },
    isPicked(item) { return this.picked.includes(item.code); },
    pickBlocked(item) { return !['on', 'ok'].includes(this.pickState(item)); },
    pickClass(item) {
        const state = this.pickState(item);
        // Kartu yang terhalang memakai gaya is-nostack yang sudah ada.
        return [`is-${state === 'oneship' ? 'nostack' : state}`, this.isShippingVoucher(item) ? 'is-shipping' : ''].join(' ');
    },
    pickNote(item) {
        const state = this.pickState(item);
        if (state === 'off') return this.vw.needMore.replace('{amount}', rp(item.missing));
        if (state === 'nostack') return this.vw.noStack;
        if (state === 'oneship') return this.vw.oneShipping;
        if (state === 'full') return this.vw.full;
        // Di keranjang ongkir belum diketahui: potongan pasti dihitung di checkout.
        if (this.isShippingVoucher(item) && this.voucherContext().shipping_fee === undefined) return this.vw.shippingLater;
        return this.vw.save.replace('{amount}', rp(item.discount));
    },
    pickNoteClass(item) { return ['off', 'nostack', 'oneship', 'full'].includes(this.pickState(item)) ? 'is-warn' : 'is-ok'; },
    togglePick(item) {
        this.voucherError = '';
        if (this.picked.includes(item.code)) this.picked = this.picked.filter((code) => code !== item.code);
        else if (this.pickState(item) === 'ok') this.picked = [...this.picked, item.code];
    },

    async loadVoucherList() {
        try {
            const { data } = await api.get('/vouchers', { params: this.voucherContext() });
            this.vouchers = data.data.vouchers;
            this.vouchersLoaded = true;
        } catch {
            this.voucherError = this.vw.failed;
        }
    },
    async openVouchers() {
        this.voucherError = '';
        this.voucherCode = '';
        this.picked = [...this.appliedCodes];
        this.$refs.voucherDialog.showModal();
        await this.loadVoucherList();
        // Voucher terpilih yang sudah tidak ada di daftar (kedaluwarsa) dilepas dari pilihan.
        this.picked = this.picked.filter((code) => this.vouchers.some((item) => item.code === code));
    },
    closeVouchers() { this.$refs.voucherDialog.close(); },
    voucherBackdrop(event) { if (event.target === this.$refs.voucherDialog) this.closeVouchers(); },
    submitPick() { return this.applyCodes(this.picked); },
    // Kode yang diketik (voucher tersembunyi) ditambahkan ke pilihan; maksimal 2.
    applyCode() {
        const code = this.voucherCode.trim().toUpperCase();
        if (!code) return Promise.resolve();
        const codes = [...new Set([...this.picked, code])];
        if (codes.length > MAX_VOUCHERS) {
            this.voucherError = this.words.errors.voucher_limit;
            return Promise.resolve();
        }
        return this.applyCodes(codes, true);
    },
    setApplied(rows, total, shipping = 0) {
        this.applied = rows;
        this.voucherTotal = total;
        this.shippingDiscount = shipping;
        writeStored(rows.map((item) => item.code));
    },
    clearVouchers() { this.setApplied([], 0); },
    removeVoucher(item) { return this.applyCodes(this.appliedCodes.filter((code) => code !== item.code), false, true); },
    async applyCodes(codes, typed = false, quiet = false) {
        if (this.voucherBusy) return;
        this.voucherBusy = true;
        this.voucherError = '';
        try {
            if (!codes.length) {
                this.clearVouchers();
            } else {
                const { data } = await api.post('/vouchers/check', { codes, ...this.voucherIdentity(), ...this.voucherContext() });
                this.setApplied(data.data.vouchers, data.data.discount, data.data.shipping_discount);
                if (typed) this.voucherCode = '';
            }
            if (this.errors) delete this.errors.voucher_code;
            if (this.formError !== undefined) this.formError = '';
            if (!quiet) this.closeVouchers();
        } catch (e) {
            const code = e.response?.data?.errors?.voucher_code?.[0];
            this.voucherError = this.words.errors[code] || (e.response?.status === 429 ? this.words.tooMany : this.vw.failed);
        } finally {
            this.voucherBusy = false;
        }
    },
    // Halaman dibuka lagi atau isi keranjang berubah: periksa ulang pilihan tersimpan. Yang ditolak server dilepas; gagal sementara dibiarkan.
    async restoreVouchers() {
        const codes = this.applied.length ? this.appliedCodes : readStored();
        if (!codes.length) return;
        try {
            const { data } = await api.post('/vouchers/check', { codes, ...this.voucherIdentity(), ...this.voucherContext() });
            this.setApplied(data.data.vouchers, data.data.discount, data.data.shipping_discount);
        } catch (e) {
            if (e.response?.status === 422) {
                this.clearVouchers();
                this.$store.toast.show(this.vw.dropped, 'info');
            }
        }
    },
});
