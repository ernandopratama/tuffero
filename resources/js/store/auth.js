import api from './api';
import { pageData } from './site';

const MAX_ATTEMPTS = 5;
const emailValid = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
// Nomor seluler Indonesia setelah 0 atau 62 dibuang: diawali 8, total 9 sampai 12 digit.
const phoneDigits = (v) => v.replace(/\D/g, '').replace(/^(62|0)/, '');
const phoneValid = (v) => /^8\d{8,11}$/.test(phoneDigits(v));
const maskEmail = (v) => { const [name, domain] = v.split('@'); return `${name[0]}•••@${domain}`; };
const maskPhone = (v) => { const d = `0${phoneDigits(v)}`; return `${d.slice(0, 4)}-••••-${d.slice(-4)}`; };
const clock = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

// Satu komponen untuk /masuk, /daftar, dan /masuk/verifikasi: identitas dan kata sandi hanya di memori, tidak pernah ditulis ke URL atau penyimpanan browser.
// Backend akun pelanggan belum ada (OQ-18, OQ-22). Di luar environment local, masuk selalu menampilkan "belum tersedia" dan pratinjau verifikasi tidak ada.
// Kode pratinjau datang dari server hanya di local, jadi tidak pernah ikut bundel atau halaman produksi.
export const authPage = () => ({
    a: {},
    demoCodeValue: null,
    dev: false,
    mode: 'login',
    channel: 'email',
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
    demoCode: '',
    done: false,
    code: '',
    codeError: '',
    codeStatus: '',
    resendAt: 0,
    lockUntil: 0,
    now: Date.now(),
    otpTargets: [],
    otpIndex: 0,
    attemptsLeft: MAX_ATTEMPTS,
    ticker: null,

    init() {
        const data = pageData('tf-auth-data');
        this.a = data.words;
        this.dev = data.dev;
        this.demoCodeValue = data.demoCode;
        this.mode = data.mode;
        window.addEventListener('popstate', () => {
            this.mode = window.location.pathname === '/daftar' ? 'register' : 'login';
            this.enterStep();
        });
        this.$watch('locked', (v, was) => { if (was && !v) this.$nextTick(() => this.$refs.codeEl?.focus()); });
        this.enterStep();
    },

    get isOtp() { return this.mode === 'otp'; },
    get isRegister() { return this.mode === 'register'; },
    get showForm() { return !this.isOtp; },
    get showDone() { return this.isOtp && this.done; },
    get showOtp() { return this.isOtp && !this.done; },
    get words() { return this.isRegister ? this.a.register : this.a.login; },
    get channelWords() { return this.channel === 'email' ? this.a.email : this.a.phone; },
    get resendLeft() { return Math.max(0, Math.ceil((this.resendAt - this.now) / 1000)); },
    get lockLeft() { return Math.max(0, Math.ceil((this.lockUntil - this.now) / 1000)); },
    get locked() { return this.lockLeft > 0; },
    get passwordType() { return this.showPassword ? 'text' : 'password'; },
    get passwordLabel() { return this.showPassword ? this.a.password.hideLabel : this.a.password.showLabel; },
    get passwordAutocomplete() { return this.isRegister ? 'new-password' : 'current-password'; },
    get passwordHint() { return this.passwordError || (this.isRegister ? this.a.password.hintRegister : ''); },
    get phoneHint() { return this.phoneError || this.a.identity.hintRegister; },
    get otpEyebrow() { return this.otpTargets.length > 1 ? this.a.otp.step.replace('{n}', this.otpIndex + 1).replace('{total}', this.otpTargets.length) : this.a.otp.eyebrow; },
    get otpTitle() { return this.locked ? this.a.otp.lockedTitle : this.a.otp.title; },
    get otpLead() { return this.locked ? this.a.otp.lockedLead : this.a.otp.lead.replace('{id}', this.maskedId); },
    get demoText() { return this.a.otp.demo.replace('{code}', this.demoCode); },
    get lockedText() { return this.a.otp.locked.replace('{time}', clock(this.lockLeft)); },
    get resendText() { return this.a.otp.resendIn.replace('{time}', clock(this.resendLeft)); },
    get submitOtpText() { return this.locked ? this.a.otp.lockedTitle : this.a.otp.submit; },
    get showResend() { return !this.locked && this.resendLeft === 0; },
    get showWait() { return !this.locked && !this.codeError && !this.codeStatus && this.resendLeft > 0; },
    get showStatus() { return !this.locked && !this.codeError && !!this.codeStatus; },
    get showCodeError() { return !this.locked && !!this.codeError; },
    get otpClass() { return { 'is-error': !!this.codeError, 'is-locked': this.locked }; },
    get showDemo() { return !!this.demoCode && !this.locked; },
    get codeCells() { return [0, 1, 2, 3, 4, 5]; },
    cellText(i) { return this.locked ? '' : (this.code[i] || ''); },
    cellClass(i) { return { 'is-next': this.code.length === i || (this.code.length === 6 && i === 5) }; },

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
    clearField() { this.fieldError = ''; },
    clearPhone() { this.phoneError = ''; this.fieldError = ''; },
    clearPassword() { this.passwordError = ''; },
    googleNotice() { this.notice = this.a.googleUnavailable; },

    validateRegister() {
        const mail = this.email.trim();
        const tel = this.phone.trim();
        this.fieldError = !mail && !tel ? this.a.identity.required : (mail && !emailValid(mail) ? this.a.identity.emailInvalid : '');
        this.phoneError = tel && !phoneValid(tel) ? this.a.identity.phoneInvalid : '';
        this.otpTargets = [...(mail ? [{ key: 'email', id: mail }] : []), ...(tel ? [{ key: 'phone', id: tel }] : [])];
    },
    validateLogin() {
        const value = this.identity.trim();
        const valid = value.includes('@') ? emailValid(value) : phoneValid(value);
        this.fieldError = valid ? '' : this.a.identity.invalid;
        this.phoneError = '';
    },
    showTarget({ key, id }) { this.channel = key; this.maskedId = id; },
    async submit() {
        if (this.busy) return;
        this.notice = '';
        if (this.isRegister) this.validateRegister(); else this.validateLogin();
        this.passwordError = !this.password ? this.a.password.required : (this.isRegister && this.password.length < 8 ? this.a.password.tooShort : '');
        if (this.fieldError || this.phoneError || this.passwordError) {
            // Lewat id: kolom identitas bergantian masuk dan keluar DOM antara masuk dan daftar.
            document.getElementById(this.fieldError ? (this.isRegister ? 'tf-auth-email' : 'tf-auth-id') : (this.phoneError ? 'tf-auth-phone' : 'tf-auth-password'))?.focus();
            return;
        }
        this.busy = true;
        try {
            // Hanya local: masuk memakai akun uji lewat /api/v1/auth/login. Di luar local tetap "belum tersedia" (OQ-18, OQ-22).
            if (this.dev && !this.isRegister) {
                try {
                    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
                    const { data } = await api.post('/auth/login', { email: this.identity.trim(), password: this.password });
                    this.password = '';
                    if (data.data.mfa) { this.notice = 'Akun staf dengan MFA masuk lewat /login.'; return; }
                    window.location.assign('/');
                } catch {
                    this.notice = 'Email atau kata sandi salah (mode pengembangan, hanya akun email).';
                }
                return;
            }
            if (!this.isRegister || !this.demoCodeValue) { this.notice = this.words.unavailable; return; }
            this.demoCode = this.demoCodeValue;
            this.password = '';
            this.otpTargets = this.otpTargets.map(({ key, id }) => ({ key, id: key === 'email' ? maskEmail(id) : maskPhone(id) }));
            this.otpIndex = 0;
            this.showTarget(this.otpTargets[0]);
            this.resendAt = Date.now() + 60000;
            this.now = Date.now();
            this.code = '';
            this.codeError = '';
            this.codeStatus = '';
            this.go('otp', '/masuk/verifikasi');
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
        // Pratinjau pengembangan, bukan keamanan: backend sungguhan wajib menegakkan semua batas ini di server (AUTH-03, AUTH-04).
        if (this.code === this.demoCodeValue) {
            if (this.otpIndex + 1 >= this.otpTargets.length) { this.done = true; return; }
            this.otpIndex += 1;
            this.showTarget(this.otpTargets[this.otpIndex]);
            this.resendAt = Date.now() + 60000;
            this.now = Date.now();
            this.code = '';
            this.codeError = '';
            this.codeStatus = '';
            this.$refs.codeEl?.focus();
            return;
        }
        this.code = '';
        this.codeStatus = '';
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
    },
    resend() {
        if (this.busy || this.resendLeft > 0 || this.locked) return;
        this.resendAt = Date.now() + 60000;
        this.now = Date.now();
        this.code = '';
        this.codeError = '';
        this.codeStatus = this.a.otp.resent;
        this.$refs.codeEl?.focus();
    },
    enterStep() {
        document.title = this.isOtp ? this.a.otp.meta : this.words.meta;
        if (this.isOtp && !this.maskedId) { window.history.replaceState({}, '', '/daftar'); this.mode = 'register'; document.title = this.words.meta; return; }
        clearInterval(this.ticker);
        // Hitung mundur dari stempel waktu agar tidak melenceng saat tab dijeda.
        if (this.isOtp) {
            this.ticker = setInterval(() => { this.now = Date.now(); }, 1000);
            this.$nextTick(() => this.$refs.codeEl?.focus());
        } else {
            this.done = false;
        }
        window.scrollTo({ top: 0 });
    },
});
