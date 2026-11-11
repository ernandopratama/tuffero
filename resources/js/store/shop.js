import api from './api';
import { addressPicker, mix } from './addressPicker';
import { pageData } from './site';
import { clearStoredVouchers, voucherPicker } from './voucherPicker';

export const rupiah = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;
const productName = (item) => (item.variant_name === 'Standar' ? item.product_name : `${item.product_name} ${item.variant_name}`);

export const cartPage = () => mix(voucherPicker(), {
    cart: null,
    loading: true,
    failed: false,
    busy: false,
    words: {},
    voucherBase: null,

    init() {
        this.words = pageData('tf-cart-data');
        this.load();
    },
    get hasVouchers() { return this.vouchersLoaded && this.vouchers.length > 0; },
    get voucherCountText() { return this.fill(this.words.voucherCount, this.vouchers.length); },
    // Kosong: member diajak mengklaim, tamu diajak masuk dulu (klaim butuh akun, D-072). Sebelum selesai dimuat: teks lama.
    get voucherHint() {
        if (!this.vouchersLoaded) return this.words.voucherHint;
        return this.words.customer ? this.words.voucherEmptyMember : this.words.voucherEmptyGuest;
    },
    get voucherBarText() {
        if (!this.hasVoucher) return this.words.voucherOpen;
        return this.words.voucherAppliedBar.replace('{n}', this.applied.length).replace('{amount}', rupiah(this.voucherTotal));
    },
    // Daftar dan pilihan tersimpan diperiksa ulang tiap subtotal berubah, karena kelayakan voucher bergantung padanya.
    async loadVouchers() {
        this.voucherBase = this.cart?.subtotal ?? null;
        await this.loadVoucherList();
        await this.restoreVouchers();
    },
    rupiah,
    productName,
    photoUrl(item) { return encodeURI(item.photo); },
    insufficient(item) { return this.words.insufficient.replace('{n}', item.available); },
    removeLabel(item) { return this.words.removeLabel.replace('{name}', productName(item)); },
    // Total bar = subtotal produk tercentang dikurangi voucher yang dipilih (diskon dari server lewat voucherPicker); ongkir dihitung di checkout.
    get payable() { return Math.max(0, (this.cart?.subtotal ?? 0) - this.voucherTotal); },
    get payableText() { return rupiah(this.payable); },
    // Hemat = diskon produk + voucher.
    get totalSaving() { return (this.cart?.saving ?? 0) + this.voucherTotal; },
    get hasSaving() { return this.totalSaving > 0; },
    get savingText() { return rupiah(this.totalSaving); },
    hasOff(item) { return item.normal > item.price; },
    offText(item) { return '-' + Math.round((1 - item.price / item.normal) * 100) + '%'; },
    get ready() { return !this.loading && !this.failed; },
    get empty() { return this.ready && !this.cart.items.length; },
    get filled() { return this.ready && this.cart.items.length > 0; },
    // Centang menentukan produk yang dihitung di total dan dibeli saat checkout (disimpan di session lewat PUT /cart/selection); Hapus (n) menghapus yang tercentang.
    selectSeq: 0,
    get active() { return this.cart.items.filter((item) => item.issue !== 'unavailable'); },
    get gone() { return this.cart.items.filter((item) => item.issue === 'unavailable'); },
    get selectedItems() { return this.active.filter((item) => item.selected); },
    get allSelected() { return this.active.length > 0 && this.selectedItems.length === this.active.length; },
    isSelected(item) { return !!item.selected; },
    toggle(item) { return this.select(this.selectedItems.map((i) => i.sku).filter((sku) => sku !== item.sku).concat(item.selected ? [] : [item.sku])); },
    toggleAll() { return this.select(this.allSelected ? [] : this.active.map((item) => item.sku)); },
    // Total langsung dihitung di browser supaya terasa instan; respons server (sumber kebenaran) menggantikannya.
    select(skus) {
        const wanted = new Set(skus);
        this.updateError = '';
        this.cart.items.forEach((item) => { item.selected = item.issue !== 'unavailable' && wanted.has(item.sku); });
        const picked = this.cart.items.filter((item) => item.selected);
        this.cart.subtotal = picked.reduce((sum, item) => sum + item.line_total, 0);
        this.cart.saving = picked.reduce((sum, item) => sum + Math.max(0, item.normal - item.price) * item.quantity, 0);
        this.cart.can_checkout = picked.length > 0 && picked.every((item) => !item.issue);
        const seq = ++this.selectSeq;
        return api.put('/cart/selection', { skus: picked.map((item) => item.sku) })
            .then(({ data }) => { if (seq === this.selectSeq) this.apply(data); })
            .catch(() => { this.updateError = this.words.updateFailed; this.load(); });
    },
    fill(text, n) { return text.replace('{n}', n); },
    hasVariant(item) { return item.variant_name && item.variant_name !== 'Standar'; },
    variantText(item) { return this.words.variant.replace('{name}', item.variant_name); },
    selectLabel(item) { return this.words.selectLabel.replace('{name}', productName(item)); },
    get countText() { return this.fill(this.words.count, this.cart.items.length); },
    get selectAllText() { return this.fill(this.words.selectAll, this.active.length); },
    get removeSelectedText() { return this.fill(this.words.removeSelected, this.selectedItems.length); },
    get unavailableText() { return this.fill(this.words.unavailableTitle, this.gone.length); },
    get totalText() { return this.fill(this.words.total, this.selectedItems.length); },
    get checkoutText() { return this.fill(this.words.checkoutCount, this.selectedItems.length); },
    apply(data) {
        this.cart = data.data;
        this.$store.cart.count = data.data.count;
        // Kelayakan voucher bergantung pada subtotal, jadi daftar dimuat ulang saat subtotal berubah.
        if (data.data.subtotal !== this.voucherBase) this.loadVouchers();
    },
    async load() {
        this.loading = true;
        this.failed = false;
        try {
            const { data } = await api.get('/cart');
            this.apply(data);
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
    async run(request, done) {
        this.busy = true;
        try {
            const { data } = await request();
            this.apply(data);
            if (done) this.$store.toast.show(done);
        } catch {
            this.$store.toast.show(this.words.updateFailed, 'error');
        } finally {
            this.busy = false;
        }
    },
    setQuantity(item, quantity) { return this.run(() => api.patch(`/cart/items/${encodeURIComponent(item.sku)}`, { quantity })); },
    inc(item) { return this.setQuantity(item, item.quantity + 1); },
    dec(item) { return this.setQuantity(item, item.quantity - 1); },
    removing: '',
    isRemoving(item) { return this.removing === item.sku; },
    // Baris baru hilang setelah animasi buang sampah (400ms) selesai, kecuali pengguna memilih kurangi gerak.
    async remove(item) {
        this.removing = item.sku;
        const wait = new Promise((done) => setTimeout(done, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 400));
        await this.run(async () => {
            const response = await api.delete(`/cart/items/${encodeURIComponent(item.sku)}`);
            await wait;
            return response;
        }, this.words.removed);
        this.removing = '';
    },
    // Satu permintaan per produk lewat API hapus yang sudah ada; tiap respons langsung dipakai agar keranjang tidak basi bila salah satu gagal.
    async removeMany(items) {
        this.busy = true;
        try {
            for (const item of [...items]) {
                const { data } = await api.delete(`/cart/items/${encodeURIComponent(item.sku)}`);
                this.apply(data);
            }
            this.$store.toast.show(this.words.removed);
        } catch {
            this.$store.toast.show(this.words.updateFailed, 'error');
        } finally {
            this.busy = false;
        }
    },
});

const ADDRESS_FIELDS = ['street', 'district', 'city', 'province', 'postal_code'];
const CONTACT_FIELDS = ['customer_name', 'customer_email', 'customer_phone'];
const localPhone = (phone) => (phone || '').replace(/^\+62/, '');
const contactFrom = (address) => ({ customer_name: address.recipient_name, customer_phone: localPhone(address.phone) });

const COURIER_LOGOS = ['jnt', 'sicepat'];
// Posisi truk (0 sampai 1) per status paket Biteship. Status bermasalah tidak maju: tertahan di titik terakhir, kembali ke gudang bila dikembalikan.
const CARRIER_ROUTE = { confirmed: 0.08, allocated: 0.18, picking_up: 0.3, picked: 0.5, in_transit: 0.65, dropping_off: 0.85, delivered: 1, on_hold: 0.55, courier_not_found: 0.08, cancelled: 0, return_in_transit: 0.5, returned: 0, rejected: 0, disposed: 0 };
// Paket tidak akan sampai ke pelanggan: tahap 4 berhenti.
const FAILED_CARRIER = ['cancelled', 'return_in_transit', 'returned', 'rejected', 'disposed'];
const PROBLEM_CARRIER = ['on_hold', 'courier_not_found', 'cancelled', 'return_in_transit', 'returned', 'rejected', 'disposed'];

export const checkoutPage = () => mix(addressPicker('co'), voucherPicker(), {
        cart: null,
        loading: true,
        loadFailed: false,
        form: { customer_name: '', customer_email: '', customer_phone: '', street: '', district: '', city: '', province: '', postal_code: '' },
        errors: {},
        formError: '',
        submitting: false,
        rates: [],
        ratesLoading: false,
        ratesFailed: false,
        selected: null,
        ratesRequest: 0,
        // Sama untuk semua percobaan di halaman ini: kirim ganda tidak membuat pesanan ganda.
        idempotencyKey: Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join(''),
        isCustomer: false,
        addresses: [],
        addressId: '',
        saveAddress: false,
        saveLabel: '',
        words: {},
        // Ongkir baru dicek setelah pelanggan menyimpan alamat (D-075): satu panggilan Biteship per alamat, bukan per ketikan.
        addressConfirmed: false,
        // Modal "alamat sudah benar?" tampil bersama loading selimut sementara tarif ongkir dimuat; tampilan baru muncul setelah pelanggan setuju dan tarif siap.
        confirming: false,
        accepting: false,
        confirmPromise: null,
        confirmToken: 0,

        init() {
            this.words = pageData('tf-checkout-data');
            this.saveLabel = this.words.saveLabelDefault;
            this.initAddressPicker();
            this.cartReady = this.loadCart();
            this.loadAccount();
        },
        rupiah,
        productName,
        get ready() { return !this.loading && !this.loadFailed; },
        // Hanya produk yang dicentang di keranjang yang ikut checkout.
        get lines() { return (this.cart?.items ?? []).filter((item) => item.selected); },
        get empty() { return this.ready && !this.lines.length; },
        get blocked() { return this.ready && this.lines.length > 0 && !this.cart.can_checkout; },
        get canFill() { return this.ready && this.lines.length > 0 && this.cart.can_checkout; },
        get fee() { return this.selected?.fee ?? null; },
        get hasSaving() { return (this.cart?.saving ?? 0) > 0; },
        get savingText() { return rupiah(this.cart.saving); },
        get total() { return (this.cart?.subtotal ?? 0) - this.discount + (this.fee ?? 0); },
        get feeText() { return this.fee === null ? this.words.shippingPending : rupiah(this.fee); },
        get subtotalText() { return rupiah(this.cart?.subtotal ?? 0); },
        get totalText() { return rupiah(this.total); },
        get leadText() { return this.isCustomer ? this.words.leadSignedIn : this.words.lead; },
        get submitText() {
            if (this.submitting) return this.words.submitting;
            return this.fee === null ? this.words.submit : `${this.words.submit} (${this.totalText})`;
        },
        // Tampilan FigCraft 04: kartu alamat, kartu kurir, daftar item, ringkasan tagihan.
        get itemsCount() { return this.lines.length; },
        get itemsTitle() { return this.words.itemsTitle.replace(':count', this.itemsCount); },
        get productsTotalText() { return this.words.productsTotal.replace(':count', this.itemsCount); },
        get shippingText() { return this.selected ? `${this.words.shippingTotal} (${this.selected.label})` : this.words.shippingTotal; },
        get phoneText() { return this.form.customer_phone ? `+62 ${this.form.customer_phone}` : ''; },
        get addressBadge() { return this.addresses.find((a) => a.id === this.addressId)?.label || this.words.addressSaved; },
        get courierTileClass() { return { 'is-set': !!this.selected, 'is-error': !!this.errors.courier }; },
        hasVariant(item) { return item.variant_name && item.variant_name !== 'Standar'; },
        variantText(item) { return this.words.variant.replace(':name', item.variant_name); },
        qtyText(item) { return this.words.qty.replace(':count', item.quantity); },
        eachText(item) { return this.words.each.replace(':price', rupiah(item.price)); },
        photoUrl(item) { return encodeURI(item.photo); },
        // Modal 04b: pilihan baru dipakai hanya setelah Terapkan, Batal mengembalikan pilihan lama.
        draft: null,
        openShipping() {
            if (this.ratesFailed && !this.ratesLoading) this.retryRates();
            this.draft = this.selected;
            this.$refs.shipDialog.showModal();
        },
        isDraft(rate) { return this.draft?.courier === rate.courier && this.draft?.service === rate.service; },
        pickDraft(rate) { this.draft = rate; },
        applyShipping() {
            if (this.draft) this.pickRate(this.draft);
            this.closeShipping();
            this.$nextTick(() => document.getElementById('co-courier')?.focus());
        },
        closeShipping() { this.$refs.shipDialog.close(); },
        clearDraft() { this.draft = null; },
        shippingBackdrop(event) { if (event.target === this.$refs.shipDialog) this.closeShipping(); },
        backToEdit() {
            this.editAddress();
            this.$nextTick(() => document.getElementById('co-ship')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },
        get canSaveAddress() { return this.isCustomer && !this.addressId && this.addresses.length < 10; },
        get hasRates() { return this.rates.length > 0; },
        get courierHint() { return !this.addressConfirmed; },
        get noRates() { return this.addressConfirmed && !this.ratesLoading && !this.ratesFailed && !this.rates.length; },
        get addressSummary() {
            const f = this.form;
            return [f.street, `${f.district}, ${f.city}`, `${f.province} ${f.postal_code}`];
        },
        // Pemeriksaan ringan sebelum memanggil ongkir; server tetap memvalidasi ulang saat pesanan dibuat.
        confirmAddress() {
            if (this.confirming) return;
            const f = this.form;
            const errors = {};
            if (!f.street.trim()) errors.street = 'street_required';
            if (!f.province.trim()) errors.province = 'province_required';
            if (!f.district.trim()) errors.district = 'district_required';
            if (!f.city.trim()) errors.city = 'city_required';
            if (!/^\d{5}$/.test(f.postal_code)) errors.postal_code = 'postal_invalid';
            Object.entries(errors).forEach(([key, code]) => { this.errors[key] = this.words.errors[code]; });
            if (Object.keys(errors).length) {
                this.$nextTick(() => document.querySelector('.tf-checkout [aria-invalid="true"]')?.focus());
                return;
            }
            this.combo = '';
            this.formError = '';
            this.confirming = true;
            this.accepting = false;
            this.confirmPromise = this.loadRates(f.postal_code);
            this.confirmToken = this.ratesRequest;
            this.$nextTick(() => document.getElementById('co-confirm-yes')?.focus());
        },
        // Pelanggan membenarkan alamat: lanjut ke tampilan kurir begitu tarif selesai dimuat.
        async acceptAddress() {
            if (!this.confirming || this.accepting) return;
            this.accepting = true;
            const token = this.confirmToken;
            await this.confirmPromise;
            if (!this.confirming || token !== this.ratesRequest) return;
            this.confirming = false;
            this.accepting = false;
            this.addressConfirmed = true;
            this.$nextTick(() => document.getElementById('co-courier')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },
        onEscape() { if (this.confirming) this.editAddress(); },
        get confirmChecking() { return this.ratesLoading; },
        get confirmDone() { return !this.ratesLoading && !this.ratesFailed; },
        get confirmFailed() { return !this.ratesLoading && this.ratesFailed; },
        editAddress() {
            this.confirming = false;
            this.accepting = false;
            this.addressConfirmed = false;
            this.ratesRequest += 1;
            this.rates = [];
            this.selected = null;
            this.ratesFailed = false;
            this.ratesLoading = false;
            this.$nextTick(() => document.getElementById('co-street')?.focus());
        },
        retryRates() { return this.loadRates(this.form.postal_code); },
        rateKey(rate) { return `${rate.courier}-${rate.service}`; },
        isSelected(rate) { return this.selected?.courier === rate.courier && this.selected?.service === rate.service; },
        pickRate(rate) {
            this.selected = rate;
            delete this.errors.courier;
            delete this.errors.service;
            this.formError = this.errors.cart || this.errors.shipping || '';
        },
        courierLogo(rate) { return COURIER_LOGOS.includes(rate.courier) ? `/images/Logo/kurir/${rate.courier}.webp` : ''; },
        courierInitials(rate) { return String(rate.courier).slice(0, 2).toUpperCase(); },
        etdText(rate) { return rate.etd ? this.words.etd[rate.etd_unit === 'hours' ? 'hours' : 'days'].replace(':range', rate.etd) : ''; },
        rateClass(rate) { return this.isSelected(rate) ? 'is-selected' : ''; },
        addressLabel(address) { return `${address.label} · ${address.street}`; },
        lineLabel(item) { return `${item.quantity} × ${productName(item)}`; },
        clearError(key) { delete this.errors[key]; },
        invalid(key) { return !!this.errors[key]; },
        errorFor(key) { return this.errors[key]; },
        fieldClass(key) { return this.errors[key] ? 'is-error' : ''; },

        async loadCart() {
            this.loading = true;
            this.loadFailed = false;
            try {
                const { data } = await api.get('/cart');
                this.cart = data.data;
                this.restoreVouchers();
            } catch {
                this.loadFailed = true;
            } finally {
                this.loading = false;
            }
        },
        async loadRates(code) {
            this.selected = null;
            this.rates = [];
            this.ratesFailed = false;
            if (!/^\d{5}$/.test(code)) return;
            const request = ++this.ratesRequest;
            this.ratesLoading = true;
            try {
                // Keranjang kosong: server menolak ongkir dengan 422 (alamat utama memuat tarif otomatis), jadi jangan panggil.
                await this.cartReady;
                if (request !== this.ratesRequest || !this.lines.length) return;
                const { data } = await api.post('/shipping/rates', { postal_code: code });
                if (request === this.ratesRequest) this.rates = data.data.rates;
            } catch {
                if (request === this.ratesRequest) this.ratesFailed = true;
            } finally {
                if (request === this.ratesRequest) this.ratesLoading = false;
            }
        },
        async submit() {
            if (this.submitting) return;
            if (!this.addressConfirmed) {
                this.formError = this.words.confirmFirst;
                document.getElementById('co-confirm')?.focus();
                return;
            }
            this.submitting = true;
            this.errors = {};
            this.formError = '';
            const form = this.form;
            try {
                const { data } = await api.post('/checkout', {
                    customer_name: form.customer_name,
                    customer_email: form.customer_email,
                    customer_phone: form.customer_phone,
                    address: {
                        street: form.street, district: form.district, city: form.city, province: form.province, postal_code: form.postal_code,
                        ...this.pinPayload(),
                    },
                    courier: this.selected?.courier ?? '',
                    service: this.selected?.service ?? '',
                    voucher_codes: this.appliedCodes,
                }, { 'Idempotency-Key': this.idempotencyKey });
                const { public_ref: ref, token } = data.data;
                clearStoredVouchers();
                await this.storeAddress();
                window.location.href = `/pesanan/${ref}${token ? `#t=${token}` : ''}`;
            } catch (e) {
                this.submitting = false;
                const status = e.response?.status;
                if (status === 422) {
                    const fields = e.response.data.errors || {};
                    this.errors = Object.fromEntries(Object.entries(fields).map(([key, [value]]) => [key.replace('address.', ''), this.words.errors[value] || value]));
                    this.formError = this.errors.cart || this.errors.shipping || this.errors.courier || this.errors.voucher_code || '';
                    // Alamat atau data pemesan ditolak server: buka lagi isiannya supaya pesan galat terlihat.
                    if ([...ADDRESS_FIELDS, ...CONTACT_FIELDS].some((key) => this.errors[key])) this.addressConfirmed = false;
                    // Voucher ditolak server: lepas supaya pelanggan bisa memilih ulang tanpa terjebak.
                    if (this.errors.voucher_code) this.clearVouchers();
                    this.$nextTick(() => document.querySelector('.tf-checkout [aria-invalid="true"]')?.focus());
                } else {
                    this.formError = status === 429 ? this.words.tooMany : this.words.failed;
                }
            }
        },
        // Pelanggan yang masuk: isi yang masih kosong dari profil dan alamat utama. Yang sudah diketik tidak ditimpa. Server tetap memvalidasi semuanya.
        fillEmpty(values) {
            Object.entries(values).forEach(([key, value]) => { if (!this.form[key] && value) this.form[key] = value; });
        },
        chooseAddress(event) {
            const id = event.target.value === '' ? '' : Number(event.target.value);
            this.addressId = id;
            this.saveAddress = false;
            const address = this.addresses.find((a) => a.id === id);
            ADDRESS_FIELDS.forEach((key) => { this.form[key] = address ? address[key] : ''; delete this.errors[key]; });
            if (address) Object.assign(this.form, contactFrom(address));
            this.auto = {};
            this.editAddress();
            this.useSaved(address);
        },
        // Alamat dari akun sudah pernah disimpan pelanggan: titiknya dipakai dan ongkir langsung dimuat tanpa klik tambahan (D-077).
        useSaved(address) {
            if (!address) {
                this.resetPin();
                return;
            }
            this.usePin(address.latitude, address.longitude);
            this.addressConfirmed = true;
            this.loadRates(this.form.postal_code);
        },
        async loadAccount() {
            if (!this.words.customer) return;
            this.isCustomer = true;
            try {
                const [profile, saved] = await Promise.all([api.get('/account/profile'), api.get('/account/addresses')]);
                this.addresses = saved.data.data.items;
                const main = this.addresses[0];
                this.fillEmpty({ ...(main ? contactFrom(main) : {}), customer_email: profile.data.data.email });
                this.fillEmpty({ customer_name: profile.data.data.name, customer_phone: localPhone(profile.data.data.phone) });
                if (main && ADDRESS_FIELDS.every((key) => !this.form[key])) {
                    this.addressId = main.id;
                    ADDRESS_FIELDS.forEach((key) => { this.form[key] = main[key]; });
                    this.useSaved(main);
                }
            } catch { /* Gagal memuat bukan halangan: formulir tetap bisa diisi manual. */ }
        },
        // Pesanan sudah dibuat, jadi gagal menyimpan alamat tidak boleh menghalangi pelanggan.
        async storeAddress() {
            if (!this.isCustomer || this.addressId || !this.saveAddress) return;
            try {
                await api.post('/account/addresses', {
                    label: this.saveLabel || this.words.saveLabelDefault,
                    recipient_name: this.form.customer_name,
                    phone: this.form.customer_phone,
                    ...Object.fromEntries(ADDRESS_FIELDS.map((key) => [key, this.form[key]])),
                    ...this.pinPayload(),
                });
            } catch { /* lihat komentar di atas */ }
        },
});

// snap.js dimuat hanya saat pelanggan menekan Bayar, bukan di setiap halaman.
let snapLoading = null;
const loadSnap = ({ snapJs, clientKey }) => {
    if (window.snap) return Promise.resolve();
    snapLoading ??= new Promise((resolve, reject) => {
        const script = Object.assign(document.createElement('script'), { src: snapJs, onload: resolve, onerror: () => { snapLoading = null; reject(new Error('snap.js')); } });
        script.dataset.clientKey = clientKey;
        document.head.appendChild(script);
    });
    return snapLoading;
};

const STEP_INDEX = { pending_payment: 1, paid: 2, processing: 2, shipped: 3, completed: 4 };
// Langkah atas (Keranjang, Pembayaran, Pengiriman, Selesai) dan tahap kartu status (FigCraft 05/06).
const FLOW_INDEX = { pending_payment: 1, paid: 2, processing: 2, shipped: 2, completed: 3 };
const STAGE_INDEX = { paid: 1, processing: 1, shipped: 2, completed: 4 };
const VIEW = { pending_payment: 'pay', expired: 'expired', paid: 'progress', processing: 'progress', shipped: 'progress', completed: 'done', cancelled: 'closed', refunded: 'closed' };
const locale = () => (document.documentElement.lang === 'en' ? 'en-GB' : 'id-ID');
// Toko di Malang: waktu ditampilkan dalam WIB apa pun zona waktu perangkat.
const wib = (value, options) => `${new Date(value).toLocaleString(locale(), { timeZone: 'Asia/Jakarta', ...options })} WIB`;

export const orderPage = () => ({
    ref: '',
    order: null,
    loading: true,
    notFound: false,
    failed: false,
    copied: false,
    // Riwayat per hub/kota dari kurir lewat Biteship (hanya saat paket sudah dikirim dan punya resi); '' | loading | ready | failed.
    hubEvents: [],
    hubState: '',
    now: Date.now(),
    // Selisih jam server terhadap jam perangkat (dari header Date), supaya batas bayar tidak salah di perangkat dengan jam meleset.
    skew: 0,
    reason: '',
    channel: 'whatsapp',
    cancelBusy: false,
    cancelError: '',
    cancelDone: null,
    words: {},
    payConfig: null,
    payBusy: false,
    // Panel Snap tampil di dalam halaman; tetap terlihat setelah pending agar instruksi VA/QRIS tidak hilang.
    embedded: false,
    payError: '',
    // Galat dari jendela Snap hanya mengganti tampilan (11c); status pesanan tetap dibaca dari server.
    payFailed: false,
    checking: false,
    stillPending: false,
    // Tombol "Saya sudah membayar" dan pemeriksaan berkala hanya aktif setelah pembeli membuka pembayaran (diingat per pesanan selama sesi browser).
    payStarted: false,
    syncing: null,
    lastSync: 0,

    init() {
        const data = pageData('tf-order-data');
        this.ref = data.ref;
        this.words = data.words;
        this.payConfig = data.pay;
        try { this.payStarted = sessionStorage.getItem(`tf-pay-${this.ref}`) === '1'; } catch { /* sessionStorage diblokir: tombol muncul lagi setelah Bayar ditekan */ }
        this.load();
        setInterval(() => { this.now = Date.now(); }, 1000);
        // Setiap 10 detik selama panel bayar terbuka dan tab terlihat; server membatasi panggilan ke Midtrans (cooldown 5 detik).
        setInterval(() => { this.autoCheck(); }, 10000);
    },
    rupiah,
    productName,
    // Token ada di fragmen URL, jadi tidak terkirim ke server lewat log atau Referer. API menerimanya lewat header.
    headers() {
        const t = window.location.hash.startsWith('#t=') ? window.location.hash.slice(3) : '';
        return t ? { 'X-Order-Token': t } : {};
    },
    get shown() { return !this.loading && !this.notFound && !this.failed && !!this.order; },
    get hasDiscount() { return (this.order?.discount_total ?? 0) > 0; },
    get discountText() { return '-' + rupiah(this.order.discount_total); },
    get stepIndex() { return STEP_INDEX[this.order?.status] ?? -1; },
    get statusText() { return this.words.status[this.order?.status] || [this.order?.status, '']; },
    // Pesanan Dikirim: badge dan kalimat pembuka mengikuti status paket Biteship (this.carrier), bukan hanya status pesanan.
    get statusTitle() { return this.carrier ? this.track.carrier[this.carrier] : this.statusText[0]; },
    get statusNote() { return this.carrier ? this.track.carrierMessage[this.carrier].replace(':courier', this.courierText) : this.statusText[1]; },
    get isPending() { return this.order?.status === 'pending_payment'; },
    get showCancel() { return !!(this.order?.cancellation || this.order?.can_cancel); },
    get clock() {
        const expires = this.order?.expires_at;
        const s = expires ? Math.max(0, Math.floor((new Date(expires) - (this.now + this.skew)) / 1000)) : 0;
        return [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map((part) => String(part).padStart(2, '0')).join(':');
    },
    get addressLines() {
        const a = this.order?.shipping_address;
        return a ? [`${a.recipient} (${a.phone})`, a.street, `${a.district}, ${a.city}`, `${a.province} ${a.postal_code}`] : [];
    },
    get courierText() { return this.order ? `${this.order.shipping_courier.toUpperCase()} ${this.order.shipping_service.toUpperCase()}` : ''; },
    get cancelStatus() { return this.words.cancelStatus[this.order?.cancellation?.status]; },
    stepClass(i) { return { 'is-done': i < this.stepIndex, 'is-now': i === this.stepIndex }; },
    stepCurrent(i) { return i === this.stepIndex ? 'step' : null; },
    stepNumber(i) { return i + 1; },
    lineLabel(item) { return `${item.quantity} × ${productName(item)}`; },
    setChannel(value) { this.channel = value; },
    get reasonCount() { return `${this.reason.length}/500`; },
    openCancel() { this.cancelError = ''; this.$refs.cancelDialog.showModal(); },
    closeCancel() { this.$refs.cancelDialog.close(); },
    cancelBackdrop(event) { if (event.target === this.$refs.cancelDialog) this.closeCancel(); },
    // Waktu bayar habis menurut jam server: halaman langsung berganti ke "kedaluwarsa" tanpa menunggu penjadwal menutup pesanan.
    get timeUp() { return this.order?.status === 'pending_payment' && !!this.order.expires_at && new Date(this.order.expires_at) <= this.now + this.skew; },
    get view() {
        if (!this.shown) return '';
        const view = this.timeUp ? 'expired' : (VIEW[this.order.status] ?? 'closed');
        return view === 'pay' && this.payFailed ? 'payFailed' : view;
    },
    get hasFlow() { return ['pay', 'payFailed', 'progress', 'done'].includes(this.view); },
    // Langkah pemesanan hanya di halaman pembayaran; halaman status (progress, selesai) sudah punya kartu tahap sendiri.
    get showFlow() { return this.hasFlow && this.view !== 'progress' && this.view !== 'done'; },
    get flowIndex() { return FLOW_INDEX[this.order?.status] ?? -1; },
    flowClass(i) { return { 'is-done': i < this.flowIndex, 'is-now': i === this.flowIndex }; },
    flowCurrent(i) { return i === this.flowIndex ? 'step' : null; },
    get stageIndex() { return STAGE_INDEX[this.order?.status] ?? 0; },
    // Tahap 3 (kurir) menampilkan status Biteship saat ada; tahap lain tetap.
    get stages() {
        const stage = this.carrier && this.words.carrierStage?.[this.carrier];
        const end = FAILED_CARRIER.includes(this.carrier) && this.words.carrierEnd;
        return stage ? this.words.stages.map((item, i) => (i === 2 ? stage : i === 3 && end ? end : item)) : this.words.stages;
    },
    stageClass(i) { return { 'is-done': i < this.stageIndex, 'is-now': i === this.stageIndex, 'is-issue': i === this.stageIndex && PROBLEM_CARRIER.includes(this.carrier) }; },
    stageCurrent(i) { return i === this.stageIndex ? 'step' : null; },
    stageTag(i) {
        const state = i < this.stageIndex ? 'done' : i === this.stageIndex ? 'now' : FAILED_CARRIER.includes(this.carrier) ? 'stopped' : 'next';
        return `${this.words.stageLabel.replace(':n', i + 1)} · ${this.words.stageState[state]}`;
    },
    get payEyebrow() { return this.words.payEyebrow.replace(':number', this.order.order_number); },
    get payBeforeText() { return this.words.payBefore.replace(':date', wib(this.order.expires_at, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })); },
    get howSteps() { return this.words.how.map((step) => step.replace(':amount', rupiah(this.order.total))); },
    get productsText() { return this.words.products.replace(':count', this.order.items.length); },
    get shippingWithText() { return this.words.shippingWith.replace(':courier', this.courierText); },
    get expiredText() { return this.words.expiredText.replace(':number', this.order.order_number); },
    get failedText() {
        return this.words.failedText.replace(':number', this.order.order_number).replace(':time', wib(this.order.expires_at, { hour: '2-digit', minute: '2-digit' }));
    },
    get headingText() { return (this.carrier && this.words.carrierHeading?.[this.carrier]) || this.words.heading[this.order.status] || this.statusTitle; },
    get thanksText() { return this.words.thanks.replace(':name', this.order.customer_name); },
    get doneTitleText() { return this.words.doneTitle.replace(':name', this.order.customer_name); },
    get createdText() { return wib(this.order.created_at, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }); },
    // Baris alamat tanpa nama dan nomor penerima (sudah tampil di recipientText).
    get streetLines() { return this.addressLines.slice(1); },
    get recipientText() { const a = this.order.shipping_address; return a ? `${a.recipient} (${a.phone})` : ''; },
    get itemsCountText() { return this.words.itemsCount.replace(':count', this.order.items.length); },
    get itemsBadgeText() { return this.words.itemsBadge.replace(':count', this.order.items.length); },
    get checkText() { return this.checking ? this.words.checking : this.words.paidCheck; },
    get copyText() { return this.copied ? this.words.copied : this.words.copy; },
    // Kartu Lacak Paket (FigCraft 05, disesuaikan untuk J&T dan SiCepat): data dari status pesanan kita, tanpa posisi live atau profil driver.
    get track() { return this.words.track; },
    get trackStatus() { return ["paid", "processing", "shipped", "completed"].includes(this.order.status) ? this.order.status : "paid"; },
    // Status paket dari Biteship (webhook) menentukan chip, jalur, dan pesan selama pesanan Dikirim. Status yang belum dikenal jatuh ke tampilan status pesanan.
    get carrier() {
        const status = this.order.status === 'shipped' ? this.order.shipment?.status : null;
        return status && this.track.carrier?.[status] ? status : null;
    },
    get trackChip() { return this.carrier ? this.track.carrier[this.carrier] : this.track.chip[this.trackStatus]; },
    // Pesan status memakai kejadian terbaru dari kurir bila ada, mis. "Paket tiba di hub Surabaya".
    get trackMessage() {
        if (this.hubEvents.length) return this.hubEvents[0].note;
        return (this.carrier ? this.track.carrierMessage[this.carrier] : this.track.message[this.trackStatus]).replace(":courier", this.courierText);
    },
    get routeStyle() {
        const shipped = CARRIER_ROUTE[this.carrier] ?? { picking_up: 0.25, picked: 0.45, dropping_off: 0.85 }[this.hubEvents[0]?.status] ?? 0.55;
        return "--route:" + { paid: 0, processing: 0, shipped, completed: 1 }[this.trackStatus];
    },
    // Riwayat Biteship berbahasa Inggris: terjemahkan yang dikenal, sisanya tampil apa adanya.
    hubNote(note) {
        const known = this.track.hubNotes?.[note];
        if (known) return known;
        const confirmed = this.track.hubConfirmed && note.match(/^Courier order is confirmed\. .+ has been notified to pick up\. Pickup Number: (\S+)$/);
        return confirmed ? this.track.hubConfirmed.replace(':courier', this.courierText).replace(':number', confirmed[1]) : note;
    },
    get hasHub() { return this.hubEvents.length > 0; },
    get hubLoading() { return this.hubState === 'loading'; },
    get hubFailed() { return this.hubState === 'failed'; },
    async loadHub() {
        if (this.order?.status !== 'shipped' || !this.order.shipment?.waybill_id || this.hubState === 'loading') return;
        this.hubState = 'loading';
        try {
            const { data } = await api.get(`/orders/${this.ref}/tracking`, this.headers());
            const events = data.data.events.map((event, i) => ({ ...event, note: this.hubNote(event.note), latest: i === 0, time: wib(event.at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }));
            this.hubEvents = events;
            this.hubState = data.data.available ? 'ready' : 'failed';
        } catch {
            this.hubState = 'failed';
        }
    },
    get routeClass() { return "is-" + this.trackStatus + (PROBLEM_CARRIER.includes(this.carrier) ? " is-issue" : ""); },
    get destinationText() { return this.order.shipping_address?.city || ""; },
    get waybill() { return this.order.shipment?.waybill_id || ""; },
    get courierLogoUrl() { return COURIER_LOGOS.includes(this.order.shipping_courier) ? "/images/Logo/kurir/" + this.order.shipping_courier + ".webp" : ""; },
    get courierInitials() { return String(this.order.shipping_courier).slice(0, 2).toUpperCase(); },
    get trackUpdatedText() {
        const synced = this.carrier && this.order.shipment?.updated_at;
        if (synced) return this.track.updated.replace(":time", wib(synced, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }));
        const times = Object.values(this.order.timeline || {});
        return times.length ? this.track.updated.replace(":time", wib(times[times.length - 1], { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })) : "";
    },
    get trackEvents() {
        const keys = ["paid", "processing", "shipped", "completed"];
        const reached = keys.indexOf(this.trackStatus);
        return keys.map((key, i) => {
            const at = this.order.timeline?.[key];
            return { key, label: this.track.events[key], done: i <= reached, current: i === reached, time: at ? wib(at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : this.track.waiting };
        });
    },
    eventClass(event) { return { "is-done": event.done, "is-now": event.current }; },
    get shareUrl() { return "https://wa.me/?text=" + encodeURIComponent(this.track.shareText.replace(":number", this.order.order_number) + " " + window.location.href); },
    async copyWaybill() {
        await navigator.clipboard.writeText(this.waybill);
        this.$store.toast.show(this.track.waybillCopied);
    },
    hasVariant(item) { return item.variant_name && item.variant_name !== 'Standar'; },
    qtyEach(item) { return `${item.quantity} × ${rupiah(item.unit_price)}`; },
    photoUrl(item) { return encodeURI(item.photo); },
    // Hanya memuat ulang status dari server; menekan tombol ini tidak menandai pesanan lunas (BR-11).
    // Tanya status ke Midtrans lewat server lalu muat ulang pesanan. Server hanya melunasi bila Midtrans sendiri menyatakan lunas (BR-11).
    // Satu panggilan sekaligus, dan tanpa klik pembeli (force) paling cepat tiap 4 detik: callback Snap bisa menyala berkali-kali.
    syncPayment(force = false) {
        if (this.syncing) return this.syncing;
        if (!force && Date.now() - this.lastSync < 4000) return Promise.resolve();
        this.lastSync = Date.now();
        this.syncing = (async () => {
            try { await api.post(`/orders/${this.ref}/payment/sync`, {}, this.headers()); } catch { /* gagal: status tetap dimuat dari server */ }
            await this.load();
        })().finally(() => { this.syncing = null; });
        return this.syncing;
    },
    async autoCheck() {
        // Setelah waktu habis masih dicek 2 menit (pembayaran di detik terakhir bisa terlambat sampai), lalu berhenti.
        if (!this.payStarted || this.order?.status !== 'pending_payment' || document.hidden || this.checking || this.payBusy) return;
        if (this.timeUp && new Date(this.order.expires_at) < this.now + this.skew - 120000) return;
        await this.syncPayment();
    },
    async checkPaid() {
        this.checking = true;
        this.stillPending = false;
        await this.syncPayment(true);
        this.checking = false;
        this.stillPending = this.order?.status === 'pending_payment';
    },
    retryPay() {
        this.payFailed = false;
        return this.pay();
    },

    // Ulasan produk setelah pesanan selesai: satu per barang, dikirim ke server yang memeriksa status pesanan.
    drafts: {},
    reviewBusy: '',
    reviewError: '',
    get reviewItems() { return (this.order?.items || []).filter((item) => item.can_review || item.review); },
    rateOf(sku) { return this.drafts[sku]?.rating || 0; },
    bodyOf(sku) { return this.drafts[sku]?.body || ''; },
    starOn(sku, n) { return n <= this.rateOf(sku); },
    starLabel(n) { return this.words.review.star.replace(':n', n); },
    setRate(sku, n) { this.drafts = { ...this.drafts, [sku]: { ...this.drafts[sku], rating: n } }; this.reviewError = ''; },
    setBody(sku, event) { this.drafts = { ...this.drafts, [sku]: { ...this.drafts[sku], body: event.target.value } }; },
    async sendReview(sku) {
        if (!this.rateOf(sku)) { this.reviewError = this.words.review.pick; return; }
        this.reviewBusy = sku;
        this.reviewError = '';
        try {
            await api.post(`/orders/${this.ref}/reviews`, { sku, rating: this.rateOf(sku), body: this.bodyOf(sku) }, this.headers());
            await this.load();
        } catch (e) {
            this.reviewError = e.response?.status === 429 ? this.words.tooMany : this.words.review.failed;
        } finally {
            this.reviewBusy = '';
        }
    },

    async load() {
        // Muat ulang (setelah Snap ditutup atau cek status) tidak mengosongkan halaman.
        this.loading = !this.order;
        this.failed = false;
        this.notFound = false;
        try {
            const { data, headers } = await api.get(`/orders/${this.ref}`, this.headers());
            if (headers?.date) this.skew = new Date(headers.date).getTime() - Date.now();
            this.order = data.data;
            this.loadHub();
        } catch (e) {
            if (e.response?.status === 404) this.notFound = true;
            else this.failed = true;
        } finally {
            this.loading = false;
        }
    },
    async requestCancellation() {
        this.cancelBusy = true;
        this.cancelError = '';
        try {
            const { data } = await api.post(`/orders/${this.ref}/cancellation`, { reason: this.reason, channel: this.channel }, this.headers());
            this.cancelDone = data.data;
            this.reason = '';
            this.$refs.cancelDialog?.close();
            await this.load();
            // Pengguna langsung diarahkan ke CS; bila pop-up diblokir, tombol Hubungi CS tetap tampil.
            window.open(data.data.contact_url, '_blank', 'noopener,noreferrer');
        } catch (e) {
            const key = e.response?.data?.errors?.order?.[0];
            this.cancelError = this.words.cancelErrors[key] || (e.response?.status === 429 ? this.words.tooMany : this.words.cancelFailed);
        } finally {
            this.cancelBusy = false;
        }
    },
    // Hasil popup Snap bukan bukti bayar (BR-11): status selalu dimuat ulang dari server, yang diperbarui notifikasi Midtrans.
    async pay() {
        if (this.payBusy || !this.payConfig) return;
        this.payBusy = true;
        this.payError = '';
        try {
            const [{ data }] = await Promise.all([api.post(`/orders/${this.ref}/payment`, {}, this.headers()), loadSnap(this.payConfig)]);
            const done = () => { this.payBusy = false; this.syncPayment(); };
            const failed = () => { this.payFailed = true; done(); };
            this.embedded = true;
            this.payStarted = true;
            try { sessionStorage.setItem(`tf-pay-${this.ref}`, '1'); } catch { /* abaikan */ }
            await this.$nextTick();
            window.snap.embed(data.data.snap_token, { embedId: 'snap-container', onSuccess: done, onPending: done, onError: failed, onClose: done });
            this.payBusy = false;
            document.getElementById('snap-container')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            this.payBusy = false;
            // 422 = pesanan sudah tidak bisa dibayar (waktu habis atau sudah ditutup): mencoba lagi tidak membantu.
            this.payError = e.response?.status === 422 ? this.words.payExpired : this.words.payFailed;
            if (e.response?.status === 422) this.load();
        }
    },
    async copyLink() {
        await navigator.clipboard.writeText(window.location.href);
        this.copied = true;
        this.$store.toast.show(this.words.copied);
    },
});

export const lookupPage = () => ({
    form: { order_number: '', contact: '' },
    busy: false,
    notFound: false,
    error: '',
    words: {},

    init() { this.words = pageData('tf-lookup-data'); },
    async submit() {
        if (this.busy) return;
        this.busy = true;
        this.notFound = false;
        this.error = '';
        try {
            const { data } = await api.post('/orders/lookup', this.form);
            window.location.href = `/pesanan/${data.data.public_ref}`;
        } catch (e) {
            const status = e.response?.status;
            this.busy = false;
            if (status === 404) this.notFound = true;
            else this.error = status === 429 ? this.words.tooMany : this.words.failed;
        }
    },
});
