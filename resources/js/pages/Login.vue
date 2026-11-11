<script setup>
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import LoginMascot from '../components/LoginMascot.vue';
import OtpInput from '../components/OtpInput.vue';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const form = reactive({ email: '', password: '' });
// credentials, setup, challenge, recovery-codes
const step = ref('credentials');
const code = ref('');
// Metode langkah kedua: app (authenticator), email (kode OTP email), recovery (kode pemulihan).
const method = ref('app');
const emailSent = ref(false);
const enrollment = ref(null);
const recoveryCodes = ref([]);
const copied = ref(false);
const keyCopied = ref(false);
const saved = ref(false);
const error = ref('');
const loading = ref(false);
const codeInput = ref(null);
const showPassword = ref(false);
// Kolom yang sedang aktif menentukan pose maskot; berhasil menggantikan pose lain sampai ada input baru.
const focusedField = ref('');
const success = ref(false);
const mascotState = computed(() => {
    if (success.value) return 'success';
    return step.value === 'credentials' && focusedField.value ? focusedField.value : 'idle';
});
const focusField = (name) => { focusedField.value = name; success.value = false; };

const challengeHint = computed(() => ({
    app: 'Buka aplikasi authenticator Anda dan ketik 6 digit kodenya.',
    email: emailSending.value ? 'Mengirim kode ke email akun Anda...' : emailSent.value ? 'Kode dikirim ke email akun Anda. Berlaku 5 menit; periksa juga folder spam.' : 'Kode belum terkirim. Tekan Kirim ulang kode.',
    recovery: 'Masukkan salah satu kode pemulihan yang Anda simpan. Setiap kode hanya berlaku sekali.',
}[method.value]));

// Teks pengiriman dipecah per kata lalu per huruf; kata tidak terpotong di tengah saat baris berganti.
const waveWords = computed(() => {
    let i = 0;
    return challengeHint.value.split(' ').map((word) => [...word].map((char) => ({ char, i: i++ })));
});

function backToLogin() {
    step.value = 'credentials';
    error.value = '';
    code.value = '';
    method.value = 'app';
    emailSent.value = false;
}

const title = computed(() => ({
    credentials: 'Masuk ke Akun Anda',
    setup: 'Aktifkan Verifikasi Dua Langkah',
    challenge: 'Verifikasi Dua Langkah',
    'recovery-codes': 'Simpan Kode Pemulihan',
}[step.value]));

function errorMessage(e) {
    const messages = {
        401: step.value === 'credentials' ? 'Email atau password salah.' : 'Sesi login berakhir. Masuk lagi.',
        422: 'Kode salah atau sudah dipakai. Coba kode berikutnya.',
        429: 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
        503: 'Email gagal dikirim. Pakai kode authenticator atau coba lagi nanti.',
    };
    return messages[e.response?.status] || 'Login gagal. Coba lagi.';
}

async function run(action) {
    loading.value = true;
    error.value = '';
    try {
        await action();
    } catch (e) {
        error.value = errorMessage(e);
        code.value = '';
        if (e.response?.status === 401 && step.value !== 'credentials') {
            step.value = 'credentials';
            form.password = '';
        }
    } finally {
        loading.value = false;
    }
}

async function showCodeStep(next) {
    step.value = next;
    code.value = '';
    await nextTick();
    codeInput.value?.focus();
}

const submitCredentials = () => run(async () => {
    const mfa = await auth.login(form);
    form.password = '';

    if (mfa === 'setup') {
        enrollment.value = await auth.startMfaSetup();
        await showCodeStep('setup');
    } else if (mfa === 'challenge') {
        await showCodeStep('challenge');
    } else {
        success.value = true;
        router.push({ name: 'Dashboard' });
    }
});

const submitSetup = () => run(async () => {
    recoveryCodes.value = await auth.confirmMfa(code.value);
    enrollment.value = null;
    step.value = 'recovery-codes';
    success.value = true;
});

const submitChallenge = () => run(async () => {
    const field = { app: 'code', email: 'email_code', recovery: 'recovery_code' }[method.value];
    await auth.verifyMfa({ [field]: code.value });
    success.value = true;
    router.push({ name: 'Dashboard' });
});

