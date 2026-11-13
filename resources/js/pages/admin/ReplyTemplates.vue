<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const templates = ref([]);
const variables = ref([]);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { title: '', body: '', scope: 'any', sort_order: '', is_active: true };
const form = reactive({ ...blank });

const SCOPES = { any: 'Semua halaman', order: 'Pesanan', refund: 'Refund', cancellation: 'Pembatalan' };
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

const stats = computed(() => [
    { icon: 'mail', label: 'Total template', value: templates.value.length },
    { icon: 'check', label: 'Aktif', value: templates.value.filter((t) => t.is_active).length },
]);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/reply-templates');
        templates.value = data.data.items;
        variables.value = data.data.variables;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function open(item = null) {
    editing.value = item;
    Object.assign(form, blank, item ? { ...item } : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { title: form.title, body: form.body, scope: form.scope, is_active: form.is_active };
    if (form.sort_order !== '' && form.sort_order !== null) body.sort_order = Number(form.sort_order);
    try {
        if (editing.value) await api.patch(`/admin/reply-templates/${editing.value.id}`, body);
        else await api.post('/admin/reply-templates', body);
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

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Template Balasan" description="Teks siap pakai untuk membalas pelanggan lewat WhatsApp. Variabel diisi otomatis dari data pesanan. Sistem tidak mengirim pesan sendiri. Yang tidak dipakai dinonaktifkan, bukan dihapus. Setiap perubahan tercatat di Audit Log." />
    <AddButton label="Tambah template" @click="open()" />
  </div>

  <div v-if="loading" role="status" aria-label="Memuat template" class="mt-space-lg grid gap-space-md lg:grid-cols-2">
    <div v-for="n in 4" :key="n" class="h-44 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="error" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ error }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <div v-else-if="!templates.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
    <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="mail" :size="26" /></span>
    <p class="font-heading text-headline-sm">Belum ada template</p>
    <p class="max-w-md text-body-sm text-secondary">Tambahkan teks yang sering dipakai saat membalas pelanggan.</p>
    <AddButton label="Tambah template" expanded class="mt-1" @click="open()" />
  </div>

  <template v-else>
    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-2" aria-label="Ringkasan template">
      <li v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon :name="stat.icon" /></span>
        <span class="min-w-0">
          <span class="block text-body-sm text-secondary">{{ stat.label }}</span>
          <span class="block font-heading text-headline-sm tabular-nums">{{ stat.value }}</span>
        </span>
      </li>
    </ul>

    <ul class="mt-space-lg grid grid-cols-1 gap-space-md lg:grid-cols-2">
      <li v-for="t in templates" :key="t.id" class="flex flex-col rounded-3xl border bg-surface p-space-lg shadow-card" :class="t.is_active ? 'border-border' : 'border-dashed border-border bg-surface/60'">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl" :class="t.is_active ? 'bg-accent-tint-15 text-primary' : 'bg-secondary-tint-12 text-secondary'" aria-hidden="true"><AdminIcon name="mail" :size="18" /></span>
          <h2 class="min-w-0 flex-1 font-heading text-headline-sm" :class="t.is_active ? '' : 'text-secondary'">{{ t.title }}</h2>
          <IconAction icon="edit" label="Ubah" :subject="t.title" @click="open(t)" />
        </div>
        <p class="mt-space-md line-clamp-4 whitespace-pre-line rounded-2xl rounded-tl-md bg-background/70 px-4 py-3 text-body-sm">{{ t.body }}</p>
        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-space-md">
          <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label-sm" :class="t.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
            <i class="size-1.5 rounded-full" :class="t.is_active ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ t.is_active ? 'Aktif' : 'Nonaktif' }}
          </span>
          <span class="rounded-full bg-info-surface px-2.5 py-1 text-label-sm text-info">{{ SCOPES[t.scope] || t.scope }}</span>
          <span class="ml-auto text-label-sm text-secondary">Urutan {{ t.sort_order }}</span>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="editing ? 'update' : 'create'" size="2xl" :title="editing ? 'Ubah template' : 'Tambah template'" :subtitle="editing ? editing.title : 'Teks balasan untuk pelanggan'">
    <form id="template-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>

      <label class="block text-label-md">
        Judul
        <input v-model="form.title" required maxlength="120" :class="field">
        <span v-if="fieldErrors.title" class="mt-1 block text-body-sm text-error">{{ fieldErrors.title[0] }}</span>
      </label>

      <label class="block text-label-md">
        Isi template
        <textarea v-model="form.body" required rows="7" maxlength="1000" :class="`${field} resize-none`"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/1000</span>
        <span v-if="fieldErrors.body" class="block text-body-sm text-error">{{ fieldErrors.body[0] }}</span>
      </label>
      <p class="-mt-2 text-body-sm text-secondary">
        Variabel yang diisi otomatis:
        <code v-for="name in variables" :key="name" class="mr-1 rounded bg-secondary-tint-12 px-1.5 py-0.5 text-label-sm">&#123;{{ name }}&#125;</code>
        Jangan menulis nominal uang atau janji hasil di template. Untuk bagian yang diisi CS saat membalas, tulis dalam tanda kurung, misalnya (isi alasan).
      </p>

      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Muncul di
          <select v-model="form.scope" :class="field">
            <option v-for="(label, key) in SCOPES" :key="key" :value="key">{{ label }}</option>
          </select>
          <span v-if="fieldErrors.scope" class="mt-1 block text-body-sm text-error">{{ fieldErrors.scope[0] }}</span>
        </label>
        <label class="block text-label-md">
          Urutan <span class="font-normal text-secondary">(opsional)</span>
          <input v-model="form.sort_order" type="number" min="0" max="1000" :class="field">
          <span v-if="fieldErrors.sort_order" class="mt-1 block text-body-sm text-error">{{ fieldErrors.sort_order[0] }}</span>
        </label>
      </div>

      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="form.is_active ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="form.is_active" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span>
          <span class="block text-label-md">Aktif</span>
          <span class="block text-body-sm text-secondary">Tampil di pilihan "Balas pelanggan".</span>
        </span>
      </label>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button form="template-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
