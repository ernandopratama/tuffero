<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AddButton from '../../components/ui/AddButton.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

// Draf konten AI Marketing (D-062, D-079). Marketing menyusun dan mengajukan; Pemilik atau Super Admin memutuskan; memposting tetap manual (BR-19).
// Izin sebenarnya dijaga server; tombol di sini hanya mengikuti izin supaya tidak membingungkan.
const auth = useAuthStore();
const canManage = computed(() => auth.can('marketing_drafts.manage'));
const canApprove = computed(() => auth.can('marketing_drafts.approve'));

// Jadwal hanya tanggal (tanpa jam), jadi tidak memakai formatDate yang menampilkan jam.
const day = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const TYPES = { video_script: 'Skrip video', caption: 'Caption', calendar_item: 'Kalender konten', trend_report: 'Riset tren' };
const PLATFORMS = { instagram: 'Instagram', tiktok: 'TikTok' };
// Asal draf: manual tidak diberi lencana. Draf AI dan Hermes tetap perlu diperiksa manusia sebelum diajukan.
const ORIGINS = { ai: 'Dibuat AI', hermes: 'Dari Hermes' };
const STATUS = {
    draft: { label: 'Draf', cls: 'bg-secondary-tint-12 text-secondary' },
    in_review: { label: 'Menunggu keputusan', cls: 'bg-warning-surface text-warning' },
    approved: { label: 'Disetujui', cls: 'bg-success-surface text-success' },
    rejected: { label: 'Ditolak', cls: 'bg-error-surface text-on-error-surface' },
    posted: { label: 'Sudah diposting', cls: 'bg-accent-tint-15 text-primary' },
};

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';
const btn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const btnPrimary = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`;

const items = ref([]);
const frequency = ref({ posts_per_week: 0, videos_per_week: 0 });
const week = ref({ posts: 0, videos: 0 });
const loading = ref(true);
const loadError = ref('');
const filter = ref('');

// Pembuat draf AI (D-079): hasilnya draf biasa yang tetap harus diajukan dan disetujui.
const aiEnabled = ref(false);
const aiDialog = ref(null);
const aiBlank = { type: 'caption', platform: '', brief: '', scheduled_on: '' };
const ai = reactive({ ...aiBlank });
const aiBusy = ref(false);
const aiError = ref('');
const aiFields = ref({});

function openAi() {
    Object.assign(ai, aiBlank);
    aiError.value = '';
    aiFields.value = {};
    aiDialog.value.showModal();
}

async function generate() {
    aiBusy.value = true;
    aiError.value = '';
    aiFields.value = {};
    try {
        await api.post('/admin/marketing-drafts/generate', { ...ai, platform: ai.platform || null, scheduled_on: ai.scheduled_on || null }, { toast: 'Draf dibuat. Periksa dulu sebelum diajukan.', timeout: 150000 });
        aiDialog.value.close();
        filter.value = 'draft';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        aiError.value = message;
        aiFields.value = fields;
    } finally {
        aiBusy.value = false;
    }
}

const dialog = ref(null);
const deleteDialog = ref(null);
const decideDialog = ref(null);
const editing = ref(null);
const toDelete = ref(null);
const toDecide = ref(null);
const decision = ref('approve');
const note = ref('');
const saving = ref(false);
const busy = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { type: 'caption', platform: '', title: '', body: '', sources: '', source_period: '', scheduled_on: '' };
const form = reactive({ ...blank });

const visible = computed(() => (filter.value ? items.value.filter((row) => row.status === filter.value) : items.value));
const counts = computed(() => Object.fromEntries(Object.keys(STATUS).map((key) => [key, items.value.filter((row) => row.status === key).length])));
const editable = (row) => ['draft', 'rejected'].includes(row.status);
// Formulir terkunci bagi akun tanpa izin menyusun, dan bagi draf yang sedang ditinjau atau sudah disetujui.
const readOnly = computed(() => !canManage.value || (editing.value && !editable(editing.value)));
const isTrend = computed(() => form.type === 'trend_report');

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/marketing-drafts');
        items.value = data.data.items;
        frequency.value = data.data.frequency;
        aiEnabled.value = data.data.ai_enabled;
        week.value = data.data.week;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function open(row = null) {
    formError.value = '';
    fieldErrors.value = {};
    editing.value = row;
    Object.assign(form, blank, row ? {
        type: row.type,
        platform: row.platform ?? '',
        title: row.title,
        body: row.body,
        sources: row.sources ?? '',
        source_period: row.source_period ?? '',
        scheduled_on: row.scheduled_on ?? '',
    } : {});
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { ...form, platform: form.platform || null, sources: form.sources || null, source_period: form.source_period || null, scheduled_on: form.scheduled_on || null };
    try {
        if (editing.value) await api.patch(`/admin/marketing-drafts/${editing.value.id}`, body);
        else await api.post('/admin/marketing-drafts', body);
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

async function move(row, action) {
    busy.value = true;
    try {
        await api.post(`/admin/marketing-drafts/${row.id}/move`, { action });
        await load();
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

function askDecide(row, kind) {
    toDecide.value = row;
    decision.value = kind;
    note.value = '';
    formError.value = '';
    decideDialog.value.showModal();
}

async function confirmDecide() {
    busy.value = true;
    formError.value = '';
    try {
        await api.post(`/admin/marketing-drafts/${toDecide.value.id}/decide`, { decision: decision.value, note: note.value || null });
        decideDialog.value.close();
        await load();
    } catch (e) {
        formError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

function askDelete(row) {
    toDelete.value = row;
    deleteDialog.value.showModal();
}

async function confirmDelete() {
    busy.value = true;
    try {
        await api.delete(`/admin/marketing-drafts/${toDelete.value.id}`, { toast: 'Draf dihapus.' });
        deleteDialog.value.close();
        await load();
    } catch (e) {
        loadError.value = apiError(e).message;
        deleteDialog.value.close();
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Draf Konten" description="Skrip video, caption, kalender konten, dan riset tren. Marketing menyusun dan mengajukan, Pemilik memutuskan. Memposting tetap dilakukan manual." />
    <div v-if="canManage" class="flex flex-wrap items-center gap-2">
      <button type="button" :disabled="!aiEnabled" :title="aiEnabled ? '' : 'Pembuat draf AI belum aktif. Hubungi Super Admin.'" :class="btn" @click="openAi"><AdminIcon name="sparkles" :size="16" />Buat dengan AI</button>
      <AddButton label="Tambah draf" @click="open()" />
    </div>
  </div>

  <div v-if="loading" role="status" aria-label="Memuat draf" class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
    <div v-for="n in 3" :key="n" class="h-48 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Muat ulang</button>
  </div>

  <template v-else>
    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-3" aria-label="Ringkasan minggu ini">
      <li class="rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="block text-body-sm text-secondary">Posting minggu ini</span>
        <span class="block font-heading text-headline-sm tabular-nums">{{ week.posts }}/{{ frequency.posts_per_week }}</span>
        <span class="block text-body-sm text-secondary">Caption dan kalender konten yang dijadwalkan</span>
      </li>
      <li class="rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="block text-body-sm text-secondary">Video minggu ini</span>
        <span class="block font-heading text-headline-sm tabular-nums">{{ week.videos }}/{{ frequency.videos_per_week }}</span>
        <span class="block text-body-sm text-secondary">Skrip video yang dijadwalkan</span>
      </li>
      <li class="rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="block text-body-sm text-secondary">Menunggu keputusan</span>
        <span class="block font-heading text-headline-sm tabular-nums">{{ counts.in_review }}</span>
        <span class="block text-body-sm text-secondary">Target minggu diatur Super Admin di Pengaturan</span>
      </li>
    </ul>

    <div class="mt-space-md flex flex-wrap gap-2" role="group" aria-label="Filter status">
      <button type="button" :aria-pressed="filter === ''" :class="`rounded-full border px-3 py-1 text-label-md ${press} ${filter === '' ? 'border-primary bg-accent-tint-15' : 'border-border hover:bg-accent-tint-12'}`" @click="filter = ''">Semua ({{ items.length }})</button>
      <button v-for="(meta, key) in STATUS" :key="key" type="button" :aria-pressed="filter === key" :class="`rounded-full border px-3 py-1 text-label-md ${press} ${filter === key ? 'border-primary bg-accent-tint-15' : 'border-border hover:bg-accent-tint-12'}`" @click="filter = key">{{ meta.label }} ({{ counts[key] }})</button>
    </div>

    <div v-if="!visible.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
      <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="edit" :size="26" /></span>
      <p class="font-heading text-headline-sm">{{ filter ? 'Tidak ada draf dengan status ini' : 'Belum ada draf' }}</p>
      <p class="max-w-md text-body-sm text-secondary">Susun skrip video, caption, atau kalender konten, lalu ajukan ke Pemilik untuk disetujui.</p>
      <AddButton v-if="canManage && !filter" label="Tambah draf" expanded class="mt-1" @click="open()" />
    </div>

    <ul v-else class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="row in visible" :key="row.id" class="flex flex-col gap-2 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full px-2.5 py-1 text-label-sm" :class="STATUS[row.status].cls">{{ STATUS[row.status].label }}</span>
          <span class="rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ TYPES[row.type] }}</span>
          <span v-if="row.platform" class="rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ PLATFORMS[row.platform] }}</span>
          <span v-if="ORIGINS[row.origin]" class="inline-flex items-center gap-1 rounded-full bg-accent-tint-15 px-2.5 py-1 text-label-sm text-primary"><AdminIcon name="sparkles" :size="12" />{{ ORIGINS[row.origin] }}</span>
        </div>
        <h2 class="font-heading text-headline-sm">{{ row.title }}</h2>
        <p class="line-clamp-3 whitespace-pre-line text-body-sm text-secondary">{{ row.body }}</p>
        <p v-if="row.scheduled_on" class="flex items-center gap-1.5 text-body-sm"><AdminIcon name="clock" :size="16" class="shrink-0 text-secondary" />Jadwal {{ day.format(new Date(`${row.scheduled_on}T00:00:00`)) }}</p>
        <p v-if="row.review_note" class="rounded-2xl bg-secondary-tint-12 px-3 py-2 text-body-sm">Catatan {{ row.reviewer || 'peninjau' }}: {{ row.review_note }}</p>
        <p class="text-body-sm text-secondary">{{ row.origin === 'hermes' ? 'Dikirim Hermes (riset terjadwal)' : `Disusun ${row.creator || 'akun yang sudah dihapus'}` }}</p>

        <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-2">
          <div class="flex flex-wrap gap-2">
            <button v-if="canManage && editable(row)" type="button" :disabled="busy" :class="btnPrimary" @click="move(row, 'submit')">Ajukan</button>
            <button v-if="canManage && row.status === 'in_review'" type="button" :disabled="busy" :class="btn" @click="move(row, 'withdraw')">Tarik kembali</button>
            <button v-if="canApprove && row.status === 'in_review'" type="button" :disabled="busy" :class="btnPrimary" @click="askDecide(row, 'approve')">Setujui</button>
            <button v-if="canApprove && row.status === 'in_review'" type="button" :disabled="busy" :class="btn" @click="askDecide(row, 'reject')">Tolak</button>
            <button v-if="canManage && row.status === 'approved'" type="button" :disabled="busy" :class="btnPrimary" @click="move(row, 'post')">Tandai sudah diposting</button>
          </div>
          <div class="flex items-center gap-1">
            <IconAction v-if="canManage && editable(row)" icon="trash" tone="danger" label="Hapus" :subject="row.title" @click="askDelete(row)" />
            <IconAction :icon="canManage && editable(row) ? 'edit' : 'eye'" :label="canManage && editable(row) ? 'Ubah' : 'Lihat'" :subject="row.title" @click="open(row)" />
          </div>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="editing ? (readOnly ? 'read' : 'update') : 'create'" size="2xl" :title="editing ? (readOnly ? 'Lihat draf' : 'Ubah draf') : 'Tambah draf'" :subtitle="editing?.title">
    <form id="draft-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="readOnly" class="space-y-space-md">
        <div class="grid gap-space-md sm:grid-cols-3">
          <label class="block text-label-md">
            Jenis
            <select v-model="form.type" :class="field"><option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Platform <span class="font-normal text-secondary">(opsional)</span>
            <select v-model="form.platform" :class="field"><option value="">Tidak ditentukan</option><option v-for="(label, key) in PLATFORMS" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Jadwal posting <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.scheduled_on" type="date" :class="field">
          </label>
        </div>
        <label class="block text-label-md">
          Judul
          <input v-model="form.title" required maxlength="150" :class="field">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md">
          Isi
          <textarea v-model="form.body" required rows="8" maxlength="5000" :class="`${field} resize-y`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/5000</span>
          <span v-if="fieldErrors.body" class="block text-body-sm font-normal text-error">{{ fieldErrors.body[0] }}</span>
        </label>
        <div class="grid gap-space-md sm:grid-cols-2">
          <label class="block text-label-md">
            Sumber <span class="font-normal text-secondary">{{ isTrend ? '(wajib untuk riset tren)' : '(opsional)' }}</span>
            <textarea v-model="form.sources" rows="3" maxlength="1000" :required="isTrend" :class="`${field} resize-none`"></textarea>
            <span v-if="fieldErrors.sources" class="block text-body-sm font-normal text-error">{{ fieldErrors.sources[0] }}</span>
          </label>
          <label class="block text-label-md">
            Periode sumber <span class="font-normal text-secondary">{{ isTrend ? '(wajib)' : '(opsional)' }}</span>
            <input v-model="form.source_period" maxlength="100" :required="isTrend" placeholder="Contoh: 1 sampai 7 November 2026" :class="field">
            <span v-if="fieldErrors.source_period" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.source_period[0] }}</span>
          </label>
        </div>
        <p class="text-body-sm text-secondary">Jangan menulis klaim kesehatan, alergen, atau halal tanpa data resmi (OQ-20).</p>
      </fieldset>
    </form>
    <template #footer>
      <button type="button" :class="btn" @click="dialog.close()">Tutup</button>
      <button v-if="!readOnly" form="draft-form" :disabled="saving" :class="btnPrimary"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : (editing ? 'Simpan' : 'Buat draf') }}</button>
    </template>
  </AppModal>

  <AppModal ref="aiDialog" variant="create" size="lg" title="Buat draf dengan AI" subtitle="Claude menulis satu draf. Periksa dan sunting sebelum diajukan.">
    <form id="ai-form" class="space-y-space-md" @submit.prevent="generate">
      <p v-if="aiError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ aiError }}</p>
      <fieldset :disabled="aiBusy" class="space-y-space-md">
        <div class="grid gap-space-md sm:grid-cols-3">
          <label class="block text-label-md">
            Jenis
            <select v-model="ai.type" :class="field"><option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Platform <span class="font-normal text-secondary">(opsional)</span>
            <select v-model="ai.platform" :class="field"><option value="">Tidak ditentukan</option><option v-for="(label, key) in PLATFORMS" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Jadwal posting <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="ai.scheduled_on" type="date" :class="field">
          </label>
        </div>
        <label class="block text-label-md">
          Brief
          <textarea v-model="ai.brief" required rows="5" minlength="10" maxlength="1000" placeholder="Contoh: caption Instagram untuk Nastar Sultan jelang Natal, tema hadiah keluarga." :class="`${field} resize-y`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ ai.brief.length }}/1000</span>
          <span v-if="aiFields.brief" class="block text-body-sm font-normal text-error">{{ aiFields.brief[0] }}</span>
        </label>
        <p v-if="ai.type === 'trend_report'" class="text-body-sm text-secondary">Riset tren mencari di web untuk 7 hari terakhir dan menyertakan sumbernya. Bisa memakan waktu sampai 2 menit. Tanpa sumber yang bisa dikutip, draf tidak dibuat.</p>
        <p class="text-body-sm text-secondary">AI hanya melihat brief ini dan daftar nama produk tayang. Tidak ada data pesanan, pelanggan, harga, atau stok yang dikirim.</p>
      </fieldset>
    </form>
    <template #footer>
      <button type="button" :disabled="aiBusy" :class="btn" @click="aiDialog.close()">Batal</button>
      <button form="ai-form" :disabled="aiBusy" :class="btnPrimary"><AdminIcon name="sparkles" :size="16" />{{ aiBusy ? 'AI sedang menulis...' : 'Buat draf' }}</button>
    </template>
  </AppModal>

  <AppModal ref="decideDialog" variant="update" size="md" :title="decision === 'approve' ? 'Setujui draf?' : 'Tolak draf?'" :subtitle="toDecide?.title">
    <p v-if="formError" role="alert" class="mb-3 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
    <p class="text-body-sm">{{ decision === 'approve' ? 'Setelah disetujui, tim marketing boleh memposting secara manual. Sistem tidak memposting otomatis.' : 'Draf kembali ke Marketing untuk diperbaiki. Tulis alasannya.' }}</p>
    <label class="mt-3 block text-label-md">
      Catatan <span class="font-normal text-secondary">{{ decision === 'approve' ? '(opsional)' : '(wajib)' }}</span>
      <textarea v-model="note" rows="3" maxlength="500" :class="`${field} resize-none`"></textarea>
    </label>
    <template #footer>
      <button type="button" :disabled="busy" :class="btn" @click="decideDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="btnPrimary" @click="confirmDecide"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : (decision === 'approve' ? 'Setujui' : 'Tolak') }}</button>
    </template>
  </AppModal>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus draf?" :subtitle="toDelete?.title">
    <p class="text-body-sm">Draf ini hilang dari daftar dan tidak bisa dikembalikan dari dashboard. Penghapusan tercatat di Audit Log.</p>
    <template #footer>
      <button type="button" :disabled="busy" :class="btn" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="confirmDelete"><AdminIcon name="trash" :size="16" />{{ busy ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>
