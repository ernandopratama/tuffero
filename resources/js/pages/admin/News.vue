<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AddButton from '../../components/ui/AddButton.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { TONE_CLASS } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';

// Berita popup beranda (D-085): berita, update, dan promo manual. Kampanye yang sedang berjalan ikut tampil otomatis.
// Lihat: semua role admin. Kelola: Marketing dan Super Admin (izin news.manage, dijaga server).
const auth = useAuthStore();
const canManage = computed(() => auth.can('news.manage'));

const KINDS = {
    news: { label: 'Berita', text: 'Kabar umum toko.', badge: 'bg-secondary-tint-12 text-secondary' },
    update: { label: 'Update', text: 'Pembaruan layanan atau produk.', badge: 'bg-success-surface text-success' },
    promo: { label: 'Promo', text: 'Penawaran khusus di luar kampanye.', badge: 'bg-error-surface text-on-error-surface' },
};
const STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    upcoming: { label: 'Akan datang', tone: 'info' },
    live: { label: 'Tayang', tone: 'ok' },
    ended: { label: 'Berakhir', tone: 'neutral' },
};
const DOT = { ok: 'bg-success', info: 'bg-info', neutral: 'bg-secondary' };

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

const items = ref([]);
const popup = ref({ limit: 5, used: 0 });
const loading = ref(true);
const loadError = ref('');

const dialog = ref(null);
const deleteDialog = ref(null);
const editing = ref(null);
const toDelete = ref(null);
const saving = ref(false);
const deleting = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { kind: 'news', title: '', title_en: '', body: '', body_en: '', cta_label: '', cta_label_en: '', cta_url: '', starts_at: '', ends_at: '', sort_order: 0, is_published: false };
const form = reactive({ ...blank });

const imageInput = ref(null);
const imageFile = ref(null);
const imageName = ref('');
const imageBusy = ref(false);
const imageError = ref('');

const toneOf = (row) => STATUS[row.status]?.tone || 'neutral';
const liveCount = computed(() => items.value.filter((row) => row.status === 'live').length);
const draftCount = computed(() => items.value.filter((row) => row.status === 'draft').length);
const slotPercent = computed(() => Math.min(100, Math.round((popup.value.used / popup.value.limit) * 100)));
const preview = computed(() => ({
    badge: KINDS[form.kind]?.label || 'Berita',
    title: form.title || 'Judul berita',
    body: form.body || 'Teks singkat tampil di sini.',
    cta: form.cta_label && form.cta_url ? form.cta_label : '',
    image: editing.value?.image_url || '',
}));

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/news');
        items.value = data.data.items;
        popup.value = data.data.popup;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function resetImage() {
    imageFile.value = null;
    imageName.value = '';
    imageError.value = '';
    if (imageInput.value) imageInput.value.value = '';
}

function open(row = null) {
    formError.value = '';
    fieldErrors.value = {};
    resetImage();
    editing.value = row;
    Object.assign(form, blank, row ? {
        kind: row.kind,
        title: row.title,
        title_en: row.title_en ?? '',
        body: row.body,
        body_en: row.body_en ?? '',
        cta_label: row.cta_label ?? '',
        cta_label_en: row.cta_label_en ?? '',
        cta_url: row.cta_url ?? '',
        starts_at: toLocalInput(row.starts_at),
        ends_at: toLocalInput(row.ends_at),
        sort_order: row.sort_order,
        is_published: row.is_published,
    } : {});
    dialog.value.showModal();
}

function onPick(event) {
    imageFile.value = event.target.files[0] || null;
    imageName.value = imageFile.value?.name || '';
    imageError.value = '';
}

async function uploadImage(id) {
    const body = new FormData();
    body.append('image', imageFile.value);
    const { data } = await api.post(`/admin/news/${id}/image`, body);
    editing.value = data.data;
    resetImage();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        ...form,
        title_en: form.title_en || null,
        body_en: form.body_en || null,
        cta_label: form.cta_label || null,
        cta_label_en: form.cta_label_en || null,
        cta_url: form.cta_url || null,
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        sort_order: Number(form.sort_order) || 0,
    };
    let saved = null;
    try {
        const { data } = editing.value ? await api.patch(`/admin/news/${editing.value.id}`, body) : await api.post('/admin/news', body);
        saved = data.data;
        editing.value = saved;
        if (imageFile.value) await uploadImage(saved.id);
        dialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        // Berita sudah tersimpan tetapi gambarnya gagal: modal tetap terbuka dalam mode ubah supaya gambar bisa dicoba lagi.
        if (saved) {
            imageError.value = fields.image?.[0] || message;
            await load();
        } else {
            formError.value = message;
            fieldErrors.value = fields;
        }
    } finally {
        saving.value = false;
    }
}

