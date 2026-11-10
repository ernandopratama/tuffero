<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { ROLE_LABELS } from '../../utils/adminMenu';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const auth = useAuthStore();

const COLUMNS = [
    { key: 'name', label: 'Nama' },
    { key: 'role', label: 'Role' },
    { key: 'status', label: 'Status' },
    { key: 'mfa', label: 'MFA' },
    { key: 'actions', label: 'Aksi' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/users', { filters: { role: '', is_active: '' } });
const roles = ref([]);
const roleOptions = computed(() => roles.value.map((role) => ({ value: role.name, label: ROLE_LABELS[role.name] || role.name })));
const pageError = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const form = reactive({ name: '', email: '', role: '', password: '', is_active: true, mfa_required: true });
const isSelf = computed(() => editing.value?.id === auth.user?.id);

async function loadRoles() {
    try {
        const { data } = await api.get('/admin/roles');
        roles.value = data.data.roles;
    } catch (e) {
        pageError.value = apiError(e).message;
    }
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
            await api.patch(`/admin/users/${editing.value.id}`, body, { toast: 'Akun diperbarui.' });
        } else {
            await api.post('/admin/users', { name: form.name, email: form.email, role: form.role, password: form.password }, { toast: 'Akun dibuat. Pengguna mendaftarkan MFA saat login pertama.' });
        }
        form.password = '';
        dialog.value.close();
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
    pageError.value = '';
    try {
        await api.post(`/admin/users/${user.id}/reset-mfa`);
        pageNotice.value = `MFA ${user.name} direset.`;
        await load();
    } catch (e) {
        pageError.value = apiError(e).message;
    }
}

function mfaLabel(user) {
    if (!user.mfa_required) return 'Tidak wajib';
    return user.mfa_enrolled ? 'Aktif' : 'Belum daftar';
}

onMounted(loadRoles);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Pengguna dan Role" description="Kelola akun staf. Perubahan tercatat di audit log." />
    <div class="ml-auto flex flex-wrap items-center gap-2">
      <TableToolbar v-model="search" placeholder="Cari nama atau email">
        <FilterSelect v-model="filter.role" label="Role" :options="roleOptions" />
        <FilterSelect v-model="filter.is_active" label="Status" :options="{ 1: 'Aktif', 0: 'Nonaktif' }" />
      </TableToolbar>
      <AddButton label="Tambah akun" @click="openForm()" />
    </div>
  </div>

  <p v-if="pageError" role="alert" class="mt-space-md rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ pageError }}</p>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar akun staf"
    empty-text="Tidak ada akun yang cocok."
    min-width="min-w-[760px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-name="{ row }">
      <span class="block text-label-md">{{ row.name }}<template v-if="row.id === auth.user?.id"> (Anda)</template></span>
      <span class="break-all text-secondary">{{ row.email }}</span>
    </template>
    <template #cell-role="{ row }">{{ ROLE_LABELS[row.role] || 'Tanpa role' }}</template>
    <template #cell-status="{ row }">
      <span class="rounded-full px-2 py-0.5 text-label-sm" :class="row.is_active ? 'bg-success-surface text-success' : 'bg-error-surface text-on-error-surface'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
    </template>
    <template #cell-mfa="{ row }">{{ mfaLabel(row) }}</template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction icon="edit" label="Ubah" :subject="row.name" @click="openForm(row)" />
        <IconAction v-if="row.mfa_enrolled && row.id !== auth.user?.id" icon="shield" tone="danger" label="Reset MFA" :subject="row.name" @click="resetMfa(row)" />
      </div>
    </template>
  </DataTable>

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

  <AppModal ref="dialog" :variant="editing ? 'update' : 'create'" :title="editing ? 'Ubah akun' : 'Tambah akun'">
    <form id="user-form" class="space-y-space-md" @submit.prevent="save">
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

    </form>
    <template #footer>
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
      <button form="user-form" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
