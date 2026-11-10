<script setup>
import { computed, ref } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import AdminIcon from './AdminIcon.vue';
import AppModal from './ui/AppModal.vue';

// Ganti authenticator sendiri (D-090), tiga langkah:
// 1. verifikasi: password dan faktor yang sekarang (kode atau kode pemulihan);
// 2. pasang: pindai QR ke autentikator baru, lalu ketik kodenya;
// 3. simpan kode pemulihan baru (hanya tampil sekali, yang lama tidak berlaku lagi).
// Authenticator lama tetap berlaku sampai langkah 2 berhasil.
const emit = defineEmits(['replaced']);

const dialog = ref(null);
const step = ref('verify');
const busy = ref(false);
const error = ref('');
const fields = ref({});

const password = ref('');
const useRecovery = ref(false);
const code = ref('');
const recoveryCode = ref('');
const setup = ref(null);
const newCode = ref('');
const recoveryCodes = ref([]);
const saved = ref(false);
const copied = ref(false);

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary';

const titles = { verify: 'Ganti autentikator', scan: 'Pasang autentikator baru', codes: 'Simpan kode pemulihan' };
const canVerify = computed(() => password.value !== '' && (useRecovery.value ? recoveryCode.value.trim() !== '' : code.value.trim().length === 6));

function reset() {
    step.value = 'verify';
    error.value = '';
    fields.value = {};
    password.value = '';
    code.value = '';
    recoveryCode.value = '';
    useRecovery.value = false;
    newCode.value = '';
    setup.value = null;
    recoveryCodes.value = [];
    saved.value = false;
    copied.value = false;
}

function open() {
    reset();
    dialog.value.showModal();
}

function fail(e) {
    const { message, fields: errors } = apiError(e);
    fields.value = errors;
    error.value = Object.keys(errors).length ? '' : message;
}

async function start() {
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const body = { current_password: password.value };
        if (useRecovery.value) body.recovery_code = recoveryCode.value.trim();
        else body.code = code.value.trim();
        const { data } = await api.post('/admin/profile/mfa/start', body);
        setup.value = data.data;
        step.value = 'scan';
        password.value = '';
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const { data } = await api.post('/admin/profile/mfa/confirm', { code: newCode.value.trim() });
        recoveryCodes.value = data.data.recovery_codes;
        step.value = 'codes';
        emit('replaced', data.data);
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1600);
    } catch { /* clipboard ditolak: kode tetap bisa disalin manual atau diunduh */ }
}

