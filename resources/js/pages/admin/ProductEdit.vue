<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import RupiahInput from '../../components/ui/RupiahInput.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const route = useRoute();
const auth = useAuthStore();
const canManage = computed(() => auth.can('products.manage'));
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';
const card = 'rounded-3xl border border-border bg-surface p-space-lg shadow-card';
const TR_MODES = [
    { key: 'ai', title: 'Otomatis dengan AI', text: 'Diterjemahkan setiap kali nama atau deskripsi Indonesia berubah.' },
    { key: 'manual', title: 'Manual', text: 'Saya ketik sendiri; AI tidak akan menimpanya.' },
];

const product = ref(null);
const loading = ref(true);
const loadError = ref('');

const info = reactive({ name: '', description: '', badges: '', category_id: '', type_id: '' });
const categories = ref([]);
const infoBusy = ref(false);
const infoError = ref('');
const infoFields = ref({});
const infoSaved = ref(false);
const publishError = ref('');

// Terjemahan Inggris (D-065): mode 'ai' = otomatis saat disimpan; 'manual' = admin yang mengisi. Tombol AI hanya mengisi usulan.
const tr = reactive({ mode: 'ai', name_en: '', description_en: '', variants: {} });
const trBusy = ref(false);
const trAiBusy = ref(false);
const trError = ref('');
const trNote = ref('');

const imageInput = ref(null);
const imageAlt = ref('');
const imageBusy = ref(false);
const imageError = ref('');
const imageName = ref('');
const imageDialog = ref(null);
const imageToDelete = ref(null);

const variantDialog = ref(null);
const editingVariant = ref(null);
const variantBusy = ref(false);
const variantError = ref('');
const variantFields = ref({});
const blankVariant = { sku: '', name: '', price: '', compare_at_price: '', weight_gram: '', net_weight_gram: '', length_cm: '', width_cm: '', height_cm: '', shelf_life_days: '', max_order_quantity: 50, low_stock_threshold: '', is_active: true };
const variant = reactive({ ...blankVariant });
const NUMERIC = ['price', 'compare_at_price', 'weight_gram', 'net_weight_gram', 'length_cm', 'width_cm', 'height_cm', 'shelf_life_days', 'max_order_quantity', 'low_stock_threshold'];

// Diskon produk per varian (D-070): harga jual varian tetap; potongan dihitung server per segmen.
const AUDIENCES = { all: 'Semua pelanggan', guest: 'Non Member (tanpa akun)', member: 'Member (sudah login)' };
const discountDialog = ref(null);
const editingDiscount = ref(null);
const discountBusy = ref(false);
const discountError = ref('');
const discountFields = ref({});
const blankDiscount = { variant_id: '', audience: 'all', type: 'percent', value: '', starts_at: '', ends_at: '', is_active: true };
const discount = reactive({ ...blankDiscount });

const discountRows = computed(() => (product.value?.variants || []).flatMap((item) => item.discounts.map((entry) => ({ ...entry, variant: item }))));
const discountLabel = (entry) => (entry.type === 'percent' ? entry.value + '%' : rupiah.format(entry.value));
const discountedPrice = (entry) => {
    const amount = entry.type === 'percent' ? Math.floor((entry.variant.price * entry.value) / 100) : entry.value;
    return entry.variant.price - Math.max(0, Math.min(amount, entry.variant.price - 1));
};
const previewPrice = computed(() => {
    const target = product.value?.variants.find((item) => item.id === discount.variant_id);
    return target && discount.value ? discountedPrice({ variant: target, type: discount.type, value: discount.value }) : null;
});

// Ringkasan di atas halaman: dari data produk yang sudah dimuat, tanpa panggilan baru.
const activeVariants = computed(() => (product.value?.variants || []).filter((item) => item.is_active));
const priceText = computed(() => {
    const prices = activeVariants.value.map((item) => item.price);
    if (!prices.length) return '-';
    const low = Math.min(...prices);
    const high = Math.max(...prices);
    return low === high ? rupiah.format(low) : `${rupiah.format(low)} - ${rupiah.format(high)}`;
});
const stats = computed(() => product.value && [
    { icon: 'tag', label: 'Harga jual', value: priceText.value },
    { icon: 'box', label: 'Varian aktif', value: `${activeVariants.value.length}/${product.value.variants.length}` },
    { icon: 'sparkles', label: 'Diskon aktif', value: discountRows.value.filter((entry) => entry.is_active).length },
    { icon: 'eye', label: 'Foto', value: product.value.images.length },
]);
// Halaman toko dibuat per varian (/produk/<sku>); varian aktif pertama mewakili produk.
const storePath = computed(() => (activeVariants.value[0] ? `/produk/${activeVariants.value[0].sku.toLowerCase()}` : ''));
const badgeList = computed(() => info.badges.split(',').map((badge) => badge.trim()).filter(Boolean));

