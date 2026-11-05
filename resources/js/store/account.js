import api from './api';
import { pageData } from './site';
import { rupiah } from './shop';

const orderLabel = (words, order) => words.status[order.status]?.[0] || order.status;
const orderDate = (order) => new Date(order.created_at).toLocaleDateString(document.documentElement.lang === 'id' ? 'id-ID' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
const signInPath = '/masuk';

export const accountPage = () => ({
    words: {},
    profile: null,
    loadFailed: false,
    name: '',
    nameError: '',
    nameStatus: '',
    nameFailed: '',
    savingName: false,
    pw: { current_password: '', password: '', password_confirmation: '' },
    pwErrors: {},
    pwStatus: '',
    pwFailed: '',
    savingPw: false,
    lastOrder: null,
    ordersLoaded: false,
    mainAddress: null,
    addressesLoaded: false,

    init() {
        this.words = pageData('tf-account-data');
        this.load();
        // Pesanan dan alamat tidak boleh menghalangi profil: bila gagal, tautan ke halamannya tetap tampil.
        api.get('/account/orders?page=1').then(({ data }) => { this.lastOrder = data.data.items[0] || null; this.ordersLoaded = true; }).catch(() => {});
        api.get('/account/addresses').then(({ data }) => { this.mainAddress = data.data.items[0] || null; this.addressesLoaded = true; }).catch(() => {});
    },
    rupiah,
    orderDate,
    get heading() { return this.profile ? this.words.hello.replace('{name}', this.profile.name) : this.words.title; },
    get loadingProfile() { return !this.profile && !this.loadFailed; },
    get ready() { return !!this.profile && !this.loadFailed; },
    get lastLabel() { return this.lastOrder ? orderLabel(this.words, this.lastOrder) : ''; },
    get lastHref() { return this.lastOrder ? `/pesanan/${this.lastOrder.public_ref}` : '#'; },
    get showNoOrders() { return !this.lastOrder && this.ordersLoaded; },
    get showNoAddress() { return !this.mainAddress && this.addressesLoaded; },
    get addressText() { return this.mainAddress ? `${this.mainAddress.label} · ${this.mainAddress.street}, ${this.mainAddress.city}` : ''; },
    get emailState() { return this.profile?.email_verified ? this.words.verified : this.words.unverified; },
    get phoneState() { return this.profile?.phone_verified ? this.words.verified : this.words.unverified; },
    get phoneText() { return this.profile?.phone || this.words.noPhone; },
    message(code) { return this.words.errors[code] || this.words.failed; },
    // Session berakhir di tengah jalan: kembali ke halaman masuk.
    failure(e) {
        const status = e.response?.status;
        if (status === 401) { window.location.assign(signInPath); return ''; }
        return status === 429 ? this.words.tooMany : this.words.failed;
    },
    async load() {
        this.loadFailed = false;
        try {
            const { data } = await api.get('/account/profile');
            this.profile = data.data;
            this.name = data.data.name;
        } catch (e) {
            if (this.failure(e)) this.loadFailed = true;
        }
    },
    async saveName() {
        if (this.savingName) return;
        this.savingName = true;
        this.nameError = '';
        this.nameStatus = '';
        this.nameFailed = '';
        try {
            const { data } = await api.patch('/account/profile', { name: this.name });
            this.profile = data.data;
            this.name = data.data.name;
            this.$root.dispatchEvent(new CustomEvent('tf-name-changed', { bubbles: true, detail: data.data.name }));
            this.nameStatus = this.words.saved;
        } catch (e) {
            if (e.response?.status === 422) this.nameError = this.message(e.response.data.errors?.name?.[0]);
            else this.nameFailed = this.failure(e);
        } finally {
            this.savingName = false;
        }
    },
    pwError(key) { return this.pwErrors[key]; },
    pwClass(key) { return this.pwErrors[key] ? 'is-error' : ''; },
    async savePassword() {
        if (this.savingPw) return;
        this.savingPw = true;
        this.pwErrors = {};
        this.pwStatus = '';
        this.pwFailed = '';
        try {
            await api.put('/account/profile/password', this.pw);
            Object.assign(this.pw, { current_password: '', password: '', password_confirmation: '' });
            this.pwStatus = this.words.passwordSaved;
        } catch (e) {
            if (e.response?.status === 422) {
                this.pwErrors = Object.fromEntries(Object.entries(e.response.data.errors || {}).map(([field, [code]]) => [field, this.message(code)]));
            } else {
                this.pwFailed = this.failure(e);
            }
        } finally {
            this.savingPw = false;
        }
    },
});

export const accountOrdersPage = () => ({
    words: {},
    items: [],
    meta: null,
    loading: true,
    failed: false,

    init() {
        this.words = pageData('tf-orders-data');
        this.load();
    },
    rupiah,
    get isEmpty() { return !this.loading && !this.failed && !this.items.length; },
    get hasItems() { return !this.loading && !this.failed && this.items.length > 0; },
    get showPages() { return !!this.meta && this.meta.last_page > 1; },
    get pageText() { return this.meta ? `${this.meta.current_page} / ${this.meta.last_page}` : ''; },
    href(order) { return `/pesanan/${order.public_ref}`; },
    meta2(order) { return `${orderDate(order)} · ${this.words.items.replace('{n}', order.item_count)}`; },
    label(order) { return orderLabel(this.words, order); },
    retry() { return this.load(this.meta?.current_page || 1); },
    prev() { return this.load(this.meta.current_page - 1); },
    next() { return this.load(this.meta.current_page + 1); },
    async load(page = 1) {
        this.loading = true;
        this.failed = false;
        try {
            const { data } = await api.get(`/account/orders?page=${page}`);
            this.items = data.data.items;
            this.meta = data.data.meta;
        } catch (e) {
            if (e.response?.status === 401) { window.location.assign(signInPath); return; }
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
});

const blank = () => ({ label: '', recipient_name: '', phone: '', street: '', district: '', city: '', province: '', postal_code: '', is_default: false });

export const addressesPage = () => ({
    words: {},
    max: 10,
    items: [],
    loading: true,
    loadFailed: false,
    editing: null, // null = form tertutup, 0 = alamat baru, angka lain = id alamat.
    form: blank(),
    errors: {},
    busy: false,
    failed: '',
    status: '',

    init() {
        this.words = pageData('tf-addresses-data');
        this.load();
    },
    get initialLoading() { return this.loading && !this.items.length; },
    get ready() { return !this.initialLoading && !this.loadFailed; },
    get isEditing() { return this.editing !== null; },
    get formTitle() { return this.editing ? this.words.editTitle : this.words.addTitle; },
    get showEmpty() { return !this.isEditing && !this.items.length; },
    get showList() { return this.items.length > 0; },
    get canAdd() { return !this.isEditing && this.items.length < this.max; },
    get atLimit() { return !this.isEditing && this.items.length >= this.max; },
    get listFailed() { return !!this.failed && !this.isEditing; },
    get formFailed() { return !!this.failed && this.isEditing; },
    lines(a) { return [`${a.recipient_name} (${a.phone})`, a.street, `${a.district}, ${a.city}`, `${a.province} ${a.postal_code}`]; },
    invalid(key) { return !!this.errors[key]; },
    errorFor(key) { return this.errors[key]; },
    fieldClass(key) { return this.errors[key] ? 'is-error' : ''; },
    message(code) { return this.words.errors[code] || this.words.checkoutErrors[code] || this.words.failed; },
    failure(e) {
        if (e.response?.status === 401) { window.location.assign(signInPath); return ''; }
        return e.response?.status === 429 ? this.words.tooMany : this.words.failed;
    },
    async load() {
        this.loading = true;
        this.loadFailed = false;
        try {
            this.items = (await api.get('/account/addresses')).data.data.items;
        } catch (e) {
            if (this.failure(e)) this.loadFailed = true;
        } finally {
            this.loading = false;
        }
    },
    openForm(address = null) {
        this.form = { ...blank(), ...(address ? { ...address, phone: address.phone.replace(/^\+62/, '') } : {}) };
        this.errors = {};
        this.failed = '';
        this.status = '';
        this.editing = address ? address.id : 0;
        // Form baru dipasang x-if setelah tick ini.
        setTimeout(() => document.querySelector('#ad-label')?.focus());
    },
    openNew() { this.openForm(); },
    closeForm() { this.editing = null; },
    async save() {
        if (this.busy) return;
        this.busy = true;
        this.errors = {};
        this.failed = '';
        try {
            const { id, ...body } = this.form;
            if (this.editing) await api.patch(`/account/addresses/${this.editing}`, body);
            else await api.post('/account/addresses', body);
            this.editing = null;
            this.status = this.words.saved;
            await this.load();
        } catch (e) {
            if (e.response?.status === 422) {
                const fields = e.response.data.errors || {};
                this.errors = Object.fromEntries(Object.entries(fields).map(([key, [code]]) => [key, this.message(code)]));
                this.failed = this.errors.address || '';
                setTimeout(() => document.querySelector('.tf-account-section form [aria-invalid="true"]')?.focus());
            } else {
                this.failed = this.failure(e);
            }
        } finally {
            this.busy = false;
        }
    },
    // Aksi di kartu: gagal ditampilkan di atas daftar, daftar tetap seperti semula.
    async act(request, done) {
        if (this.busy) return;
        this.busy = true;
        this.failed = '';
        this.status = '';
        try {
            await request();
            this.status = done;
            await this.load();
        } catch (e) {
            this.failed = this.failure(e);
        } finally {
            this.busy = false;
        }
    },
    makeDefault(a) { return this.act(() => api.post(`/account/addresses/${a.id}/default`), this.words.saved); },
    remove(a) {
        if (window.confirm(this.words.confirmDelete.replace('{label}', a.label))) this.act(() => api.delete(`/account/addresses/${a.id}`), this.words.deleted);
    },
});
