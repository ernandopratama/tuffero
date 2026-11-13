<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AvatarEditor from '../../components/ui/AvatarEditor.vue';
import ActivityCard from '../../components/ActivityCard.vue';
import MfaResetDialog from '../../components/MfaResetDialog.vue';
import UsernameDialog from '../../components/UsernameDialog.vue';
import NotificationPrefsCard from '../../components/NotificationPrefsCard.vue';
import SessionsCard from '../../components/SessionsCard.vue';
import { useAuthStore } from '../../stores/auth';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { ROLE_LABELS } from '../../utils/adminMenu';

const auth = useAuthStore();

const profile = ref(null);
const loading = ref(true);
const loadError = ref('');

const form = reactive({ name: '', email: '', phone: '', current_password: '', mfa_code: '' });
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});

const pw = reactive({ current_password: '', password: '', password_confirmation: '' });
const pwSaving = ref(false);
const pwSaved = ref(false);
const pwError = ref('');
const pwFieldErrors = ref({});
const reveal = ref(false);

const emailChanged = computed(() => !!profile.value && form.email.trim() !== profile.value.email);
// Nomor HP diseragamkan seperti di server (+628xxx) supaya "0812..." dan "+62812..." tidak dianggap berbeda.
const normalizePhone = (value) => {
    const digits = String(value || '').replace(/\D/g, '').replace(/^(62|0)/, '');
    return digits ? `+62${digits}` : '';
};
const phoneChanged = computed(() => !!profile.value && normalizePhone(form.phone) !== (profile.value.phone || ''));
// Email dan nomor HP adalah jalur pemulihan akun: butuh password saat ini, dan kode autentikator bila MFA aktif.
const sensitiveChanged = computed(() => emailChanged.value || phoneChanged.value);
const needsMfa = computed(() => sensitiveChanged.value && !!profile.value?.mfa?.enabled);
const dirty = computed(() => !!profile.value && (form.name.trim() !== profile.value.name || sensitiveChanged.value));
const saveBlocked = computed(() => !dirty.value || (sensitiveChanged.value && !form.current_password) || (needsMfa.value && form.mfa_code.trim().length < 6));
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

const usernameDialog = ref(null);

