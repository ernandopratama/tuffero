<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Syarat dan Ketentuan toko (Admin Toko dan Super Admin). Teks polos dua bahasa; tampil di /kontak#syarat. Setiap perubahan menaikkan
// versi, dan versi itu dicatat di tiap pengajuan refund sebagai S&K yang disetujui pelanggan.
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary';

const sections = ref([]);
const version = ref('');
const updatedAt = ref(null);
const loading = ref(true);
const loadError = ref('');
const busy = ref(false);

const formDialog = ref(null);
const deleteDialog = ref(null);
const editing = ref(null);
const form = reactive({ title_id: '', title_en: '', body_id: '', body_en: '', is_published: true });
const formError = ref('');
const deleting = ref(null);
const deleteError = ref('');

async function load() {
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/terms');
        sections.value = data.data.sections;
        version.value = data.data.version;
        updatedAt.value = data.data.updated_at;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function openForm(section = null) {
    editing.value = section;
    Object.assign(form, section ? { title_id: section.title_id, title_en: section.title_en, body_id: section.body_id, body_en: section.body_en, is_published: section.is_published } : { title_id: '', title_en: '', body_id: '', body_en: '', is_published: true });
    formError.value = '';
    formDialog.value.showModal();
}

async function save() {
    busy.value = true;
    formError.value = '';
    try {
        await (editing.value ? api.put(`/admin/terms/${editing.value.id}`, form) : api.post('/admin/terms', form));
        formDialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = Object.values(fields)[0]?.[0] || message;
    } finally {
        busy.value = false;
    }
}

function askDelete(section) {
    deleting.value = section;
    deleteError.value = '';
    deleteDialog.value.showModal();
}

async function remove() {
    busy.value = true;
    try {
        await api.delete(`/admin/terms/${deleting.value.id}`);
        deleteDialog.value.close();
        await load();
    } catch (e) {
        deleteError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

// Pindah urutan sering dipakai berturut-turut, jadi tanpa toast; hasilnya terlihat langsung di daftar.
async function move(section, direction) {
    busy.value = true;
    try {
        await api.post(`/admin/terms/${section.id}/move`, { direction }, { toast: false });
        await load();
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Syarat dan Ketentuan" description="Teks yang tampil di halaman Kontak toko (Indonesia dan Inggris). Setiap perubahan menaikkan versi, dan versi itu dicatat di tiap pengajuan refund." />
    <button type="button" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`" @click="openForm()"><AdminIcon name="plus" :size="16" />Tambah butir</button>
  </div>

  <section class="mt-space-lg flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-border bg-surface px-5 py-4 shadow-card" aria-label="Versi S&K">
    <span class="rounded-full bg-accent-tint-20 px-3 py-1 text-label-md">Versi {{ version || '-' }}</span>
    <span class="text-body-sm text-secondary">Diperbarui {{ formatDate(updatedAt) }}</span>
    <a href="/kontak#syarat" target="_blank" rel="noopener noreferrer" :class="`ml-auto inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`"><AdminIcon name="eye" :size="16" />Lihat di toko</a>
  </section>

  <div v-if="loading" role="status" aria-label="Memuat" class="mt-space-md space-y-3">
    <div v-for="n in 4" :key="n" class="h-28 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-md flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" /><span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <p v-else-if="!sections.length" class="mt-space-md rounded-2xl border border-border bg-surface p-6 text-center text-body-sm text-secondary shadow-card">Belum ada butir. Toko memakai teks bawaan sampai ada butir yang tayang.</p>

  <ol v-else class="mt-space-md space-y-3" aria-label="Butir Syarat dan Ketentuan">
    <li v-for="(section, i) in sections" :key="section.id" class="flex gap-3 rounded-2xl border border-border bg-surface p-4 shadow-card sm:gap-4 sm:p-5">
      <span class="grid size-9 shrink-0 place-items-center rounded-full bg-secondary-tint-12 text-label-md" aria-hidden="true">{{ i + 1 }}</span>
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="font-heading text-headline-sm">{{ section.title_id }}</h2>
          <span class="rounded-full px-2.5 py-1 text-label-sm" :class="section.is_published ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">{{ section.is_published ? 'Tayang' : 'Disembunyikan' }}</span>
        </div>
        <p class="text-label-sm text-secondary">{{ section.title_en }}</p>
        <p class="mt-2 line-clamp-3 whitespace-pre-line text-body-sm text-secondary">{{ section.body_id }}</p>
      </div>
      <div class="flex shrink-0 flex-col items-center gap-1 sm:flex-row">
        <IconAction icon="chevron-up" label="Naikkan" :subject="section.title_id" :disabled="busy || i === 0" @click="move(section, 'up')" />
        <IconAction icon="chevron-down" label="Turunkan" :subject="section.title_id" :disabled="busy || i === sections.length - 1" @click="move(section, 'down')" />
        <IconAction icon="edit" label="Ubah" :subject="section.title_id" @click="openForm(section)" />
        <IconAction icon="trash" tone="danger" label="Hapus" :subject="section.title_id" @click="askDelete(section)" />
      </div>
    </li>
  </ol>

  <AppModal ref="formDialog" :variant="editing ? 'update' : 'create'" size="2xl" :title="editing ? 'Ubah butir' : 'Tambah butir'" :subtitle="editing?.title_id">
    <form id="terms-form" class="space-y-space-md" @submit.prevent="save">
      <div class="grid gap-space-md md:grid-cols-2">
        <fieldset class="space-y-3">
          <legend class="text-label-md">Indonesia</legend>
          <label class="block text-label-md">Judul<input v-model="form.title_id" required maxlength="150" :class="field"></label>
          <label class="block text-label-md">Isi<textarea v-model="form.body_id" required rows="9" maxlength="4000" :class="field + ' resize-y'"></textarea><span class="mt-1 block text-right text-label-sm font-normal text-secondary">{{ form.body_id.length }}/4000</span></label>
        </fieldset>
        <fieldset class="space-y-3">
          <legend class="text-label-md">English</legend>
          <label class="block text-label-md">Title<input v-model="form.title_en" required maxlength="150" :class="field"></label>
          <label class="block text-label-md">Body<textarea v-model="form.body_en" required rows="9" maxlength="4000" :class="field + ' resize-y'"></textarea><span class="mt-1 block text-right text-label-sm font-normal text-secondary">{{ form.body_en.length }}/4000</span></label>
        </fieldset>
      </div>
      <p class="text-label-sm text-secondary">Teks polos. Pisahkan paragraf dengan satu baris kosong. Kode HTML tidak dipakai dan dibuang.</p>
      <label class="flex min-h-10 cursor-pointer items-center gap-2 text-label-md"><input v-model="form.is_published" type="checkbox" class="size-4 accent-[var(--color-primary)]">Tayang di toko</label>
      <p v-if="formError" role="alert" class="flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ formError }}</p>
    </form>
    <template #footer>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="formDialog.close()">Batal</button>
      <button form="terms-form" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus butir" :subtitle="deleting?.title_id">
    <p class="text-body-sm">Butir ini dihapus dari Syarat dan Ketentuan di toko dan versi dinaikkan. Pengajuan refund yang sudah ada tetap mencatat versi lamanya. Tindakan ini tidak bisa dibatalkan.</p>
    <p v-if="deleteError" role="alert" class="mt-3 text-body-sm text-error">{{ deleteError }}</p>
    <template #footer>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="remove"><AdminIcon name="trash" :size="16" />{{ busy ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>