function openDiscount(entry = null) {
    editingDiscount.value = entry;
    Object.assign(discount, blankDiscount, entry ? {
        variant_id: entry.variant.id,
        audience: entry.audience,
        type: entry.type,
        value: entry.value,
        starts_at: toLocalInput(entry.starts_at),
        ends_at: toLocalInput(entry.ends_at),
        is_active: entry.is_active,
    } : { variant_id: product.value.variants[0]?.id ?? '' });
    discountError.value = '';
    discountFields.value = {};
    discountDialog.value.showModal();
}

async function saveDiscount() {
    discountBusy.value = true;
    discountError.value = '';
    discountFields.value = {};
    try {
        const body = { audience: discount.audience, type: discount.type, value: discount.value, starts_at: fromLocalInput(discount.starts_at), ends_at: fromLocalInput(discount.ends_at), is_active: discount.is_active };
        if (editingDiscount.value) await api.patch('/admin/discounts/' + editingDiscount.value.id, body);
        else await api.post('/admin/variants/' + discount.variant_id + '/discounts', body);
        const { data } = await api.get('/admin/products/' + product.value.id);
        setProduct(data.data);
        discountDialog.value.close();
    } catch (e) {
        const { message, fields } = apiError(e);
        discountError.value = message;
        discountFields.value = fields;
    } finally {
        discountBusy.value = false;
    }
}

function setProduct(data) {
    product.value = data;
    Object.assign(tr, {
        mode: data.translation_mode,
        name_en: data.name_en || '',
        description_en: data.description_en || '',
        variants: Object.fromEntries(data.variants.map((item) => [item.id, item.name_en || ''])),
    });
    Object.assign(info, { name: data.name, description: data.description || '', badges: (data.badges || []).join(', '), category_id: data.product_category_id ?? '', type_id: data.product_type_id ?? '' });
}

// Kategori dan jenis yang bisa dipilih: yang aktif, ditambah yang sedang dipakai produk ini walau sudah nonaktif.
const categoryOptions = computed(() => categories.value.filter((category) => category.is_active || category.id === info.category_id));
const typeOptions = computed(() => (categories.value.find((category) => category.id === info.category_id)?.types || []).filter((type) => type.is_active || type.id === info.type_id));

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const [{ data }, list] = await Promise.all([api.get(`/admin/products/${route.params.id}`), api.get('/admin/categories')]);
        categories.value = list.data.data.items;
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
        const { data } = await api.patch(`/admin/products/${product.value.id}`, { name: info.name, product_category_id: info.category_id || null, product_type_id: info.type_id || null, description: info.description || null, badges: info.badges.split(',').map((badge) => badge.trim()).filter(Boolean) });
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

// Mengetik terjemahan sendiri berarti mode manual, supaya AI tidak menimpanya.
function typed() {
    tr.mode = 'manual';
    trNote.value = '';
}

async function suggestTranslation() {
    trAiBusy.value = true;
    trError.value = '';
    trNote.value = '';
    try {
        const { data } = await api.post(`/admin/products/${product.value.id}/translate`);
        Object.assign(tr, { name_en: data.data.name_en, description_en: data.data.description_en || '', variants: { ...tr.variants, ...data.data.variants } });
        trNote.value = 'Usulan AI sudah diisi. Periksa dulu, lalu klik Simpan terjemahan.';
    } catch (e) {
        trError.value = apiError(e).message;
    } finally {
        trAiBusy.value = false;
    }
}

async function saveTranslation() {
    trBusy.value = true;
    trError.value = '';
    trNote.value = '';
    try {
        const { data } = await api.patch(`/admin/products/${product.value.id}`, {
            translation_mode: tr.mode,
            name_en: tr.name_en || null,
            description_en: tr.description_en || null,
            variant_names_en: tr.variants,
        });
        setProduct(data.data);
        trNote.value = tr.mode === 'ai' ? 'Tersimpan. AI menerjemahkan dalam beberapa detik; muat ulang halaman untuk melihat hasilnya.' : 'Tersimpan.';
    } catch (e) {
        trError.value = apiError(e).message;
    } finally {
        trBusy.value = false;
    }
}

async function togglePublish() {
    publishError.value = '';
    try {
        const { data } = await api.patch(`/admin/products/${product.value.id}`, { is_published: !product.value.is_published });
        setProduct(data.data);
    } catch (e) {
        const { message, fields } = apiError(e);
        publishError.value = fields.is_published?.[0] || fields.product_category_id?.[0] || message;
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
        imageName.value = '';
        imageAlt.value = '';
    } catch (e) {
        const { message, fields } = apiError(e);
        imageError.value = fields.image?.[0] || fields.alt?.[0] || message;
    } finally {
        imageBusy.value = false;
    }
}