// Kode 6 digit lengkap (diketik atau ditempel) langsung dikirim, tanpa menekan tombol. Kode pemulihan bukan 6 digit, jadi tetap manual.
// `loading` mencegah kiriman ganda; kode dikosongkan bila ditolak (lihat run), jadi tidak berulang sendiri.
watch(code, (value) => {
    if (loading.value || !/^\d{6}$/.test(value)) return;
    if (step.value === 'setup') submitSetup();
    else if (step.value === 'challenge' && method.value !== 'recovery') submitChallenge();
});

async function chooseMethod(next) {
    method.value = next;
    error.value = '';
    await showCodeStep('challenge');
}

// Jeda kirim ulang kode email, sama dengan batas server (60 detik).
const resendIn = ref(0);
let resendTimer = null;

function startCooldown(seconds = 60) {
    clearInterval(resendTimer);
    resendIn.value = seconds;
    resendTimer = setInterval(() => {
        resendIn.value -= 1;
        if (resendIn.value <= 0) clearInterval(resendTimer);
    }, 1000);
}

onUnmounted(() => clearInterval(resendTimer));

// Pindah ke tab Email dulu, baru kirim: pengiriman email bisa lambat dan layar tidak boleh terlihat macet di tab lama.
const emailSending = ref(false);
async function sendEmailCode() {
    if (resendIn.value > 0 || emailSending.value) return;
    await chooseMethod('email');
    emailSending.value = true;
    try {
        await auth.sendMfaEmail();
        emailSent.value = true;
        startCooldown();
    } catch (e) {
        if (e.response?.status === 429) {
            // Server masih menahan kode sebelumnya: tampilkan hitung mundur, bukan pesan "terlalu banyak percobaan".
            emailSent.value = true;
            startCooldown();
            error.value = 'Kode sudah dikirim. Tunggu sebelum meminta lagi.';
        } else {
            error.value = errorMessage(e);
        }
    } finally {
        emailSending.value = false;
    }
}

// Clipboard bisa ditolak browser (izin atau konteks tidak aman): kode tetap bisa disalin manual atau diunduh.
async function copyText(text, flag) {
    try {
        await navigator.clipboard.writeText(text);
        flag.value = true;
        setTimeout(() => { flag.value = false; }, 1600);
    } catch { /* abaikan */ }
}
const copyRecoveryCodes = () => copyText(recoveryCodes.value.join('\n'), copied);
const copyKey = () => copyText(enrollment.value.secret, keyCopied);

