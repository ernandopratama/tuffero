<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import { useAuthStore } from '../../stores/auth';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { ROLE_LABELS } from '../../utils/adminMenu';

const auth = useAuthStore();

const profile = ref(null);
const loading = ref(true);
const loadError = ref('');

const form = reactive({ name: '', email: '', current_password: '' });
const saving = ref(false);
const saved = ref(false);
const formError = ref('');
const fieldErrors = ref({});

const pw = reactive({ current_password: '', password: '', password_confirmation: '' });
const pwSaving = ref(false);
const pwSaved = ref(false);
const pwError = ref('');
const pwFieldErrors = ref({});
const reveal = ref(false);

const emailChanged = computed(() => !!profile.value && form.email.trim() !== profile.value.email);
const dirty = computed(() => !!profile.value && (form.name.trim() !== profile.value.name || emailChanged.value));
const initials = computed(() => (profile.value?.name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
const since = computed(() => (profile.value?.created_at ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(profile.value.created_at)) : ''));

// Syarat password sama dengan yang dicek server (min 12, huruf besar dan kecil, angka, konfirmasi, beda dari yang lama).
const rules = computed(() => [
    { text: 'Minimal 12 karakter', ok: pw.password.length >= 12 },
    { text: 'Huruf besar dan huruf kecil', ok: /[a-z]/.test(pw.password) && /[A-Z]/.test(pw.password) },
    { text: 'Memuat angka', ok: /\d/.test(pw.password) },
    { text: 'Konfirmasi sama', ok: pw.password !== '' && pw.password === pw.password_confirmation },
    { text: 'Berbeda dari password saat ini', ok: pw.password !== '' && pw.password !== pw.current_password },
]);
const pwReady = computed(() => pw.current_password !== '' && rules.value.every((r) => r.ok));

function fill(data) {
    profile.value = data;
    form.name = data.name;
    form.email = data.email;
    form.current_password = '';
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/profile');
        fill(data.data);
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    saved.value = false;
    formError.value = '';
    fieldErrors.value = {};
    try {
        const body = { name: form.name.trim(), email: form.email.trim() };
        if (emailChanged.value) body.current_password = form.current_password;
        const { data } = await api.patch('/admin/profile', body);
        fill(data.data);
        // Nama di bilah atas ikut berubah tanpa memuat ulang halaman.
        auth.setUser(data.data.user);
        saved.value = true;
    } catch (e) {
        const { message, fields } = apiError(e);
        fieldErrors.value = fields;
        formError.value = Object.keys(fields).length ? '' : message;
    } finally {
        saving.value = false;
    }
}

async function changePassword() {
    pwSaving.value = true;
    pwSaved.value = false;
    pwError.value = '';
    pwFieldErrors.value = {};
    try {
        await api.put('/admin/profile/password', { ...pw });
        Object.assign(pw, { current_password: '', password: '', password_confirmation: '' });
        reveal.value = false;
        pwSaved.value = true;
    } catch (e) {
        const { message, fields } = apiError(e);
        pwFieldErrors.value = fields;
        pwError.value = Object.keys(fields).length ? '' : message;
    } finally {
        pwSaving.value = false;
    }
}

const input = 'mt-1 h-11 w-full rounded-xl border bg-surface px-4 text-body-md focus:outline-2 focus:outline-primary';
const primaryButton = 'inline-flex h-10 items-center justify-center rounded-full bg-accent px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100';

onMounted(load);
</script>

<template>
  <div :aria-busy="loading">
    <p v-if="loading" role="status" class="rounded-card border border-border bg-surface p-6 text-secondary shadow-card">Memuat profil...</p>

    <div v-else-if="loadError" role="alert" class="rounded-card border border-border bg-surface p-6 shadow-card">
      <h1 class="font-heading text-headline-sm">Profil belum bisa dimuat</h1>
      <p class="mt-1 text-secondary">{{ loadError }}</p>
      <button type="button" :class="`${primaryButton} mt-4`" @click="load">Coba lagi</button>
    </div>

    <template v-else>
      <header class="flex animate-rise items-center gap-4 rounded-card border border-border bg-surface p-6 shadow-card">
        <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-primary font-heading text-headline-sm text-accent" aria-hidden="true">{{ initials }}</span>
        <div class="min-w-0">
          <h1 class="truncate font-heading text-headline-md">{{ profile.name }}</h1>
          <p class="truncate text-body-sm text-secondary">{{ profile.email }}</p>
          <p class="mt-2 flex flex-wrap items-center gap-2 text-label-md">
            <span class="rounded-full bg-accent-tint-20 px-3 py-1">{{ ROLE_LABELS[profile.role] || 'Tanpa role' }}</span>
            <span v-if="since" class="text-secondary">Akun sejak {{ since }}</span>
          </p>
        </div>
      </header>

      <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="grid content-start gap-4">
          <!-- Data akun -->
          <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 60ms" aria-labelledby="account-title">
            <h2 id="account-title" class="font-heading text-headline-sm">Data akun</h2>
            <form class="mt-4 grid gap-4" novalidate @submit.prevent="save">
              <div>
                <label for="profile-name" class="text-label-md">Nama</label>
                <input id="profile-name" v-model="form.name" required maxlength="255" autocomplete="name" :class="[input, fieldErrors.name ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.name" :aria-describedby="fieldErrors.name ? 'profile-name-error' : undefined" @input="saved = false">
                <p v-if="fieldErrors.name" id="profile-name-error" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.name[0] }}</p>
              </div>

              <div>
                <label for="profile-email" class="text-label-md">Email</label>
                <input id="profile-email" v-model="form.email" type="email" required maxlength="255" autocomplete="email" :class="[input, fieldErrors.email ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.email" aria-describedby="profile-email-hint" @input="saved = false">
                <p id="profile-email-hint" class="mt-1 text-body-sm text-secondary">Dipakai untuk masuk. Mengganti email meminta password saat ini.</p>
                <p v-if="fieldErrors.email" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.email[0] }}</p>
              </div>

              <div v-if="emailChanged" class="animate-pop origin-top">
                <label for="profile-current" class="text-label-md">Password saat ini</label>
                <input id="profile-current" v-model="form.current_password" type="password" required autocomplete="current-password" :class="[input, fieldErrors.current_password ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.current_password">
                <p v-if="fieldErrors.current_password" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.current_password[0] }}</p>
              </div>

              <p v-if="formError" role="alert" class="rounded-xl bg-error-surface px-4 py-2.5 text-body-sm text-on-error-surface">{{ formError }}</p>
              <p v-if="saved" role="status" class="flex items-center gap-2 rounded-xl bg-success-surface px-4 py-2.5 text-body-sm text-success"><AdminIcon name="check" :size="16" />Profil tersimpan.</p>

              <div>
                <button type="submit" :class="primaryButton" :disabled="saving || !dirty || (emailChanged && !form.current_password)">{{ saving ? 'Menyimpan...' : 'Simpan perubahan' }}</button>
              </div>
            </form>
          </section>

          <!-- Ganti password -->
          <section class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 110ms" aria-labelledby="password-title">
            <div class="flex items-start justify-between gap-3">
              <h2 id="password-title" class="font-heading text-headline-sm">Ganti password</h2>
              <button
                type="button"
                class="flex h-9 items-center gap-2 rounded-full border border-border px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
                :aria-pressed="reveal"
                @click="reveal = !reveal"
              >
                <AdminIcon :name="reveal ? 'eye-off' : 'eye'" :size="16" />{{ reveal ? 'Sembunyikan' : 'Tampilkan' }}
              </button>
            </div>

            <form class="mt-4 grid gap-4" novalidate @submit.prevent="changePassword">
              <div>
                <label for="pw-current" class="text-label-md">Password saat ini</label>
                <input id="pw-current" v-model="pw.current_password" :type="reveal ? 'text' : 'password'" required autocomplete="current-password" :class="[input, pwFieldErrors.current_password ? 'border-error' : 'border-secondary']" :aria-invalid="!!pwFieldErrors.current_password" @input="pwSaved = false">
                <p v-if="pwFieldErrors.current_password" role="alert" class="mt-1 text-body-sm text-error">{{ pwFieldErrors.current_password[0] }}</p>
              </div>
              <div>
                <label for="pw-new" class="text-label-md">Password baru</label>
                <input id="pw-new" v-model="pw.password" :type="reveal ? 'text' : 'password'" required autocomplete="new-password" aria-describedby="pw-rules" :class="[input, pwFieldErrors.password ? 'border-error' : 'border-secondary']" :aria-invalid="!!pwFieldErrors.password" @input="pwSaved = false">
                <p v-if="pwFieldErrors.password" role="alert" class="mt-1 text-body-sm text-error">{{ pwFieldErrors.password[0] }}</p>
              </div>
              <div>
                <label for="pw-confirm" class="text-label-md">Ulangi password baru</label>
                <input id="pw-confirm" v-model="pw.password_confirmation" :type="reveal ? 'text' : 'password'" required autocomplete="new-password" :class="[input, 'border-secondary']" @input="pwSaved = false">
              </div>

              <ul id="pw-rules" class="grid gap-1.5 rounded-2xl bg-background px-4 py-3 text-body-sm" aria-label="Syarat password">
                <li v-for="r in rules" :key="r.text" class="flex items-center gap-2" :class="r.ok ? 'text-success' : 'text-secondary'">
                  <AdminIcon :name="r.ok ? 'check' : 'circle'" :size="14" />
                  {{ r.text }}<span class="sr-only"> {{ r.ok ? 'terpenuhi' : 'belum terpenuhi' }}</span>
                </li>
              </ul>

              <p v-if="pwError" role="alert" class="rounded-xl bg-error-surface px-4 py-2.5 text-body-sm text-on-error-surface">{{ pwError }}</p>
              <p v-if="pwSaved" role="status" class="flex items-center gap-2 rounded-xl bg-success-surface px-4 py-2.5 text-body-sm text-success"><AdminIcon name="check" :size="16" />Password diganti. Perangkat lain yang masuk dengan akun ini sudah dikeluarkan.</p>

              <div>
                <button type="submit" :class="primaryButton" :disabled="pwSaving || !pwReady">{{ pwSaving ? 'Menyimpan...' : 'Ganti password' }}</button>
              </div>
            </form>
          </section>
        </div>

        <!-- Keamanan -->
        <section class="animate-rise content-start rounded-card border border-border bg-surface p-6 shadow-card lg:self-start" style="animation-delay: 160ms" aria-labelledby="security-title">
          <h2 id="security-title" class="font-heading text-headline-sm">Keamanan</h2>
          <dl class="mt-4 grid gap-4">
            <div class="flex items-center justify-between gap-3">
              <dt class="text-body-md">Verifikasi dua langkah (MFA)</dt>
              <dd>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-label-md" :class="profile.mfa.enabled ? 'bg-success-surface text-success' : 'bg-accent-tint-20'">
                  <AdminIcon :name="profile.mfa.enabled ? 'check' : 'alert'" :size="14" />{{ profile.mfa.enabled ? 'Aktif' : 'Belum aktif' }}
                </span>
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-body-md">Wajib MFA</dt>
              <dd class="text-label-md">{{ profile.mfa.required ? 'Ya' : 'Tidak' }}</dd>
            </div>
            <div v-if="profile.mfa.enabled" class="flex items-center justify-between gap-3">
              <dt class="text-body-md">Kode pemulihan tersisa</dt>
              <dd class="text-label-md">{{ profile.mfa.recovery_codes_left }}</dd>
            </div>
          </dl>
          <p v-if="profile.mfa.enabled && profile.mfa.recovery_codes_left <= 2" role="note" class="mt-4 rounded-2xl bg-accent-tint-15 px-4 py-3 text-body-sm">Kode pemulihan hampir habis. Minta Super Admin mereset MFA Anda untuk membuat kode baru.</p>
          <p class="mt-4 text-body-sm text-secondary">MFA diaktifkan saat masuk dan hanya Super Admin yang bisa mereset atau mengubah kewajibannya. Ini mencegah akun yang dibajak mematikan pengamannya sendiri.</p>
        </section>
      </div>
    </template>
  </div>
</template>
