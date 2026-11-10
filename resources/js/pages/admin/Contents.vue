<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import AdminComments from '../../components/AdminComments.vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { TONE_CLASS } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

// scope 'video' = halaman Video Website; 'promo' = banner dan teks promo.
// embedded = dipasang di halaman lain (Galeri) yang sudah punya judul sendiri.
const props = defineProps({ scope: { type: String, default: 'promo' }, embedded: { type: Boolean, default: false } });
const isVideo = props.scope === 'video';
const TYPES = { banner: { label: 'Banner', icon: 'layers' }, text: { label: 'Teks promo', icon: 'sparkles' }, video: { label: 'Video', icon: 'play' } };
const FORM_TYPES = isVideo ? { video: 'Video' } : { banner: 'Banner', text: 'Teks promo' };
const TYPE_HINTS = { banner: 'Gambar promo di website toko. Butuh gambar sebelum siap tayang.', text: 'Teks promo singkat di website toko.', video: 'Tautan video YouTube, TikTok, atau Instagram.' };
const PROVIDERS = { youtube: 'YouTube', tiktok: 'TikTok', instagram: 'Instagram' };
const STATE_DOT = { ok: 'bg-success', info: 'bg-info', neutral: 'bg-secondary' };

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

const auth = useAuthStore();
const canManage = computed(() => auth.can('content.manage'));

const COLUMNS = [
    { key: 'title', label: 'Judul', cellClass: 'text-left' },
    { key: 'type', label: 'Jenis' },
    { key: 'period', label: 'Tayang' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/contents', {
    filters: { type: '', is_published: '' },
    params: () => ({ scope: props.scope }),
});

// Keadaan tayang untuk lencana: draf, terjadwal, tayang, atau berakhir (menurut tanda siap tayang dan jendela waktunya).
function stateOf(row) {
    const now = Date.now();
    if (!row.is_published) return { label: 'Draf', tone: 'neutral' };
    if (row.ends_at && new Date(row.ends_at).getTime() < now) return { label: 'Berakhir', tone: 'neutral' };
    if (row.starts_at && new Date(row.starts_at).getTime() > now) return { label: 'Terjadwal', tone: 'info' };
    return { label: 'Tayang', tone: 'ok' };
}

const dialog = ref(null);
const confirmDialog = ref(null);
const confirming = ref('');
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { type: isVideo ? 'video' : 'banner', title: '', body: '', video_url: '', sort_order: 0, starts_at: '', ends_at: '', is_published: false };
const form = reactive({ ...blank });
const imageInput = ref(null);
const imageName = ref('');
const imageBusy = ref(false);
const imageError = ref('');
const thumbBusy = ref(false);
const thumbMsg = ref('');
const THUMB_NOTES = { fetched: 'Thumbnail diambil otomatis.', failed: 'Thumbnail otomatis gagal. Unggah manual di bawah.' };

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
    imageName.value = '';
    thumbMsg.value = '';
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
    if (form.type === 'video') {
        body.video_url = form.video_url;
        body.sort_order = Number(form.sort_order) || 0;
    }
    try {
        if (editing.value) {
            const { data } = await api.patch(`/admin/contents/${editing.value.id}`, { ...body, is_published: form.is_published });
            // Pengambilan thumbnail gagal: tetap terbuka supaya admin bisa mengunggah manual.
            if (data.data.thumbnail_status === 'failed') {
                fill(data.data);
                thumbMsg.value = THUMB_NOTES.failed;
            } else {
                dialog.value.close();
            }
        } else {
            const { data } = await api.post('/admin/contents', { ...body, type: form.type });
            // Tetap terbuka supaya gambar banner bisa langsung diunggah.
            fill(data.data);
            thumbMsg.value = THUMB_NOTES[data.data.thumbnail_status] || '';
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
        imageName.value = '';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        imageError.value = fields.image?.[0] || message;
    } finally {
        imageBusy.value = false;
    }
}

// Dua aksi yang tidak bisa dibatalkan dari dashboard meminta konfirmasi lewat modal.
async function ask(kind) {
    // Mengambil thumbnail otomatis tanpa gambar lama tidak menimpa apa pun, jadi langsung jalan.
    if (kind === 'thumb' && !editing.value.image_url) return refreshThumb();
    confirming.value = kind;
    await nextTick();
    confirmDialog.value.showModal();
}

