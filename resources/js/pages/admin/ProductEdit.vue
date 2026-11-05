<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

const route = useRoute();
const auth = useAuthStore();
const canManage = computed(() => auth.can('products.manage'));
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const product = ref(null);
const loading = ref(true);
const loadError = ref('');

const info = reactive({ name: '', description: '', badges: '' });
const infoBusy = ref(false);
const infoError = ref('');
const infoFields = ref({});
const infoSaved = ref(false);
const publishError = ref('');

const imageInput = ref(null);
const imageAlt = ref('');
const imageBusy = ref(false);
const imageError = ref('');

const variantDialog = ref(null);
const editingVariant = ref(null);
const variantBusy = ref(false);
const variantError = ref('');
const variantFields = ref({});
const blankVariant = { sku: '', name: '', price: '', compare_at_price: '', weight_gram: '', net_weight_gram: '', length_cm: '', width_cm: '', height_cm: '', shelf_life_days: '', max_order_quantity: 50, is_active: true };
const variant = reactive({ ...blankVariant });
const NUMERIC = ['price', 'compare_at_price', 'weight_gram', 'net_weight_gram', 'length_cm', 'width_cm', 'height_cm', 'shelf_life_days', 'max_order_quantity'];

function setProduct(data) {
    product.value = data;
    Object.assign(info, { name: data.name, description: data.description || '', badges: (data.badges || []).join(', ') });
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get(`/admin/products/${route.params.id}`);
        setProduct(data.data);
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function saveInfo() {
    infoBusy.value = true;
    infoError.value = '';
    infoFields.value = {};
    infoSaved.value = false;
    try {
        const { data } = await api.patch(`/admin/products/${product.value.id}`, { name: info.name, description: info.description || null, badges: info.badges.split(',').map((badge) => badge.trim()).filter(Boolean) });
        setProduct(data.data);
        infoSaved.value = true;
    } catch (e) {
        const { message, fields } = apiError(e);
        infoError.value = message;
        infoFields.value = fields;
    } finally {
        infoBusy.value = false;
    }
}

async function togglePublish() {
    publishError.value = '';
    try {
        const { data } = await api.patch(`/admin/products/${product.value.id}`, { is_published: !product.value.is_published });
        setProduct(data.data);
    } catch (e) {
        const { message, fields } = apiError(e);
        publishError.value = fields.is_published?.[0] || message;
    }
}

async function uploadImage() {
    const file = imageInput.value?.files?.[0];
    imageError.value = '';
    if (!file) {
        imageError.value = 'Pilih foto dulu.';
        return;
    }
    imageBusy.value = true;
    try {
        const body = new FormData();
        body.append('image', file);
        body.append('alt', imageAlt.value);
        const { data } = await api.post(`/admin/products/${product.value.id}/images`, body);
        setProduct(data.data);
        imageInput.value.value = '';
        imageAlt.value = '';
    } catch (e) {
        const { message, fields } = apiError(e);
        imageError.value = fields.image?.[0] || fields.alt?.[0] || message;
    } finally {
        imageBusy.value = false;
    }
}

async function removeImage(image) {
    if (!window.confirm('Hapus foto ini?')) return;
    imageError.value = '';
    try {
        const { data } = await api.delete(`/admin/images/${image.id}`);
        setProduct(data.data);
    } catch (e) {
        imageError.value = apiError(e).message;
    }
}

function openVariant(item = null) {
    editingVariant.value = item;
    Object.assign(variant, blankVariant, item ? Object.fromEntries(Object.entries(item).map(([key, value]) => [key, value ?? ''])) : {});
    variantError.value = '';
    variantFields.value = {};
    variantDialog.value.showModal();
}

async function saveVariant() {
    variantBusy.value = true;
    variantError.value = '';
    variantFields.value = {};
    try {
        const body = { sku: variant.sku, name: variant.name, is_active: variant.is_active };
        NUMERIC.forEach((key) => { body[key] = variant[key] === '' ? null : variant[key]; });
        const { data } = editingVariant.value
            ? await api.patch(`/admin/variants/${editingVariant.value.id}`, body)
            : await api.post(`/admin/products/${product.value.id}/variants`, body);
        setProduct(data.data);
        variantDialog.value.close();
    } catch (e) {
        const { message, fields } = apiError(e);
        variantError.value = message;
        variantFields.value = fields;
    } finally {
        variantBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
  <router-link :to="{ name: 'AdminProducts' }" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary">Kembali ke daftar produk</router-link>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat produk...</p>
  <div v-else-if="loadError" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ loadError }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <template v-else>
    <div class="mt-space-md flex flex-wrap items-center justify-between gap-space-md">
      <div>
        <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">{{ product.name }}</h1>
        <p class="mt-1 text-body-sm text-secondary break-all">/produk/{{ product.slug }}</p>
      </div>
      <div class="flex items-center gap-space-sm">
        <span class="rounded-full px-2 py-0.5 text-label-sm" :class="product.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ product.is_published ? 'Tayang' : 'Draf' }}</span>
        <button v-if="canManage" type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="togglePublish">{{ product.is_published ? 'Tarik publikasi' : 'Publikasikan' }}</button>
      </div>
    </div>
    <p v-if="publishError" role="alert" class="mt-space-sm rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ publishError }}</p>

    <section class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg">
      <h2 class="font-heading text-headline-sm">Informasi produk</h2>
      <form class="mt-space-md space-y-space-md" @submit.prevent="saveInfo">
        <label class="block text-label-md">
          Nama produk
          <input v-model="info.name" required maxlength="150" :disabled="!canManage" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70" @input="infoSaved = false">
          <span v-if="infoFields.name" class="mt-1 block text-body-sm text-error">{{ infoFields.name[0] }}</span>
        </label>
        <label class="block text-label-md">
          Deskripsi
          <textarea v-model="info.description" rows="4" maxlength="5000" :disabled="!canManage" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70" @input="infoSaved = false"></textarea>
        </label>
        <label class="block text-label-md">
          Lencana (pisahkan dengan koma, maksimal 5)
          <input v-model="info.badges" maxlength="200" placeholder="Contoh: Terlaris, Edisi Ramadan" :disabled="!canManage" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70" @input="infoSaved = false">
          <span v-if="infoFields.badges || infoFields['badges.0']" class="mt-1 block text-body-sm text-error">{{ (infoFields.badges || infoFields['badges.0'])[0] }}</span>
        </label>
        <p v-if="infoError" role="alert" class="text-body-sm text-error">{{ infoError }}</p>
        <p v-else-if="infoSaved" role="status" class="text-body-sm text-success">Tersimpan.</p>
        <button v-if="canManage" :disabled="infoBusy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ infoBusy ? 'Menyimpan...' : 'Simpan' }}</button>
      </form>
    </section>

    <section class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg">
      <div class="flex flex-wrap items-center justify-between gap-space-sm">
        <h2 class="font-heading text-headline-sm">Varian dan harga</h2>
        <button v-if="canManage" type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="openVariant()">Tambah varian</button>
      </div>
      <p v-if="!product.variants.length" class="mt-space-md text-secondary">Belum ada varian. Produk butuh minimal satu varian aktif sebelum dipublikasikan.</p>
      <div v-else class="relative mt-space-md overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-body-sm">
          <thead class="border-b border-border text-label-md">
            <tr>
              <th scope="col" class="py-2 pr-4">SKU</th>
              <th scope="col" class="py-2 pr-4">Varian</th>
              <th scope="col" class="py-2 pr-4">Harga jual</th>
              <th scope="col" class="py-2 pr-4">Harga normal</th>
              <th scope="col" class="py-2 pr-4">Berat</th>
              <th scope="col" class="py-2 pr-4">Status</th>
              <th v-if="canManage" scope="col" class="py-2"><span class="sr-only">Aksi</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in product.variants" :key="item.id" class="border-b border-border last:border-b-0">
              <td class="py-2 pr-4 font-mono">{{ item.sku }}</td>
              <td class="py-2 pr-4">{{ item.name }}</td>
              <td class="whitespace-nowrap py-2 pr-4">{{ rupiah.format(item.price) }}</td>
              <td class="whitespace-nowrap py-2 pr-4">{{ item.compare_at_price ? rupiah.format(item.compare_at_price) : '-' }}</td>
              <td class="whitespace-nowrap py-2 pr-4">{{ item.weight_gram }} g</td>
              <td class="py-2 pr-4">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</td>
              <td v-if="canManage" class="py-2 text-right"><button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="openVariant(item)">Ubah</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg">
      <h2 class="font-heading text-headline-sm">Foto produk</h2>
      <p v-if="!product.images.length" class="mt-space-md text-secondary">Belum ada foto.</p>
      <ul v-else class="mt-space-md grid grid-cols-2 gap-space-md sm:grid-cols-4">
        <li v-for="image in product.images" :key="image.id">
          <img :src="image.url" :alt="image.alt" class="aspect-square w-full rounded object-cover">
          <button v-if="canManage" type="button" class="mt-1 text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="removeImage(image)">Hapus</button>
        </li>
      </ul>
      <form v-if="canManage" class="mt-space-md space-y-space-sm border-t border-border pt-space-md" @submit.prevent="uploadImage">
        <label class="block text-label-md">
          Foto (JPG, PNG, atau WebP, maksimal 2 MB)
          <input ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-body-md">
        </label>
        <label class="block text-label-md">
          Teks alternatif
          <input v-model="imageAlt" required maxlength="150" placeholder="Contoh: Toples Nastar Sultan" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
        <button :disabled="imageBusy" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60">{{ imageBusy ? 'Mengunggah...' : 'Unggah foto' }}</button>
      </form>
    </section>
  </template>

  <dialog ref="variantDialog" aria-labelledby="variant-form-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="saveVariant">
      <h2 id="variant-form-title" class="font-heading text-headline-md">{{ editingVariant ? 'Ubah varian' : 'Tambah varian' }}</h2>
      <p v-if="variantError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ variantError }}</p>
      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          SKU
          <input v-model="variant.sku" required maxlength="64" class="mt-1 w-full rounded border border-secondary px-3 py-2 font-mono text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.sku" class="mt-1 block text-body-sm text-error">{{ variantFields.sku[0] }}</span>
        </label>
        <label class="block text-label-md">
          Nama varian
          <input v-model="variant.name" required maxlength="100" placeholder="Contoh: Toples 500 g" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.name" class="mt-1 block text-body-sm text-error">{{ variantFields.name[0] }}</span>
        </label>
        <label class="block text-label-md">
          Harga jual (Rp)
          <input v-model.number="variant.price" type="number" required min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.price" class="mt-1 block text-body-sm text-error">{{ variantFields.price[0] }}</span>
        </label>
        <label class="block text-label-md">
          Harga normal (Rp, opsional)
          <input v-model.number="variant.compare_at_price" type="number" min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.compare_at_price" class="mt-1 block text-body-sm text-error">{{ variantFields.compare_at_price[0] }}</span>
        </label>
        <label class="block text-label-md">
          Berat paket (gram)
          <input v-model.number="variant.weight_gram" type="number" required min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.weight_gram" class="mt-1 block text-body-sm text-error">{{ variantFields.weight_gram[0] }}</span>
        </label>
        <label class="block text-label-md">
          Berat bersih (gram, opsional)
          <input v-model.number="variant.net_weight_gram" type="number" min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <label class="block text-label-md">
          Umur simpan (hari, opsional)
          <input v-model.number="variant.shelf_life_days" type="number" min="1" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <label class="block text-label-md">
          Batas jumlah per pesanan
          <input v-model.number="variant.max_order_quantity" type="number" required min="1" max="999" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="variantFields.max_order_quantity" class="mt-1 block text-body-sm text-error">{{ variantFields.max_order_quantity[0] }}</span>
        </label>
        <fieldset class="text-label-md">
          <legend>Dimensi paket (cm, opsional)</legend>
          <div class="mt-1 grid grid-cols-3 gap-space-xs">
            <input v-model.number="variant.length_cm" type="number" min="1" aria-label="Panjang" placeholder="P" class="w-full rounded border border-secondary px-2 py-2 text-body-md focus:outline-2 focus:outline-primary">
            <input v-model.number="variant.width_cm" type="number" min="1" aria-label="Lebar" placeholder="L" class="w-full rounded border border-secondary px-2 py-2 text-body-md focus:outline-2 focus:outline-primary">
            <input v-model.number="variant.height_cm" type="number" min="1" aria-label="Tinggi" placeholder="T" class="w-full rounded border border-secondary px-2 py-2 text-body-md focus:outline-2 focus:outline-primary">
          </div>
        </fieldset>
      </div>
      <label class="flex items-center gap-space-sm text-label-md">
        <input v-model="variant.is_active" type="checkbox" class="size-4 accent-primary">
        Varian aktif
      </label>
      <span v-if="variantFields.is_active" class="block text-body-sm text-error">{{ variantFields.is_active[0] }}</span>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="variantDialog.close()">Batal</button>
        <button :disabled="variantBusy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ variantBusy ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </dialog>
</template>
