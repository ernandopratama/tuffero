<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

// Role dan izin (D-129), hanya Super Admin. Server yang menjaga aturan: role Super Admin terkunci, izin khusus Super Admin tidak bisa
// diberikan ke role lain, role bawaan dan izin bawaan tidak bisa dihapus. Setiap perubahan dikonfirmasi dengan kata sandi.
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary';
const ghostBtn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const primaryBtn = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-primary px-4 text-label-md text-surface hover:bg-primary/90 ${press}`;
const dangerBtn = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`;

const auth = useAuthStore();
const roles = ref([]);
const permissions = ref([]);
const loading = ref(true);
const loadError = ref('');
const tab = ref('roles');
const selectedId = ref(null);
const draft = ref(new Set());
const query = ref('');

async function load() {
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/access');
        roles.value = data.data.roles;
        permissions.value = data.data.permissions;
        const keep = roles.value.find((r) => r.id === selectedId.value) || roles.value.find((r) => !r.locked) || roles.value[0];
        select(keep);
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

const selected = computed(() => roles.value.find((r) => r.id === selectedId.value) || null);
function select(role) {
    selectedId.value = role?.id ?? null;
    draft.value = new Set(role?.permissions || []);
}

const byName = computed(() => Object.fromEntries(permissions.value.map((p) => [p.name, p])));
const labelOf = (name) => byName.value[name]?.label || name;
const groups = computed(() => {
    const q = query.value.trim().toLowerCase();
    const map = new Map();
    for (const p of permissions.value) {
        if (q && !`${p.label} ${p.name}`.toLowerCase().includes(q)) continue;
        if (!map.has(p.group)) map.set(p.group, []);
        map.get(p.group).push(p);
    }
    return [...map.entries()].map(([name, items]) => ({ name, items }));
});

const added = computed(() => [...draft.value].filter((n) => !selected.value?.permissions.includes(n)));
const removed = computed(() => (selected.value?.permissions || []).filter((n) => !draft.value.has(n)));
const dirty = computed(() => added.value.length > 0 || removed.value.length > 0);
const editable = (p) => !selected.value?.locked && !p.super_admin_only;

function toggle(name) {
    const next = new Set(draft.value);
    next.has(name) ? next.delete(name) : next.add(name);
    draft.value = next;
}
function toggleGroup(group, on) {
    const next = new Set(draft.value);
    group.items.filter(editable).forEach((p) => (on ? next.add(p.name) : next.delete(p.name)));
    draft.value = next;
}
const groupCount = (group) => group.items.filter((p) => draft.value.has(p.name)).length;
const groupAll = (group) => group.items.filter(editable).every((p) => draft.value.has(p.name));

// Satu dialog konfirmasi untuk semua perubahan: menampilkan ringkasan, meminta kata sandi, lalu menjalankan permintaan.
const confirmDialog = ref(null);
const pending = ref(null);
const password = ref('');
const confirmError = ref('');
const busy = ref(false);

async function ask(action) {
    pending.value = action;
    password.value = '';
    confirmError.value = '';
    formDialog.value?.close();
    confirmDialog.value.showModal();
    await nextTick();
    document.getElementById('access-password')?.focus();
}

async function confirm() {
    if (!password.value) {
        confirmError.value = 'Masukkan kata sandi Anda untuk mengonfirmasi.';
        return;
    }
    busy.value = true;
    confirmError.value = '';
    try {
        await pending.value.run(password.value);
        confirmDialog.value.close();
        if (pending.value.afterSelect) selectedId.value = pending.value.afterSelect;
        await load();
        // Izin akun sendiri bisa ikut berubah (mis. role kustom); muat ulang supaya menu sesuai.
        await auth.loadUser();
    } catch (e) {
        const { message, fields } = apiError(e);
        // Kode atau nama ditolak server: kembali ke form dengan isian tetap utuh.
        const formField = fields?.name?.[0] || fields?.label?.[0];
        if (formField && formDialog.value) {
            confirmDialog.value.close();
            formError.value = formField;
            formDialog.value.showModal();
            return;
        }
        confirmError.value = fields?.password?.[0] || fields?.permissions?.[0] || fields?.role?.[0] || fields?.permission?.[0] || message;
    } finally {
        busy.value = false;
    }
}

function saveRole() {
    const role = selected.value;
    ask({
        title: `Simpan izin ${role.label}`,
        variant: 'update',
        lines: [...added.value.map((n) => ['add', labelOf(n), n]), ...removed.value.map((n) => ['remove', labelOf(n), n])],
        note: role.users_count ? `Berlaku langsung untuk ${role.users_count} akun dengan role ini.` : 'Belum ada akun dengan role ini.',
        // Hanya selisihnya yang dikirim, supaya perubahan Super Admin lain di role yang sama tidak tertimpa.
        run: (pw) => api.patch(`/admin/roles/${role.id}`, { password: pw, add: added.value, remove: removed.value }, { toast: 'Izin role disimpan.' }),
    });
}

function deleteRole() {
    const role = selected.value;
    ask({
        title: `Hapus role ${role.label}`,
        variant: 'delete',
        lines: [],
        note: 'Role kustom ini dihapus permanen beserta daftar izinnya. Tindakan ini tidak bisa dibatalkan.',
        run: (pw) => api.delete(`/admin/roles/${role.id}`, { data: { password: pw }, toast: 'Role dihapus.' }),
    });
}

// Form role baru dan izin kustom (tambah atau ubah). Kata sandi diminta di langkah konfirmasi.
const formDialog = ref(null);
const form = reactive({ kind: 'role', id: null, name: '', label: '', description: '', copyFrom: '' });
const formError = ref('');

function openRoleForm() {
    Object.assign(form, { kind: 'role', id: null, name: '', label: '', description: '', copyFrom: '' });
    formError.value = '';
    formDialog.value.showModal();
}
function openPermissionForm(permission = null) {
    Object.assign(form, { kind: 'permission', id: permission?.id ?? null, name: permission?.name ?? '', label: permission?.label ?? '', description: permission?.description ?? '', copyFrom: '' });
    formError.value = '';
    formDialog.value.showModal();
}
const copyOptions = computed(() => roles.value.filter((r) => !r.locked));

function submitForm() {
    formError.value = '';
    const label = form.label.trim();
    if (!label || (!form.id && !form.name.trim())) {
        formError.value = 'Kode dan nama tampilan wajib diisi.';
        return;
    }
    if (form.kind === 'role') {
        const base = roles.value.find((r) => r.id === Number(form.copyFrom));
        const perms = (base?.permissions || []).filter((n) => !byName.value[n]?.super_admin_only);
        const name = form.name.trim();
        ask({
            title: `Buat role ${label}`,
            variant: 'create',
            lines: perms.map((n) => ['add', labelOf(n), n]),
            note: base ? `Izin awal disalin dari ${base.label}.` : 'Role dibuat tanpa izin. Atur izinnya setelah dibuat.',
            run: async (pw) => {
                const { data } = await api.post('/admin/roles', { password: pw, name, label, permissions: perms }, { toast: 'Role dibuat.' });
                pending.value.afterSelect = data.data.id;
            },
        });
        return;
    }
    const body = { label, description: form.description.trim() || null };
    ask(form.id
        ? { title: `Ubah izin ${label}`, variant: 'update', lines: [], note: 'Hanya nama tampilan dan keterangan yang berubah. Kode izin tetap.', run: (pw) => api.patch(`/admin/permissions/${form.id}`, { password: pw, ...body }, { toast: 'Izin disimpan.' }) }
        : { title: `Buat izin ${label}`, variant: 'create', lines: [], note: 'Izin kustom otomatis dimiliki Super Admin. Izin ini belum membuka fitur apa pun sampai ada kode yang memeriksanya.', run: (pw) => api.post('/admin/permissions', { password: pw, name: form.name.trim(), ...body }, { toast: 'Izin dibuat.' }) });
}

function deletePermission(permission) {
    const holders = roles.value.filter((r) => r.permissions.includes(permission.name)).map((r) => r.label);
    ask({
        title: `Hapus izin ${permission.label}`,
        variant: 'delete',
        lines: [],
        note: `Izin kustom ini dicabut dari ${holders.length} role (${holders.join(', ') || '-'}) dan dihapus permanen. Tindakan ini tidak bisa dibatalkan.`,
        run: (pw) => api.delete(`/admin/permissions/${permission.id}`, { data: { password: pw }, toast: 'Izin dihapus.' }),
    });
}

const holdersOf = (name) => roles.value.filter((r) => r.permissions.includes(name)).length;
const TABS = [{ key: 'roles', label: 'Role' }, { key: 'permissions', label: 'Izin' }];

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Role dan Izin" icon="shield" description="Atur izin tiap role, buat role atau izin kustom. Setiap perubahan dicatat di audit log dan dikabarkan ke Super Admin lain." />
    <div class="relative grid grid-cols-2 rounded-full bg-secondary-tint-12 p-1" role="tablist" aria-label="Role dan izin">
      <span class="pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-surface shadow-sm transition-transform duration-200 ease-out-strong motion-reduce:transition-none" :style="{ transform: tab === 'permissions' ? 'translateX(100%)' : 'translateX(0)' }" aria-hidden="true"></span>
      <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="relative min-h-10 min-w-24 rounded-full px-4 text-label-md transition-colors duration-[160ms] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :class="tab === t.key ? 'text-primary' : 'text-secondary hover:text-primary'" @click="tab = t.key">{{ t.label }}</button>
    </div>
  </div>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat role dan izin...</p>
  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    {{ loadError }}<button type="button" :class="ghostBtn" @click="load">Coba lagi</button>
  </div>

  <!-- Tab Role: daftar role di kiri, matriks izin role terpilih di kanan -->
  <div v-else-if="tab === 'roles'" class="mt-space-lg grid gap-space-lg lg:grid-cols-[300px_minmax(0,1fr)]">
    <aside class="space-y-2" aria-label="Daftar role">
      <button v-for="role in roles" :key="role.id" type="button" class="flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition-[transform,background-color,border-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]" :class="role.id === selectedId ? 'border-primary bg-surface shadow-card' : 'border-border bg-surface/60 hover:bg-surface'" :aria-current="role.id === selectedId ? 'true' : undefined" @click="select(role)">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full" :class="role.locked ? 'bg-primary text-surface' : 'bg-accent-tint-20 text-primary'"><AdminIcon :name="role.locked ? 'shield' : 'users'" :size="18" /></span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2"><span class="truncate text-label-md">{{ role.label }}</span><span v-if="!role.system" class="rounded-full bg-info-surface px-2 py-0.5 text-label-sm text-info">Kustom</span></span>
          <span class="block truncate text-body-sm text-secondary">{{ role.users_count }} akun · {{ role.locked ? 'semua izin' : `${role.permissions.length} izin` }}</span>
        </span>
      </button>
      <button type="button" :class="`${ghostBtn} w-full justify-center`" @click="openRoleForm"><AdminIcon name="plus" :size="16" />Tambah role</button>
    </aside>

    <section v-if="selected" class="min-w-0 rounded-card border border-border bg-surface p-5 shadow-card sm:p-6" aria-labelledby="role-title">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 id="role-title" class="font-heading text-headline-sm">{{ selected.label }}</h2>
          <p class="text-body-sm text-secondary"><span class="font-mono">{{ selected.name }}</span> · {{ selected.system ? 'Role bawaan' : 'Role kustom' }} · {{ selected.users_count }} akun</p>
        </div>
        <button v-if="!selected.system" type="button" :disabled="selected.users_count > 0" :title="selected.users_count > 0 ? 'Pindahkan akun ke role lain dulu' : undefined" :class="`${ghostBtn} border-error text-error hover:bg-error-surface`" @click="deleteRole"><AdminIcon name="trash" :size="16" />Hapus role</button>
      </div>

      <p v-if="selected.locked" class="mt-space-md flex items-start gap-2 rounded-2xl bg-secondary-tint-12 p-3 text-body-sm"><AdminIcon name="shield" :size="18" />Super Admin selalu memegang semua izin, termasuk izin kustom baru. Role ini tidak bisa diubah supaya akses pengelola tidak pernah terkunci.</p>
      <p v-else-if="selected.users_count === 0 && !selected.system" class="mt-space-md text-body-sm text-secondary">Belum ada akun dengan role ini. Tetapkan role lewat halaman Pengguna.</p>

      <label class="mt-space-md block text-label-md">
        <span class="sr-only">Cari izin</span>
        <input v-model="query" type="search" placeholder="Cari izin (nama atau kode)" :class="field">
      </label>

      <div class="mt-space-md space-y-space-md">
        <fieldset v-for="group in groups" :key="group.name" class="rounded-2xl border border-border p-4">
          <legend class="flex w-full flex-wrap items-center justify-between gap-2 px-1">
            <span class="text-label-md">{{ group.name }} <span class="text-secondary">({{ groupCount(group) }}/{{ group.items.length }})</span></span>
          </legend>
          <div v-if="!selected.locked && group.items.some(editable)" class="-mt-1 mb-2 flex justify-end">
            <button type="button" class="min-h-8 rounded-full px-3 text-label-sm underline underline-offset-2 hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="toggleGroup(group, !groupAll(group))">{{ groupAll(group) ? 'Kosongkan kelompok' : 'Pilih semua' }}</button>
          </div>
          <ul class="grid gap-1 sm:grid-cols-2">
            <li v-for="p in group.items" :key="p.name">
              <label class="flex min-h-11 items-start gap-3 rounded-xl px-2 py-2 transition-colors duration-150" :class="editable(p) ? 'cursor-pointer hover:bg-background' : 'cursor-not-allowed opacity-70'">
                <input type="checkbox" class="mt-0.5 size-5 shrink-0 accent-primary" :checked="selected.locked || draft.has(p.name)" :disabled="!editable(p)" @change="toggle(p.name)">
                <span class="min-w-0">
                  <span class="block text-body-sm">{{ p.label }}
                    <span v-if="p.super_admin_only" class="ml-1 inline-flex items-center gap-1 rounded-full bg-primary px-2 py-0.5 align-middle text-label-sm text-surface"><AdminIcon name="shield" :size="12" />Khusus Super Admin</span>
                    <span v-else-if="!p.system" class="ml-1 rounded-full bg-info-surface px-2 py-0.5 align-middle text-label-sm text-info">Kustom</span>
                  </span>
                  <span class="block truncate font-mono text-label-sm text-secondary">{{ p.name }}</span>
                </span>
              </label>
            </li>
          </ul>
        </fieldset>
        <p v-if="!groups.length" class="text-secondary">Tidak ada izin yang cocok dengan pencarian.</p>
      </div>

      <!-- Bilah simpan menempel di bawah selama ada perubahan -->
      <div v-if="dirty" class="sticky bottom-3 mt-space-md flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary bg-surface p-3 shadow-float motion-safe:animate-rise" role="status">
        <span class="text-label-md"><span class="text-success">+{{ added.length }}</span> / <span class="text-error">-{{ removed.length }}</span> izin belum disimpan</span>
        <span class="flex gap-2">
          <button type="button" :class="ghostBtn" @click="select(selected)">Batalkan</button>
          <button type="button" :class="primaryBtn" @click="saveRole"><AdminIcon name="check" :size="16" />Simpan perubahan</button>
        </span>
      </div>
    </section>
  </div>

  <!-- Tab Izin: semua izin; izin kustom bisa diubah dan dihapus -->
  <section v-else class="mt-space-lg rounded-card border border-border bg-surface p-5 shadow-card sm:p-6" aria-labelledby="perm-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 id="perm-title" class="font-heading text-headline-sm">Daftar izin</h2>
        <p class="mt-1 max-w-2xl text-body-sm text-secondary">Izin bawaan dipakai fitur aplikasi, jadi tidak bisa diubah atau dihapus. Izin kustom bisa dibuat untuk penanda atau fitur berikutnya, tetapi belum membuka halaman apa pun sampai ada kode yang memeriksanya.</p>
      </div>
      <button type="button" :class="primaryBtn" @click="openPermissionForm()"><AdminIcon name="plus" :size="16" />Tambah izin</button>
    </div>
    <label class="mt-space-md block text-label-md"><span class="sr-only">Cari izin</span><input v-model="query" type="search" placeholder="Cari izin (nama atau kode)" :class="field"></label>
    <div v-for="group in groups" :key="group.name" class="mt-space-md">
      <h3 class="text-label-md text-secondary">{{ group.name }}</h3>
      <ul class="mt-1 divide-y divide-border rounded-2xl border border-border">
        <li v-for="p in group.items" :key="p.name" class="flex flex-wrap items-center gap-3 px-3 py-2.5">
          <span class="min-w-0 flex-1">
            <span class="block text-body-sm">{{ p.label }}</span>
            <span class="block truncate font-mono text-label-sm text-secondary">{{ p.name }}<template v-if="p.description"> · {{ p.description }}</template></span>
          </span>
          <span class="whitespace-nowrap text-label-sm text-secondary">{{ holdersOf(p.name) }} role</span>
          <span v-if="p.super_admin_only" class="rounded-full bg-primary px-2 py-0.5 text-label-sm text-surface">Khusus Super Admin</span>
          <span v-else class="rounded-full px-2 py-0.5 text-label-sm" :class="p.system ? 'bg-secondary-tint-12' : 'bg-info-surface text-info'">{{ p.system ? 'Bawaan' : 'Kustom' }}</span>
          <span v-if="!p.system" class="flex gap-1">
            <IconAction icon="edit" label="Ubah izin" :subject="p.label" @click="openPermissionForm(p)" />
            <IconAction icon="trash" tone="danger" label="Hapus izin" :subject="p.label" @click="deletePermission(p)" />
          </span>
        </li>
      </ul>
    </div>
    <p v-if="!groups.length" class="mt-space-md text-secondary">Tidak ada izin yang cocok dengan pencarian.</p>
  </section>

  <AppModal ref="formDialog" :variant="form.id ? 'update' : 'create'" size="md" :title="form.kind === 'role' ? 'Tambah role' : (form.id ? 'Ubah izin kustom' : 'Tambah izin kustom')">
    <form id="access-form" class="space-y-space-md" @submit.prevent="submitForm">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">Nama tampilan<input v-model="form.label" required maxlength="100" :placeholder="form.kind === 'role' ? 'Contoh: Admin Toko Cabang' : 'Contoh: Ekspor laporan'" :class="field"></label>
      <label class="block text-label-md">Kode
        <input v-model="form.name" :disabled="!!form.id" required maxlength="100" autocapitalize="none" spellcheck="false" :placeholder="form.kind === 'role' ? 'admin-cabang' : 'laporan.export'" :class="`${field} font-mono disabled:opacity-60`">
        <span class="mt-1 block text-body-sm font-normal text-secondary">{{ form.kind === 'role' ? 'Huruf kecil, angka, dan tanda hubung. Tidak bisa diubah setelah dibuat.' : 'Format kelompok.aksi, huruf kecil. Tidak bisa diubah setelah dibuat.' }}</span>
      </label>
      <label v-if="form.kind === 'role'" class="block text-label-md">Salin izin awal dari
        <select v-model="form.copyFrom" :class="field"><option value="">Tanpa izin</option><option v-for="r in copyOptions" :key="r.id" :value="r.id">{{ r.label }}</option></select>
      </label>
      <label v-else class="block text-label-md">Keterangan (opsional)<textarea v-model="form.description" rows="2" maxlength="255" :class="`${field} resize-y`"></textarea></label>
    </form>
    <template #footer>
      <button type="button" :class="ghostBtn" @click="formDialog.close()">Batal</button>
      <button type="submit" form="access-form" :class="primaryBtn">Lanjut</button>
    </template>
  </AppModal>

  <AppModal ref="confirmDialog" :variant="pending?.variant || 'update'" size="md" :title="pending?.title || 'Konfirmasi'" subtitle="Konfirmasi dengan kata sandi">
    <form id="access-confirm" class="space-y-space-md" @submit.prevent="confirm">
      <ul v-if="pending?.lines.length" class="max-h-56 space-y-1 overflow-y-auto rounded-2xl border border-border p-3 text-body-sm">
        <li v-for="[kind, label, name] in pending.lines" :key="kind + name" class="flex items-start gap-2">
          <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-label-sm" :class="kind === 'add' ? 'bg-success-surface text-success' : 'bg-error-surface text-on-error-surface'" aria-hidden="true">{{ kind === 'add' ? '+' : '−' }}</span>
          <span><span class="sr-only">{{ kind === 'add' ? 'Ditambah:' : 'Dicabut:' }}</span>{{ label }} <span class="font-mono text-label-sm text-secondary">{{ name }}</span></span>
        </li>
      </ul>
      <p v-if="pending?.note" class="text-body-sm">{{ pending.note }}</p>
      <label class="block text-label-md">Kata sandi Anda
        <input id="access-password" v-model="password" type="password" required autocomplete="current-password" :aria-invalid="!!confirmError" :aria-describedby="confirmError ? 'access-error' : undefined" :class="field">
      </label>
      <p v-if="confirmError" id="access-error" role="alert" class="text-body-sm text-error">{{ confirmError }}</p>
    </form>
    <template #footer>
      <button type="button" :disabled="busy" :class="ghostBtn" @click="confirmDialog.close()">Batal</button>
      <button type="submit" form="access-confirm" :disabled="busy" :aria-busy="busy" :class="pending?.variant === 'delete' ? dangerBtn : primaryBtn">{{ busy ? 'Menyimpan...' : (pending?.variant === 'delete' ? 'Hapus' : 'Konfirmasi') }}</button>
    </template>
  </AppModal>
</template>
