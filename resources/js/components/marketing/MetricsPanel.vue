<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AdminIcon from '../AdminIcon.vue';
import IconAction from '../ui/IconAction.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Analisis konten pribadi (rapat 14 Nov 2026 poin 28): angka harian konten tayang, disalin manual dari insight TikTok atau
// Instagram. Angka kumulatif sampai tanggal ukur; tren = selisih terhadap catatan sebelumnya. Izin dijaga server.
const props = defineProps({
    draft: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const PLATFORMS = { tiktok: 'TikTok', instagram: 'Instagram' };
const PLATFORM_SOLID = { instagram: 'bg-[#C13584]', tiktok: 'bg-[#111827]' };
const COUNTS = [
    { key: 'views', label: 'Views', required: true },
    { key: 'likes', label: 'Likes', required: true },
    { key: 'shares', label: 'Shares', required: true },
    { key: 'comments', label: 'Komentar', required: true },
    { key: 'saves', label: 'Disimpan', required: true },
    { key: 'reach', label: 'Reach', required: false },
    { key: 'new_followers', label: 'Followers baru', required: false },
];
const number = new Intl.NumberFormat('id-ID');
const day = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const fmt = (value) => (value === null || value === undefined ? '–' : number.format(value));
const signed = (value) => (value === null || value === undefined ? '' : `${value >= 0 ? '+' : ''}${number.format(value)}`);
const dayLabel = (value) => day.format(new Date(`${value}T00:00:00`));
// Hari ini menurut WIB, sama dengan yang dipakai server untuk batas tanggal ukur.
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';
const btn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const btnPrimary = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`;

const items = ref([]);
const summary = ref({});
const loading = ref(true);
const loadError = ref('');
const busy = ref(false);
const error = ref('');
const fieldErrors = ref({});
const editingId = ref(null);
const blank = () => ({ platform: props.draft.platform || 'tiktok', post_url: '', measured_on: today(), views: '', likes: '', shares: '', comments: '', saves: '', reach: '', new_followers: '' });
const form = reactive(blank());

// Tren: setiap baris dibandingkan dengan catatan sebelumnya di platform yang sama (daftar dari server terbaru dulu).
const rows = computed(() => items.value.map((item) => {
    const previous = items.value.find((other) => other.platform === item.platform && other.measured_on < item.measured_on);
    return { ...item, viewsChange: previous ? item.views - previous.views : null };
}));
const summaries = computed(() => Object.values(summary.value));

async function load() {
    loadError.value = '';
    const id = props.draft.id;
    try {
        const { data } = await api.get(`/admin/marketing-drafts/${id}/metrics`);
        // Jawaban draf sebelumnya yang datang terlambat tidak boleh menimpa panel draf yang kini dibuka.
        if (id !== props.draft.id) return;
        items.value = data.data.items;
        summary.value = data.data.summary;
    } catch (e) {
        if (id === props.draft.id) loadError.value = apiError(e).message;
    } finally {
        if (id === props.draft.id) loading.value = false;
    }
}

function reset() {
    editingId.value = null;
    // Tautan posting terakhir dipakai lagi supaya catatan harian berikutnya cukup mengisi angka.
    Object.assign(form, blank(), items.value[0] ? { platform: items.value[0].platform, post_url: items.value[0].post_url } : {});
    fieldErrors.value = {};
    error.value = '';
}

function startEdit(item) {
    editingId.value = item.id;
    Object.assign(form, blank(), Object.fromEntries(Object.keys(blank()).map((key) => [key, item[key] ?? ''])));
    fieldErrors.value = {};
    error.value = '';
}

async function save() {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    const body = { ...form, reach: form.reach === '' ? null : form.reach, new_followers: form.new_followers === '' ? null : form.new_followers };
    try {
        const url = `/admin/marketing-drafts/${props.draft.id}/metrics`;
        const { data } = editingId.value
            ? await api.patch(`${url}/${editingId.value}`, body, { toast: 'Catatan metrik diubah.' })
            : await api.post(url, body, { toast: 'Catatan metrik disimpan.' });
        items.value = data.data.items;
        summary.value = data.data.summary;
        reset();
        emit('changed');
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = message;
        fieldErrors.value = fields;
    } finally {
        busy.value = false;
    }
}

async function remove(item) {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await api.delete(`/admin/marketing-drafts/${props.draft.id}/metrics/${item.id}`, { toast: 'Catatan metrik dihapus.' });
        items.value = data.data.items;
        summary.value = data.data.summary;
        if (editingId.value === item.id) reset();
        emit('changed');
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

watch(() => props.draft.id, async () => {
    loading.value = true;
    await load();
    reset();
}, { immediate: true });
</script>

<template>
  <section aria-labelledby="metrics-title" class="mt-space-lg space-y-space-md">
    <div class="rounded-3xl border border-border bg-gradient-to-br from-accent-tint-12 to-background/60 p-4 sm:p-5">
      <h4 id="metrics-title" class="flex items-center gap-2 font-heading text-headline-sm"><span class="grid size-10 place-items-center rounded-full bg-primary text-surface" aria-hidden="true"><AdminIcon name="chart" :size="20" /></span>Performa konten</h4>
      <p class="mt-1 text-body-sm text-secondary">Salin angka dari insight TikTok atau Instagram sekali sehari. Angka adalah total sampai tanggal ukur.</p>

      <div v-if="loading" role="status" aria-label="Memuat metrik" class="mt-space-md h-24 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
      <p v-else-if="loadError" role="alert" class="mt-space-md rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ loadError }}</p>
      <p v-else-if="!summaries.length" class="mt-space-md rounded-2xl bg-surface/80 px-4 py-3 text-body-sm">Belum ada catatan metrik untuk konten ini.</p>

      <!-- Ringkasan per platform: angka terakhir dan perubahan dari catatan sebelumnya -->
      <div v-else class="mt-space-md grid gap-space-md lg:grid-cols-2">
        <article v-for="item in summaries" :key="item.platform" class="rounded-2xl border border-primary bg-surface p-3 shadow-[4px_4px_0_0_var(--color-primary)]">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="PLATFORM_SOLID[item.platform]">{{ PLATFORMS[item.platform] }}</span>
            <a :href="item.post_url" target="_blank" rel="noopener noreferrer" class="text-label-md text-info underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Buka posting</a>
          </div>
          <p class="mt-1 text-body-sm text-secondary">{{ item.days }} catatan · terakhir {{ dayLabel(item.latest_on) }}<template v-if="item.engagement_rate !== null"> · interaksi {{ item.engagement_rate.toLocaleString('id-ID') }}% dari views</template></p>
          <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 sm:grid-cols-4">
            <div v-for="count in COUNTS" :key="count.key">
              <dt class="text-label-sm uppercase tracking-wide text-secondary">{{ count.label }}</dt>
              <dd class="font-heading text-body-lg tabular-nums">{{ fmt(item.totals[count.key]) }}
                <span v-if="item.change && item.change[count.key] !== null" class="text-body-sm font-normal" :class="item.change[count.key] < 0 ? 'text-error' : 'text-success'">{{ signed(item.change[count.key]) }}</span>
              </dd>
            </div>
          </dl>
        </article>
      </div>
    </div>

    <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>

    <!-- Formulir catatan harian -->
    <form v-if="canEdit" class="rounded-3xl border border-border bg-surface p-space-md" @submit.prevent="save">
      <h5 class="mb-2 text-label-sm uppercase tracking-wide text-secondary">{{ editingId ? 'Ubah catatan' : 'Tambah catatan hari ini' }}</h5>
      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block text-label-md">
          Platform
          <select v-model="form.platform" :class="field" :aria-invalid="Boolean(fieldErrors.platform)">
            <option v-for="(label, key) in PLATFORMS" :key="key" :value="key">{{ label }}</option>
          </select>
          <span v-if="fieldErrors.platform" class="mt-1 block text-body-sm text-error">{{ fieldErrors.platform }}</span>
        </label>
        <label class="block text-label-md sm:col-span-2">
          Tautan posting
          <input v-model.trim="form.post_url" type="url" required maxlength="500" placeholder="https://www.tiktok.com/@akun/video/..." :class="field" :aria-invalid="Boolean(fieldErrors.post_url)">
          <span v-if="fieldErrors.post_url" class="mt-1 block text-body-sm text-error">{{ fieldErrors.post_url }}</span>
        </label>
        <label class="block text-label-md">
          Tanggal ukur
          <input v-model="form.measured_on" type="date" required :max="today()" :class="field" :aria-invalid="Boolean(fieldErrors.measured_on)">
          <span v-if="fieldErrors.measured_on" class="mt-1 block text-body-sm text-error">{{ fieldErrors.measured_on }}</span>
        </label>
        <label v-for="count in COUNTS" :key="count.key" class="block text-label-md">
          {{ count.label }}<span v-if="!count.required" class="font-normal text-secondary"> (opsional)</span>
          <input v-model.number="form[count.key]" type="number" inputmode="numeric" min="0" step="1" :required="count.required" :class="field" :aria-invalid="Boolean(fieldErrors[count.key])">
          <span v-if="fieldErrors[count.key]" class="mt-1 block text-body-sm text-error">{{ fieldErrors[count.key] }}</span>
        </label>
      </div>
      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button v-if="editingId" type="button" :class="btn" @click="reset">Batal</button>
        <button type="submit" :disabled="busy" :class="btnPrimary"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : (editingId ? 'Simpan perubahan' : 'Simpan catatan') }}</button>
      </div>
    </form>

    <!-- Riwayat harian (tren): views bertambah berapa dari catatan sebelumnya -->
    <div v-if="rows.length" class="overflow-x-auto rounded-3xl border border-border bg-surface">
      <table class="w-full min-w-[720px] text-body-sm">
        <caption class="sr-only">Riwayat metrik harian</caption>
        <thead class="text-left text-label-sm uppercase tracking-wide text-secondary">
          <tr>
            <th scope="col" class="px-3 py-2">Tanggal</th>
            <th scope="col" class="px-3 py-2">Platform</th>
            <th v-for="count in COUNTS" :key="count.key" scope="col" class="px-3 py-2 text-right">{{ count.label }}</th>
            <th v-if="canEdit" scope="col" class="px-3 py-2"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-t border-border" :class="editingId === row.id ? 'bg-accent-tint-12' : ''">
            <td class="whitespace-nowrap px-3 py-2">{{ dayLabel(row.measured_on) }}</td>
            <td class="px-3 py-2">{{ PLATFORMS[row.platform] }}</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ fmt(row.views) }}<span v-if="row.viewsChange !== null" class="block text-label-sm" :class="row.viewsChange < 0 ? 'text-error' : 'text-success'">{{ signed(row.viewsChange) }}</span></td>
            <td v-for="count in COUNTS.slice(1)" :key="count.key" class="px-3 py-2 text-right tabular-nums">{{ fmt(row[count.key]) }}</td>
            <td v-if="canEdit" class="px-1 py-1">
              <div class="flex justify-end">
                <IconAction icon="edit" label="Ubah" :subject="`catatan ${dayLabel(row.measured_on)}`" :disabled="busy" @click="startEdit(row)" />
                <IconAction icon="trash" tone="danger" label="Hapus" :subject="`catatan ${dayLabel(row.measured_on)}`" :disabled="busy" @click="remove(row)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
