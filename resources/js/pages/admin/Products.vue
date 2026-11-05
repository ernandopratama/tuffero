<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const products = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref('');
const page = ref(1);
const loading = ref(true);
const error = ref('');

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

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/products', { params: { page: page.value, search: search.value || undefined } });
        products.value = data.data.items;
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

watch(page, load);
onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <div>
      <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Produk dan Harga</h1>
      <p class="mt-space-sm text-secondary">Produk baru tersimpan sebagai draf sampai dipublikasikan.</p>
    </div>
    <button v-if="auth.can('products.manage')" type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="openForm">Tambah produk</button>
  </div>

  <form class="mt-space-lg flex max-w-md gap-space-sm" role="search" @submit.prevent="applySearch">
    <label class="w-full text-label-md">
      Cari nama atau SKU
      <input v-model="search" type="search" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
    </label>
    <button class="self-end rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary">Cari</button>
  </form>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat produk...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!products.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada produk.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[720px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Produk</th>
            <th scope="col" class="px-4 py-3">Varian</th>
            <th scope="col" class="px-4 py-3">Harga jual</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="product in products" :key="product.id" class="border-b border-border last:border-b-0">
            <td class="px-4 py-3">
              <div class="flex items-center gap-space-sm">
                <img v-if="product.images[0]" :src="product.images[0].url" :alt="product.images[0].alt" width="44" height="44" class="size-11 rounded object-cover">
                <div v-else class="size-11 rounded bg-accent-tint-12" aria-hidden="true"></div>
                <span class="text-label-md">{{ product.name }}</span>
              </div>
            </td>
            <td class="px-4 py-3">{{ product.variants.length }}</td>
            <td class="whitespace-nowrap px-4 py-3">{{ priceRange(product) }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-label-sm" :class="product.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ product.is_published ? 'Tayang' : 'Draf' }}</span>
            </td>
            <td class="px-4 py-3 text-right">
              <router-link :to="{ name: 'AdminProductEdit', params: { id: product.id } }" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary">{{ auth.can('products.manage') ? 'Ubah' : 'Lihat' }}</router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman produk">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} produk)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="dialog" aria-labelledby="product-form-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="create">
      <h2 id="product-form-title" class="font-heading text-headline-md">Tambah produk</h2>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama produk
        <input v-model="form.name" required maxlength="150" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.name" class="mt-1 block text-body-sm text-error">{{ fieldErrors.name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Deskripsi
        <textarea v-model="form.description" rows="4" maxlength="5000" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
      </label>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
        <button :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </dialog>
</template>
