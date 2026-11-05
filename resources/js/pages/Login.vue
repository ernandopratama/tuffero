<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
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

const title = computed(() => ({
    credentials: 'Masuk Admin',
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
        router.push({ name: 'Dashboard' });
    }
});

const submitSetup = () => run(async () => {
    recoveryCodes.value = await auth.confirmMfa(code.value);
    enrollment.value = null;
    step.value = 'recovery-codes';
});

const submitChallenge = () => run(async () => {
    await auth.verifyMfa(useRecoveryCode.value ? { recovery_code: code.value } : { code: code.value });
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
  <div class="flex min-h-screen items-center justify-center bg-background p-4 font-sans">
    <div class="w-full max-w-sm space-y-4 rounded-lg border border-border bg-surface p-6 text-primary">
      <h1 class="font-heading text-headline-md">{{ title }}</h1>
      <p v-if="error" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ error }}</p>

      <form v-if="step === 'credentials'" class="space-y-4" @submit.prevent="submitCredentials">
        <label class="block text-label-md">
          Email
          <input v-model="form.email" type="email" required autocomplete="username" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <label class="block text-label-md">
          Password
          <input v-model="form.password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <button :disabled="loading" class="w-full rounded bg-accent px-4 py-2 text-label-md text-primary hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ loading ? 'Memproses...' : 'Masuk' }}</button>
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
