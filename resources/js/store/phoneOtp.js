import api from './api';

// Turnstile dimuat hanya saat langkah OTP tampil, bukan di setiap halaman.
let turnstileLoading = null;
const loadTurnstile = () => {
    if (window.turnstile) return Promise.resolve();
    turnstileLoading ??= new Promise((resolve, reject) => {
        const script = Object.assign(document.createElement('script'), {
            src: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            async: true,
            onload: resolve,
            onerror: () => { turnstileLoading = null; reject(new Error('turnstile')); },
        });
        document.head.appendChild(script);
    });
    return turnstileLoading;
};

/**
 * Langkah verifikasi WhatsApp tamu di checkout (D-051, D-093). Tiap pesan berbiaya: kode hanya dikirim saat pelanggan
 * menekan tombol, dan status perangkat atau kode yang masih berlaku diperiksa dulu tanpa mengirim pesan.
 * Butuh `words.otp`, `words.errors`, `form.customer_phone`, dan `phoneText` dari halaman.
 */
export const phoneOtp = () => {
    // Widget dan timer di luar data Alpine, sama seperti peta: proxy reaktif tidak perlu melacaknya.
    let widget = null;
    let timer = null;

    return {
        otpStatus: '',
        otpPhone: '',
        otpCode: '',
        otpBusy: false,
        otpVerifying: false,
        otpError: '',
        otpResendIn: 0,
        otpToken: '',

        get otpRequired() { return !!this.words.otp; },
        get phoneOk() { return !this.otpRequired || (this.otpStatus === 'verified' && this.otpPhone === this.form.customer_phone); },
        get otpIsVerified() { return this.otpStatus === 'verified'; },
        get otpNeedsLogin() { return this.otpStatus === 'login_required'; },
        get otpShowForm() { return this.otpStatus === 'required' || this.otpStatus === 'sent'; },
        get otpSent() { return this.otpStatus === 'sent'; },
        get otpCanSend() { return !this.otpBusy && this.otpResendIn === 0; },
        get otpLead() { return this.words.otp.lead.replace(':phone', this.phoneText); },
        get otpSendText() {
            if (this.otpResendIn > 0) return this.words.otp.resendIn.replace(':s', this.otpResendIn);
            return this.otpSent ? this.words.otp.resend : this.words.otp.send;
        },
        get otpBusyText() { return this.otpVerifying ? this.words.otp.checking : this.words.otp.sending; },
        get otpFieldClass() { return this.otpError && this.otpSent ? 'is-error' : ''; },
        get otpInvalid() { return !!this.otpError; },

        resetOtp() {
            clearInterval(timer);
            timer = null;
            if (widget !== null) window.turnstile?.remove(widget);
            widget = null;
            Object.assign(this, { otpStatus: '', otpPhone: '', otpCode: '', otpBusy: false, otpVerifying: false, otpError: '', otpResendIn: 0, otpToken: '' });
        },
        // Dipanggil setelah alamat dibenarkan: perangkat yang sudah terverifikasi atau kode yang masih berlaku langsung terlihat.
        probeOtp() {
            if (!this.otpRequired) return undefined;
            this.resetOtp();
            this.otpPhone = this.form.customer_phone;
            return this.callOtp({ probe: true });
        },
        async sendOtp() {
            if (!this.otpCanSend) return;
            await this.callOtp({ resend: this.otpSent, captcha_token: this.otpToken || null });
            // Token Turnstile hanya berlaku sekali.
            this.otpToken = '';
            if (widget !== null) window.turnstile?.reset(widget);
        },
        async callOtp(body) {
            const phone = this.form.customer_phone;
            this.otpBusy = true;
            this.otpError = '';
            try {
                const { data } = await api.post('/phone-otp/send', { phone, ...body });
                if (phone !== this.otpPhone) return;
                const { status, resend_in: resendIn } = data.data;
                this.otpStatus = status === 'code_active' ? 'sent' : status;
                if (this.otpSent) {
                    this.countdown(resendIn ?? 0);
                    this.$nextTick(() => document.getElementById('co-otp-code')?.focus());
                }
                if (this.otpShowForm) this.$nextTick(() => this.renderCaptcha());
            } catch (e) {
                if (phone !== this.otpPhone) return;
                const code = this.otpErrorCode(e);
                // Nomor ditolak server: buka lagi isian kontak supaya bisa diperbaiki.
                if (code === 'phone_invalid') {
                    this.errors.customer_phone = this.words.errors.phone_invalid;
                    this.addressConfirmed = false;
                    return;
                }
                if (!this.otpStatus) this.otpStatus = 'required';
                this.otpError = this.otpMessage(e);
                this.$nextTick(() => this.renderCaptcha());
            } finally {
                this.otpBusy = false;
            }
        },
        onOtpInput(event) {
            this.otpCode = event.target.value.replace(/\D/g, '').slice(0, 6);
            event.target.value = this.otpCode;
            this.otpError = '';
            if (this.otpCode.length === 6) this.verifyOtp();
        },
        async verifyOtp() {
            if (this.otpBusy || this.otpCode.length !== 6) return;
            this.otpBusy = true;
            this.otpVerifying = true;
            this.otpError = '';
            try {
                await api.post('/phone-otp/verify', { phone: this.otpPhone, code: this.otpCode });
                clearInterval(timer);
                this.otpStatus = 'verified';
                this.otpResendIn = 0;
                if (this.formError === this.words.otp.needed) this.formError = '';
            } catch (e) {
                this.otpError = this.otpMessage(e);
                this.otpCode = '';
                this.$nextTick(() => document.getElementById('co-otp-code')?.focus());
            } finally {
                this.otpBusy = false;
                this.otpVerifying = false;
            }
        },
        countdown(seconds) {
            clearInterval(timer);
            this.otpResendIn = seconds;
            if (seconds <= 0) return;
            timer = setInterval(() => {
                this.otpResendIn = Math.max(0, this.otpResendIn - 1);
                if (this.otpResendIn === 0) clearInterval(timer);
            }, 1000);
        },
        async renderCaptcha() {
            const el = document.getElementById('co-turnstile');
            const sitekey = this.words.otp.siteKey;
            if (!sitekey || widget !== null || !el) return;
            try {
                await loadTurnstile();
            } catch {
                return; // Server menolak tanpa token dan pesan galatnya tampil; pelanggan bisa mencoba lagi.
            }
            if (widget !== null || !el.isConnected) return;
            widget = window.turnstile.render(el, {
                sitekey,
                appearance: 'interaction-only',
                callback: (token) => { this.otpToken = token; },
                'expired-callback': () => { this.otpToken = ''; },
            });
        },
        otpErrorCode(e) { return Object.values(e.response?.data?.errors ?? {})[0]?.[0]; },
        otpMessage(e) { return this.words.errors[this.otpErrorCode(e)] || (e.response?.status === 429 ? this.words.tooMany : this.words.failed); },
    };
};
