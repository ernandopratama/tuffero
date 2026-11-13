import api from './api';
import { pageData } from './site';

const rupiah = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;
const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`{${key}}`, value), text);

// Form ajukan refund atau retur (D-031, D-097), tanpa akun: kepemilikan dibuktikan nomor pesanan dan email atau HP.
// Tiga langkah: cari pesanan, pilih barang dan masalah, lalu konfirmasi dengan tombol hubungi CS. Harga, jumlah, dan batas waktu
// dihitung ulang server; angka di layar hanya perkiraan. Video unboxing tidak diunggah ke situs.
export const refundPage = () => ({
    words: {},
    locale: 'id-ID',
    step: 'verify',
    form: { order_number: '', contact: '' },
    order: null,
    lines: [],
    reason: '',
    description: '',
    pref: 'whatsapp',
    terms: false,
    busy: false,
    notFound: false,
    error: '',
    done: null,
    copied: false,
    key: null,

    init() {
        this.words = pageData('tf-refund-data');
        this.locale = document.documentElement.lang === 'en' ? 'en-GB' : 'id-ID';
    },

    get orderLine() { return fill(this.words.orderLine, { n: this.order?.order_number ?? '' }); },
    get received() { return !!this.order?.received_at; },
    get windows() { return this.order?.windows; },
    // Retur produk utuh dan klaim lainnya punya batas waktu berbeda (D-097 dan D-043).
    get windowOpen() { return !!this.windows && (this.isReturn ? this.windows.return.open : this.windows.claim.open); },
    get isReturn() { return this.reason === 'return_unopened'; },
    get deadlineText() {
        if (!this.windows || !this.reason) return '';
        const win = this.isReturn ? this.windows.return : this.windows.claim;
        const date = new Date(win.closes_at).toLocaleString(this.locale, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        return fill(win.open ? this.words.windowOpen : this.words.windowClosed, { date });
    },
    get anyAvailable() { return this.lines.some((line) => line.available > 0); },
    get selected() { return this.lines.filter((line) => line.qty > 0); },
    // Nilai refund = yang dibayar untuk barang itu (setelah bagian potongan voucher), dibulatkan ke bawah seperti di server.
    get total() { return this.selected.reduce((sum, line) => sum + this.lineValue(line), 0); },
    get hasDiscount() { return !!this.order?.has_discount; },
    get totalText() { return rupiah(this.total); },
    get descriptionCount() { return `${this.description.length}/1000`; },
    get canSubmit() {
        return !this.busy && this.selected.length > 0 && !!this.reason && this.windowOpen && this.description.trim().length >= 10 && this.terms;
    },
    get submitDisabled() { return !this.canSubmit; },
    get reasonOptions() {
        return Object.entries(this.words.reasons).map(([value, label]) => ({ value, label, closed: !!this.windows && !(value === 'return_unopened' ? this.windows.return.open : this.windows.claim.open) }));
    },

    availableText(line) { return line.available > 0 ? fill(this.words.available, { n: line.available, m: line.ordered }) : this.words.unavailable; },
    unitText(line) { return rupiah(line.unit_price); },
    lineValue(line) { return Math.max(1, Math.floor((line.paid_total * line.qty) / line.ordered)); },
    dec(line) { if (line.qty > 0) line.qty -= 1; },
    inc(line) { if (line.qty < line.available) line.qty += 1; },

    async find() {
        if (this.busy) return;
        this.busy = true;
        this.notFound = false;
        this.error = '';
        try {
            const { data } = await api.post('/refunds/lookup', this.form);
            this.order = data.data;
            this.lines = data.data.items.map((item) => ({ ...item, qty: 0 }));
            this.reason = '';
            this.step = 'form';
        } catch (e) {
            const status = e.response?.status;
            if (status === 422) this.notFound = true;
            else this.error = status === 429 ? this.words.tooMany : this.words.failed;
        } finally {
            this.busy = false;
        }
    },

    change() {
        this.step = 'verify';
        this.order = null;
        this.lines = [];
        this.error = '';
        this.key = null;
    },

    async submit() {
        if (!this.canSubmit) return;
        this.busy = true;
        this.error = '';
        // Satu kunci per pengajuan: klik ganda atau coba ulang setelah jaringan putus tidak membuat dua pengajuan.
        this.key ??= crypto.randomUUID();
        try {
            const { data } = await api.post('/refunds', {
                ...this.form,
                reason_type: this.reason,
                description: this.description,
                contact_preference: this.pref,
                terms: this.terms,
                items: this.selected.map((line) => ({ id: line.id, quantity: line.qty })),
            }, { 'Idempotency-Key': this.key });
            this.done = data.data;
            this.step = 'done';
            this.key = null;
            window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            // Langsung diarahkan ke CS; bila pop-up diblokir, tombol Hubungi CS tetap tampil.
            if (data.data.contact_url) window.open(data.data.contact_url, '_blank', 'noopener,noreferrer');
        } catch (e) {
            const errors = e.response?.data?.errors ?? {};
            const code = [errors.order?.[0], errors.items?.[0], errors.description?.[0], errors.terms?.[0], errors.reason_type?.[0]].find(Boolean);
            this.error = this.words.errors[code] || (e.response?.status === 429 ? this.words.tooMany : this.words.failed);
        } finally {
            this.busy = false;
        }
    },

    async copyNumber() {
        await navigator.clipboard.writeText(this.done.refund_number);
        this.copied = true;
        this.$store.toast.show(this.words.copied);
    },

    another() {
        this.done = null;
        this.copied = false;
        this.reason = '';
        this.description = '';
        this.terms = false;
        this.find();
    },
});