function download() {
    const blob = new Blob([`Kode pemulihan Tuffero\n\n${recoveryCodes.value.join('\n')}\n`], { type: 'text/plain' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'kode-pemulihan-tuffero.txt';
    link.click();
    URL.revokeObjectURL(link.href);
}

defineExpose({ open });
</script>

<template>
  <AppModal ref="dialog" variant="update" size="lg" :title="titles[step]" :subtitle="`Langkah ${step === 'verify' ? 1 : step === 'scan' ? 2 : 3} dari 3`">
    <!-- 1. Verifikasi -->
    <form v-if="step === 'verify'" id="mfa-reset-verify" class="space-y-space-md" @submit.prevent="start">
      <p class="flex items-start gap-2 rounded-2xl bg-background px-4 py-3 text-body-sm"><AdminIcon name="shield" :size="16" class="mt-0.5 shrink-0" />Buktikan dulu bahwa ini Anda. Autentikator lama tetap berlaku sampai yang baru berhasil dipasang.</p>
      <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>
      <label class="block text-label-md">
        Password saat ini
        <input v-model="password" type="password" required autocomplete="current-password" :class="[field, fields.current_password ? 'border-error' : 'border-secondary']">
        <span v-if="fields.current_password" role="alert" class="mt-1 block text-body-sm font-normal text-error">{{ fields.current_password[0] }}</span>
      </label>
      <label v-if="!useRecovery" class="block text-label-md">
        Kode autentikator saat ini
        <input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" :class="[field, 'font-mono tracking-widest', fields.code ? 'border-error' : 'border-secondary']">
        <span v-if="fields.code" role="alert" class="mt-1 block text-body-sm font-normal text-error">{{ fields.code[0] }}</span>
      </label>
      <label v-else class="block text-label-md">
        Kode pemulihan
        <input v-model="recoveryCode" maxlength="32" placeholder="xxxxx-xxxxx" :class="[field, 'font-mono', fields.code ? 'border-error' : 'border-secondary']">
        <span v-if="fields.code" role="alert" class="mt-1 block text-body-sm font-normal text-error">{{ fields.code[0] }}</span>
      </label>
      <button type="button" :class="`text-label-md text-secondary underline hover:text-primary ${press}`" @click="useRecovery = !useRecovery; fields = {}">{{ useRecovery ? 'Pakai kode autentikator' : 'HP hilang? Pakai kode pemulihan' }}</button>
    </form>

    <!-- 2. Pasang -->
    <form v-else-if="step === 'scan'" id="mfa-reset-confirm" class="space-y-space-md" @submit.prevent="confirm">
      <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>
      <div class="grid gap-space-md sm:grid-cols-[auto_1fr] sm:items-center">
        <img :src="setup.qr_code" alt="Kode QR untuk aplikasi autentikator" width="200" height="200" class="mx-auto rounded-2xl border border-border bg-white p-2">
        <div>
          <p class="text-body-sm">Pindai kode QR dengan aplikasi autentikator (Google Authenticator, Authy, dan sejenisnya). Tidak bisa memindai? Ketik kunci ini:</p>
          <p class="mt-2 break-all rounded-2xl bg-background px-3 py-2 font-mono text-label-md tracking-wider">{{ setup.secret }}</p>
        </div>
      </div>
      <label class="block text-label-md">
        Kode dari autentikator baru
        <input v-model="newCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" :class="[field, 'font-mono tracking-widest', fields.code ? 'border-error' : 'border-secondary']">
        <span v-if="fields.code" role="alert" class="mt-1 block text-body-sm font-normal text-error">{{ fields.code[0] }}</span>
      </label>
      <p class="text-body-sm text-secondary">Setelah berhasil, autentikator lama berhenti berlaku, kode pemulihan lama hangus, dan perangkat lain dikeluarkan.</p>
    </form>

    <!-- 3. Kode pemulihan -->
    <div v-else class="space-y-space-md">
      <p class="flex items-start gap-2 rounded-2xl bg-success-surface px-4 py-3 text-body-sm text-success"><AdminIcon name="check" :size="16" class="mt-0.5 shrink-0" />Autentikator baru aktif. Simpan kode pemulihan di bawah di tempat aman. Kode ini hanya tampil sekali dan dipakai bila HP Anda hilang.</p>
      <ul class="grid grid-cols-2 gap-2" aria-label="Kode pemulihan">
        <li v-for="item in recoveryCodes" :key="item" class="rounded-xl border border-border bg-background px-3 py-2 text-center font-mono text-label-md">{{ item }}</li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="copyCodes"><AdminIcon :name="copied ? 'check' : 'copy'" :size="16" />{{ copied ? 'Tersalin' : 'Salin semua' }}</button>
        <button type="button" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="download"><AdminIcon name="arrow-right" :size="16" class="rotate-90" />Unduh .txt</button>
      </div>
      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="saved ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="saved" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span class="text-label-md">Saya sudah menyimpan kode pemulihan</span>
      </label>
    </div>

    <template #footer>
      <template v-if="step === 'codes'">
        <button type="button" :disabled="!saved" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`" @click="dialog.close()"><AdminIcon name="check" :size="16" />Selesai</button>
      </template>
      <template v-else>
        <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
        <button v-if="step === 'verify'" form="mfa-reset-verify" :disabled="busy || !canVerify" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`">{{ busy ? 'Memeriksa...' : 'Lanjut' }}</button>
        <button v-else form="mfa-reset-confirm" :disabled="busy || newCode.trim().length !== 6" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ busy ? 'Memeriksa...' : 'Aktifkan' }}</button>
      </template>
    </template>
  </AppModal>
</template>