function fill(data) {
    // Respons simpan profil tidak memuat saluran kode; WhatsApp hanya untuk nomor yang sudah terverifikasi.
    profile.value = { ...data, otp_channels: data.otp_channels || (data.phone && data.phone_verified ? ['email', 'phone'] : ['email']) };
    form.name = data.name;
    form.email = data.email;
    form.phone = data.phone || '';
    form.current_password = '';
    form.mfa_code = '';
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

const mfaDialog = ref(null);
// Autentikator diganti: sisa kode pemulihan di kartu Keamanan ikut diperbarui.
function onMfaReplaced(data) {
    profile.value = { ...profile.value, mfa: data.mfa };
}

// Foto berubah: bilah atas ikut berubah tanpa memuat ulang halaman.
function onAvatar(data) {
    profile.value = { ...profile.value, ...data };
    if (data.user) auth.setUser(data.user);
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    try {
        const body = { name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim() || null };
        if (sensitiveChanged.value) body.current_password = form.current_password;
        if (needsMfa.value) body.mfa_code = form.mfa_code.trim();
        const { data } = await api.patch('/admin/profile', body, { toast: 'Profil tersimpan.' });
        fill(data.data);
        // Nama di bilah atas ikut berubah tanpa memuat ulang halaman.
        auth.setUser(data.data.user);
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
        <AvatarEditor :src="profile.avatar_url" :name="profile.name" size="size-20" @changed="onAvatar" />
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
                <input id="profile-name" v-model="form.name" required maxlength="255" autocomplete="name" :class="[input, fieldErrors.name ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.name" :aria-describedby="fieldErrors.name ? 'profile-name-error' : undefined">
                <p v-if="fieldErrors.name" id="profile-name-error" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.name[0] }}</p>
              </div>

              <div>
                <label for="profile-email" class="text-label-md">Email</label>
                <input id="profile-email" v-model="form.email" type="email" required maxlength="255" autocomplete="email" :class="[input, fieldErrors.email ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.email" aria-describedby="profile-email-hint">
                <p id="profile-email-hint" class="mt-1 text-body-sm text-secondary">Dipakai untuk masuk dan pemulihan akun.</p>
                <p v-if="fieldErrors.email" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.email[0] }}</p>
              </div>

              <div>
                <label for="profile-phone" class="text-label-md">Nomor HP <span class="font-normal text-secondary">(opsional)</span></label>
                <input id="profile-phone" v-model="form.phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081234567890" :class="[input, fieldErrors.phone ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.phone" aria-describedby="profile-phone-hint">
                <p id="profile-phone-hint" class="mt-1 text-body-sm text-secondary">Nomor seluler Indonesia. Disimpan sebagai +628xxx dan belum terverifikasi.</p>
                <p v-if="fieldErrors.phone" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.phone[0] }}</p>
              </div>

              <div>
                <span class="text-label-md">Username <span class="font-normal text-secondary">(opsional)</span></span>
                <div class="mt-1 flex items-center justify-between gap-3 rounded-xl border border-border bg-background/60 px-4 py-2">
                  <span class="min-w-0 truncate" :class="profile.username ? 'font-mono text-body-md' : 'text-body-sm text-secondary'">{{ profile.username || 'Belum diatur, masuk memakai email' }}</span>
                  <button type="button" class="inline-flex min-h-9 shrink-0 items-center rounded-full border border-secondary px-4 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary active:scale-[0.97]" @click="usernameDialog.open()">{{ profile.username ? 'Ubah' : 'Atur' }}</button>
                </div>
                <p class="mt-1 text-body-sm text-secondary">Bisa dipakai masuk selain email. Mengubahnya perlu kode konfirmasi lewat email atau WhatsApp.</p>
              </div>

              <!-- Mengganti email atau nomor HP: konfirmasi identitas dulu -->
              <div v-if="sensitiveChanged" class="animate-pop origin-top grid gap-4 rounded-2xl border border-accent bg-accent-tint-12 p-4">
                <p class="flex items-start gap-2 text-body-sm"><AdminIcon name="shield" :size="16" class="mt-0.5 shrink-0" />Email dan nomor HP dipakai untuk memulihkan akun, jadi perubahannya perlu dikonfirmasi{{ needsMfa ? ' dengan password dan kode autentikator' : ' dengan password saat ini' }}.</p>
                <div>
                  <label for="profile-current" class="text-label-md">Password saat ini</label>
                  <input id="profile-current" v-model="form.current_password" type="password" required autocomplete="current-password" :class="[input, fieldErrors.current_password ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.current_password">
                  <p v-if="fieldErrors.current_password" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.current_password[0] }}</p>
                </div>
                <div v-if="needsMfa">
                  <label for="profile-mfa" class="text-label-md">Kode autentikator</label>
                  <input id="profile-mfa" v-model="form.mfa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" :class="[input, 'font-mono tracking-widest', fieldErrors.mfa_code ? 'border-error' : 'border-secondary']" :aria-invalid="!!fieldErrors.mfa_code" aria-describedby="profile-mfa-hint">
                  <p id="profile-mfa-hint" class="mt-1 text-body-sm text-secondary">6 digit dari aplikasi autentikator Anda. Satu kode hanya bisa dipakai sekali.</p>
                  <p v-if="fieldErrors.mfa_code" role="alert" class="mt-1 text-body-sm text-error">{{ fieldErrors.mfa_code[0] }}</p>
                </div>
              </div>

              <p v-if="formError" role="alert" class="rounded-xl bg-error-surface px-4 py-2.5 text-body-sm text-on-error-surface">{{ formError }}</p>

              <div>
                <button type="submit" :class="primaryButton" :disabled="saving || saveBlocked">{{ saving ? 'Menyimpan...' : 'Simpan perubahan' }}</button>
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
          <p class="mt-4 text-body-sm text-secondary">MFA diaktifkan saat masuk. Mengganti autentikator butuh password dan kode yang sekarang, jadi akun yang dibajak tidak bisa mengganti pengamannya sendiri. Hanya Super Admin yang bisa mereset MFA yang hilang atau mengubah kewajibannya.</p>
          <button v-if="profile.mfa.enabled" type="button" :class="`mt-4 inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]'}`" @click="mfaDialog.open()"><AdminIcon name="shield" :size="16" />Ganti autentikator</button>
        </section>
      </div>

      <SessionsCard class="mt-4" />
      <NotificationPrefsCard class="mt-4" />
      <ActivityCard class="mt-4" />
      <MfaResetDialog ref="mfaDialog" @replaced="onMfaReplaced" />
      <UsernameDialog ref="usernameDialog" :current="profile.username" :channels="profile.otp_channels" :email="profile.email" @changed="fill" />
    </template>
  </div>
</template>
