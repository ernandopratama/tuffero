<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

const SOURCES = { google: 'Google Maps', whatsapp: 'WhatsApp', instagram: 'Instagram', lainnya: 'Lainnya' };

const reviews = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const page = ref(1);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { author_name: '', rating: '', body: '', source: 'google', reviewed_on: '', is_published: true };
const form = reactive({ ...blank });

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/reviews', { params: { page: page.value } });
        reviews.value = data.data.items;
        meta.value = data.data.meta;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

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

async function remove(review) {
    if (!window.confirm(`Hapus ulasan dari ${review.author_name}?`)) return;
    try {
        await api.delete(`/admin/reviews/${review.id}`);
        await load();
    } catch (e) {
        error.value = apiError(e).message;
    }
}

function go(next) {
    page.value = next;
    load();
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-space-md">
    <div>
      <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Ulasan Toko</h1>
      <p class="mt-space-sm text-secondary">Tampil di halaman produk. Rating dan jumlah ulasan diatur di menu Pengaturan. Hanya tambahkan ulasan yang boleh ditampilkan.</p>
    </div>
    <button type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="open()">Tambah ulasan</button>
  </div>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat ulasan...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!reviews.length" class="mt-space-lg text-secondary">Belum ada ulasan.</p>
  <div v-else class="mt-space-lg overflow-x-auto rounded-lg border border-border bg-surface">
    <table class="w-full text-left text-body-md">
      <caption class="sr-only">Daftar ulasan toko</caption>
      <thead>
        <tr class="border-b border-border text-label-md">
          <th scope="col" class="px-space-md py-2">Pengulas</th>
          <th scope="col" class="px-space-md py-2">Ulasan</th>
          <th scope="col" class="px-space-md py-2">Sumber</th>
          <th scope="col" class="px-space-md py-2">Status</th>
          <th scope="col" class="px-space-md py-2"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="review in reviews" :key="review.id" class="border-b border-border align-top last:border-b-0">
          <td class="px-space-md py-2">{{ review.author_name }}<span v-if="review.rating" class="block text-body-sm text-secondary">{{ review.rating }} dari 5</span></td>
          <td class="max-w-md px-space-md py-2"><p class="line-clamp-3">{{ review.body }}</p></td>
          <td class="px-space-md py-2">{{ SOURCES[review.source] || review.source }}</td>
          <td class="px-space-md py-2"><span class="rounded-full px-2 py-0.5 text-label-sm" :class="review.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ review.is_published ? 'Tayang' : 'Disembunyikan' }}</span></td>
          <td class="whitespace-nowrap px-space-md py-2 text-right">
            <button type="button" class="text-label-md underline focus-visible:outline-2 focus-visible:outline-primary" @click="open(review)">Ubah</button>
            <button type="button" class="ml-space-sm text-label-md text-error underline focus-visible:outline-2 focus-visible:outline-primary" @click="remove(review)">Hapus</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <nav v-if="meta.last_page > 1" class="mt-space-md flex items-center gap-space-sm" aria-label="Halaman">
    <button type="button" :disabled="page <= 1" class="rounded border border-secondary px-3 py-1 text-label-md disabled:opacity-50" @click="go(page - 1)">Sebelumnya</button>
    <span class="text-body-sm">{{ meta.current_page }} / {{ meta.last_page }}</span>
    <button type="button" :disabled="page >= meta.last_page" class="rounded border border-secondary px-3 py-1 text-label-md disabled:opacity-50" @click="go(page + 1)">Berikutnya</button>
  </nav>

  <dialog ref="dialog" aria-labelledby="review-form-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-lg border border-border bg-surface p-space-lg">
    <form class="space-y-space-md" @submit.prevent="save">
      <h2 id="review-form-title" class="font-heading text-headline-md">{{ editing ? 'Ubah ulasan' : 'Tambah ulasan' }}</h2>
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama pengulas
        <input v-model="form.author_name" required maxlength="100" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
        <span v-if="fieldErrors.author_name" class="mt-1 block text-body-sm text-error">{{ fieldErrors.author_name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Isi ulasan
        <textarea v-model="form.body" required rows="4" maxlength="1000" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary" />
        <span v-if="fieldErrors.body" class="mt-1 block text-body-sm text-error">{{ fieldErrors.body[0] }}</span>
      </label>
      <div class="grid gap-space-md sm:grid-cols-3">
        <label class="block text-label-md">
          Rating (opsional)
          <input v-model.number="form.rating" type="number" min="1" max="5" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="fieldErrors.rating" class="mt-1 block text-body-sm text-error">{{ fieldErrors.rating[0] }}</span>
        </label>
        <label class="block text-label-md">
          Sumber
          <select v-model="form.source" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
            <option v-for="(label, key) in SOURCES" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
        <label class="block text-label-md">
          Tanggal (opsional)
          <input v-model="form.reviewed_on" type="date" class="mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md focus:outline-2 focus:outline-primary">
          <span v-if="fieldErrors.reviewed_on" class="mt-1 block text-body-sm text-error">{{ fieldErrors.reviewed_on[0] }}</span>
        </label>
      </div>
      <label class="flex items-center gap-space-sm text-label-md">
        <input v-model="form.is_published" type="checkbox" class="size-4 accent-primary">
        Tampilkan di toko
      </label>
      <div class="flex justify-end gap-space-sm">
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
        <button :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </dialog>
</template>
