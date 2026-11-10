<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { CATEGORY_COLORS, COLOR_KEYS, categoryColor } from '../../utils/categoryColors';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const auth = useAuthStore();
const canManage = computed(() => auth.can('categories.manage'));

const categories = ref([]);
const loading = ref(true);
const error = ref('');

// Satu dialog untuk kategori dan jenis: bentuk isiannya sama (nama ID, nama EN, urutan, aktif).
const dialog = ref(null);
const target = ref(null); // { kind: 'category' | 'type', parent?: kategori induk jenis, item?: yang diubah }
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { name_id: '', name_en: '', sort_order: '', is_active: true, color: '' };
const form = reactive({ ...blank });

// Akordeon: kategori pertama terbuka saat halaman dimuat, sisanya tertutup.
const openIds = ref([]);
const isOpen = (id) => openIds.value.includes(id);
const toggle = (id) => { openIds.value = isOpen(id) ? openIds.value.filter((x) => x !== id) : [...openIds.value, id]; };

const title = computed(() => {
    if (!target.value) return '';
    const noun = target.value.kind === 'type' ? 'jenis' : 'kategori';
    return `${target.value.item ? 'Ubah' : 'Tambah'} ${noun}`;
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/categories');
        categories.value = data.data.items;
        if (!openIds.value.length && categories.value.length) openIds.value = [categories.value[0].id];
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function open(kind, parent = null, item = null) {
    target.value = { kind, parent, item };
    // Kategori baru mulai dengan warna palet yang belum dipakai, supaya langsung berbeda dari yang lain.
    const unused = COLOR_KEYS.find((key) => !categories.value.some((c) => c.color === key)) || COLOR_KEYS[0];
    Object.assign(form, blank, item
        ? { name_id: item.name_id, name_en: item.name_en, sort_order: item.sort_order, is_active: item.is_active, color: item.color || '' }
        : { color: kind === 'category' ? unused : '' });
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    const { kind, parent, item } = target.value;
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { name_id: form.name_id, name_en: form.name_en, is_active: form.is_active };
    if (form.sort_order !== '') body.sort_order = Number(form.sort_order);
    if (kind === 'category' && form.color) body.color = form.color;
    try {
        if (kind === 'category') {
            if (item) await api.patch(`/admin/categories/${item.id}`, body);
            else await api.post('/admin/categories', body);
        } else if (item) {
            await api.patch(`/admin/category-types/${item.id}`, body);
        } else {
            await api.post(`/admin/categories/${parent.id}/types`, body);
        }
        dialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = fields.is_active?.[0] || message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-space-md">
    <PageHeader title="Kategori Produk" description="Susunannya Kategori, lalu Jenis, lalu Produk, lalu Varian. Kategori dan jenis tampil sebagai filter di toko. Yang sudah tidak dipakai dinonaktifkan, bukan dihapus." />
    <AddButton v-if="canManage" label="Tambah kategori" @click="open('category')" />
  </div>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat kategori...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!categories.length" class="mt-space-lg text-secondary">Belum ada kategori.</p>
  <div v-else class="mt-space-lg space-y-space-md">
    <section v-for="(category, index) in categories" :key="category.id" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-card">
      <div class="flex flex-wrap items-center gap-space-sm px-5 py-4" :class="[categoryColor(category.color, index).soft, categoryColor(category.color, index).ink]">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-3 rounded-xl text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          :aria-expanded="isOpen(category.id)"
          :aria-controls="`category-${category.id}`"
          @click="toggle(category.id)"
        >
          <span class="size-3.5 shrink-0 rounded-full" :class="categoryColor(category.color, index).dot" aria-hidden="true"></span>
          <span class="min-w-0">
            <span class="block font-heading text-headline-sm">{{ category.name_id }}</span>
            <span class="block text-body-sm opacity-80">{{ category.name_en }} · /produk?kategori={{ category.slug }} · {{ category.products_count }} produk · {{ category.types.length }} jenis</span>
          </span>
          <AdminIcon name="chevron-down" class="ml-auto transition-transform duration-200 ease-out-strong" :class="isOpen(category.id) ? 'rotate-180' : ''" />
        </button>
        <div class="flex items-center gap-space-sm">
          <span class="rounded-full px-2.5 py-1 text-label-sm" :class="category.is_active ? 'bg-success-surface text-success' : 'bg-surface/70'">{{ category.is_active ? 'Aktif' : 'Nonaktif' }}</span>
          <IconAction v-if="canManage" icon="edit" label="Ubah" :subject="category.name_id" class="bg-surface/70 hover:bg-surface" @click="open('category', null, category)" />
        </div>
      </div>

      <div
        :id="`category-${category.id}`"
        class="grid transition-[grid-template-rows] duration-300 ease-drawer"
        :class="isOpen(category.id) ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
      >
        <div class="overflow-hidden" :inert="!isOpen(category.id)">
          <div class="px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-space-sm">
              <h3 class="text-label-md">Jenis</h3>
              <IconAction v-if="canManage" icon="plus" tone="primary" label="Tambah jenis" :subject="category.name_id" @click="open('type', category)" />
            </div>
            <p v-if="!category.types.length" class="mt-space-sm text-body-sm text-secondary">Belum ada jenis. Produk di kategori ini tidak punya sub-filter.</p>
            <ul v-else class="mt-space-sm divide-y divide-border">
              <li v-for="type in category.types" :key="type.id" class="flex flex-wrap items-center justify-between gap-space-sm py-2">
                <div class="flex items-center gap-2">
                  <span class="size-2 shrink-0 rounded-full" :class="categoryColor(category.color, index).dot" aria-hidden="true"></span>
                  <span class="text-body-md">{{ type.name_id }}</span>
                  <span class="text-body-sm text-secondary">{{ type.name_en }} · {{ type.products_count }} produk</span>
                </div>
                <div class="flex items-center gap-space-sm">
                  <span class="rounded-full px-2 py-0.5 text-label-sm" :class="type.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ type.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                  <IconAction v-if="canManage" icon="edit" label="Ubah" :subject="type.name_id" @click="open('type', category, type)" />
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>
  </div>

  <AppModal ref="dialog" :variant="target?.item ? 'update' : 'create'" :title="title">
    <form v-if="target" id="category-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="target.kind === 'type'" class="text-body-sm text-secondary">Di kategori {{ target.parent.name_id }}.</p>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama (Bahasa Indonesia)
        <input v-model="form.name_id" required maxlength="80" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.name_id" class="mt-1 block text-body-sm text-error">{{ fieldErrors.name_id[0] }}</span>
      </label>
      <label class="block text-label-md">
        Nama (Bahasa Inggris)
        <input v-model="form.name_en" required maxlength="80" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.name_en" class="mt-1 block text-body-sm text-error">{{ fieldErrors.name_en[0] }}</span>
      </label>
      <label class="block text-label-md">
        Urutan (opsional, angka kecil tampil lebih dulu)
        <input v-model="form.sort_order" type="number" min="0" max="1000" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.sort_order" class="mt-1 block text-body-sm text-error">{{ fieldErrors.sort_order[0] }}</span>
      </label>
      <fieldset v-if="target.kind === 'category'">
        <legend class="text-label-md">Warna kategori</legend>
        <div class="mt-2 flex flex-wrap gap-2">
          <label v-for="(c, key) in CATEGORY_COLORS" :key="key" class="cursor-pointer" :title="c.label">
            <input v-model="form.color" type="radio" name="category-color" :value="key" class="peer sr-only">
            <span class="flex size-9 items-center justify-center rounded-full border-2 border-transparent transition-transform duration-150 peer-checked:scale-110 peer-checked:border-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary" :class="c.soft">
              <span class="size-5 rounded-full" :class="c.dot"></span>
            </span>
            <span class="sr-only">{{ c.label }}</span>
          </label>
        </div>
      </fieldset>
      <label class="flex items-center gap-space-sm text-label-md">
        <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary">
        Aktif (tampil di toko dan bisa dipilih untuk produk)
      </label>
    </form>
    <template #footer>
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
      <button form="category-form" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
