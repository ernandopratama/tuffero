import api from './api';
import { loadTurnstile } from './phoneOtp';
import { pageData } from './site';

const MAX_ATTEMPTS = 5;
const emailValid = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
// Nomor seluler Indonesia setelah 0 atau 62 dibuang: diawali 8, total 9 sampai 12 digit.
const phoneDigits = (v) => v.replace(/\D/g, '').replace(/^(62|0)/, '');
const phoneValid = (v) => /^8\d{8,11}$/.test(phoneDigits(v));
const maskEmail = (v) => { const [name, domain] = v.split('@'); return `${name[0]}•••@${domain}`; };
const maskPhone = (v) => { const d = `0${phoneDigits(v)}`; return `${d.slice(0, 4)}-••••-${d.slice(-4)}`; };
const clock = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

const errorCode = (e) => Object.values(e.response?.data?.errors ?? {})[0]?.[0];

// Satu komponen untuk /masuk, /daftar, dan /masuk/verifikasi: identitas dan kata sandi hanya di memori, tidak pernah ditulis ke URL atau penyimpanan browser.
// Daftar: pelanggan memilih verifikasi lewat email atau WhatsApp, kode dikirim dan diperiksa server (D-095), akun dibuat setelah kode benar.
// Masuk dengan kata sandi baru ada di local (OQ-18, OQ-22); di luar local tetap menampilkan "belum tersedia".
export const authPage = () => ({
    a: {},
    dev: false,
    google: false,
    // Saklar registration.whatsapp_required (D-146): mati berarti daftar cukup email, tanpa nomor dan tanpa langkah pilih saluran.
    whatsappRequired: true,
    mode: 'login',
    channel: 'email',
    // Saluran yang sudah lolos kode pada pendaftaran berjalan; akun dibuat setelah email dan WhatsApp keduanya selesai (D-145).
    verified: [],
    identity: '',
    email: '',
    phone: '',
    password: '',
    showPassword: false,
    fieldError: '',
    phoneError: '',
    passwordError: '',
    notice: '',
    busy: false,
    maskedId: '',
    code: '',
    codeError: '',
    codeStatus: '',
    resendAt: 0,
    lockUntil: 0,
    now: Date.now(),
    attemptsLeft: MAX_ATTEMPTS,
    ticker: null,
    siteKey: '',
    captchaToken: '',
    widget: null,

    init() {
        const data = pageData('tf-auth-data');
        this.a = data.words;
        this.dev = data.dev;
        this.google = data.google;
        this.whatsappRequired = data.whatsappRequired !== false;
        this.siteKey = data.siteKey || '';
        this.mode = data.mode;
        if (data.googleStatus) this.notice = data.googleStatus === 'failed' ? this.a.googleFailed : this.a.googleUnavailable;
        window.addEventListener('popstate', () => {
            this.mode = window.location.pathname === '/daftar' ? 'register' : 'login';
            this.enterStep();
        });
        this.$watch('locked', (v, was) => { if (was && !v) this.$nextTick(() => this.$refs.codeEl?.focus()); });
        this.enterStep();
    },

    get isOtp() { return this.mode === 'otp'; },
    get isRegister() { return this.mode === 'register'; },
    get isMethod() { return this.mode === 'method'; },
    get showForm() { return !this.isOtp && !this.isMethod; },
    get showMethod() { return this.isMethod; },
    get showOtp() { return this.isOtp; },
    get words() { return this.isRegister ? this.a.register : this.a.login; },
    get leadText() { return this.isRegister && !this.whatsappRequired ? this.a.register.leadEmail : this.words.lead; },
    get channelWords() { return this.channel === 'email' ? this.a.email : this.a.phone; },
    get resendLeft() { return Math.max(0, Math.ceil((this.resendAt - this.now) / 1000)); },
    get lockLeft() { return Math.max(0, Math.ceil((this.lockUntil - this.now) / 1000)); },
    get locked() { return this.lockLeft > 0; },
    get passwordType() { return this.showPassword ? 'text' : 'password'; },
    get passwordLabel() { return this.showPassword ? this.a.password.hideLabel : this.a.password.showLabel; },
    get passwordAutocomplete() { return this.isRegister ? 'new-password' : 'current-password'; },
    get passwordHint() { return this.passwordError || (this.isRegister ? this.a.password.hintRegister : ''); },
    get phoneHint() { return this.phoneError || this.a.identity.hintRegister; },
    get otpTitle() { return this.locked ? this.a.otp.lockedTitle : this.a.otp.title; },
    get otpLead() { return this.locked ? this.a.otp.lockedLead : this.a.otp.lead.replace('{id}', this.maskedId); },
    get lockedText() { return this.a.otp.locked.replace('{time}', clock(this.lockLeft)); },
    get resendText() { return this.a.otp.resendIn.replace('{time}', clock(this.resendLeft)); },
    get submitOtpText() { return this.locked ? this.a.otp.lockedTitle : this.a.otp.submit; },
    get showResend() { return !this.locked && this.resendLeft === 0; },
    get showWait() { return !this.locked && !this.codeError && !this.codeStatus && this.resendLeft > 0; },
    get showStatus() { return !this.locked && !this.codeError && !!this.codeStatus; },
    get showCodeError() { return !this.locked && !!this.codeError; },
    get otpClass() { return { 'is-error': !!this.codeError, 'is-locked': this.locked }; },
    get canWhatsapp() { return phoneValid(this.phone.trim()); },
    get whatsappTarget() { return this.canWhatsapp ? `+62 ${phoneDigits(this.phone)}` : this.a.methods.phoneMissing; },
    get emailDone() { return this.verified.includes('email'); },
    get phoneDone() { return this.verified.includes('phone'); },
    get doneCount() { return this.verified.length; },
    get progressText() { return this.a.methods.progress.replace(':n', this.doneCount); },
    get stepText() { return this.a.otp.step.replace('{n}', this.doneCount + 1); },
    // Dua segmen bilah kemajuan di langkah pilih saluran verifikasi.
    segClass(i) { return { 'is-on': i < this.doneCount }; },
    get codeCells() { return [0, 1, 2, 3, 4, 5]; },
    cellText(i) { return this.locked ? '' : (this.code[i] || ''); },
    cellClass(i) { return { 'is-next': this.code.length === i || (this.code.length === 6 && i === 5), 'is-filled': !!this.code[i] }; },

    go(mode, path) {
        this.mode = mode;
        window.history.pushState({}, '', path);
        this.enterStep();
    },
    setMode(mode) {
        this.fieldError = '';
        this.phoneError = '';
        this.passwordError = '';
        this.notice = '';
        this.password = '';
        this.showPassword = false;
        this.go(mode, mode === 'register' ? '/daftar' : '/masuk');
    },
    tabLogin() { this.setMode('login'); },
    tabRegister() { this.setMode('register'); },
    backToRegister() { this.go('register', '/daftar'); },
    togglePassword() { this.showPassword = !this.showPassword; },
    // Mengubah email atau nomor membatalkan pendaftaran yang sedang berjalan: sesi server memegang data lama, jadi mulai dari awal.
    resetProgress() { this.verified = []; },
    clearField() { this.fieldError = ''; this.resetProgress(); },
    clearPhone() { this.phoneError = ''; this.fieldError = ''; this.resetProgress(); },
    clearPassword() { this.passwordError = ''; },
    // Navigasi penuh (bukan fetch): Google mengalihkan browser, dan server menyimpan state OAuth di sesi.
    googleNotice() {
        if (this.google) window.location.assign('/auth/google');
        else this.notice = this.a.googleUnavailable;
    },

    validateRegister() {
        const mail = this.email.trim();
        const tel = this.phone.trim();
        this.fieldError = !mail ? this.a.identity.required : (emailValid(mail) ? '' : this.a.identity.emailInvalid);
        this.phoneError = !this.whatsappRequired ? '' : (!tel ? this.a.identity.phoneRequired : (phoneValid(tel) ? '' : this.a.identity.phoneInvalid));
    },
    validateLogin() {
        const value = this.identity.trim();
        const valid = value.includes('@') ? emailValid(value) : phoneValid(value);
        this.fieldError = valid ? '' : this.a.identity.invalid;
        this.phoneError = '';
    },
    errorText(e) { return this.a.errors[errorCode(e)] || (e.response?.status === 429 ? this.a.errors.tooMany : this.a.errors.failed); },
    startCountdown(seconds) { this.resendAt = Date.now() + seconds * 1000; this.now = Date.now(); },
    async submit() {
        if (this.busy) return;
        this.notice = '';
        // Satu saluran sudah lolos: lanjut ke saluran berikutnya tanpa mengisi ulang (kata sandi sudah di sesi server).
        if (this.isRegister && this.doneCount > 0) { this.go('method', '/daftar'); return; }
        if (this.isRegister) this.validateRegister(); else this.validateLogin();
        this.passwordError = !this.password ? this.a.password.required : (this.isRegister && this.password.length < 8 ? this.a.password.tooShort : '');
        if (this.fieldError || this.phoneError || this.passwordError) {
            // Lewat id: kolom identitas bergantian masuk dan keluar DOM antara masuk dan daftar.
            document.getElementById(this.fieldError ? (this.isRegister ? 'tf-auth-email' : 'tf-auth-id') : (this.phoneError ? 'tf-auth-phone' : 'tf-auth-password'))?.focus();
            return;
        }
        // Daftar: pilihan saluran verifikasi dulu (langkah sendiri), kode baru dikirim setelah dipilih.
        // Saklar WhatsApp mati: tanpa langkah itu, kode langsung dikirim ke email.
        if (this.isRegister) { if (this.whatsappRequired) this.go('method', '/daftar'); else await this.chooseMethod('email'); return; }
        this.busy = true;
        try {
            await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
            const { data } = await api.post('/auth/login', { login: this.identity.trim(), password: this.password });
            this.password = '';
            if (data.data.mfa) { this.notice = this.words.staff; return; }
            window.location.assign('/akun');
        } catch (e) {
            this.notice = e.response?.status === 429 ? this.a.errors.tooMany : (e.response?.status === 401 ? this.words.invalid : this.words.unavailable);
        } finally {
            this.busy = false;
        }
    },
    // Turnstile (interaction-only) dirender ulang tiap langkah verifikasi; token sekali pakai dan wajib untuk kode WhatsApp.
    async renderCaptcha() {
        const el = document.getElementById('tf-auth-turnstile');
        if (!this.siteKey || !el) return;
        if (this.widget !== null) { try { window.turnstile?.remove(this.widget); } catch { /* widget lama sudah lepas dari DOM */ } this.widget = null; }
        this.captchaToken = '';
        try {
            await loadTurnstile();
        } catch {
            return; // Server menolak tanpa token dan pesan galatnya tampil; pelanggan bisa mencoba lagi.
        }
        if (!el.isConnected || this.widget !== null) return;
        this.widget = window.turnstile.render(el, {
            sitekey: this.siteKey,
            appearance: 'interaction-only',
            callback: (token) => { this.captchaToken = token; },
            'expired-callback': () => { this.captchaToken = ''; },
        });
    },
    // Menunggu token (biasanya di bawah 2 detik); tanpa token permintaan tetap dikirim dan server yang menolak dengan pesan jelas.
    async takeCaptcha() {
        if (!this.siteKey) return null;
        for (let i = 0; i < 50 && !this.captchaToken; i++) await new Promise((r) => setTimeout(r, 100));
        const token = this.captchaToken || null;
        this.captchaToken = '';
        if (this.widget !== null) window.turnstile?.reset(this.widget);
        return token;
    },
    closeMethods() { this.go('register', '/daftar'); },
    // Satu saluran wajib dipilih. Kata sandi dikirim sekali ke server (di-hash di sana dan disimpan di sesi), lalu dihapus dari memori.
    async chooseMethod(channel) {
        if (this.busy || (channel === 'phone' && !this.canWhatsapp) || this.verified.includes(channel)) return;
        this.busy = true;
        try {
            const captcha_token = await this.takeCaptcha();
            const { data } = await (this.doneCount > 0
                ? api.post('/auth/register/channel', { channel, captcha_token })
                : api.post('/auth/register/send', { email: this.email.trim(), phone: this.whatsappRequired ? this.phone.trim() : null, password: this.password, channel, captcha_token }));
            this.password = '';
            this.channel = channel;
            this.maskedId = channel === 'email' ? maskEmail(this.email.trim()) : maskPhone(this.phone);
            this.startCountdown(data.data.resend_in ?? 60);
            this.code = '';
            this.codeError = '';
            this.codeStatus = '';
            this.attemptsLeft = MAX_ATTEMPTS;
            this.go('otp', '/masuk/verifikasi');
        } catch (e) {
            // Sesi pendaftaran habis di server: progres di layar tidak berlaku lagi, kembali ke formulir (kata sandi harus diisi ulang).
            if (errorCode(e) === 'otp_expired') { this.resetProgress(); this.notice = this.errorText(e); this.go('register', '/daftar'); } else this.notice = this.errorText(e);
        } finally {
            this.busy = false;
        }
    },
    onCodeInput(e) {
        this.code = e.target.value.replace(/\D/g, '').slice(0, 6);
        e.target.value = this.code;
        this.codeError = '';
    },
    async verify() {
        if (this.busy || this.locked) return;
        if (this.code.length < 6) { this.codeError = this.a.otp.incomplete; this.$refs.codeEl?.focus(); return; }
        this.busy = true;
        try {
            const { data } = await api.post('/auth/register/verify', { code: this.code });
            if (data.data.registered) {
                window.location.assign('/akun');
                return;
            }
            // Satu saluran selesai: lanjut ke pilihan saluran berikutnya.
            this.verified = data.data.verified;
            this.code = '';
            this.codeError = '';
            this.notice = '';
            this.go('method', '/daftar');
            return;
        } catch (e) {
            const failure = errorCode(e);
            this.code = '';
            this.codeStatus = '';
            // Sesi pendaftaran habis (mis. cookie dihapus): kembali ke formulir.
            if (failure === 'otp_expired') { this.notice = this.a.errors.otp_expired; this.resetProgress(); this.backToRegister(); return; }
            if (failure !== 'otp_invalid') { this.codeError = this.errorText(e); return; }
            // Server menegakkan batasnya (AUTH-03, AUTH-04); hitungan di sini hanya cerminan untuk tampilan.
            this.attemptsLeft -= 1;
            if (this.attemptsLeft <= 0) {
                this.attemptsLeft = MAX_ATTEMPTS;
                this.lockUntil = Date.now() + 900000;
                this.now = Date.now();
                this.codeError = '';
                return;
            }
            this.codeError = this.a.otp.wrong.replace('{n}', this.attemptsLeft);
            this.$refs.codeEl?.focus();
        } finally {
            this.busy = false;
        }
    },
    async resend() {
        if (this.busy || this.resendLeft > 0 || this.locked) return;
        this.busy = true;
        try {
            const { data } = await api.post('/auth/register/resend', { captcha_token: this.channel === 'phone' ? await this.takeCaptcha() : null });
            this.startCountdown(data.data.resend_in ?? 60);
            this.code = '';
            this.codeError = '';
            this.codeStatus = this.a.otp.resent;
            this.$refs.codeEl?.focus();
        } catch (e) {
            this.codeStatus = '';
            this.codeError = this.errorText(e);
        } finally {
            this.busy = false;
        }
    },
    enterStep() {
        document.title = this.isOtp ? this.a.otp.meta : (this.isMethod ? this.a.register.meta : this.words.meta);
        // Langkah pilih saluran butuh email dan nomor yang valid di memori (mis. muat ulang menghapusnya): kembali ke formulir.
        if (this.isMethod && !(emailValid(this.email.trim()) && (!this.whatsappRequired || phoneValid(this.phone.trim())))) { window.history.replaceState({}, '', '/daftar'); this.mode = 'register'; document.title = this.words.meta; return; }
        if (this.isOtp && !this.maskedId) { window.history.replaceState({}, '', '/daftar'); this.mode = 'register'; document.title = this.words.meta; return; }
        clearInterval(this.ticker);
        // Hitung mundur dari stempel waktu agar tidak melenceng saat tab dijeda.
        if (this.isOtp) {
            this.ticker = setInterval(() => { this.now = Date.now(); }, 1000);
            this.$nextTick(() => this.$refs.codeEl?.focus());
        }
        if (this.isMethod || this.isOtp) this.$nextTick(() => this.renderCaptcha());
        if (this.isMethod) this.$nextTick(() => document.querySelector('.tf-vm-card:not(:disabled)')?.focus());
        window.scrollTo({ top: 0 });
    },
});
