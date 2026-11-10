<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import LoginMascot from '../components/LoginMascot.vue';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const form = reactive({ email: '', password: '' });
// credentials, setup, challenge, recovery-codes
const step = ref('credentials');
const code = ref('');
const useRecoveryCode = ref(false);
const enrollment = ref(null);
const recoveryCodes = ref([]);
const copied = ref(false);
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
    await auth.verifyMfa(useRecoveryCode.value ? { recovery_code: code.value } : { code: code.value });
    success.value = true;
    router.push({ name: 'Dashboard' });
});

async function toggleRecoveryCode() {
    useRecoveryCode.value = !useRecoveryCode.value;
    error.value = '';
    await showCodeStep('challenge');
}

async function copyRecoveryCodes() {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
    copied.value = true;
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-background p-4 font-sans">
    <!-- Maskot menumpang di tepi atas kartu; z-10 supaya tangannya terlihat di atas kartu. -->
    <div class="relative z-10 -mb-px w-44 sm:w-52">
      <LoginMascot :state="mascotState" />
    </div>
    <div class="w-full max-w-sm space-y-4 rounded-lg border border-border bg-surface p-6 pt-8 text-primary shadow-card sm:p-8 sm:pt-10">
      <div class="text-center">
        <h1 class="font-heading text-headline-md">{{ title }}</h1>
        <p v-if="step === 'credentials'" class="mt-1 text-body-sm text-secondary">Kelola pesanan, stok, dan operasional Tuffero Patisserie.</p>
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

      <form v-else-if="step === 'setup'" class="space-y-4" @submit.prevent="submitSetup">
        <p class="text-body-sm text-secondary">Pindai kode QR dengan aplikasi authenticator, misalnya Google Authenticator, lalu masukkan 6 digit kodenya.</p>
        <img :src="enrollment.qr_code" alt="Kode QR untuk aplikasi authenticator" width="220" height="220" class="mx-auto">
        <div>
          <p class="text-label-md">Atau masukkan kunci ini secara manual</p>
          <code class="mt-1 block rounded bg-accent-tint-12 px-3 py-2 text-body-sm break-all">{{ enrollment.secret }}</code>
        </div>
        <label class="block text-label-md">
          Kode 6 digit
          <input ref="codeInput" v-model="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md tracking-widest focus:outline-2 focus:outline-primary">
        </label>
        <button :disabled="loading" class="w-full rounded bg-accent px-4 py-2 text-label-md text-primary hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ loading ? 'Memproses...' : 'Aktifkan' }}</button>
      </form>

      <form v-else-if="step === 'challenge'" class="space-y-4" @submit.prevent="submitChallenge">
        <label class="block text-label-md">
          {{ useRecoveryCode ? 'Kode pemulihan' : 'Kode 6 digit dari aplikasi authenticator' }}
          <input
            v-if="useRecoveryCode"
            ref="codeInput"
            v-model="code"
            required
            autocomplete="off"
            maxlength="32"
            class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"
          >
          <input
            v-else
            ref="codeInput"
            v-model="code"
            required
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md tracking-widest focus:outline-2 focus:outline-primary"
          >
        </label>
        <button :disabled="loading" class="w-full rounded bg-accent px-4 py-2 text-label-md text-primary hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ loading ? 'Memproses...' : 'Verifikasi' }}</button>
        <button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="toggleRecoveryCode">
          {{ useRecoveryCode ? 'Pakai kode authenticator' : 'Pakai kode pemulihan' }}
        </button>
      </form>

      <div v-else class="space-y-4">
        <p class="text-body-sm text-secondary">Simpan kode ini di tempat aman. Setiap kode hanya bisa dipakai sekali dan tidak akan ditampilkan lagi.</p>
        <ul class="grid grid-cols-2 gap-2 rounded bg-accent-tint-12 p-3 font-mono text-body-sm">
          <li v-for="recoveryCode in recoveryCodes" :key="recoveryCode">{{ recoveryCode }}</li>
        </ul>
        <button type="button" class="w-full rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="copyRecoveryCodes">
          {{ copied ? 'Tersalin' : 'Salin kode' }}
        </button>
        <button type="button" class="w-full rounded bg-accent px-4 py-2 text-label-md text-primary hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="router.push({ name: 'Dashboard' })">
          Saya sudah menyimpannya
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
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
