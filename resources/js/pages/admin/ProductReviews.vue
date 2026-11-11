<script setup>
import { computed, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';
import { useDataTable } from '../../composables/useDataTable';
import { useAuthStore } from '../../stores/auth';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Semua peran bisa melihat; tambah, ubah, hapus hanya Super Admin (server tetap memeriksa izinnya).
const auth = useAuthStore();
const canManage = computed(() => auth.can('product_reviews.manage'));

const STAR = 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z';
const RATINGS = [5, 4, 3, 2, 1].map((n) => ({ value: n, label: `${n} bintang` }));
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

const columns = computed(() => [
    { key: 'author_name', label: 'Pengulas', cellClass: 'text-left' },
    { key: 'product_name', label: 'Produk' },
    { key: 'body', label: 'Ulasan' },
    { key: 'status', label: 'Status' },
    ...(canManage.value ? [{ key: 'actions', label: 'Aksi' }] : []),
]);

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/product-reviews', { filters: { rating: '', is_published: '' } });

const dialog = ref(null);
const deleteDialog = ref(null);
const editing = ref(null);
const toDelete = ref(null);
const saving = ref(false);
const deleting = ref(false);
const deleteError = ref('');
const formError = ref('');
const fieldErrors = ref({});
const products = ref([]);
const blank = { product_id: '', author_name: '', rating: '', body: '', is_published: true };
const form = reactive({ ...blank });

async function open(review = null) {
    editing.value = review;
    Object.assign(form, blank, review ? { author_name: review.author_name, rating: review.rating, body: review.body ?? '', is_published: review.is_published } : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
    if (!review && !products.value.length) {
        try {
            const { data } = await api.get('/admin/products', { params: { per_page: 100 } });
            products.value = data.data.items;
        } catch (e) {
            formError.value = apiError(e).message;
        }
    }
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { author_name: form.author_name, rating: form.rating === '' ? null : form.rating, body: form.body || null, is_published: form.is_published };
    try {
        if (editing.value) await api.patch(`/admin/product-reviews/${editing.value.id}`, body);
        else await api.post('/admin/product-reviews', { ...body, product_id: form.product_id });
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
        await api.delete(`/admin/product-reviews/${toDelete.value.id}`, { toast: 'Ulasan dihapus.' });
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
    <PageHeader title="Ulasan Produk" :description="canManage ? 'Ulasan dan rating dari pembeli setelah pesanan selesai. Anda bisa menambah, mengubah, menyembunyikan, atau menghapusnya.' : 'Ulasan dan rating dari pembeli setelah pesanan selesai. Peran Anda hanya bisa melihat.'" />
    <AddButton v-if="canManage" label="Tambah ulasan" class="mt-3.5" @click="open()" />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari pengulas, produk, atau isi ulasan">
      <FilterSelect v-model="filter.rating" label="Rating" :options="RATINGS" />
      <FilterSelect v-model="filter.is_published" label="Status" :options="{ 1: 'Tayang', 0: 'Disembunyikan' }" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar ulasan produk"
    empty-text="Belum ada ulasan produk."
    min-width="min-w-[860px]"
    :columns="columns"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-author_name="{ row }">
      <span class="block truncate text-label-md">{{ row.author_name }}</span>
      <span class="mt-0.5 flex items-center gap-0.5 text-accent" role="img" :aria-label="`${row.rating} dari 5 bintang`">
        <svg v-for="n in 5" :key="n" viewBox="0 0 24 24" width="14" height="14" :fill="n <= row.rating ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" :class="n <= row.rating ? '' : 'opacity-40'" aria-hidden="true"><path :d="STAR" /></svg>
      </span>
    </template>
    <template #cell-product_name="{ row }">{{ row.product_name || '-' }}</template>
    <template #cell-body="{ row }">
      <p v-if="row.body" class="mx-auto line-clamp-3 max-w-md break-words">{{ row.body }}</p>
      <p v-else class="text-secondary">Tanpa teks</p>
      <p class="mt-1 text-body-sm text-secondary">{{ formatDate(row.created_at).split(',')[0] }}</p>
    </template>
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

  <AppModal v-if="canManage" ref="dialog" :variant="editing ? 'update' : 'create'" size="xl" :title="editing ? 'Ubah ulasan produk' : 'Tambah ulasan produk'" :subtitle="editing?.product_name">
    <form id="product-review-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label v-if="!editing" class="block text-label-md">
        Produk
        <select v-model="form.product_id" required :class="field">
          <option value="" disabled>Pilih produk</option>
          <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option>
        </select>
        <span v-if="fieldErrors.product_id" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.product_id[0] }}</span>
      </label>
      <label class="block text-label-md">
        Nama pengulas
        <input v-model="form.author_name" required maxlength="100" :class="field">
        <span v-if="fieldErrors.author_name" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.author_name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Isi ulasan <span class="font-normal text-secondary">(opsional)</span>
        <textarea v-model="form.body" rows="4" maxlength="1000" :class="`${field} resize-none`"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/1000</span>
        <span v-if="fieldErrors.body" class="block text-body-sm font-normal text-error">{{ fieldErrors.body[0] }}</span>
      </label>

      <fieldset>
        <legend class="text-label-md">Rating</legend>
        <div class="mt-1 flex flex-wrap items-center gap-1">
          <label v-for="n in 5" :key="n" class="relative cursor-pointer rounded-full p-1 transition-transform duration-[160ms] ease-out-strong active:scale-[0.9] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
            <input v-model.number="form.rating" type="radio" name="product-review-rating" :value="n" required class="sr-only">
            <span class="sr-only">{{ n }} bintang</span>
            <svg viewBox="0 0 24 24" width="32" height="32" :fill="n <= Number(form.rating || 0) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" class="text-accent" aria-hidden="true"><path :d="STAR" /></svg>
          </label>
        </div>
        <span v-if="fieldErrors.rating" class="mt-1 block text-body-sm text-error">{{ fieldErrors.rating[0] }}</span>
      </fieldset>

      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="form.is_published ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="form.is_published" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span><span class="block text-label-md">Tampilkan di halaman produk</span><span class="block text-body-sm text-secondary">Ulasan yang disembunyikan tidak tampil di toko.</span></span>
      </label>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button form="product-review-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal v-if="canManage" ref="deleteDialog" variant="delete" size="md" title="Hapus ulasan?" :subtitle="toDelete?.author_name">
    <p class="text-body-sm">Ulasan ini dihapus dari halaman produk dan tidak bisa dikembalikan. Penghapusan tercatat di Audit Log.</p>
    <p v-if="deleteError" role="alert" class="mt-3 text-body-sm text-error">{{ deleteError }}</p>
    <template #footer>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="confirmDelete"><AdminIcon name="trash" :size="16" />{{ deleting ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>