async function refreshThumb() {
    confirmDialog.value?.close();
    thumbBusy.value = true;
    thumbMsg.value = '';
    try {
        const { data } = await api.post(`/admin/contents/${editing.value.id}/thumbnail`);
        fill(data.data);
        thumbMsg.value = THUMB_NOTES.fetched;
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        thumbMsg.value = fields.video_url?.[0] || message;
    } finally {
        thumbBusy.value = false;
    }
}

async function remove() {
    saving.value = true;
    formError.value = '';
    try {
        await api.delete(`/admin/contents/${editing.value.id}`, { toast: 'Konten dihapus.' });
        confirmDialog.value.close();
        dialog.value.close();
        await load();
    } catch (e) {
        confirmDialog.value.close();
        formError.value = apiError(e).message;
    } finally {
        saving.value = false;
    }
}

watch(() => props.scope, () => {
    filter.type = '';
    filter.is_published = '';
    if (page.value === 1) load();
    else page.value = 1;
});
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-space-md">
    <PageHeader v-if="!embedded" :title="isVideo ? 'Video Website' : 'Konten Promo'" :description="isVideo ? 'Tautan video YouTube, TikTok, atau Instagram Tuffero. Video yang siap tayang dan punya thumbnail tampil di bagian &quot;Di Balik Layar&quot; halaman Galeri toko, urut menurut angka urutan.' : 'Banner dan teks promo untuk website toko.'" />
    <AddButton v-if="canManage" :label="isVideo ? 'Tambah video' : 'Tambah konten'" class="ml-auto" :class="embedded ? '' : 'mt-3.5'" @click="open()" />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari judul">
      <FilterSelect v-if="!isVideo" v-model="filter.type" label="Jenis" :options="FORM_TYPES" />
      <FilterSelect v-model="filter.is_published" label="Status" :options="{ 1: 'Siap tayang', 0: 'Draf' }" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    :caption="isVideo ? 'Daftar video' : 'Daftar konten promo'"
    :empty-text="isVideo ? 'Belum ada video.' : 'Belum ada konten promo.'"
    min-width="min-w-[760px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-title="{ row }">
      <div class="inline-flex max-w-sm items-center gap-3 text-left">
        <img v-if="row.image_url" :src="row.image_url" :alt="row.title" width="56" height="56" loading="lazy" class="size-14 shrink-0 rounded-2xl border border-border object-cover">
        <span v-else class="grid size-14 shrink-0 place-items-center rounded-2xl border border-dashed border-border bg-accent-tint-12 text-secondary" aria-hidden="true"><AdminIcon :name="TYPES[row.type]?.icon || 'layers'" :size="20" /></span>
        <span class="min-w-0">
          <span class="block truncate text-label-md">{{ row.title }}</span>
          <span v-if="row.body" class="line-clamp-1 text-body-sm text-secondary">{{ row.body }}</span>
          <span v-else-if="row.type === 'video' && !row.image_url" class="block text-body-sm text-warning">Butuh thumbnail</span>
          <span v-else-if="row.type === 'banner' && !row.image_url" class="block text-body-sm text-warning">Butuh gambar</span>
        </span>
      </div>
    </template>
    <template #cell-type="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm"><AdminIcon :name="TYPES[row.type]?.icon || 'layers'" :size="12" />{{ TYPES[row.type]?.label || row.type }}</span>
      <span v-if="row.provider" class="mt-1 block text-body-sm text-secondary">{{ PROVIDERS[row.provider] }}</span>
    </template>
    <template #cell-period="{ row }">
      <template v-if="row.starts_at || row.ends_at">
        <span class="block whitespace-nowrap">{{ row.starts_at ? formatDate(row.starts_at) : 'Sekarang' }}</span>
        <span class="block whitespace-nowrap text-body-sm text-secondary">sampai {{ row.ends_at ? formatDate(row.ends_at) : 'tanpa batas' }}</span>
      </template>
      <span v-else class="text-secondary">Tanpa batas waktu</span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[stateOf(row).tone]">
        <i class="size-1.5 rounded-full" :class="STATE_DOT[stateOf(row).tone]" aria-hidden="true"></i>{{ stateOf(row).label }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Ubah' : 'Lihat'" :subject="row.title" @click="open(row)" />
        <IconAction v-if="row.type === 'video' && row.video_url" icon="play" label="Putar video" :subject="row.title" :href="row.video_url" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="editing ? (canManage ? 'update' : 'read') : 'create'" size="xl" :title="editing ? (canManage ? 'Ubah konten' : 'Detail konten') : 'Tambah konten'" :subtitle="editing?.title">
    <form id="content-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="space-y-space-md">
        <fieldset v-if="!isVideo" class="grid gap-2 sm:grid-cols-2">
          <legend class="mb-2 text-label-md">Jenis</legend>
          <label v-for="(label, key) in FORM_TYPES" :key="key" class="flex items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="[form.type === key ? 'border-primary bg-accent-tint-12' : 'border-border', editing ? 'cursor-default opacity-80' : 'cursor-pointer hover:border-secondary']">
            <input v-model="form.type" type="radio" :value="key" :disabled="!!editing" class="mt-1 size-4 accent-primary">
            <span><span class="block text-label-md">{{ label }}</span><span class="block text-body-sm text-secondary">{{ TYPE_HINTS[key] }}</span></span>
          </label>
        </fieldset>

        <label class="block text-label-md">
          Judul
          <input v-model="form.title" required maxlength="150" :class="field">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md">
          {{ form.type === 'text' ? 'Teks promo' : 'Keterangan' }} <span v-if="form.type !== 'text'" class="font-normal text-secondary">(opsional)</span>
          <textarea v-model="form.body" rows="3" maxlength="5000" :required="form.type === 'text'" :class="`${field} resize-none`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.body.length }}/5000</span>
          <span v-if="fieldErrors.body" class="block text-body-sm font-normal text-error">{{ fieldErrors.body[0] }}</span>
        </label>
        <label v-if="form.type === 'video'" class="block text-label-md">
          Tautan video
          <input v-model="form.video_url" type="url" required maxlength="255" placeholder="https://www.youtube.com/watch?v=..." :class="field">
          <span class="mt-1 block text-body-sm font-normal text-secondary">Hanya tautan https YouTube, TikTok, atau Instagram. Kode embed tidak diterima.</span>
          <span v-if="fieldErrors.video_url" class="block text-body-sm font-normal text-error">{{ fieldErrors.video_url[0] }}</span>
        </label>
        <div class="grid gap-space-md sm:grid-cols-3">
          <label v-if="form.type === 'video'" class="block text-label-md">
            Urutan tampil
            <input v-model="form.sort_order" type="number" min="0" max="9999" :class="field">
            <span class="mt-1 block text-body-sm font-normal text-secondary">Kecil tampil lebih dulu.</span>
            <span v-if="fieldErrors.sort_order" class="block text-body-sm font-normal text-error">{{ fieldErrors.sort_order[0] }}</span>
          </label>
          <label class="block text-label-md">
            Mulai tayang <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.starts_at" type="datetime-local" :class="field">
          </label>
          <label class="block text-label-md">
            Selesai tayang <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.ends_at" type="datetime-local" :class="field">
            <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.ends_at[0] }}</span>
          </label>
        </div>
        <template v-if="editing">
          <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.is_published ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="form.is_published" type="checkbox" class="mt-0.5 size-4 accent-primary">
            <span><span class="block text-label-md">Siap tayang</span><span class="block text-body-sm text-secondary">Tampil di website selama periodenya. Draf tidak tampil.</span></span>
          </label>
          <span v-if="fieldErrors.is_published" class="block text-body-sm text-error">{{ fieldErrors.is_published[0] }}</span>
        </template>
      </fieldset>
    </form>

    <section v-if="editing && (editing.type === 'banner' || editing.type === 'video')" class="mt-space-md space-y-space-sm border-t border-border pt-space-md" aria-labelledby="content-media">
      <h3 id="content-media" class="font-heading text-headline-sm">{{ editing.type === 'video' ? 'Thumbnail video' : 'Gambar banner' }}</h3>
      <p v-if="editing.type === 'video'" class="text-body-sm text-secondary">Potret 9:16, mis. 1080 × 1920. Ambil dari sampul atau tangkapan layar video.</p>
      <img v-if="editing.image_url" :src="editing.image_url" :alt="editing.title" class="max-h-48 rounded-2xl border border-border object-contain">
      <p v-else class="flex items-start gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning"><AdminIcon name="alert" :size="16" class="mt-0.5 shrink-0" />{{ editing.type === 'video' ? 'Belum ada thumbnail. Video butuh thumbnail sebelum siap tayang.' : 'Belum ada gambar. Banner butuh gambar sebelum siap tayang.' }}</p>
      <div v-if="canManage && editing.type === 'video'">
        <button v-if="editing.can_auto_thumbnail" type="button" :disabled="thumbBusy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="ask('thumb')"><AdminIcon name="sparkles" :size="16" />{{ thumbBusy ? 'Mengambil...' : `Ambil thumbnail otomatis dari ${PROVIDERS[editing.provider]}` }}</button>
        <p v-else class="text-body-sm text-secondary">Thumbnail otomatis hanya untuk video YouTube. Untuk TikTok dan Instagram, unggah thumbnail manual.</p>
        <p v-if="thumbMsg" role="status" class="mt-1 text-body-sm text-secondary">{{ thumbMsg }}</p>
      </div>
      <form v-if="canManage" class="flex flex-wrap items-stretch gap-2" @submit.prevent="uploadImage">
        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-secondary px-4 py-3 transition-colors duration-150 hover:bg-accent-tint-12 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
          <AdminIcon name="plus" :size="20" class="shrink-0 text-secondary" />
          <span class="min-w-0"><span class="block break-all text-label-md">{{ imageName || 'Pilih gambar' }}</span><span class="block text-body-sm text-secondary">JPG, JPEG, atau PNG, maksimal 2 MB</span></span>
          <input ref="imageInput" type="file" accept="image/jpeg,image/png" aria-label="Gambar" class="sr-only" @change="imageName = $event.target.files[0]?.name || ''">
        </label>
        <button :disabled="imageBusy" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`">{{ imageBusy ? 'Mengunggah...' : 'Unggah' }}</button>
      </form>
      <p v-if="imageError" role="alert" class="text-body-sm text-error">{{ imageError }}</p>
    </section>

    <a v-if="editing?.video_url" :href="editing.video_url" target="_blank" rel="noopener noreferrer" :class="`mt-space-md inline-flex min-h-10 items-center gap-1.5 rounded-full border border-border px-4 text-label-md hover:bg-accent-tint-15 ${press}`"><AdminIcon name="play" :size="16" />Buka video<span class="sr-only"> (tab baru)</span></a>

    <AdminComments v-if="editing" :url="`/admin/contents/${editing.id}/comments`" class="mt-space-md" />

    <template #footer>
      <button v-if="canManage && editing" type="button" :disabled="saving" :class="`mr-auto inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-label-md text-error hover:bg-error-surface ${press}`" @click="ask('delete')"><AdminIcon name="trash" :size="16" />Hapus</button>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Tutup</button>
      <button v-if="canManage" form="content-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>

  <AppModal ref="confirmDialog" :variant="confirming === 'delete' ? 'delete' : 'update'" size="md" :title="confirming === 'delete' ? 'Hapus konten?' : 'Ganti thumbnail?'" :subtitle="editing?.title">
    <p v-if="confirming === 'delete'" class="text-body-sm">Konten hilang dari daftar dan website toko. Riwayatnya tetap tercatat di Audit Log.</p>
    <p v-else class="text-body-sm">Thumbnail yang sekarang diganti dengan thumbnail otomatis dari platform.</p>
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="confirmDialog.close()">Batal</button>
      <button v-if="confirming === 'delete'" type="button" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="remove"><AdminIcon name="trash" :size="16" />{{ saving ? 'Menghapus...' : 'Hapus' }}</button>
      <button v-else type="button" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="refreshThumb"><AdminIcon name="check" :size="16" />Ganti</button>
    </template>
  </AppModal>
</template>
