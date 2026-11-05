<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AdminComments from '../../components/AdminComments.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { useAuthStore } from '../../stores/auth';

const TYPES = { banner: 'Banner', text: 'Teks promo', video: 'Video' };

const auth = useAuthStore();
const canManage = computed(() => auth.can('content.manage'));

const contents = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { type: 'banner', title: '', body: '', video_url: '', starts_at: '', ends_at: '', is_published: false };
const form = reactive({ ...blank });
const imageInput = ref(null);
const imageBusy = ref(false);
const imageError = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/contents', { params: { page: page.value } });
        contents.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function fill(content) {
    editing.value = content;
    Object.assign(form, blank, content ? {
        ...content,
        body: content.body || '',
        video_url: content.video_url || '',
        starts_at: toLocalInput(content.starts_at),
        ends_at: toLocalInput(content.ends_at),
    } : {});
}

function open(content = null) {
    fill(content);
    formError.value = '';
    fieldErrors.value = {};
    imageError.value = '';
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        title: form.title,
        body: form.body || null,
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
    };
    if (form.type === 'video') body.video_url = form.video_url;
    try {
        if (editing.value) {
            await api.patch(`/admin/contents/${editing.value.id}`, { ...body, is_published: form.is_published });
            dialog.value.close();
        } else {
            const { data } = await api.post('/admin/contents', { ...body, type: form.type });
            // Tetap terbuka supaya gambar banner bisa langsung diunggah.
            fill(data.data);
        }
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

async function uploadImage() {
    const file = imageInput.value?.files?.[0];
    imageError.value = '';
    if (!file) {
        imageError.value = 'Pilih gambar dulu.';
        return;
    }
    imageBusy.value = true;
    try {
        const upload = new FormData();
        upload.append('image', file);
        const { data } = await api.post(`/admin/contents/${editing.value.id}/image`, upload);
        fill(data.data);
        imageInput.value.value = '';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        imageError.value = fields.image?.[0] || message;
    } finally {
        imageBusy.value = false;
    }
}

watch(page, load);
onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <div>
      <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Konten Promo</h1>
      <p class="mt-space-sm text-secondary">Banner, teks promo, dan tautan video. Belum tampil di toko sampai lokasi tayangnya ditentukan.</p>
    </div>
    <button v-if="canManage" type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="open()">Tambah konten</button>
  </div>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat konten...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!contents.length" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg text-secondary">Belum ada konten promo.</p>
  <template v-else>
    <div class="relative mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
      <table class="w-full min-w-[640px] text-left text-body-sm">
        <thead class="border-b border-border text-label-md">
          <tr>
            <th scope="col" class="px-4 py-3">Judul</th>
            <th scope="col" class="px-4 py-3">Jenis</th>
            <th scope="col" class="px-4 py-3">Tayang</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="content in contents" :key="content.id" class="border-b border-border last:border-b-0">
            <td class="px-4 py-3 text-label-md">{{ content.title }}</td>
            <td class="px-4 py-3">{{ TYPES[content.type] }}</td>
            <td class="px-4 py-3">{{ formatDate(content.starts_at) }} sampai {{ formatDate(content.ends_at) }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-label-sm" :class="content.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ content.is_published ? 'Siap tayang' : 'Draf' }}</span>
            </td>
            <td class="px-4 py-3 text-right">
              <button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="open(content)">{{ canManage ? 'Ubah' : 'Lihat' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav class="mt-space-md flex items-center justify-between gap-space-md" aria-label="Halaman konten">
      <p class="text-body-sm text-secondary">Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} konten)</p>
      <div class="flex gap-space-sm">
        <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page -= 1">Sebelumnya</button>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1.5 text-label-md hover:bg-accent-tint-15 disabled:opacity-50" @click="page += 1">Berikutnya</button>
      </div>
    </nav>
  </template>

  <dialog ref="dialog" aria-labelledby="content-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg text-primary backdrop:bg-primary/50">
    <form class="space-y-space-md" @submit.prevent="save">
      <h2 id="content-title" class="font-heading text-headline-md">{{ editing ? (canManage ? 'Ubah konten' : 'Detail konten') : 'Tambah konten' }}</h2>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="space-y-space-md">
        <label class="block text-label-md">
          Jenis
          <select v-model="form.type" :disabled="!!editing" class="mt-1 w-full rounded border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
            <option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
        <label class="block text-label-md">
          Judul
          <input v-model="form.title" required maxlength="150" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md">
          {{ form.type === 'text' ? 'Teks promo' : 'Keterangan (opsional)' }}
          <textarea v-model="form.body" rows="3" maxlength="5000" :required="form.type === 'text'" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70"></textarea>
          <span v-if="fieldErrors.body" class="mt-1 block text-body-sm text-error">{{ fieldErrors.body[0] }}</span>
        </label>
        <label v-if="form.type === 'video'" class="block text-label-md">
          Tautan video
          <input v-model="form.video_url" type="url" required maxlength="255" placeholder="https://www.youtube.com/watch?v=..." class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          <span class="mt-1 block text-body-sm font-normal text-secondary">Hanya tautan https YouTube, TikTok, atau Instagram. Kode embed tidak diterima.</span>
          <span v-if="fieldErrors.video_url" class="mt-1 block text-body-sm text-error">{{ fieldErrors.video_url[0] }}</span>
        </label>
        <div class="grid gap-space-md sm:grid-cols-2">
          <label class="block text-label-md">
            Mulai tayang (opsional)
            <input v-model="form.starts_at" type="datetime-local" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
          </label>
          <label class="block text-label-md">
            Selesai tayang (opsional)
            <input v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary disabled:opacity-70">
            <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm text-error">{{ fieldErrors.ends_at[0] }}</span>
          </label>
        </div>
        <template v-if="editing">
          <label class="flex items-center gap-space-sm text-label-md">
            <input v-model="form.is_published" type="checkbox" class="size-4 accent-primary">
            Siap tayang
          </label>
          <span v-if="fieldErrors.is_published" class="block text-body-sm text-error">{{ fieldErrors.is_published[0] }}</span>
        </template>
      </fieldset>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Tutup</button>
        <button v-if="canManage" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>

    <section v-if="editing && editing.type === 'banner'" class="mt-space-md border-t border-border pt-space-md">
      <h3 class="font-heading text-headline-sm">Gambar banner</h3>
      <img v-if="editing.image_url" :src="editing.image_url" :alt="editing.title" class="mt-space-sm max-h-48 rounded object-contain">
      <p v-else class="mt-1 text-body-sm text-secondary">Belum ada gambar. Banner butuh gambar sebelum siap tayang.</p>
      <form v-if="canManage" class="mt-space-sm space-y-space-sm" @submit.prevent="uploadImage">
        <label class="block text-label-md">
          Gambar (JPG, PNG, atau WebP, maksimal 2 MB)
          <input ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-body-md">
        </label>
        <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
        <button :disabled="imageBusy" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60">{{ imageBusy ? 'Mengunggah...' : 'Unggah gambar' }}</button>
      </form>
    </section>

    <a v-if="editing?.video_url" :href="editing.video_url" target="_blank" rel="noopener noreferrer" class="mt-space-md block text-label-md underline focus-visible:outline-2 focus-visible:outline-primary">Buka video (tab baru)</a>

    <AdminComments v-if="editing" :url="`/admin/contents/${editing.id}/comments`" class="mt-space-md" />
  </dialog>
</template>
