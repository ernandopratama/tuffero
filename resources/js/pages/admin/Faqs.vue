<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

const faqs = ref([]);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { title: '', title_en: '', keywords: '', answer_id: '', answer_en: '', sort_order: '', is_active: true, is_suggested: false };
const form = reactive({ ...blank });

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';
const TOGGLES = [
    { key: 'is_active', title: 'Aktif', text: 'Dipakai chat toko untuk menjawab.' },
    { key: 'is_suggested', title: 'Tombol saran', text: 'Chat menampilkan paling banyak 5, sesuai urutan.' },
];
const KEYWORD_PREVIEW = 6;

const splitKeywords = (text) => (text || '').split(',').map((word) => word.trim()).filter(Boolean);
const keywordsOf = (faq) => {
    const words = splitKeywords(faq.keywords);
    return { shown: words.slice(0, KEYWORD_PREVIEW), more: Math.max(0, words.length - KEYWORD_PREVIEW) };
};
const keywordCount = computed(() => splitKeywords(form.keywords).length);
const stats = computed(() => [
    { icon: 'chat', label: 'Total FAQ', value: faqs.value.length },
    { icon: 'check', label: 'Aktif', value: faqs.value.filter((faq) => faq.is_active).length },
    { icon: 'sparkles', label: 'Tombol saran', value: `${faqs.value.filter((faq) => faq.is_active && faq.is_suggested).length}/5` },
]);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/faqs');
        faqs.value = data.data.items;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function open(item = null) {
    editing.value = item;
    Object.assign(form, blank, item ? { ...item, title_en: item.title_en || '', answer_en: item.answer_en || '' } : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { title: form.title, title_en: form.title_en, keywords: form.keywords, answer_id: form.answer_id, answer_en: form.answer_en, is_active: form.is_active, is_suggested: form.is_suggested };
    if (form.sort_order !== '') body.sort_order = Number(form.sort_order);
    try {
        if (editing.value) await api.patch(`/admin/faqs/${editing.value.id}`, body);
        else await api.post('/admin/faqs', body);
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

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="FAQ Chat" description="Jawaban otomatis chat toko untuk pertanyaan yang diketik pelanggan. Chat mencocokkan kata kunci dengan teks pelanggan. Yang tidak dipakai lagi dinonaktifkan, bukan dihapus. Setiap perubahan tercatat di Audit Log." />
    <AddButton label="Tambah FAQ" @click="open()" />
  </div>

  <!-- memuat: kerangka seukuran kartu supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat FAQ" class="mt-space-lg grid gap-space-md lg:grid-cols-2">
    <div v-for="n in 4" :key="n" class="h-52 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="error" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ error }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <div v-else-if="!faqs.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
    <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="chat" :size="26" /></span>
    <p class="font-heading text-headline-sm">Belum ada FAQ</p>
    <p class="max-w-md text-body-sm text-secondary">Tambahkan pertanyaan yang sering ditanyakan pelanggan supaya chat toko bisa menjawab otomatis.</p>
    <AddButton label="Tambah FAQ" expanded class="mt-1" @click="open()" />
  </div>

  <template v-else>
    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-3" aria-label="Ringkasan FAQ">
      <li v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon :name="stat.icon" /></span>
        <span class="min-w-0">
          <span class="block text-body-sm text-secondary">{{ stat.label }}</span>
          <span class="block font-heading text-headline-sm tabular-nums">{{ stat.value }}</span>
        </span>
      </li>
    </ul>

    <ul class="mt-space-lg grid gap-space-md lg:grid-cols-2">
      <li
        v-for="faq in faqs"
        :key="faq.id"
        class="flex flex-col rounded-3xl border bg-surface p-space-lg shadow-card transition-colors duration-150"
        :class="faq.is_active ? 'border-border' : 'border-dashed border-border bg-surface/60'"
      >
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl" :class="faq.is_active ? 'bg-accent-tint-15 text-primary' : 'bg-secondary-tint-12 text-secondary'" aria-hidden="true"><AdminIcon name="chat" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="font-heading text-headline-sm" :class="faq.is_active ? '' : 'text-secondary'">{{ faq.title }}</h2>
            <p v-if="faq.title_en" class="truncate text-body-sm text-secondary">{{ faq.title_en }}</p>
          </div>
          <IconAction icon="edit" label="Ubah" :subject="faq.title" @click="open(faq)" />
        </div>

        <ul class="mt-space-md flex flex-wrap gap-1.5" aria-label="Kata kunci">
          <li v-for="word in keywordsOf(faq).shown" :key="word" class="rounded-full border border-border bg-background/70 px-2.5 py-0.5 text-label-sm text-secondary">{{ word }}</li>
          <li v-if="keywordsOf(faq).more" class="rounded-full px-2 py-0.5 text-label-sm text-secondary">+{{ keywordsOf(faq).more }} lagi</li>
        </ul>

        <p class="mt-space-md line-clamp-3 rounded-2xl rounded-tl-md bg-background/70 px-4 py-3 text-body-sm">{{ faq.answer_id }}</p>

        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-space-md">
          <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label-sm" :class="faq.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
            <i class="size-1.5 rounded-full" :class="faq.is_active ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ faq.is_active ? 'Aktif' : 'Nonaktif' }}
          </span>
          <span v-if="faq.is_suggested" class="inline-flex items-center gap-1 rounded-full bg-accent-tint-20 px-2.5 py-1 text-label-sm"><AdminIcon name="sparkles" :size="12" />Tombol saran</span>
          <span class="rounded-full px-2.5 py-1 text-label-sm" :class="faq.answer_en ? 'bg-info-surface text-info' : 'bg-warning-surface text-warning'" :title="faq.answer_en ? '' : 'Pelanggan berbahasa Inggris melihat jawaban Indonesia.'">{{ faq.answer_en ? 'ID + EN' : 'Hanya ID' }}</span>
          <span v-if="faq.sort_order != null" class="ml-auto text-label-sm text-secondary">Urutan {{ faq.sort_order }}</span>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="editing ? 'update' : 'create'" size="2xl" :title="editing ? 'Ubah FAQ' : 'Tambah FAQ'" :subtitle="editing ? editing.title : 'Jawaban otomatis chat toko'">
    <form id="faq-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>

      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Judul <span class="font-normal text-secondary">(Indonesia)</span>
          <input v-model="form.title" required maxlength="120" :class="field">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md">
          Judul <span class="font-normal text-secondary">(Inggris, opsional)</span>
          <input v-model="form.title_en" maxlength="120" :class="field">
          <span v-if="fieldErrors.title_en" class="mt-1 block text-body-sm text-error">{{ fieldErrors.title_en[0] }}</span>
        </label>
      </div>
      <p class="-mt-2 text-body-sm text-secondary">Judul menjadi teks tombol saran bila dicentang di bawah. Tulis seperti pertanyaan pelanggan, singkat.</p>

      <label class="block text-label-md">
        Kata kunci <span class="font-normal text-secondary">(dipisah koma, Indonesia dan Inggris)</span>
        <textarea v-model="form.keywords" required rows="2" maxlength="500" :class="`${field} resize-none`"></textarea>
        <span class="mt-1 flex flex-wrap justify-between gap-2 text-body-sm font-normal text-secondary">
          <span>Minimal 3 huruf per kata kunci, maksimal 20. Kata kunci pendek atau umum membuat chat salah menjawab.</span>
          <span class="tabular-nums" :class="keywordCount > 20 ? 'text-error' : ''">{{ keywordCount }}/20</span>
        </span>
        <span v-if="fieldErrors.keywords" class="mt-1 block text-body-sm text-error">{{ fieldErrors.keywords[0] }}</span>
      </label>

      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Jawaban <span class="font-normal text-secondary">(Indonesia)</span>
          <textarea v-model="form.answer_id" required rows="5" maxlength="600" :class="`${field} resize-none`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.answer_id.length }}/600</span>
          <span v-if="fieldErrors.answer_id" class="block text-body-sm text-error">{{ fieldErrors.answer_id[0] }}</span>
        </label>
        <label class="block text-label-md">
          Jawaban <span class="font-normal text-secondary">(Inggris, opsional)</span>
          <textarea v-model="form.answer_en" rows="5" maxlength="600" :class="`${field} resize-none`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.answer_en.length }}/600</span>
          <span v-if="fieldErrors.answer_en" class="block text-body-sm text-error">{{ fieldErrors.answer_en[0] }}</span>
        </label>
      </div>

      <label class="block text-label-md sm:w-1/2">
        Urutan <span class="font-normal text-secondary">(opsional)</span>
        <input v-model="form.sort_order" type="number" min="0" max="1000" :class="field">
        <span class="mt-1 block text-body-sm font-normal text-secondary">Angka kecil dicocokkan lebih dulu bila skornya sama.</span>
        <span v-if="fieldErrors.sort_order" class="mt-1 block text-body-sm text-error">{{ fieldErrors.sort_order[0] }}</span>
      </label>

      <div class="grid gap-2 sm:grid-cols-2">
        <label v-for="toggle in TOGGLES" :key="toggle.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="form[toggle.key] ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
          <input v-model="form[toggle.key]" type="checkbox" class="mt-0.5 size-4 accent-primary">
          <span>
            <span class="block text-label-md">{{ toggle.title }}</span>
            <span class="block text-body-sm text-secondary">{{ toggle.text }}</span>
          </span>
        </label>
      </div>
    </form>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Batal</button>
      <button form="faq-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
