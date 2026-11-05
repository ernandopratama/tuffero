import api from './api';
import { pageData } from './site';

export const rupiah = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;
const productName = (item) => (item.variant_name === 'Standar' ? item.product_name : `${item.product_name} ${item.variant_name}`);

export const cartPage = () => ({
    cart: null,
    loading: true,
    failed: false,
    busy: false,
    updateError: '',
    words: {},

    init() {
        this.words = pageData('tf-cart-data');
        this.load();
    },
    rupiah,
    productName,
    photoUrl(item) { return encodeURI(item.photo); },
    insufficient(item) { return this.words.insufficient.replace('{n}', item.available); },
    removeLabel(item) { return this.words.removeLabel.replace('{name}', productName(item)); },
    get ready() { return !this.loading && !this.failed; },
    get empty() { return this.ready && !this.cart.items.length; },
    get filled() { return this.ready && this.cart.items.length > 0; },
    apply(data) {
        this.cart = data.data;
        this.$store.cart.count = data.data.count;
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
    async run(request) {
        this.busy = true;
        this.updateError = '';
        try {
            const { data } = await request();
            this.apply(data);
        } catch {
            this.updateError = this.words.updateFailed;
        } finally {
            this.busy = false;
        }
    },
    setQuantity(item, quantity) { return this.run(() => api.patch(`/cart/items/${encodeURIComponent(item.sku)}`, { quantity })); },
    inc(item) { return this.setQuantity(item, item.quantity + 1); },
    dec(item) { return this.setQuantity(item, item.quantity - 1); },
    remove(item) { return this.run(() => api.delete(`/cart/items/${encodeURIComponent(item.sku)}`)); },
});

const ADDRESS_FIELDS = ['street', 'district', 'city', 'province', 'postal_code'];
const localPhone = (phone) => (phone || '').replace(/^\+62/, '');
const contactFrom = (address) => ({ customer_name: address.recipient_name, customer_phone: localPhone(address.phone) });

export const checkoutPage = () => ({
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

    init() {
        this.words = pageData('tf-checkout-data');
        this.saveLabel = this.words.saveLabelDefault;
        this.$watch('form.postal_code', (code) => this.loadRates(code));
        this.loadCart();
        this.loadAccount();
    },
    rupiah,
    productName,
    get ready() { return !this.loading && !this.loadFailed; },
    get empty() { return this.ready && !this.cart.items.length; },
    get blocked() { return this.ready && this.cart.items.length > 0 && !this.cart.can_checkout; },
    get canFill() { return this.ready && this.cart.items.length > 0 && this.cart.can_checkout; },
    get fee() { return this.selected?.fee ?? null; },
    get total() { return (this.cart?.subtotal ?? 0) + (this.fee ?? 0); },
    get feeText() { return this.fee === null ? this.words.shippingPending : rupiah(this.fee); },
    get subtotalText() { return rupiah(this.cart?.subtotal ?? 0); },
    get totalText() { return rupiah(this.total); },
    get leadText() { return this.isCustomer ? this.words.leadSignedIn : this.words.lead; },
    get submitText() { return this.submitting ? this.words.submitting : this.words.submit; },
    get canSaveAddress() { return this.isCustomer && !this.addressId && this.addresses.length < 10; },
    get hasRates() { return this.rates.length > 0; },
    get courierHint() { return !this.ratesLoading && !this.ratesFailed && !this.rates.length; },
    rateKey(rate) { return `${rate.courier}-${rate.service}`; },
    isSelected(rate) { return this.selected?.courier === rate.courier && this.selected?.service === rate.service; },
    pickRate(rate) {
        this.selected = rate;
        delete this.errors.courier;
        delete this.errors.service;
        this.formError = this.errors.cart || this.errors.shipping || '';
    },
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
        this.submitting = true;
        this.errors = {};
        this.formError = '';
        const form = this.form;
        try {
            const { data } = await api.post('/checkout', {
                customer_name: form.customer_name,
                customer_email: form.customer_email,
                customer_phone: form.customer_phone,
                address: { street: form.street, district: form.district, city: form.city, province: form.province, postal_code: form.postal_code },
                courier: this.selected?.courier ?? '',
                service: this.selected?.service ?? '',
            }, { 'Idempotency-Key': this.idempotencyKey });
            const { public_ref: ref, token } = data.data;
            await this.storeAddress();
            window.location.href = `/pesanan/${ref}${token ? `#t=${token}` : ''}`;
        } catch (e) {
            this.submitting = false;
            const status = e.response?.status;
            if (status === 422) {
                const fields = e.response.data.errors || {};
                this.errors = Object.fromEntries(Object.entries(fields).map(([key, [value]]) => [key.replace('address.', ''), this.words.errors[value] || value]));
                this.formError = this.errors.cart || this.errors.shipping || this.errors.courier || '';
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
            });
        } catch { /* lihat komentar di atas */ }
    },
});

const STEP_INDEX = { pending_payment: 1, paid: 2, processing: 2, shipped: 3, completed: 4 };

export const orderPage = () => ({
    ref: '',
    order: null,
    loading: true,
    notFound: false,
    failed: false,
    copied: false,
    now: Date.now(),
    reason: '',
    channel: 'whatsapp',
    cancelBusy: false,
    cancelError: '',
    cancelDone: null,
    words: {},

    init() {
        const data = pageData('tf-order-data');
        this.ref = data.ref;
        this.words = data.words;
        this.load();
        setInterval(() => { this.now = Date.now(); }, 1000);
    },
    rupiah,
    productName,
    // Token ada di fragmen URL, jadi tidak terkirim ke server lewat log atau Referer. API menerimanya lewat header.
    headers() {
        const t = window.location.hash.startsWith('#t=') ? window.location.hash.slice(3) : '';
        return t ? { 'X-Order-Token': t } : {};
    },
    get shown() { return !this.loading && !this.notFound && !this.failed && !!this.order; },
    get stepIndex() { return STEP_INDEX[this.order?.status] ?? -1; },
    get statusText() { return this.words.status[this.order?.status] || [this.order?.status, '']; },
    get statusTitle() { return this.statusText[0]; },
    get statusNote() { return this.statusText[1]; },
    get isPending() { return this.order?.status === 'pending_payment'; },
    get showCancel() { return !!(this.order?.cancellation || this.order?.can_cancel); },
    get clock() {
        const expires = this.order?.expires_at;
        const s = expires ? Math.max(0, Math.floor((new Date(expires) - this.now) / 1000)) : 0;
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

    async load() {
        this.loading = true;
        this.failed = false;
        this.notFound = false;
        try {
            const { data } = await api.get(`/orders/${this.ref}`, this.headers());
            this.order = data.data;
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
    async copyLink() {
        await navigator.clipboard.writeText(window.location.href);
        this.copied = true;
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
