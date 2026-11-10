<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const auth = useAuthStore();
const router = useRouter();
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const COLUMNS = [
    { key: 'name', label: 'Produk', cellClass: 'text-left' },
    { key: 'variants', label: 'Varian' },
    { key: 'price', label: 'Harga jual' },
    { key: 'is_published', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const STATUS = { published: 'Tayang', draft: 'Draf' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/products', { filters: { status: '', category_id: '' } });

const categories = ref([]);
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name_id })));

// Pintasan "Jadikan draf": produk hilang dari toko tetapi datanya utuh, dan bisa dipublikasikan lagi dari halaman ubah.
const drafting = ref(null);
const actionError = ref('');
async function makeDraft(product) {
    drafting.value = product.id;
    actionError.value = '';
    try {
        await api.patch(`/admin/products/${product.id}`, { is_published: false }, { toast: `${product.name} dijadikan draf.` });
        await load();
    } catch (e) {
        actionError.value = `${product.name}: ${apiError(e).message}`;
    } finally {
        drafting.value = null;
    }
}

const dialog = ref(null);
const form = reactive({ name: '', description: '' });
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});

function priceRange(product) {
    const prices = product.variants.filter((variant) => variant.is_active).map((variant) => variant.price);
    if (!prices.length) return '-';
    const low = Math.min(...prices);
    const high = Math.max(...prices);
    return low === high ? rupiah.format(low) : `${rupiah.format(low)} - ${rupiah.format(high)}`;
}

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

const categoryName = (product) => categories.value.find((c) => c.id === product.product_category_id)?.name_id || 'Tanpa kategori';
// Halaman toko per varian: /produk/<sku>. Varian aktif pertama mewakili produk.
const storePath = (product) => { const variant = product.variants.find((v) => v.is_active); return variant ? `/produk/${variant.sku.toLowerCase()}` : ''; };
const activeCount = (product) => product.variants.filter((variant) => variant.is_active).length;

// Harga coret varian aktif termurah, hanya bila lebih tinggi dari harga jualnya.
function strikePrice(product) {
    const cheapest = product.variants.filter((variant) => variant.is_active).sort((a, b) => a.price - b.price)[0];
    return cheapest?.compare_at_price > cheapest?.price ? cheapest.compare_at_price : null;
}

function openForm() {
    Object.assign(form, { name: '', description: '' });
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function create() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    try {
        const { data } = await api.post('/admin/products', { name: form.name, description: form.description || null });
        dialog.value.close();
        router.push({ name: 'AdminProductEdit', params: { id: data.data.id } });
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

// Daftar kategori hanya untuk pilihan filter; bila gagal, filter kategori kosong.
onMounted(async () => {
    try {
        categories.value = (await api.get('/admin/categories')).data.data.items;
    } catch {
        categories.value = [];
    }
});
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Produk dan Harga" description="Produk baru tersimpan sebagai draf sampai dipublikasikan." />
    <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
      <TableToolbar v-model="search" placeholder="Cari nama atau SKU">
        <FilterSelect v-model="filter.status" label="Status" :options="STATUS" />
        <FilterSelect v-model="filter.category_id" label="Kategori" :options="categoryOptions" />
      </TableToolbar>
      <AddButton v-if="auth.can('products.manage')" label="Tambah produk" @click="openForm" />
    </div>
  </div>

  <p v-if="actionError" role="alert" class="mt-space-md rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ actionError }}</p>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar produk"
    empty-text="Belum ada produk."
    min-width="min-w-[900px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-name="{ row }">
      <div class="inline-flex max-w-sm items-center gap-3 text-left">
        <img v-if="row.images[0]" :src="row.images[0].url" :alt="row.images[0].alt || row.name" width="56" height="56" loading="lazy" class="size-14 shrink-0 rounded-2xl border border-border object-cover">
        <span v-else class="grid size-14 shrink-0 place-items-center rounded-2xl border border-dashed border-border bg-accent-tint-12 text-secondary" aria-hidden="true"><AdminIcon name="tag" :size="20" /></span>
        <span class="min-w-0">
          <span class="block truncate text-label-md">{{ row.name }}</span>
          <span class="block truncate text-body-sm text-secondary">{{ categoryName(row) }}</span>
        </span>
      </div>
    </template>
    <template #cell-variants="{ row }">
      <span class="block text-label-md tabular-nums">{{ row.variants.length }} varian</span>
      <span class="block text-body-sm" :class="activeCount(row) ? 'text-secondary' : 'text-error'">{{ activeCount(row) ? `${activeCount(row)} aktif` : 'Tidak ada yang aktif' }}</span>
    </template>
    <template #cell-price="{ row }">
      <span class="block whitespace-nowrap text-label-md tabular-nums">{{ priceRange(row) }}</span>
      <span v-if="strikePrice(row)" class="block whitespace-nowrap text-body-sm tabular-nums text-secondary line-through">{{ rupiah.format(strikePrice(row)) }}</span>
    </template>
    <template #cell-is_published="{ row }">
      <div class="flex flex-col items-center gap-1">
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="row.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
          <i class="size-1.5 rounded-full" :class="row.is_published ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ row.is_published ? 'Tayang' : 'Draf' }}
        </span>
        <span v-if="row.translation_outdated" class="inline-flex items-center gap-1 whitespace-nowrap text-label-sm text-warning"><AdminIcon name="alert" :size="12" />Terjemahan usang</span>
      </div>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction v-if="row.is_published && storePath(row)" icon="eye" label="Lihat di toko" :subject="row.name" :href="storePath(row)" />
        <IconAction v-if="row.is_published && auth.can('products.manage')" icon="undo" label="Jadikan draf" :subject="row.name" :disabled="drafting === row.id" @click="makeDraft(row)" />
        <IconAction :icon="auth.can('products.manage') ? 'edit' : 'sliders'" :label="auth.can('products.manage') ? 'Ubah' : 'Lihat detail'" :subject="row.name" :to="{ name: 'AdminProductEdit', params: { id: row.id } }" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" variant="create" title="Tambah produk" subtitle="Tersimpan sebagai draf, lalu lengkapi varian dan foto">
    <form id="product-form" class="space-y-space-md" @submit.prevent="create">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama produk
        <input v-model="form.name" required maxlength="150" :class="field">
        <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.name.length }}/150</span>
        <span v-if="fieldErrors.name" class="block text-body-sm text-error">{{ fieldErrors.name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Deskripsi <span class="font-normal text-secondary">(opsional)</span>
        <textarea v-model="form.description" rows="5" maxlength="5000" :class="`${field} resize-none`"></textarea>
        <span v-if="fieldErrors.description" class="mt-1 block text-body-sm text-error">{{ fieldErrors.description[0] }}</span>
      </label>
      <p class="flex items-start gap-2 rounded-2xl bg-background/70 p-3 text-body-sm text-secondary">
        <AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />Setelah disimpan, halaman ubah produk terbuka untuk menambah varian, harga, dan foto.
      </p>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button form="product-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan dan lanjut' }}</button>
    </template>
  </AppModal>
</template>
