<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AdminComments from '../../components/AdminComments.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const canManage = computed(() => auth.can('vouchers.manage'));
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const vouchers = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref('');
const page = ref(1);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { code: '', type: 'percent', value: '', min_subtotal: 0, max_discount: '', quota: '', starts_at: '', ends_at: '', is_active: false };
const form = reactive({ ...blank });

const valueLabel = (voucher) => (voucher.type === 'percent' ? `${voucher.value}%` : rupiah.format(voucher.value));

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/vouchers', { params: { page: page.value, search: search.value || undefined } });
        vouchers.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function applySearch() {
    if (page.value === 1) load();
    else page.value = 1;
}

function open(voucher = null) {
    editing.value = voucher;
    Object.assign(form, blank, voucher ? {
        ...voucher,
        max_discount: voucher.max_discount ?? '',
        quota: voucher.quota ?? '',
        starts_at: toLocalInput(voucher.starts_at),
        ends_at: toLocalInput(voucher.ends_at),
    } : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        code: form.code,
        type: form.type,
        value: form.value,
        min_subtotal: form.min_subtotal === '' ? 0 : form.min_subtotal,
        quota: form.quota === '' ? null : form.quota,
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        is_active: form.is_active,
    };
    if (form.type === 'percent') body.max_discount = form.max_discount === '' ? null : form.max_discount;
    try {
        if (editing.value) await api.patch(`/admin/vouchers/${editing.value.id}`, body);
        else await api.post('/admin/vouchers', body);
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

watch(page, load);
onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <div>
      <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Voucher</h1>
      <p class="mt-space-sm text-secondary">Voucher belum berlaku di toko sampai checkout dibangun dan aturan voucher diputuskan (Q-005).</p>
    </div>
    <button v-if="canManage" type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="open()">Tambah voucher</button>
  </div>

  <form class="mt-space-lg flex max-w-md gap-space-sm" role="search" @submit.prevent="applySearch">
    <label class="w-full text-label-md">
      Cari kode
      <input v-model="search" type="search" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
    </label>
    <button class="self-end rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary">Cari</button>
  </form>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat voucher...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!vouchers.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada voucher.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[720px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Kode</th>
            <th scope="col" class="px-4 py-3">Diskon</th>
            <th scope="col" class="px-4 py-3">Terpakai</th>
            <th scope="col" class="px-4 py-3">Berlaku</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="voucher in vouchers" :key="voucher.id" class="border-b border-border last:border-b-0">
            <td class="px-4 py-3 font-mono">{{ voucher.code }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ valueLabel(voucher) }}</td>
            <td class="px-4 py-3">{{ voucher.used_count }} / {{ voucher.quota ?? '∞' }}</td>
            <td class="px-4 py-3">{{ formatDate(voucher.starts_at) }} sampai {{ formatDate(voucher.ends_at) }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-label-sm" :class="voucher.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ voucher.is_active ? 'Aktif' : 'Nonaktif' }}</span>
            </td>
            <td class="px-4 py-3 text-right">
              <button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="open(voucher)">{{ canManage ? 'Ubah' : 'Lihat' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman voucher">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} voucher)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="dialog" aria-labelledby="voucher-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="save">
      <h2 id="voucher-title" class="font-heading text-headline-md">{{ editing ? (canManage ? 'Ubah voucher' : 'Detail voucher') : 'Tambah voucher' }}</h2>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Kode
          <input v-model="form.code" required maxlength="40" class="mt-1 w-full rounded border border-secondary px-3 py-2 font-mono text-body-md uppercase focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.code" class="mt-1 block text-body-sm text-error">{{ fieldErrors.code[0] }}</span>
        </label>
        <label class="block text-label-md">
          Jenis
          <select v-model="form.type" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
            <option value="percent">Persen</option>
            <option value="fixed">Potongan rupiah</option>
          </select>
        </label>
        <label class="block text-label-md">
          {{ form.type === 'percent' ? 'Diskon (%)' : 'Potongan (Rp)' }}
          <input v-model.number="form.value" type="number" required min="1" :max="form.type === 'percent' ? 100 : undefined" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.value" class="mt-1 block text-body-sm text-error">{{ fieldErrors.value[0] }}</span>
        </label>
        <label v-if="form.type === 'percent'" class="block text-label-md">
          Batas diskon (Rp, opsional)
          <input v-model.number="form.max_discount" type="number" min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.max_discount" class="mt-1 block text-body-sm text-error">{{ fieldErrors.max_discount[0] }}</span>
        </label>
        <label class="block text-label-md">
          Minimum belanja (Rp)
          <input v-model.number="form.min_subtotal" type="number" min="0" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.min_subtotal" class="mt-1 block text-body-sm text-error">{{ fieldErrors.min_subtotal[0] }}</span>
        </label>
        <label class="block text-label-md">
          Kuota (opsional)
          <input v-model.number="form.quota" type="number" min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.quota" class="mt-1 block text-body-sm text-error">{{ fieldErrors.quota[0] }}</span>
        </label>
        <label class="block text-label-md">
          Mulai (opsional)
          <input v-model="form.starts_at" type="datetime-local" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
        </label>
        <label class="block text-label-md">
          Selesai (opsional)
          <input v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm text-error">{{ fieldErrors.ends_at[0] }}</span>
        </label>
        <label class="flex items-center gap-space-sm text-label-md sm:col-span-2">
          <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary">
          Voucher aktif
        </label>
      </fieldset>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">{{ canManage ? 'Batal' : 'Tutup' }}</button>
        <button v-if="canManage" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
    <AdminComments v-if="editing" :url="`/admin/vouchers/${editing.id}/comments`" class="mt-space-md" />
  </dialog>
</template>
