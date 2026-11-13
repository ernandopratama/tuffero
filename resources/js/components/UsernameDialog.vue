<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import OtpInput from './OtpInput.vue';
import AppModal from './ui/AppModal.vue';

// Ganti username sendiri, dua langkah: pilih username baru dan saluran kode (email akun atau WhatsApp terverifikasi),
// lalu masukkan kode 6 digit. Perubahan oleh Super Admin lewat halaman Pengguna tidak memakai dialog ini.
const props = defineProps({ current: { type: String, default: null }, channels: { type: Array, default: () => ['email'] }, email: { type: String, default: '' } });
const emit = defineEmits(['changed']);

const dialog = ref(null);
const codeInput = ref(null);
const step = ref('form');
const username = ref('');
const channel = ref('email');
const code = ref('');
const busy = ref(false);
const error = ref('');
const fields = ref({});
const resendIn = ref(0);
let timer;

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary';
const CHANNEL_LABEL = { email: 'Email', phone: 'WhatsApp' };

const clean = computed(() => username.value.trim().toLowerCase());
const unchanged = computed(() => clean.value === (props.current || ''));

function open() {
    step.value = 'form';
    username.value = props.current || '';
    channel.value = 'email';
    code.value = '';
    error.value = '';
    fields.value = {};
    dialog.value.showModal();
}

function startTimer(seconds) {
    clearInterval(timer);
    resendIn.value = seconds;
    timer = setInterval(() => {
        resendIn.value = Math.max(0, resendIn.value - 1);
        if (resendIn.value === 0) clearInterval(timer);
    }, 1000);
}

async function sendCode() {
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const { data } = await api.post('/admin/profile/username/code', { channel: channel.value });
        startTimer(data.data.resend_in);
        code.value = '';
        step.value = 'code';
        await nextTick();
        codeInput.value?.focus();
    } catch (e) {
        const { message, fields: f } = apiError(e);
        fields.value = f;
        error.value = f.channel?.[0] || f.code?.[0] || message;
    } finally {
        busy.value = false;
    }
}

async function save() {
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const { data } = await api.put('/admin/profile/username', { username: clean.value || null, channel: channel.value, code: code.value }, { toast: 'Username tersimpan.' });
        emit('changed', data.data);
        dialog.value.close();
    } catch (e) {
        const { message, fields: f } = apiError(e);
        fields.value = f;
        // Username yang ditolak (dipakai orang lain, format salah) dikoreksi di langkah pertama.
        if (f.username) step.value = 'form';
        error.value = f.username ? '' : (f.code?.[0] || message);
    } finally {
        busy.value = false;
    }
}

onBeforeUnmount(() => clearInterval(timer));
defineExpose({ open });
</script>

<template>
  <AppModal ref="dialog" variant="update" size="md" title="Ubah username" :subtitle="step === 'code' ? `Kode dikirim lewat ${CHANNEL_LABEL[channel]}` : 'Dipakai untuk masuk selain email'">
    <form v-if="step === 'form'" id="username-form" class="space-y-space-md" @submit.prevent="sendCode">
      <label class="block text-label-md">
        Username baru
        <input v-model="username" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false" :class="[field, fields.username ? 'border-error' : 'border-secondary']" :aria-invalid="!!fields.username">
        <span class="mt-1 block text-body-sm font-normal text-secondary">Huruf kecil, angka, titik, garis bawah, atau strip; 3 sampai 30 karakter. Kosongkan untuk masuk hanya dengan email.</span>
        <span v-if="fields.username" role="alert" class="mt-1 block text-body-sm text-error">{{ fields.username[0] }}</span>
      </label>
      <fieldset>
        <legend class="text-label-md">Kirim kode konfirmasi lewat</legend>
        <div class="mt-1 flex flex-wrap gap-2">
          <label v-for="item in channels" :key="item" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full border px-4 text-label-md" :class="channel === item ? 'border-primary bg-accent-tint-15' : 'border-border'">
            <input v-model="channel" type="radio" name="username-channel" :value="item" class="accent-primary">
            {{ CHANNEL_LABEL[item] }}
          </label>
        </div>
        <p class="mt-1 text-body-sm text-secondary">{{ channel === 'email' ? `Dikirim ke ${email}.` : 'Dikirim ke nomor WhatsApp akun Anda.' }}<template v-if="channels.length === 1"> WhatsApp tersedia bila nomor HP akun sudah terverifikasi.</template></p>
      </fieldset>
      <p v-if="error" role="alert" class="text-body-sm text-error">{{ error }}</p>
    </form>

    <form v-else id="username-code-form" class="space-y-space-md" @submit.prevent="save">
      <p class="text-body-sm">Masukkan kode 6 digit untuk mengubah username menjadi <strong class="break-all">{{ clean || '(kosong)' }}</strong>. Berlaku 5 menit.</p>
      <OtpInput ref="codeInput" v-model="code" label="Kode 6 digit" :disabled="busy" :invalid="!!error" />
      <p v-if="error" role="alert" class="text-body-sm text-error">{{ error }}</p>
      <button type="button" :disabled="busy || resendIn > 0" :class="`inline-flex min-h-10 items-center rounded-full px-3 text-body-sm underline disabled:no-underline ${press}`" @click="sendCode">{{ resendIn > 0 ? `Kirim ulang kode (${resendIn} dtk)` : 'Kirim ulang kode' }}</button>
    </form>

    <template #footer>
      <button v-if="step === 'code'" type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="step = 'form'">Kembali</button>
      <button v-else type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button v-if="step === 'form'" form="username-form" :disabled="busy || unchanged" :class="`inline-flex min-h-10 items-center rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`">{{ busy ? 'Mengirim...' : 'Kirim kode' }}</button>
      <button v-else form="username-code-form" :disabled="busy || code.length < 6" :class="`inline-flex min-h-10 items-center rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`">{{ busy ? 'Menyimpan...' : 'Simpan username' }}</button>
    </template>
  </AppModal>
</template>
