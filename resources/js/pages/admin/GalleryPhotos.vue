<script setup>
import { computed, reactive, ref } from 'vue';
import AppModal from '../../components/ui/AppModal.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import IconAction from '../../components/ui/IconAction.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import AddButton from '../../components/ui/AddButton.vue';

// Foto di halaman Galeri toko (D-083). Kunci kategori = tab ikon di toko.
const CATEGORIES = { nastar: 'Nastar', macaron: 'Macaron', pia: 'Pia', snack: 'Camilan' };
const COLUMNS = [
    { key: 'photo', label: 'Foto' },
    { key: 'title', label: 'Judul' },
    { key: 'category', label: 'Kategori' },
    { key: 'sort_order', label: 'Urutan' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];

const auth = useAuthStore();
const canManage = computed(() => auth.can('content.manage'));

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/gallery-photos', {
    filters: { category: '', is_published: '' },
});

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { title: '', title_en: '', category: 'nastar', sort_order: 0, is_featured: false, is_published: true };
const form = reactive({ ...blank });
const imageInput = ref(null);
const imageError = ref('');
const imageBusy = ref(false);

function open(photo = null) {
    editing.value = photo;
    Object.assign(form, blank, photo ? { ...photo, title_en: photo.title_en || '' } : {});
    formError.value = '';
    fieldErrors.value = {};
    imageError.value = '';
    dialog.value.showModal();
}

