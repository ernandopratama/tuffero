<script setup>
import { reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const SOURCES = { google: 'Google Maps', whatsapp: 'WhatsApp', instagram: 'Instagram', lainnya: 'Lainnya' };
const STAR = 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z';
const AVATAR_TONES = ['bg-accent-tint-20 text-primary', 'bg-info-surface text-info', 'bg-success-surface text-success', 'bg-warning-surface text-warning', 'bg-secondary-tint-12 text-secondary'];

const COLUMNS = [
    { key: 'author_name', label: 'Pengulas', cellClass: 'text-left' },
    { key: 'body', label: 'Ulasan' },
    { key: 'source', label: 'Sumber' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const RATINGS = [5, 4, 3, 2, 1].map((n) => ({ value: n, label: `${n} bintang` }));

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/reviews', { filters: { rating: '', is_published: '' } });

const dialog = ref(null);
const deleteDialog = ref(null);
const editing = ref(null);
const toDelete = ref(null);
const saving = ref(false);
const deleting = ref(false);
const deleteError = ref('');
const formError = ref('');
const fieldErrors = ref({});
const blank = { author_name: '', rating: '', body: '', source: 'google', reviewed_on: '', is_published: true };
const form = reactive({ ...blank });

const initials = (name) => (name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
const avatarTone = (name) => AVATAR_TONES[[...(name || '')].reduce((sum, ch) => sum + ch.charCodeAt(0), 0) % AVATAR_TONES.length];

function open(review = null) {
    editing.value = review;
    Object.assign(form, blank, review ? Object.fromEntries(Object.entries(review).map(([key, value]) => [key, value ?? ''])) : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        author_name: form.author_name,
        rating: form.rating === '' ? null : form.rating,
        body: form.body,
        source: form.source,
        reviewed_on: form.reviewed_on || null,
        is_published: form.is_published,
    };
    try {
        if (editing.value) await api.patch(`/admin/reviews/${editing.value.id}`, body);
        else await api.post('/admin/reviews', body);
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

function askDelete(review) {
    toDelete.value = review;
    deleteError.value = '';
    deleteDialog.value.showModal();
}

async function confirmDelete() {
    deleting.value = true;
    deleteError.value = '';
    try {
        await api.delete(`/admin/reviews/${toDelete.value.id}`, { toast: 'Ulasan dihapus.' });
        deleteDialog.value.close();
        await load();
    } catch (e) {
        deleteError.value = apiError(e).message;
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-space-md">
    <PageHeader title="Ulasan Toko" description="Tampil di halaman produk. Rating dan jumlah ulasan diatur di menu Pengaturan. Hanya tambahkan ulasan yang boleh ditampilkan." />
    <AddButton label="Tambah ulasan" class="mt-3.5" @click="open()" />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari nama atau isi ulasan">
      <FilterSelect v-model="filter.rating" label="Rating" :options="RATINGS" />
      <FilterSelect v-model="filter.is_published" label="Status" :options="{ 1: 'Tayang', 0: 'Disembunyikan' }" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar ulasan toko"
    empty-text="Belum ada ulasan."
    min-width="min-w-[860px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-author_name="{ row }">
      <div class="inline-flex max-w-xs items-center gap-3 text-left">
        <span class="grid size-10 shrink-0 place-items-center rounded-full text-label-md" :class="avatarTone(row.author_name)" aria-hidden="true">{{ initials(row.author_name) }}</span>
        <span class="min-w-0">
          <span class="block truncate text-label-md">{{ row.author_name }}</span>
          <span v-if="row.rating" class="mt-0.5 flex items-center gap-0.5 text-accent" role="img" :aria-label="`${row.rating} dari 5 bintang`">
            <svg v-for="n in 5" :key="n" viewBox="0 0 24 24" width="14" height="14" :fill="n <= row.rating ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" :class="n <= row.rating ? '' : 'opacity-40'" aria-hidden="true"><path :d="STAR" /></svg>
          </span>
          <span v-else class="block text-body-sm text-secondary">Tanpa rating</span>
        </span>
      </div>
    </template>
    <template #cell-body="{ row }">
      <p class="mx-auto line-clamp-3 max-w-md break-words">{{ row.body }}</p>
      <p v-if="row.reviewed_on" class="mt-1 text-body-sm text-secondary">{{ formatDate(row.reviewed_on).split(',')[0] }}</p>
    </template>
    <template #cell-source="{ row }"><span class="inline-flex whitespace-nowrap rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ SOURCES[row.source] || row.source }}</span></template>
    <template #cell-status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="row.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
        <i class="size-1.5 rounded-full" :class="row.is_published ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ row.is_published ? 'Tayang' : 'Disembunyikan' }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction icon="edit" label="Ubah" :subject="row.author_name" @click="open(row)" />
        <IconAction icon="trash" tone="danger" label="Hapus" :subject="row.author_name" @click="askDelete(row)" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="editing ? 'update' : 'create'" size="xl" :title="editing ? 'Ubah ulasan' : 'Tambah ulasan'" :subtitle="editing?.author_name">
    <!-- Pratinjau kartu ulasan seperti yang dilihat pelanggan -->
    <figure class="flex items-start gap-3 rounded-3xl border border-border bg-background/60 p-4" aria-hidden="true">
      <span class="grid size-11 shrink-0 place-items-center rounded-full text-label-md" :class="avatarTone(form.author_name)">{{ initials(form.author_name) }}</span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-label-md">{{ form.author_name || 'Nama pengulas' }}</p>
        <p class="mt-0.5 flex items-center gap-0.5 text-accent">
          <svg v-for="n in 5" :key="n" viewBox="0 0 24 24" width="14" height="14" :fill="n <= Number(form.rating || 0) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" :class="n <= Number(form.rating || 0) ? '' : 'opacity-40'"><path :d="STAR" /></svg>
        </p>
        <p class="mt-1 line-clamp-3 break-words text-body-sm">{{ form.body || 'Isi ulasan tampil di sini.' }}</p>
      </div>
    </figure>

    <form id="review-form" class="mt-space-md space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama pengulas
        <input v-model="form.author_name" required maxlength="100" :class="field">
        <span v-if="fieldErrors.author_name" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.author_name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Isi ulasan
        <textarea v-model="form.body" required rows="4" maxlength="1000" :class="`${field} resize-none`"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/1000</span>
        <span v-if="fieldErrors.body" class="block text-body-sm font-normal text-error">{{ fieldErrors.body[0] }}</span>
      </label>

      <fieldset>
        <legend class="text-label-md">Rating <span class="font-normal text-secondary">(opsional)</span></legend>
        <div class="mt-1 flex flex-wrap items-center gap-1">
          <label v-for="n in 5" :key="n" class="relative cursor-pointer rounded-full p-1 transition-transform duration-[160ms] ease-out-strong active:scale-[0.9] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
            <input v-model.number="form.rating" type="radio" name="review-rating" :value="n" class="sr-only">
            <span class="sr-only">{{ n }} bintang</span>
            <svg viewBox="0 0 24 24" width="32" height="32" :fill="n <= Number(form.rating || 0) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" class="text-accent" aria-hidden="true"><path :d="STAR" /></svg>
          </label>
          <button v-if="form.rating !== ''" type="button" :class="`ml-2 rounded-full px-3 py-1.5 text-label-md text-secondary underline hover:text-primary ${press}`" @click="form.rating = ''">Hapus rating</button>
        </div>
        <span v-if="fieldErrors.rating" class="mt-1 block text-body-sm text-error">{{ fieldErrors.rating[0] }}</span>
      </fieldset>

      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Sumber
          <select v-model="form.source" :class="field">
            <option v-for="(label, key) in SOURCES" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
        <label class="block text-label-md">
          Tanggal <span class="font-normal text-secondary">(opsional)</span>
          <input v-model="form.reviewed_on" type="date" :class="field">
          <span v-if="fieldErrors.reviewed_on" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.reviewed_on[0] }}</span>
        </label>
      </div>

      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="form.is_published ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="form.is_published" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span><span class="block text-label-md">Tampilkan di toko</span><span class="block text-body-sm text-secondary">Ulasan yang disembunyikan tidak tampil di halaman produk.</span></span>
      </label>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button form="review-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus ulasan?" :subtitle="toDelete?.author_name">
    <p class="text-body-sm">Ulasan ini dihapus dari toko dan tidak bisa dikembalikan dari dashboard. Penghapusan tercatat di Audit Log.</p>
    <p v-if="deleteError" role="alert" class="mt-3 text-body-sm text-error">{{ deleteError }}</p>
    <template #footer>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="confirmDelete"><AdminIcon name="trash" :size="16" />{{ deleting ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>