function downloadRecoveryCodes() {
    const blob = new Blob([`Kode pemulihan Tuffero\n\n${recoveryCodes.value.join('\n')}\n`], { type: 'text/plain' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'kode-pemulihan-tuffero.txt';
    link.click();
    URL.revokeObjectURL(link.href);
}

const METHODS = [
    { key: 'app', label: 'Aplikasi' },
    { key: 'email', label: 'Email' },
    { key: 'recovery', label: 'Pemulihan' },
];
const segment = 'inline-flex min-h-10 items-center justify-center rounded-full px-2 text-label-md transition-[background-color,color,box-shadow,transform] duration-200 ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60';
const recoveryInput = 'block h-14 w-full rounded-2xl border bg-surface px-4 text-center font-mono text-body-md tracking-wider focus:border-accent focus:outline-2 focus:outline-primary';
const primaryBtn = 'inline-flex h-11 w-full items-center justify-center gap-2 rounded-full bg-accent px-5 text-label-md text-primary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100';
const ghostBtn = 'inline-flex h-10 items-center justify-center gap-2 rounded-full border border-secondary px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-background p-4 font-sans">
    <!-- Maskot menumpang di tepi atas kartu; z-10 supaya tangannya terlihat di atas kartu. -->
    <div class="relative z-10 -mb-px w-44 sm:w-52" :class="step === 'setup' ? 'lg:w-40' : ''">
      <LoginMascot :state="mascotState" />
    </div>
    <div class="w-full space-y-4 rounded-lg border border-border bg-surface p-6 pt-8 text-primary shadow-card transition-[max-width] duration-300 ease-out-strong sm:p-8 sm:pt-10" :class="step === 'setup' ? 'max-w-md lg:max-w-3xl' : 'max-w-sm'">
      <div class="text-center">
        <h1 class="font-heading text-headline-md">{{ title }}</h1>
        <p v-if="step === 'credentials'" class="mt-1 text-body-sm text-secondary">Kelola pesanan, stok, dan operasional Tuffero Patisserie.</p>
        <p v-else-if="step === 'challenge'" class="mt-1 text-body-sm text-secondary" role="status" :aria-busy="emailSending">
          <!-- Mengirim kode: teks bergelombang huruf demi huruf (naik dan membesar bergantian) -->
          <template v-if="emailSending">
            <span class="sr-only">{{ challengeHint }}</span>
            <span class="font-semibold text-primary" aria-hidden="true">
              <template v-for="(word, w) in waveWords" :key="w"><span class="inline-block whitespace-nowrap"><span v-for="letter in word" :key="letter.i" class="wave-letter" :style="{ animationDelay: `${-letter.i * 0.06}s` }">{{ letter.char }}</span></span>{{ ' ' }}</template>
            </span>
          </template>
          <template v-else>{{ challengeHint }}</template>
        </p>
      </div>
      <!-- Ruang pesan error selalu ada supaya form tidak bergeser saat pesan muncul. -->
      <div class="min-h-8" aria-live="polite">
        <p v-if="error" role="alert" class="rounded bg-error-surface px-3 py-1.5 text-body-sm text-on-error-surface">{{ error }}</p>
      </div>

      <form v-if="step === 'credentials'" class="space-y-4" @submit.prevent="submitCredentials">
        <div>
          <label for="login-email" class="text-label-md">Email</label>
          <div class="relative mt-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7" /></svg>
            <input
              id="login-email"
              v-model="form.email"
              type="email"
              required
              autocomplete="username"
              spellcheck="false"
              class="w-full rounded-md border border-secondary py-2.5 pl-10 pr-3 text-body-md focus:border-accent focus:outline-2 focus:outline-primary"
              @focus="focusField('username')"
              @blur="focusedField = ''"
            >
          </div>
        </div>
        <div>
          <label for="login-password" class="text-label-md">Password</label>
          <div class="relative mt-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
            <input
              id="login-password"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              required
              autocomplete="current-password"
              spellcheck="false"
              class="w-full rounded-md border border-secondary py-2.5 pl-10 pr-12 text-body-md focus:border-accent focus:outline-2 focus:outline-primary"
              @focus="focusField('password')"
              @blur="focusedField = ''"
            >
            <!-- mousedown dicegah supaya fokus tetap di kolom password saat tombol diklik. -->
            <button type="button" :aria-label="showPassword ? 'Sembunyikan password' : 'Tampilkan password'" :aria-pressed="showPassword" class="absolute right-1.5 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full text-secondary hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @mousedown.prevent @click="showPassword = !showPassword">
              <svg v-if="!showPassword" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>
              <svg v-else class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18" /><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4.4-1" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></svg>
            </button>
          </div>
        </div>
        <button :disabled="loading" class="fancy">
          <span class="top-key"></span>
          <span class="text">{{ loading ? 'Memproses...' : 'Masuk' }}</span>
          <span class="bottom-key-1"></span>
          <span class="bottom-key-2"></span>
        </button>
        <p class="text-center">
          <router-link :to="{ name: 'ForgotPassword' }" class="text-label-md text-secondary underline hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">Lupa kata sandi?</router-link>
        </p>
      </form>

      <form v-else-if="step === 'setup'" class="space-y-4 motion-safe:animate-rise" @submit.prevent="submitSetup">
        <ol class="flex items-center justify-center gap-2 text-label-sm" aria-label="Langkah">
          <li class="inline-flex items-center gap-1.5 rounded-full bg-accent px-3 py-1"><span class="grid size-4 place-items-center rounded-full bg-primary text-[10px] text-background" aria-hidden="true">1</span>Pindai</li>
          <li class="h-px w-6 bg-border" aria-hidden="true"></li>
          <li class="inline-flex items-center gap-1.5 rounded-full px-3 py-1" :class="code.length ? 'bg-accent' : 'bg-secondary-tint-12'"><span class="grid size-4 place-items-center rounded-full bg-primary text-[10px] text-background" aria-hidden="true">2</span>Masukkan kode</li>
        </ol>

        <!-- Desktop: QR di kiri, kode di kanan (tanpa menggulir). HP dan tablet: ditumpuk, QR dulu. -->
        <div class="grid gap-5 lg:grid-cols-[auto_1fr] lg:items-center lg:gap-10">
          <div class="space-y-3">
            <div class="mx-auto w-fit rounded-3xl border border-border bg-white p-3 shadow-card">
              <img :src="enrollment.qr_code" alt="Kode QR untuk aplikasi authenticator" width="220" height="220" class="block size-[180px] sm:size-[220px]">
            </div>
            <details class="rounded-2xl border border-border bg-background/60 px-4 py-2.5 lg:max-w-[244px]">
              <summary class="cursor-pointer list-none text-center text-label-md text-secondary marker:hidden hover:text-primary focus-visible:outline-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">Tidak bisa memindai? Ketik kuncinya</summary>
              <div class="mt-3 space-y-2">
                <code class="block break-all rounded-xl bg-accent-tint-12 px-3 py-2 text-center font-mono text-body-sm tracking-wider">{{ enrollment.secret }}</code>
                <button type="button" :class="[ghostBtn, 'w-full']" @click="copyKey">{{ keyCopied ? 'Tersalin' : 'Salin kunci' }}</button>
              </div>
            </details>
          </div>

          <div class="space-y-4">
            <ol class="space-y-2 text-body-sm text-secondary">
              <li class="flex gap-2"><span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-accent-tint-20 text-label-sm text-primary" aria-hidden="true">1</span>Buka aplikasi authenticator, misalnya Google Authenticator.</li>
              <li class="flex gap-2"><span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-accent-tint-20 text-label-sm text-primary" aria-hidden="true">2</span>Pindai kode QR di samping, atau ketik kuncinya.</li>
              <li class="flex gap-2"><span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-accent-tint-20 text-label-sm text-primary" aria-hidden="true">3</span>Ketik 6 digit yang muncul di aplikasi.</li>
            </ol>
            <div>
              <p class="mb-2 text-center text-label-md lg:text-left">Kode 6 digit</p>
              <OtpInput ref="codeInput" v-model="code" label="Kode 6 digit dari aplikasi authenticator" :disabled="loading" :invalid="!!error" />
            </div>
            <button :disabled="loading || code.length !== 6" :class="primaryBtn">{{ loading ? 'Memeriksa...' : 'Aktifkan' }}</button>
          </div>
        </div>
      </form>

      <form v-else-if="step === 'challenge'" class="space-y-5 motion-safe:animate-rise" @submit.prevent="submitChallenge">
        <!-- Pilih cara verifikasi: tiga pilihan sejajar, bukan tautan bergaris bawah bertumpuk -->
        <div class="grid grid-cols-3 gap-1 rounded-full bg-secondary-tint-12 p-1" role="group" aria-label="Cara verifikasi">
          <button v-for="item in METHODS" :key="item.key" type="button" :aria-pressed="method === item.key" :disabled="loading" :class="[segment, method === item.key ? 'bg-surface text-primary shadow-card' : 'text-secondary hover:text-primary']" @click="item.key === 'email' && !emailSent && !emailSending ? sendEmailCode() : chooseMethod(item.key)">{{ item.label }}</button>
        </div>

        <div v-if="method === 'recovery'">
          <label for="mfa-recovery" class="mb-2 block text-center text-label-md">Kode pemulihan</label>
          <input id="mfa-recovery" ref="codeInput" v-model="code" required autocomplete="off" maxlength="32" placeholder="xxxxx-xxxxx" :class="[recoveryInput, error ? 'border-error' : 'border-secondary']">
        </div>
        <div v-else>
          <p class="mb-2 text-center text-label-md">Kode 6 digit</p>
          <OtpInput ref="codeInput" v-model="code" :label="method === 'email' ? 'Kode 6 digit dari email' : 'Kode 6 digit dari aplikasi authenticator'" :disabled="loading" :invalid="!!error" />
        </div>

        <button :disabled="loading || (method !== 'recovery' && code.length !== 6)" :class="primaryBtn">{{ loading ? 'Memeriksa...' : 'Verifikasi' }}</button>

        <div class="flex items-center justify-between gap-2 text-label-md">
          <button type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-secondary transition-colors duration-150 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="backToLogin">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>Kembali
          </button>
          <button v-if="method === 'email'" type="button" :disabled="loading || emailSending || resendIn > 0" class="inline-flex min-h-10 items-center rounded-full px-3 underline transition-colors duration-150 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary disabled:no-underline disabled:opacity-60" @click="sendEmailCode">{{ resendIn > 0 ? `Kirim ulang kode (${resendIn} dtk)` : 'Kirim ulang kode' }}</button>
        </div>
      </form>

      <div v-else class="space-y-4 motion-safe:animate-rise">
        <p class="text-body-sm text-secondary">Simpan kode ini di tempat aman. Dipakai bila HP Anda hilang. Setiap kode hanya bisa dipakai sekali dan tidak akan ditampilkan lagi.</p>
        <ul class="grid grid-cols-2 gap-2" aria-label="Kode pemulihan">
          <li v-for="recoveryCode in recoveryCodes" :key="recoveryCode" class="rounded-xl border border-border bg-background px-3 py-2 text-center font-mono text-label-md">{{ recoveryCode }}</li>
        </ul>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="[ghostBtn, 'flex-1']" @click="copyRecoveryCodes">{{ copied ? 'Tersalin' : 'Salin semua' }}</button>
          <button type="button" :class="[ghostBtn, 'flex-1']" @click="downloadRecoveryCodes">Unduh .txt</button>
        </div>
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="saved ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
          <input v-model="saved" type="checkbox" class="mt-0.5 size-4 accent-primary">
          <span class="text-label-md">Saya sudah menyimpan kode pemulihan</span>
        </label>
        <button type="button" :disabled="!saved" :class="primaryBtn" @click="router.push({ name: 'Dashboard' })">Lanjut ke dashboard</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Pemuat teks bergelombang. Huruf bergerak naik dan membesar lewat transform (tidak mengubah tinggi baris, tidak menggeser tata letak). */
@keyframes wave-letter {
    0%, 100% { transform: translateY(0) scale(1); }
    50% { transform: translateY(-3px) scale(1.18); }
}
.wave-letter { display: inline-block; white-space: pre; animation: wave-letter 1.1s ease-in-out infinite; }
@media (prefers-reduced-motion: reduce) { .wave-letter { animation: none; } }

/* Tombol Masuk: garis tepi dengan celah yang menutup saat disentuh (sudut sama dengan kolom input). Celah berwarna sama dengan kartu (surface). */
.fancy {
    --key: var(--color-surface);
    position: relative;
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 1.1em 2em;
    border: 2px solid var(--color-primary);
    border-radius: var(--radius-md);
    background-color: var(--color-accent-hover);
    color: var(--color-primary);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.05em;
    cursor: pointer;
    user-select: none;
    transition: background-color 0.3s ease-in-out, color 0.3s ease-in-out, transform 160ms cubic-bezier(0.22, 1, 0.36, 1);
}
.fancy::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 1.5em;
    width: 1.5625rem;
    height: 2px;
    background: var(--color-primary);
    transform: translateY(-50%);
    transition: background-color 0.3s linear, width 0.3s linear;
}
.fancy .text {
    display: block;
    font-size: 1.125em;
    line-height: 1.33333em;
    text-align: center;
    text-transform: uppercase;
    color: inherit;
}
.fancy .top-key,
.fancy .bottom-key-1,
.fancy .bottom-key-2 {
    position: absolute;
    height: 2px;
    background: var(--key);
}
.fancy .top-key { top: -2px; left: 0.625rem; width: 1.5625rem; transition: width 0.5s ease-out, left 0.3s ease-out; }
.fancy .bottom-key-1 { right: 1.875rem; bottom: -2px; width: 1.5625rem; transition: width 0.5s ease-out, right 0.3s ease-out; }
.fancy .bottom-key-2 { right: 0.625rem; bottom: -2px; width: 0.625rem; transition: width 0.5s ease-out, right 0.3s ease-out; }
.fancy:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 4px; }
.fancy:disabled { opacity: 0.6; cursor: not-allowed; }

/* Hover dibatasi perangkat dengan penunjuk halus supaya layar sentuh tidak menahan keadaan hover. */
@media (hover: hover) and (pointer: fine) {
    .fancy:not(:disabled):hover { background-color: var(--color-primary); color: var(--color-background); }
    .fancy:not(:disabled):hover::before { width: 0.9375rem; background: var(--color-accent); }
    .fancy:not(:disabled):hover .top-key { left: -2px; width: 0; }
    .fancy:not(:disabled):hover .bottom-key-1,
    .fancy:not(:disabled):hover .bottom-key-2 { right: 0; width: 0; }
}
</style>