async function save() {
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        title: form.title,
        title_en: form.title_en || null,
        category: form.category,
        sort_order: Number(form.sort_order) || 0,
        is_featured: form.is_featured,
        is_published: form.is_published,
    };
    saving.value = true;
    try {
        if (editing.value) {
            await api.patch(`/admin/gallery-photos/${editing.value.id}`, body);
        } else {
            const file = imageInput.value?.files?.[0];
            if (!file) {
                fieldErrors.value = { image: ['Pilih foto dulu.'] };
                return;
            }
            const upload = new FormData();
            Object.entries(body).forEach(([key, value]) => {
                if (value === null) return;
                upload.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
            });
            upload.append('image', file);
            await api.post('/admin/gallery-photos', upload);
        }
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

async function replaceImage() {
    const file = imageInput.value?.files?.[0];
    imageError.value = '';
    if (!file) {
        imageError.value = 'Pilih foto dulu.';
        return;
    }
    imageBusy.value = true;
    try {
        const upload = new FormData();
        upload.append('image', file);
        const { data } = await api.post(`/admin/gallery-photos/${editing.value.id}/image`, upload);
        editing.value = data.data;
        imageInput.value.value = '';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        imageError.value = fields.image?.[0] || message;
    } finally {
        imageBusy.value = false;
    }
}

async function remove() {
    if (!window.confirm(`Hapus "${editing.value.title}"? Foto hilang dari daftar dan toko; riwayatnya tetap tercatat.`)) return;
    saving.value = true;
    formError.value = '';
    try {
        await api.delete(`/admin/gallery-photos/${editing.value.id}`);
        dialog.value.close();
        await load();
    } catch (e) {
        formError.value = apiError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-end gap-2">
    <TableToolbar v-model="search" placeholder="Cari judul foto">
      <FilterSelect v-model="filter.category" label="Kategori" :options="CATEGORIES" />
      <FilterSelect v-model="filter.is_published" label="Status" :options="{ 1: 'Tayang', 0: 'Disembunyikan' }" />
    </TableToolbar>
    <AddButton v-if="canManage" label="Tambah foto" @click="open()" />
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-md"
    caption="Daftar foto galeri"
    empty-text="Belum ada foto galeri."
    min-width="min-w-[640px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-photo="{ row }"><img :src="row.image_url" alt="" width="48" height="48" loading="lazy" class="size-12 rounded object-cover"></template>
    <template #cell-title="{ row }">
      <span class="text-label-md">{{ row.title }}</span>
      <span v-if="row.is_featured" class="ml-2 rounded-full bg-accent-tint-15 px-2 py-0.5 text-label-sm">Besar</span>
    </template>
    <template #cell-category="{ row }">{{ CATEGORIES[row.category] }}</template>
    <template #cell-status="{ row }">
      <span class="rounded-full px-2 py-0.5 text-label-sm" :class="row.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ row.is_published ? 'Tayang' : 'Disembunyikan' }}</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Ubah' : 'Lihat'" :subject="row.title" @click="open(row)" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="editing ? (canManage ? 'update' : 'read') : 'create'" size="xl" :title="editing ? (canManage ? 'Ubah foto' : 'Detail foto') : 'Tambah foto'">
    <form id="gallery-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="space-y-space-md">
        <label class="block text-label-md">
          Judul (Indonesia)
          <input v-model="form.title" required maxlength="150" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md">
          Judul (English, opsional)
          <input v-model="form.title_en" maxlength="150" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span class="mt-1 block text-body-sm font-normal text-secondary">Bila kosong, halaman English memakai judul Indonesia.</span>
        </label>
        <div class="grid gap-space-md sm:grid-cols-2">
          <label class="block text-label-md">
            Kategori
            <select v-model="form.category" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
              <option v-for="(label, key) in CATEGORIES" :key="key" :value="key">{{ label }}</option>
            </select>
            <span v-if="fieldErrors.category" class="mt-1 block text-body-sm text-error">{{ fieldErrors.category[0] }}</span>
          </label>
          <label class="block text-label-md">
            Urutan tampil
            <input v-model="form.sort_order" type="number" min="0" max="9999" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
            <span class="mt-1 block text-body-sm font-normal text-secondary">Angka kecil tampil lebih dulu.</span>
            <span v-if="fieldErrors.sort_order" class="mt-1 block text-body-sm text-error">{{ fieldErrors.sort_order[0] }}</span>
          </label>
        </div>
        <label class="flex items-center gap-space-sm text-label-md">
          <input v-model="form.is_featured" type="checkbox" class="size-4 accent-primary">
          Tampil besar di grid
        </label>
        <label class="flex items-center gap-space-sm text-label-md">
          <input v-model="form.is_published" type="checkbox" class="size-4 accent-primary">
          Tayang di toko
        </label>
        <label v-if="!editing" class="block text-label-md">
          Foto (JPG, JPEG, atau PNG, maksimal 5 MB)
          <input ref="imageInput" type="file" required accept="image/jpeg,image/png" class="mt-1 block w-full text-body-md">
          <span class="mt-1 block text-body-sm font-normal text-secondary">Disarankan persegi 1:1. Foto diperkecil otomatis ke maksimal 1600 piksel.</span>
          <span v-if="fieldErrors.image" class="mt-1 block text-body-sm text-error">{{ fieldErrors.image[0] }}</span>
        </label>
      </fieldset>
    </form>

    <section v-if="editing" class="mt-space-md border-t border-border pt-space-md">
      <h3 class="font-heading text-headline-sm">Foto</h3>
      <img :src="editing.image_url" :alt="editing.title" class="mt-space-sm max-h-48 rounded object-contain">
      <form v-if="canManage" class="mt-space-sm space-y-space-sm" @submit.prevent="replaceImage">
        <label class="block text-label-md">
          Ganti foto (JPG, JPEG, atau PNG, maksimal 5 MB)
          <input ref="imageInput" type="file" accept="image/jpeg,image/png" class="mt-1 block w-full text-body-md">
        </label>
        <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
        <button :disabled="imageBusy" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60">{{ imageBusy ? 'Mengunggah...' : 'Unggah foto baru' }}</button>
      </form>
    </section>

    <template #footer>
      <button v-if="canManage && editing" type="button" :disabled="saving" class="mr-auto rounded px-3 py-2 text-label-md text-error underline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60" @click="remove">Hapus</button>
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Tutup</button>
      <button v-if="canManage" form="gallery-form" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