async function removeImage() {
    imageBusy.value = true;
    imageError.value = '';
    try {
        const { data } = await api.delete(`/admin/news/${editing.value.id}/image`);
        editing.value = data.data;
        await load();
    } catch (e) {
        imageError.value = apiError(e).message;
    } finally {
        imageBusy.value = false;
    }
}

function askDelete(row) {
    toDelete.value = row;
    deleteDialog.value.showModal();
}

async function confirmDelete() {
    deleting.value = true;
    try {
        await api.delete(`/admin/news/${toDelete.value.id}`, { toast: 'Berita dihapus.' });
        deleteDialog.value.close();
        await load();
    } catch (e) {
        loadError.value = apiError(e).message;
        deleteDialog.value.close();
    } finally {
        deleting.value = false;
    }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Berita Popup" description="Berita, update, dan promo di popup beranda toko. Kampanye yang sedang berjalan ikut tampil otomatis. Popup memuat paling banyak 5 item dan tampil sekali per sesi pengunjung." />
    <AddButton v-if="canManage" label="Tambah berita" @click="open()" />
  </div>

  <div v-if="loading" role="status" aria-label="Memuat berita" class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
    <div v-for="n in 3" :key="n" class="h-72 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <template v-else>
    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-3" aria-label="Ringkasan popup">
      <li class="rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <div class="flex items-center gap-3">
          <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="bell" /></span>
          <span class="min-w-0">
            <span class="block text-body-sm text-secondary">Slot popup terpakai</span>
            <span class="block font-heading text-headline-sm tabular-nums">{{ popup.used }}/{{ popup.limit }}</span>
          </span>
        </div>
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-secondary-tint-12" role="progressbar" :aria-valuenow="popup.used" aria-valuemin="0" :aria-valuemax="popup.limit" aria-label="Slot popup terpakai">
          <div class="h-full origin-left rounded-full bg-accent transition-transform duration-300 ease-out-strong" :style="{ transform: `scaleX(${slotPercent / 100})` }"></div>
        </div>
      </li>
      <li class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="check" /></span>
        <span class="min-w-0"><span class="block text-body-sm text-secondary">Berita tayang</span><span class="block font-heading text-headline-sm tabular-nums">{{ liveCount }}</span></span>
      </li>
      <li class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="edit" /></span>
        <span class="min-w-0"><span class="block text-body-sm text-secondary">Draf</span><span class="block font-heading text-headline-sm tabular-nums">{{ draftCount }}</span></span>
      </li>
    </ul>

    <p v-if="popup.used >= popup.limit" class="mt-space-md flex items-start gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
      <AdminIcon name="alert" :size="16" class="mt-0.5 shrink-0" />Slot popup penuh. Urutannya: kampanye berjalan, lalu berita menurut angka urutan. Item di luar {{ popup.limit }} teratas tidak tampil.
    </p>

    <div v-if="!items.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
      <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="bell" :size="26" /></span>
      <p class="font-heading text-headline-sm">Belum ada berita</p>
      <p class="max-w-md text-body-sm text-secondary">Tambahkan berita, update, atau promo yang ingin dilihat pengunjung saat membuka beranda.</p>
      <AddButton v-if="canManage" label="Tambah berita" expanded class="mt-1" @click="open()" />
    </div>

    <ul v-else class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="row in items" :key="row.id" class="flex flex-col overflow-hidden rounded-3xl border border-border bg-surface shadow-card" :class="row.status === 'draft' || row.status === 'ended' ? 'opacity-90' : ''">
        <div class="relative aspect-[16/9] bg-gradient-to-br from-accent-tint-20 to-accent-tint-12">
          <img v-if="row.image_url" :src="row.image_url" :alt="row.title" loading="lazy" class="size-full object-cover">
          <span v-else class="grid size-full place-items-center text-secondary" aria-hidden="true"><AdminIcon name="bell" :size="32" /></span>
          <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm shadow-card" :class="TONE_CLASS[toneOf(row)]">
            <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ STATUS[row.status]?.label }}
          </span>
          <span class="absolute right-3 top-3 rounded-full px-2.5 py-1 text-label-sm shadow-card" :class="KINDS[row.kind]?.badge">{{ KINDS[row.kind]?.label }}</span>
        </div>
        <div class="flex flex-1 flex-col gap-2 p-space-md">
          <h2 class="font-heading text-headline-sm">{{ row.title }}</h2>
          <p class="line-clamp-2 text-body-sm text-secondary">{{ row.body }}</p>
          <p class="flex items-start gap-1.5 text-body-sm"><AdminIcon name="clock" :size="16" class="mt-0.5 shrink-0 text-secondary" /><span v-if="row.starts_at || row.ends_at">{{ row.starts_at ? formatDate(row.starts_at) : 'Sekarang' }} <span class="text-secondary">sampai</span> {{ row.ends_at ? formatDate(row.ends_at) : 'tanpa batas' }}</span><span v-else class="text-secondary">Tanpa batas waktu</span></p>
          <div class="mt-auto flex items-center justify-between gap-2 pt-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm tabular-nums">Urutan {{ row.sort_order }}</span>
            <div class="flex items-center gap-1">
              <IconAction v-if="canManage" icon="trash" tone="danger" label="Hapus" :subject="row.title" @click="askDelete(row)" />
              <IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Ubah' : 'Lihat'" :subject="row.title" @click="open(row)" />
            </div>
          </div>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="editing ? (canManage ? 'update' : 'read') : 'create'" size="2xl" :title="editing ? (canManage ? 'Ubah berita' : 'Lihat berita') : 'Tambah berita'" :subtitle="editing?.title">
    <div class="grid gap-space-lg lg:grid-cols-5">
      <form id="news-form" class="space-y-space-md lg:col-span-3" @submit.prevent="save">
        <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
        <fieldset :disabled="!canManage" class="space-y-space-md">
          <fieldset class="grid gap-2 sm:grid-cols-3">
            <legend class="mb-2 text-label-md">Jenis</legend>
            <label v-for="(kind, key) in KINDS" :key="key" class="flex cursor-pointer items-start gap-2 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.kind === key ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
              <input v-model="form.kind" type="radio" :value="key" class="mt-1 size-4 accent-primary">
              <span><span class="block text-label-md">{{ kind.label }}</span><span class="block text-body-sm text-secondary">{{ kind.text }}</span></span>
            </label>
          </fieldset>

          <div class="grid gap-space-md sm:grid-cols-2">
            <label class="block text-label-md">
              Judul <span class="font-normal text-secondary">(Indonesia)</span>
              <input v-model="form.title" required maxlength="150" :class="field">
              <span v-if="fieldErrors.title" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.title[0] }}</span>
            </label>
            <label class="block text-label-md">
              Judul <span class="font-normal text-secondary">(Inggris, opsional)</span>
              <input v-model="form.title_en" maxlength="150" :class="field">
            </label>
            <label class="block text-label-md">
              Teks singkat <span class="font-normal text-secondary">(Indonesia)</span>
              <textarea v-model="form.body" required rows="4" maxlength="300" :class="`${field} resize-none`"></textarea>
              <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/300</span>
              <span v-if="fieldErrors.body" class="block text-body-sm font-normal text-error">{{ fieldErrors.body[0] }}</span>
            </label>
            <label class="block text-label-md">
              Teks singkat <span class="font-normal text-secondary">(Inggris, opsional)</span>
              <textarea v-model="form.body_en" rows="4" maxlength="300" :class="`${field} resize-none`"></textarea>
              <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body_en.length }}/300</span>
            </label>
          </div>

          <div class="grid gap-space-md sm:grid-cols-2">
            <label class="block text-label-md sm:col-span-2">
              Tautan tombol <span class="font-normal text-secondary">(opsional)</span>
              <input v-model="form.cta_url" maxlength="255" placeholder="/promo/flash-sale atau https://..." :class="field">
              <span class="mt-1 block text-body-sm font-normal text-secondary">Halaman toko diawali "/", atau alamat luar diawali "https://".</span>
              <span v-if="fieldErrors.cta_url" class="block text-body-sm font-normal text-error">{{ fieldErrors.cta_url[0] }}</span>
            </label>
            <label class="block text-label-md">
              Teks tombol <span class="font-normal text-secondary">(Indonesia)</span>
              <input v-model="form.cta_label" maxlength="40" placeholder="Lihat promo" :class="field">
              <span v-if="fieldErrors.cta_label" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.cta_label[0] }}</span>
            </label>
            <label class="block text-label-md">
              Teks tombol <span class="font-normal text-secondary">(Inggris, opsional)</span>
              <input v-model="form.cta_label_en" maxlength="40" placeholder="See promo" :class="field">
            </label>
          </div>

          <div class="grid gap-space-md sm:grid-cols-3">
            <label class="block text-label-md">
              Mulai <span class="font-normal text-secondary">(opsional)</span>
              <input v-model="form.starts_at" type="datetime-local" :class="field">
            </label>
            <label class="block text-label-md">
              Selesai <span class="font-normal text-secondary">(opsional)</span>
              <input v-model="form.ends_at" type="datetime-local" :class="field">
              <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.ends_at[0] }}</span>
            </label>
            <label class="block text-label-md">
              Urutan
              <input v-model.number="form.sort_order" type="number" min="0" max="9999" :class="field">
              <span class="mt-1 block text-body-sm font-normal text-secondary">Kecil tampil lebih dulu.</span>
            </label>
          </div>

          <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.is_published ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="form.is_published" type="checkbox" class="mt-0.5 size-4 accent-primary">
            <span><span class="block text-label-md">Tayang</span><span class="block text-body-sm text-secondary">Tampil di popup beranda selama periodenya. Draf tidak tampil.</span></span>
          </label>
        </fieldset>

        <section class="space-y-space-sm border-t border-border pt-space-md" aria-labelledby="news-image">
          <h3 id="news-image" class="font-heading text-headline-sm">Gambar <span class="text-body-sm font-normal text-secondary">(opsional)</span></h3>
          <div v-if="editing?.image_url" class="flex items-center gap-3 rounded-2xl border border-border p-2">
            <img :src="editing.image_url" alt="Gambar berita saat ini" class="h-16 w-28 shrink-0 rounded-xl object-cover">
            <p class="min-w-0 flex-1 text-body-sm text-secondary">Gambar saat ini. Pilih gambar baru di bawah untuk menggantinya.</p>
            <IconAction v-if="canManage" icon="trash" tone="danger" label="Hapus gambar" :disabled="imageBusy" @click="removeImage" />
          </div>
          <label v-if="canManage" class="flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-secondary px-4 py-3 transition-colors duration-150 hover:bg-accent-tint-12 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
            <AdminIcon name="plus" :size="20" class="shrink-0 text-secondary" />
            <span class="min-w-0"><span class="block break-all text-label-md">{{ imageName || 'Pilih gambar' }}</span><span class="block text-body-sm text-secondary">JPG, JPEG, atau PNG, maksimal 3 MB. Otomatis diubah ke WebP.</span></span>
            <input ref="imageInput" type="file" accept="image/jpeg,image/png" class="sr-only" @change="onPick">
          </label>
          <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
        </section>
      </form>

      <aside class="lg:col-span-2" aria-label="Pratinjau popup">
        <p class="mb-2 text-label-md">Pratinjau</p>
        <div class="overflow-hidden rounded-3xl border border-border bg-surface p-space-md shadow-card lg:sticky lg:top-0">
          <div class="aspect-[16/9] overflow-hidden rounded-2xl bg-gradient-to-br from-accent-tint-20 to-accent-tint-12">
            <img v-if="preview.image" :src="preview.image" alt="" class="size-full object-cover">
          </div>
          <span class="mt-3 inline-flex rounded-full px-2.5 py-1 text-label-sm" :class="KINDS[form.kind]?.badge">{{ preview.badge }}</span>
          <h4 class="mt-2 break-words font-heading text-headline-sm">{{ preview.title }}</h4>
          <p class="mt-1 break-words text-body-sm text-secondary">{{ preview.body }}</p>
          <span v-if="preview.cta" class="mt-3 inline-flex min-h-10 items-center rounded-full bg-accent px-5 text-label-md">{{ preview.cta }}</span>
        </div>
        <p class="mt-2 text-body-sm text-secondary">Gambar baru tampil di pratinjau setelah disimpan.</p>
      </aside>
    </div>

    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Tutup</button>
      <button v-if="canManage" form="news-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : (editing ? 'Simpan' : 'Buat berita') }}</button>
    </template>
  </AppModal>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus berita?" :subtitle="toDelete?.title">
    <p class="text-body-sm">Berita ini tidak lagi tampil di popup dan tidak bisa dikembalikan dari dashboard. Penghapusan tercatat di Audit Log.</p>
    <template #footer>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="deleting" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="confirmDelete"><AdminIcon name="trash" :size="16" />{{ deleting ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>