// Urutan foto: foto pertama menjadi foto utama (dipakai toko). Perubahan langsung disimpan; gagal berarti urutan lama dikembalikan.
const dragId = ref(null);
const overId = ref(null);
const orderBusy = ref(false);
const orderError = ref('');

async function commitOrder(list) {
    const previous = product.value.images;
    product.value.images = list;
    orderBusy.value = true;
    orderError.value = '';
    try {
        const { data } = await api.put(`/admin/products/${product.value.id}/images/order`, { ids: list.map((item) => item.id) }, { toast: 'Urutan foto disimpan.' });
        product.value.images = data.data.images;
    } catch (e) {
        product.value.images = previous;
        orderError.value = apiError(e).message;
    } finally {
        orderBusy.value = false;
    }
}

function moveImage(image, delta) {
    const list = [...product.value.images];
    const from = list.findIndex((item) => item.id === image.id);
    const to = from + delta;
    if (to < 0 || to >= list.length) return;
    [list[from], list[to]] = [list[to], list[from]];
    commitOrder(list);
}

const makeMain = (image) => commitOrder([image, ...product.value.images.filter((item) => item.id !== image.id)]);

function onDragStart(image, event) {
    dragId.value = image.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(image.id));
}

function onDrop(target) {
    const from = dragId.value;
    dragId.value = null;
    overId.value = null;
    if (!from || from === target.id) return;
    const list = [...product.value.images];
    const [moved] = list.splice(list.findIndex((item) => item.id === from), 1);
    list.splice(list.findIndex((item) => item.id === target.id), 0, moved);
    commitOrder(list);
}

function askRemoveImage(image) {
    imageToDelete.value = image;
    imageError.value = '';
    imageDialog.value.showModal();
}

