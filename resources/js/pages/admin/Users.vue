<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { ROLE_LABELS } from '../../utils/adminMenu';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();

const users = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const roles = ref([]);
const search = ref('');
const page = ref(1);
const loading = ref(true);
const loadError = ref('');
const pageNotice = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const form = reactive({ name: '', email: '', role: '', password: '', is_active: true, mfa_required: true });
const isSelf = computed(() => editing.value?.id === auth.user?.id);

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/users', { params: { page: page.value, search: search.value || undefined } });
        users.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function loadRoles() {
    try {
        const { data } = await api.get('/admin/roles');
        roles.value = data.data.roles;
    } catch (e) {
        loadError.value = apiError(e).message;
    }
}

function applySearch() {
    if (page.value === 1) load();
    else page.value = 1;
}

function openForm(user = null) {
    editing.value = user;
    Object.assign(form, {
        name: user?.name || '',
        email: user?.email || '',
        role: user?.role || '',
        password: '',
        is_active: user?.is_active ?? true,
        mfa_required: user?.mfa_required ?? true,
    });
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    try {
        if (editing.value) {
            const body = { name: form.name, email: form.email };
            if (!isSelf.value) Object.assign(body, { role: form.role, is_active: form.is_active, mfa_required: form.mfa_required });
            if (form.password) body.password = form.password;
            await api.patch(`/admin/users/${editing.value.id}`, body);
        } else {
            await api.post('/admin/users', { name: form.name, email: form.email, role: form.role, password: form.password });
        }
        form.password = '';
        dialog.value.close();
        pageNotice.value = editing.value ? 'Akun diperbarui.' : 'Akun dibuat. Pengguna mendaftarkan MFA saat login pertama.';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

async function resetMfa(user) {
    if (!window.confirm(`Reset MFA ${user.name}? Saat login berikutnya, pengguna wajib mendaftarkan authenticator lagi.`)) return;
    pageNotice.value = '';
    try {
        await api.post(`/admin/users/${user.id}/reset-mfa`);
        pageNotice.value = `MFA ${user.name} direset.`;
        await load();
    } catch (e) {
        loadError.value = apiError(e).message;
    }
}

function mfaLabel(user) {
    if (!user.mfa_required) return 'Tidak wajib';
    return user.mfa_enrolled ? 'Aktif' : 'Belum daftar';
}

watch(page, load);
onMounted(() => {
    load();
    loadRoles();
});
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <div>
      <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pengguna dan Role</h1>
      <p class="mt-space-sm text-secondary">Kelola akun staf. Perubahan tercatat di audit log.</p>
    </div>
    <button type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="openForm()">Tambah akun</button>
  </div>

  <form class="mt-space-lg flex max-w-md gap-space-sm" role="search" @submit.prevent="applySearch">
    <label class="w-full text-label-md">
      Cari nama atau email
      <input v-model="search" type="search" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
    </label>
    <button class="self-end rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary">Cari</button>
  </form>

  <p v-if="pageNotice" role="status" class="mt-space-md rounded bg-success-surface px-3 py-2 text-body-sm text-success">{{ pageNotice }}</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pengguna...</p>
  <div v-else-if="loadError" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ loadError }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!users.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Tidak ada akun yang cocok.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[760px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Nama</th>
            <th scope="col" class="px-4 py-3">Role</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3">MFA</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users" :key="user.id" class="border-b border-border last:border-b-0">
            <td class="px-4 py-3">
              <span class="block text-label-md">{{ user.name }}<template v-if="user.id === auth.user?.id"> (Anda)</template></span>
              <span class="text-secondary break-all">{{ user.email }}</span>
            </td>
            <td class="px-4 py-3">{{ ROLE_LABELS[user.role] || 'Tanpa role' }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-label-sm" :class="user.is_active ? 'bg-success-surface text-success' : 'bg-error-surface text-on-error-surface'">{{ user.is_active ? 'Aktif' : 'Nonaktif' }}</span>
            </td>
            <td class="px-4 py-3">{{ mfaLabel(user) }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-right">
              <button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="openForm(user)">Ubah</button>
              <button v-if="user.mfa_enrolled && user.id !== auth.user?.id" type="button" class="ml-space-md text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="resetMfa(user)">Reset MFA</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman pengguna">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} akun)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <section class="mt-space-xl rounded-lg border border-border bg-surface p-space-lg">
    <h2 class="font-heading text-headline-sm">Izin tiap role</h2>
    <p class="mt-1 text-body-sm text-secondary">Hanya untuk dilihat. Izin mengikuti matriks yang disetujui di Decision Log.</p>
    <details v-for="role in roles" :key="role.name" class="mt-space-md border-t border-border pt-space-md">
      <summary class="cursor-pointer text-label-md">{{ ROLE_LABELS[role.name] || role.name }} ({{ role.permissions.length }} izin)</summary>
      <ul class="mt-space-sm flex flex-wrap gap-space-xs">
        <li v-for="permission in role.permissions" :key="permission" class="rounded-full bg-accent-tint-12 px-2 py-0.5 font-mono text-body-sm">{{ permission }}</li>
      </ul>
    </details>
  </section>

  <dialog ref="dialog" aria-labelledby="user-form-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="save">
      <h2 id="user-form-title" class="font-heading text-headline-md">{{ editing ? 'Ubah akun' : 'Tambah akun' }}</h2>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>

      <label class="block text-label-md">
        Nama
        <input v-model="form.name" required autocomplete="off" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.name" class="mt-1 block text-body-sm text-error">{{ fieldErrors.name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Email
        <input v-model="form.email" type="email" required autocomplete="off" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.email" class="mt-1 block text-body-sm text-error">{{ fieldErrors.email[0] }}</span>
      </label>
      <label class="block text-label-md">
        Role
        <select v-model="form.role" required :disabled="isSelf" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-60">
          <option value="" disabled>Pilih role</option>
          <option v-for="role in roles" :key="role.name" :value="role.name">{{ ROLE_LABELS[role.name] || role.name }}</option>
        </select>
        <span v-if="fieldErrors.role" class="mt-1 block text-body-sm text-error">{{ fieldErrors.role[0] }}</span>
      </label>
      <label class="block text-label-md">
        {{ editing ? 'Password baru (kosongkan bila tidak diubah)' : 'Password awal' }}
        <input v-model="form.password" type="password" :required="!editing" autocomplete="new-password" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span class="mt-1 block text-body-sm font-normal text-secondary">Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka. Berikan lewat jalur aman.</span>
        <span v-if="fieldErrors.password" class="mt-1 block text-body-sm text-error">{{ fieldErrors.password[0] }}</span>
      </label>
      <template v-if="editing">
        <label class="flex items-center gap-space-sm text-label-md">
          <input v-model="form.is_active" type="checkbox" :disabled="isSelf" class="size-4 accent-primary">
          Akun aktif
        </label>
        <label class="flex items-center gap-space-sm text-label-md">
          <input v-model="form.mfa_required" type="checkbox" :disabled="isSelf" class="size-4 accent-primary">
          Wajib MFA
        </label>
        <p v-if="isSelf" class="text-body-sm text-secondary">Role, status, dan MFA akun sendiri tidak bisa diubah.</p>
      </template>

      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
        <button :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </dialog>
</template>
