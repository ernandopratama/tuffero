import api from './api';
import { rupiah } from './shop';
import { pageData } from './site';
import { card } from './voucherText';

export const vouchersPage = () => ({
    words: {},
    items: [],
    loading: true,
    failed: false,
    isCustomer: false,
    busy: '',
    notice: '',
    error: '',

    init() {
        this.words = pageData('tf-vouchers-data');
        this.isCustomer = this.words.customer;
        this.load();
    },
    get isEmpty() { return !this.loading && !this.failed && !this.items.length; },
    get hasItems() { return !this.loading && !this.failed && this.items.length > 0; },
    get showSignIn() { return !this.loading && !this.isCustomer && this.items.length > 0; },
    title(item) { return card.title(this.words, item); },
    description(item) { return item.description || ''; },
    meta(item) { return card.meta(this.words, item); },
    cardClass(item) { return item.claimed || item.exhausted ? 'is-off' : ''; },
    canClaim(item) { return !item.claimed && !item.exhausted && this.busy !== item.code; },
    actionText(item) {
        if (item.claimed) return this.words.claimed;
        if (item.exhausted) return this.words.exhausted;
        return this.busy === item.code ? this.words.claiming : this.words.claim;
    },
    async load() {
        this.loading = true;
        this.failed = false;
        try {
            const { data } = await api.get('/vouchers/claimable' + (this.words.campaign ? '?campaign=' + encodeURIComponent(this.words.campaign) : ''));
            this.items = data.data.vouchers;
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
    async claim(item) {
        if (!this.isCustomer) {
            window.location.assign(this.words.signIn);
            return;
        }
        this.busy = item.code;
        this.error = '';
        this.notice = '';
        try {
            await api.post('/account/vouchers/' + encodeURIComponent(item.code) + '/claim');
            item.claimed = true;
            this.$store.toast.show(this.words.claimedNotice);
        } catch (e) {
            const code = e.response?.data?.errors?.voucher_code?.[0];
            this.error = this.words.errors[code] || this.words.claimFailed;
            if (code === 'voucher_exhausted') item.exhausted = true;
        } finally {
            this.busy = '';
        }
    },
});

export const accountVouchersPage = () => ({
    words: {},
    items: [],
    tab: 'available',
    loading: true,
    failed: false,

    init() {
        this.words = pageData('tf-account-vouchers-data');
        this.load();
    },
    get tabs() {
        return ['available', 'used', 'expired'].map((key) => ({ key, label: this.words.tabs[key] + ' (' + this.items.filter((item) => item.status === key).length + ')' }));
    },
    get shown() { return this.items.filter((item) => item.status === this.tab); },
    get isEmpty() { return !this.loading && !this.failed && !this.shown.length; },
    get hasItems() { return !this.loading && !this.failed && this.shown.length > 0; },
    setTab(key) { this.tab = key; },
    tabClass(entry) { return entry.key === this.tab ? 'is-on' : ''; },
    tabCurrent(entry) { return entry.key === this.tab ? 'true' : 'false'; },
    title(item) { return card.title(this.words, item); },
    description(item) { return item.description || ''; },
    meta(item) { return card.meta(this.words, item); },
    cardClass(item) { return item.status === 'available' ? '' : 'is-off'; },
    async load() {
        this.loading = true;
        this.failed = false;
        try {
            const { data } = await api.get('/account/vouchers');
            this.items = data.data.items;
        } catch (e) {
            if (e.response?.status === 401) { window.location.assign(this.words.signIn); return; }
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
});