async function removeImage() {
    imageBusy.value = true;
    imageError.value = '';
    try {
        const { data } = await api.delete(`/admin/images/${imageToDelete.value.id}`);
        setProduct(data.data);
        imageDialog.value.close();
    } catch (e) {
        imageError.value = apiError(e).message;
    } finally {
        imageBusy.value = false;
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
  <RouterLink :to="{ name: 'AdminProducts' }" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-border bg-surface px-4 text-label-md hover:bg-accent-tint-15 ${press}`">
    <AdminIcon name="chevron-down" :size="16" class="rotate-90" />Kembali ke daftar produk
  </RouterLink>

  <!-- memuat: kerangka seukuran isi supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat produk" class="mt-space-lg space-y-space-md">
    <div class="h-14 w-2/3 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    <div class="grid gap-space-md sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="n in 4" :key="n" class="h-24 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
    </div>
    <div class="grid gap-space-lg lg:grid-cols-3">
      <div class="h-96 animate-pulse-soft rounded-3xl bg-secondary-tint-12 lg:col-span-2"></div>
      <div class="h-96 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
    </div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <template v-else>
    <div class="mt-space-md flex flex-wrap items-end justify-between gap-space-md">
      <PageHeader :title="product.name" icon="tag" group="Produk dan Harga"><span class="break-all text-body-sm">/produk/{{ product.slug }}</span></PageHeader>
      <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-label-sm" :class="product.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
          <i class="size-1.5 rounded-full" :class="product.is_published ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ product.is_published ? 'Tayang' : 'Draf' }}
        </span>
        <a v-if="product.is_published && storePath" :href="storePath" target="_blank" rel="noopener noreferrer" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`">
          <AdminIcon name="eye" :size="16" />Lihat di toko<span class="sr-only"> (tab baru)</span>
        </a>
        <button
          v-if="canManage"
          type="button"
          :class="[`inline-flex min-h-10 items-center gap-1.5 rounded-full px-4 text-label-md ${press}`, product.is_published ? 'border border-secondary hover:bg-accent-tint-15' : 'bg-accent hover:bg-accent-hover']"
          @click="togglePublish"
        >
          <AdminIcon :name="product.is_published ? 'x' : 'check'" :size="16" />{{ product.is_published ? 'Tarik publikasi' : 'Publikasikan' }}
        </button>
      </div>
    </div>
    <p v-if="publishError" role="alert" class="mt-space-md rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ publishError }}</p>

    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-2 lg:grid-cols-4" aria-label="Ringkasan produk">
      <li v-for="stat in stats" :key="stat.label" class="flex min-w-0 items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon :name="stat.icon" /></span>
        <span class="min-w-0">
          <span class="block text-body-sm text-secondary">{{ stat.label }}</span>
          <span class="block truncate font-heading text-headline-sm tabular-nums">{{ stat.value }}</span>
        </span>
      </li>
    </ul>

    <div class="mt-space-lg grid items-start gap-space-lg lg:grid-cols-3">
      <div class="min-w-0 space-y-space-lg lg:col-span-2">
        <section :class="card" aria-labelledby="pe-info">
          <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="edit" :size="18" /></span>
            <div><h2 id="pe-info" class="font-heading text-headline-sm">Informasi produk</h2><p class="text-body-sm text-secondary">Nama, kategori, dan deskripsi yang tampil di toko.</p></div>
          </div>
          <form class="mt-space-md space-y-space-md" @submit.prevent="saveInfo">
            <label class="block text-label-md">
              Nama produk
              <input v-model="info.name" required maxlength="150" :disabled="!canManage" :class="field" @input="infoSaved = false">
              <span v-if="infoFields.name" class="mt-1 block text-body-sm font-normal text-error">{{ infoFields.name[0] }}</span>
            </label>
            <div class="grid gap-space-md sm:grid-cols-2">
              <label class="block text-label-md">
                Kategori
                <select v-model="info.category_id" :disabled="!canManage" :class="field" @change="info.type_id = ''; infoSaved = false">
                  <option value="">Belum dipilih</option>
                  <option v-for="category in categoryOptions" :key="category.id" :value="category.id">{{ category.name_id }}</option>
                </select>
                <span v-if="infoFields.product_category_id" class="mt-1 block text-body-sm font-normal text-error">{{ infoFields.product_category_id[0] }}</span>
              </label>
              <label class="block text-label-md">
                Jenis <span class="font-normal text-secondary">(opsional)</span>
                <select v-model="info.type_id" :disabled="!canManage || !typeOptions.length" :class="field" @change="infoSaved = false">
                  <option value="">Tanpa jenis</option>
                  <option v-for="type in typeOptions" :key="type.id" :value="type.id">{{ type.name_id }}</option>
                </select>
                <span v-if="infoFields.product_type_id" class="mt-1 block text-body-sm font-normal text-error">{{ infoFields.product_type_id[0] }}</span>
              </label>
            </div>
            <label class="block text-label-md">
              Deskripsi
              <textarea v-model="info.description" rows="5" maxlength="5000" :disabled="!canManage" :class="`${field} resize-y`" @input="infoSaved = false"></textarea>
              <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ info.description.length }}/5000</span>
            </label>
            <label class="block text-label-md">
              Lencana <span class="font-normal text-secondary">(pisahkan dengan koma, maksimal 5)</span>
              <input v-model="info.badges" maxlength="200" placeholder="Contoh: Terlaris, Edisi Ramadan" :disabled="!canManage" :class="field" @input="infoSaved = false">
              <span v-if="badgeList.length" class="mt-2 flex flex-wrap gap-1.5">
                <span v-for="badge in badgeList" :key="badge" class="rounded-full bg-accent-tint-20 px-2.5 py-0.5 text-label-sm">{{ badge }}</span>
              </span>
              <span v-if="infoFields.badges || infoFields['badges.0']" class="mt-1 block text-body-sm font-normal text-error">{{ (infoFields.badges || infoFields['badges.0'])[0] }}</span>
            </label>
            <p v-if="infoError" role="alert" class="text-body-sm text-error">{{ infoError }}</p>
            <div v-if="canManage" class="flex flex-wrap items-center gap-3">
              <button :disabled="infoBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ infoBusy ? 'Menyimpan...' : 'Simpan' }}</button>
              <p v-if="infoSaved" role="status" class="inline-flex items-center gap-1 text-body-sm text-success"><AdminIcon name="check" :size="16" />Tersimpan.</p>
            </div>
          </form>
        </section>

        <section :class="card" aria-labelledby="pe-variants">
          <div class="flex flex-wrap items-start justify-between gap-space-sm">
            <div class="flex items-start gap-3">
              <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="box" :size="18" /></span>
              <div><h2 id="pe-variants" class="font-heading text-headline-sm">Varian dan harga</h2><p class="text-body-sm text-secondary">Satu varian = satu SKU di toko.</p></div>
            </div>
            <AddButton v-if="canManage" label="Tambah varian" @click="openVariant()" />
          </div>
          <p v-if="!product.variants.length" class="mt-space-md flex items-start gap-2 rounded-2xl border border-dashed border-border bg-background/70 p-4 text-body-sm text-secondary">
            <AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />Belum ada varian. Produk butuh minimal satu varian aktif sebelum dipublikasikan.
          </p>
          <div v-else class="relative mt-space-md overflow-x-auto rounded-2xl border border-border">
            <table class="w-full min-w-[640px] text-center text-body-sm">
              <caption class="sr-only">Varian dan harga {{ product.name }}</caption>
              <thead class="bg-background/70 text-label-sm uppercase tracking-wide text-secondary">
                <tr>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">SKU</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Varian</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Harga jual</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Berat</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Status</th>
                  <th v-if="canManage" scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="item in product.variants" :key="item.id" class="transition-colors duration-150 hover:bg-accent-tint-12">
                  <td class="whitespace-nowrap px-4 py-3 font-mono">{{ item.sku }}</td>
                  <td class="px-4 py-3 text-label-md">{{ item.name }}</td>
                  <td class="px-4 py-3">
                    <span class="block whitespace-nowrap text-label-md tabular-nums">{{ rupiah.format(item.price) }}</span>
                    <span v-if="item.compare_at_price" class="block whitespace-nowrap text-body-sm tabular-nums text-secondary line-through">{{ rupiah.format(item.compare_at_price) }}</span>
                  </td>
                  <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ item.weight_gram }} g</td>
                  <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="item.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
                      <i class="size-1.5 rounded-full" :class="item.is_active ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                    </span>
                  </td>
                  <td v-if="canManage" class="px-4 py-3"><IconAction icon="edit" label="Ubah varian" :subject="item.name" @click="openVariant(item)" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section :class="card" aria-labelledby="pe-discounts">
          <div class="flex flex-wrap items-start justify-between gap-space-sm">
            <div class="flex items-start gap-3">
              <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="sparkles" :size="18" /></span>
              <div><h2 id="pe-discounts" class="font-heading text-headline-sm">Diskon produk</h2><p class="text-body-sm text-secondary">Potongan per varian dan per segmen pelanggan.</p></div>
            </div>
            <AddButton v-if="canManage && product.variants.length" label="Tambah diskon" @click="openDiscount()" />
          </div>
          <p class="mt-space-md flex items-start gap-2 rounded-2xl bg-background/70 p-3 text-body-sm text-secondary">
            <AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />Harga jual varian tidak berubah. Pelanggan membayar harga setelah diskon. Diskon yang berlaku bersamaan ditumpuk, tetapi total potongan paling banyak {{ product.max_discount_percent }}% dari harga jual (atur di Pengaturan, bagian Diskon).
          </p>
          <p v-if="!discountRows.length" class="mt-space-md rounded-2xl border border-dashed border-border p-4 text-center text-body-sm text-secondary">Belum ada diskon.</p>
          <div v-else class="relative mt-space-md overflow-x-auto rounded-2xl border border-border">
            <table class="w-full min-w-[720px] text-center text-body-sm">
              <caption class="sr-only">Diskon produk {{ product.name }}</caption>
              <thead class="bg-background/70 text-label-sm uppercase tracking-wide text-secondary">
                <tr>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Varian</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Untuk</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Diskon</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Berlaku</th>
                  <th scope="col" class="whitespace-nowrap px-4 py-3">Status</th>
                  <th v-if="canManage" scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="entry in discountRows" :key="entry.id" class="transition-colors duration-150 hover:bg-accent-tint-12">
                  <td class="px-4 py-3 text-label-md">{{ entry.variant.name }}</td>
                  <td class="px-4 py-3">{{ AUDIENCES[entry.audience] }}</td>
                  <td class="px-4 py-3">
                    <span class="block whitespace-nowrap text-label-md tabular-nums">{{ discountLabel(entry) }}</span>
                    <span class="block whitespace-nowrap text-body-sm tabular-nums text-secondary">jadi {{ rupiah.format(discountedPrice(entry)) }}</span>
                  </td>
                  <td class="px-4 py-3 text-body-sm">
                    <template v-if="entry.starts_at || entry.ends_at">
                      <span class="block whitespace-nowrap">{{ entry.starts_at ? formatDate(entry.starts_at) : 'Sekarang' }}</span>
                      <span class="block whitespace-nowrap text-secondary">sampai {{ entry.ends_at ? formatDate(entry.ends_at) : 'tanpa batas' }}</span>
                    </template>
                    <span v-else class="text-secondary">Selalu berlaku</span>
                  </td>
                  <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="entry.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
                      <i class="size-1.5 rounded-full" :class="entry.is_active ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ entry.is_active ? 'Aktif' : 'Nonaktif' }}
                    </span>
                  </td>
                  <td v-if="canManage" class="px-4 py-3"><IconAction icon="edit" label="Ubah diskon" :subject="entry.variant.name" @click="openDiscount(entry)" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section :class="card" aria-labelledby="pe-translation">
          <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="chat" :size="18" /></span>
            <div><h2 id="pe-translation" class="font-heading text-headline-sm">Terjemahan Inggris</h2><p class="text-body-sm text-secondary">Dipakai di halaman toko berbahasa Inggris. Bila kosong, toko menampilkan teks Indonesia.</p></div>
          </div>
          <form class="mt-space-md space-y-space-md" @submit.prevent="saveTranslation">
            <fieldset :disabled="!canManage" class="grid gap-2 sm:grid-cols-2">
              <legend class="mb-2 text-label-md">Cara menerjemahkan</legend>
              <label v-for="mode in TR_MODES" :key="mode.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="tr.mode === mode.key ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
                <input v-model="tr.mode" type="radio" :value="mode.key" class="mt-1 size-4 accent-primary">
                <span><span class="block text-label-md">{{ mode.title }}</span><span class="block text-body-sm text-secondary">{{ mode.text }}</span></span>
              </label>
            </fieldset>
            <p v-if="product.translation_outdated" role="status" class="flex items-start gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
              <AdminIcon name="alert" :size="16" class="mt-0.5 shrink-0" />Teks Indonesia berubah sejak terjemahan terakhir. Periksa terjemahan di bawah.
            </p>
            <label class="block text-label-md">
              Nama (Inggris)
              <input v-model="tr.name_en" maxlength="150" :disabled="!canManage" :class="field" @input="typed">
            </label>
            <label class="block text-label-md">
              Deskripsi (Inggris)
              <textarea v-model="tr.description_en" rows="4" maxlength="5000" :disabled="!canManage" :class="`${field} resize-y`" @input="typed"></textarea>
            </label>
            <div v-if="product.variants.length" class="grid gap-space-sm sm:grid-cols-2">
              <label v-for="item in product.variants" :key="item.id" class="block text-label-md">
                Varian {{ item.name }} (Inggris)
                <input v-model="tr.variants[item.id]" maxlength="100" :disabled="!canManage" :class="field" @input="typed">
              </label>
            </div>
            <p v-if="trError" role="alert" class="text-body-sm text-error">{{ trError }}</p>
            <p v-else-if="trNote" role="status" class="text-body-sm text-success">{{ trNote }}</p>
            <div v-if="canManage" class="flex flex-wrap gap-2">
              <button type="button" :disabled="trAiBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="suggestTranslation"><AdminIcon name="sparkles" :size="16" />{{ trAiBusy ? 'AI menerjemahkan...' : 'Terjemahkan dengan AI' }}</button>
              <button :disabled="trBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ trBusy ? 'Menyimpan...' : 'Simpan terjemahan' }}</button>
            </div>
          </form>
        </section>
      </div>

      <section class="min-w-0" :class="card" aria-labelledby="pe-photos">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="eye" :size="18" /></span>
          <div><h2 id="pe-photos" class="font-heading text-headline-sm">Foto produk</h2><p class="text-body-sm text-secondary">{{ product.images.length }} foto. Foto pertama menjadi foto utama.</p></div>
        </div>

        <p v-if="!product.images.length" class="mt-space-md rounded-2xl border border-dashed border-border bg-background/70 p-6 text-center text-body-sm text-secondary">Belum ada foto.</p>
        <ul v-else class="mt-space-md grid grid-cols-2 gap-2" aria-label="Foto produk, urut dari foto utama">
          <li
            v-for="(image, index) in product.images"
            :key="image.id"
            class="group relative overflow-hidden rounded-2xl border bg-surface transition-[border-color,box-shadow,opacity] duration-150"
            :class="[index === 0 ? 'col-span-2' : '', overId === image.id && dragId !== image.id ? 'border-primary ring-2 ring-accent' : 'border-border', dragId === image.id ? 'opacity-50' : '']"
            :draggable="canManage && product.images.length > 1"
            @dragstart="onDragStart(image, $event)"
            @dragover.prevent="dragId && image.id !== dragId && (overId = image.id)"
            @dragleave="overId = null"
            @drop.prevent="onDrop(image)"
            @dragend="dragId = null; overId = null"
          >
            <img :src="image.url" :alt="image.alt" loading="lazy" draggable="false" class="w-full object-cover" :class="index === 0 ? 'aspect-[4/3]' : 'aspect-square'">
            <span class="absolute left-2 top-2 rounded-full px-2.5 py-1 text-label-sm shadow-card" :class="index === 0 ? 'bg-primary text-surface' : 'bg-surface/90 tabular-nums'">{{ index === 0 ? 'Foto utama' : `#${index + 1}` }}</span>
            <!-- Alat foto: muncul saat disorot atau difokus; di layar sentuh selalu terlihat. -->
            <div v-if="canManage" class="absolute inset-x-0 bottom-0 flex flex-wrap items-center gap-1 bg-gradient-to-t from-primary/75 via-primary/30 to-transparent p-2 pt-8 opacity-0 transition-opacity duration-150 focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
              <IconAction v-if="index > 0" icon="star" label="Jadikan foto utama" :subject="image.alt" :disabled="orderBusy" class="bg-surface/95 shadow-card" @click="makeMain(image)" />
              <template v-if="product.images.length > 1">
                <IconAction icon="chevron-left" label="Geser ke depan" :subject="image.alt" :disabled="orderBusy || index === 0" class="bg-surface/95 shadow-card" @click="moveImage(image, -1)" />
                <IconAction icon="chevron-right" label="Geser ke belakang" :subject="image.alt" :disabled="orderBusy || index === product.images.length - 1" class="bg-surface/95 shadow-card" @click="moveImage(image, 1)" />
              </template>
              <IconAction icon="trash" tone="danger" label="Hapus foto" :subject="image.alt" :disabled="orderBusy" class="ml-auto bg-surface/95 shadow-card" @click="askRemoveImage(image)" />
            </div>
          </li>
        </ul>
        <p v-if="canManage && product.images.length > 1" class="mt-2 text-body-sm text-secondary">Seret foto, atau pakai tombol panah, untuk mengubah urutan. Foto pertama menjadi foto utama.</p>
        <p v-if="orderError" role="alert" class="mt-1 text-body-sm text-error">{{ orderError }}</p>

        <form v-if="canManage" class="mt-space-md space-y-space-sm border-t border-border pt-space-md" @submit.prevent="uploadImage">
          <label class="flex cursor-pointer flex-col items-center gap-1 rounded-2xl border border-dashed border-secondary px-4 py-5 text-center transition-colors duration-150 hover:bg-accent-tint-12 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
            <AdminIcon name="plus" :size="22" class="text-secondary" />
            <span class="break-all text-label-md">{{ imageName || 'Pilih foto' }}</span>
            <span class="text-body-sm text-secondary">JPG, JPEG, atau PNG, maksimal 2 MB</span>
            <input ref="imageInput" type="file" accept="image/jpeg,image/png" class="sr-only" @change="imageName = $event.target.files[0]?.name || ''">
          </label>
          <label class="block text-label-md">
            Teks alternatif
            <input v-model="imageAlt" required maxlength="150" placeholder="Contoh: Toples Nastar Sultan" :class="field">
          </label>
          <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
          <button :disabled="imageBusy" :class="`inline-flex min-h-10 w-full items-center justify-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="plus" :size="16" />{{ imageBusy ? 'Mengunggah...' : 'Unggah foto' }}</button>
        </form>
      </section>
    </div>
  </template>

  <AppModal v-if="product" ref="discountDialog" :variant="editingDiscount ? 'update' : 'create'" size="xl" :title="editingDiscount ? 'Ubah diskon' : 'Tambah diskon'" :subtitle="product.name">
    <form id="discount-form" class="space-y-space-md" @submit.prevent="saveDiscount">
      <p v-if="discountError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ discountError }}</p>
      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Varian
          <select v-model="discount.variant_id" :disabled="!!editingDiscount" :class="field">
            <option v-for="item in product.variants" :key="item.id" :value="item.id">{{ item.name }} ({{ rupiah.format(item.price) }})</option>
          </select>
        </label>
        <label class="block text-label-md">
          Untuk siapa
          <select v-model="discount.audience" :class="field">
            <option v-for="(label, value) in AUDIENCES" :key="value" :value="value">{{ label }}</option>
          </select>
          <span v-if="discountFields.audience" class="mt-1 block text-body-sm font-normal text-error">{{ discountFields.audience[0] }}</span>
        </label>
        <label class="block text-label-md">
          Jenis
          <select v-model="discount.type" :class="field" @change="discount.value = ''">
            <option value="percent">Persen</option>
            <option value="fixed">Potongan rupiah</option>
          </select>
        </label>
        <label class="block text-label-md">
          {{ discount.type === 'percent' ? 'Diskon (%, maksimal ' + product.max_discount_percent + ')' : 'Potongan (Rp)' }}
          <RupiahInput v-if="discount.type === 'fixed'" v-model="discount.value" required />
          <input v-else v-model.number="discount.value" type="number" required min="1" :max="product.max_discount_percent" :class="field">
          <span v-if="discountFields.value" class="mt-1 block text-body-sm font-normal text-error">{{ discountFields.value[0] }}</span>
        </label>
        <label class="block text-label-md">
          Mulai <span class="font-normal text-secondary">(opsional)</span>
          <input v-model="discount.starts_at" type="datetime-local" :class="field">
        </label>
        <label class="block text-label-md">
          Selesai <span class="font-normal text-secondary">(opsional)</span>
          <input v-model="discount.ends_at" type="datetime-local" :class="field">
          <span v-if="discountFields.ends_at" class="mt-1 block text-body-sm font-normal text-error">{{ discountFields.ends_at[0] }}</span>
        </label>
      </div>
      <p v-if="previewPrice" class="flex items-center gap-2 rounded-2xl bg-accent-tint-15 px-4 py-3 text-body-sm"><AdminIcon name="tag" :size="16" />Harga setelah diskon: <strong class="tabular-nums">{{ rupiah.format(previewPrice) }}</strong></p>
      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="discount.is_active ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="discount.is_active" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span><span class="block text-label-md">Diskon aktif</span><span class="block text-body-sm text-secondary">Berlaku di toko sesuai periode di atas.</span></span>
      </label>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="discountDialog.close()">Batal</button>
      <button form="discount-form" :disabled="discountBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ discountBusy ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal v-if="product" ref="variantDialog" :variant="editingVariant ? 'update' : 'create'" size="xl" :title="editingVariant ? 'Ubah varian' : 'Tambah varian'" :subtitle="product.name">
    <form id="variant-form" class="space-y-space-md" @submit.prevent="saveVariant">
      <p v-if="variantError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ variantError }}</p>
      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          SKU
          <input v-model="variant.sku" required maxlength="64" :class="field" class="font-mono">
          <span v-if="variantFields.sku" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.sku[0] }}</span>
        </label>
        <label class="block text-label-md">
          Nama varian
          <input v-model="variant.name" required maxlength="100" placeholder="Contoh: Toples 500 g" :class="field">
          <span v-if="variantFields.name" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.name[0] }}</span>
        </label>
        <label class="block text-label-md">
          Harga jual (Rp)
          <RupiahInput v-model="variant.price" required />
          <span v-if="variantFields.price" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.price[0] }}</span>
        </label>
        <label class="block text-label-md">
          Harga normal <span class="font-normal text-secondary">(Rp, opsional)</span>
          <RupiahInput v-model="variant.compare_at_price" />
          <span v-if="variantFields.compare_at_price" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.compare_at_price[0] }}</span>
        </label>
        <label class="block text-label-md">
          Berat paket (gram)
          <input v-model.number="variant.weight_gram" type="number" required min="1" :class="field">
          <span v-if="variantFields.weight_gram" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.weight_gram[0] }}</span>
        </label>
        <label class="block text-label-md">
          Berat bersih <span class="font-normal text-secondary">(gram, opsional)</span>
          <input v-model.number="variant.net_weight_gram" type="number" min="1" :class="field">
        </label>
        <label class="block text-label-md">
          Umur simpan <span class="font-normal text-secondary">(hari, opsional)</span>
          <input v-model.number="variant.shelf_life_days" type="number" min="1" :class="field">
        </label>
        <label class="block text-label-md">
          Batas jumlah per pesanan
          <input v-model.number="variant.max_order_quantity" type="number" required min="1" max="999" :class="field">
          <span v-if="variantFields.max_order_quantity" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.max_order_quantity[0] }}</span>
        </label>
        <label class="block text-label-md">
          Batas stok menipis <span class="font-normal text-secondary">(opsional, kosong = ikut Pengaturan)</span>
          <input v-model.number="variant.low_stock_threshold" type="number" min="0" max="1000" :class="field">
          <span v-if="variantFields.low_stock_threshold" class="mt-1 block text-body-sm font-normal text-error">{{ variantFields.low_stock_threshold[0] }}</span>
        </label>
        <fieldset class="text-label-md sm:col-span-2">
          <legend>Dimensi paket <span class="font-normal text-secondary">(cm, opsional)</span></legend>
          <div class="mt-1 grid grid-cols-3 gap-space-xs">
            <input v-model.number="variant.length_cm" type="number" min="1" aria-label="Panjang" placeholder="Panjang" :class="field" class="!mt-0">
            <input v-model.number="variant.width_cm" type="number" min="1" aria-label="Lebar" placeholder="Lebar" :class="field" class="!mt-0">
            <input v-model.number="variant.height_cm" type="number" min="1" aria-label="Tinggi" placeholder="Tinggi" :class="field" class="!mt-0">
          </div>
        </fieldset>
      </div>
      <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="variant.is_active ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
        <input v-model="variant.is_active" type="checkbox" class="mt-0.5 size-4 accent-primary">
        <span><span class="block text-label-md">Varian aktif</span><span class="block text-body-sm text-secondary">Varian nonaktif tidak tampil dan tidak bisa dibeli di toko.</span></span>
      </label>
      <span v-if="variantFields.is_active" class="block text-body-sm text-error">{{ variantFields.is_active[0] }}</span>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="variantDialog.close()">Batal</button>
      <button form="variant-form" :disabled="variantBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ variantBusy ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal v-if="product" ref="imageDialog" variant="delete" size="md" title="Hapus foto?" :subtitle="product.name" @close="imageToDelete = null">
    <div v-if="imageToDelete" class="flex items-center gap-3">
      <img :src="imageToDelete.url" :alt="imageToDelete.alt" class="size-20 shrink-0 rounded-2xl border border-border object-cover">
      <p class="text-body-sm">Foto ini dihapus dari produk dan tidak bisa dikembalikan. {{ imageToDelete.alt }}</p>
    </div>
    <p v-if="imageError" role="alert" class="mt-3 text-body-sm text-error">{{ imageError }}</p>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="imageDialog.close()">Batal</button>
      <button type="button" :disabled="imageBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="removeImage"><AdminIcon name="trash" :size="16" />{{ imageBusy ? 'Menghapus...' : 'Hapus foto' }}</button>
    </template>
  </AppModal>
</template>
