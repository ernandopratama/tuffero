<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import LoginMascot from '../components/LoginMascot.vue';
import { contact, loadContact } from '../utils/site';

// Atur ulang password dilakukan Super Admin lewat menu Pengguna; halaman ini hanya menyiapkan pesan WhatsApp.
// Nomor Super Admin dari Pengaturan (security.super_admin_whatsapp), dimuat lewat /settings/public; kosong = tombol kirim dimatikan.
const waDigits = computed(() => contact.superAdmin.replace(/\D/g, ''));
const phoneText = computed(() => (contact.superAdmin.startsWith('+62') ? `0${waDigits.value.slice(2)}`.replace(/(\d{4})(?=\d)/g, '$1-') : contact.superAdmin));
onMounted(loadContact);

const form = reactive({ name: '', account: '', position: '' });
const focused = ref(false);

const message = computed(() => [
    'Halo Super Admin Tuffero, saya lupa password akun dashboard admin dan mohon dibantu mengatur ulang.',
    '',
    `Nama: ${form.name.trim()}`,
    `Username/Email: ${form.account.trim()}`,
    `Jabatan: ${form.position.trim()}`,
    '',
    'Terima kasih.',
].join('\n'));

function send() {
    if (!waDigits.value) return;
    window.open(`https://wa.me/${waDigits.value}?text=${encodeURIComponent(message.value)}`, '_blank', 'noopener,noreferrer');
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-background p-4 font-sans">
    <div class="relative z-10 -mb-px w-44 sm:w-52">
      <LoginMascot :state="focused ? 'username' : 'idle'" />
    </div>
    <div class="w-full max-w-sm space-y-4 rounded-lg border border-border bg-surface p-6 pt-8 text-primary shadow-card sm:p-8 sm:pt-10">
      <div class="text-center">
        <h1 class="font-heading text-headline-md">Lupa Kata Sandi</h1>
        <p class="mt-1 text-body-sm text-secondary">Password diatur ulang oleh Super Admin. Isi data berikut, lalu kirim lewat WhatsApp ke Super Admin<template v-if="phoneText"> di {{ phoneText }}</template>.</p>
      </div>

      <form class="space-y-4" @submit.prevent="send">
        <div>
          <label for="forgot-name" class="text-label-md">Nama lengkap</label>
          <input id="forgot-name" v-model="form.name" required maxlength="80" autocomplete="name" class="mt-1 w-full rounded-md border border-secondary px-3 py-2.5 text-body-md focus:border-accent focus:outline-2 focus:outline-primary" @focus="focused = true" @blur="focused = false">
        </div>
        <div>
          <label for="forgot-account" class="text-label-md">Username atau email</label>
          <input id="forgot-account" v-model="form.account" required maxlength="255" autocomplete="username" spellcheck="false" class="mt-1 w-full rounded-md border border-secondary px-3 py-2.5 text-body-md focus:border-accent focus:outline-2 focus:outline-primary" @focus="focused = true" @blur="focused = false">
        </div>
        <div>
          <label for="forgot-position" class="text-label-md">Jabatan</label>
          <input id="forgot-position" v-model="form.position" required maxlength="60" autocomplete="organization-title" class="mt-1 w-full rounded-md border border-secondary px-3 py-2.5 text-body-md focus:border-accent focus:outline-2 focus:outline-primary" @focus="focused = true" @blur="focused = false">
        </div>

        <div>
          <p class="text-label-md">Pesan yang akan dikirim</p>
          <pre class="mt-1 whitespace-pre-wrap break-words rounded-md bg-accent-tint-12 px-3 py-2 font-sans text-body-sm text-secondary">{{ message }}</pre>
        </div>

        <button :disabled="!waDigits" class="w-full rounded bg-accent px-4 py-2.5 text-label-md text-primary hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">Kirim lewat WhatsApp</button>
        <p v-if="!waDigits" class="text-center text-body-sm text-secondary" role="status">Memuat nomor Super Admin. Bila tidak muncul, hubungi Super Admin lewat jalur lain.</p>
        <p class="text-center text-body-sm text-secondary">Jangan pernah mengirim password lewat WhatsApp.</p>
        <p class="text-center">
          <router-link :to="{ name: 'Login' }" class="text-label-md text-secondary underline hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">Kembali ke halaman masuk</router-link>
        </p>
      </form>
    </div>
  </div>
</template>
