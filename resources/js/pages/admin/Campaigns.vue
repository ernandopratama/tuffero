<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate, fromLocalInput, toLocalInput } from '../../utils/datetime';
import { TONE_CLASS } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AddButton from '../../components/ui/AddButton.vue';

// Kampanye promo (D-073): tema dan periode yang membungkus diskon produk (dan voucher, dipilih di halaman Voucher).
const auth = useAuthStore();
const canManage = computed(() => auth.can('campaigns.manage'));
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    upcoming: { label: 'Akan datang', tone: 'info' },
    live: { label: 'Berjalan', tone: 'ok' },
    ended: { label: 'Berakhir', tone: 'neutral' },
};
const DOT = { ok: 'bg-success', info: 'bg-info', neutral: 'bg-secondary' };
const AUDIENCES = { all: 'Semua pelanggan', guest: 'Non Member (tanpa akun)', member: 'Member (sudah login)' };

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

const items = ref([]);
const loading = ref(true);
const loadError = ref('');

const dialog = ref(null);
const campaign = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { title: '', description: '', starts_at: '', ends_at: '', is_flash: false, is_active: false };
const form = reactive({ ...blank });
const imageInput = ref(null);
const imageName = ref('');
const imageBusy = ref(false);
const imageError = ref('');

const blankItem = { variant_id: '', label: '', price: 0, audience: 'all', type: 'percent', value: '', is_active: true };
const item = reactive({ ...blankItem });
const editingItem = ref(null);
const itemBusy = ref(false);
const itemError = ref('');
const itemFields = ref({});
const search = ref('');
const results = ref([]);
let searchTimer = null;

const valueLabel = (entry) => (entry.type === 'percent' ? entry.value + '%' : rupiah.format(entry.value));
const toneOf = (row) => STATUS[row.status]?.tone || 'neutral';

// Daftar dari API belum membawa `items`; baru ada setelah detail dimuat. Tanpa ini modal gagal dirender saat dibuka.
const campaignItems = computed(() => campaign.value?.items || []);

const stats = computed(() => [
    { icon: 'sparkles', label: 'Total kampanye', value: items.value.length },
    { icon: 'check', label: 'Sedang berjalan', value: items.value.filter((row) => row.status === 'live').length },
    { icon: 'clock', label: 'Akan datang', value: items.value.filter((row) => row.status === 'upcoming').length },
]);

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/campaigns');
        items.value = data.data.items;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function fillForm(data) {
    campaign.value = data;
    Object.assign(form, blank, {
        title: data.title,
        description: data.description ?? '',
        starts_at: toLocalInput(data.starts_at),
        ends_at: toLocalInput(data.ends_at),
        is_flash: data.is_flash,
        is_active: data.is_active,
    });
}

function resetItem() {
    editingItem.value = null;
    Object.assign(item, blankItem);
    search.value = '';
    results.value = [];
    itemError.value = '';
    itemFields.value = {};
}

function open(row = null) {
    formError.value = '';
    fieldErrors.value = {};
    imageError.value = '';
    imageName.value = '';
    resetItem();
    if (row) fillForm(row);
    else {
        campaign.value = null;
        Object.assign(form, blank);
    }
    dialog.value.showModal();
    if (row) refresh(row.id);
}

async function refresh(id) {
    const { data } = await api.get('/admin/campaigns/' + id);
    fillForm(data.data);
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { ...form, description: form.description || null, starts_at: fromLocalInput(form.starts_at), ends_at: fromLocalInput(form.ends_at) };
    try {
        const { data } = campaign.value ? await api.patch('/admin/campaigns/' + campaign.value.id, body) : await api.post('/admin/campaigns', body);
        fillForm(data.data);
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
        const body = new FormData();
        body.append('image', file);
        const { data } = await api.post('/admin/campaigns/' + campaign.value.id + '/image', body);
        fillForm(data.data);
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

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        if (search.value.trim().length < 2) {
            results.value = [];
            return;
        }
        try {
            const { data } = await api.get('/admin/campaign-variants', { params: { search: search.value.trim() } });
            results.value = data.data.items;
        } catch {
            results.value = [];
        }
    }, 250);
}

function pick(entry) {
    Object.assign(item, { variant_id: entry.id, label: entry.label + ' (' + entry.sku + ')', price: entry.price });
    results.value = [];
    search.value = '';
}

function editItem(entry) {
    editingItem.value = entry;
    Object.assign(item, { variant_id: entry.variant_id, label: entry.label + ' (' + entry.sku + ')', price: entry.price, audience: entry.audience, type: entry.type, value: entry.value, is_active: entry.is_active });
    itemError.value = '';
    itemFields.value = {};
}

const previewPrice = computed(() => {
    if (!item.price || !item.value) return null;
    const amount = item.type === 'percent' ? Math.floor((item.price * item.value) / 100) : item.value;
    return item.price - Math.max(0, Math.min(amount, item.price - 1));
});

async function saveItem() {
    itemBusy.value = true;
    itemError.value = '';
    itemFields.value = {};
    const body = { audience: item.audience, type: item.type, value: item.value, is_active: item.is_active };
    try {
        const { data } = editingItem.value
            ? await api.patch('/admin/campaign-items/' + editingItem.value.id, body)
            : await api.post('/admin/campaigns/' + campaign.value.id + '/items', { ...body, variant_id: item.variant_id });
        fillForm(data.data);
        resetItem();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        itemError.value = fields.variant_id?.[0] || message;
        itemFields.value = fields;
    } finally {
        itemBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Kampanye" description="Tema dan periode promo, termasuk flash sale. Produk di dalam kampanye mendapat diskon selama kampanye berjalan; voucher dikaitkan dari halaman Voucher." />
    <AddButton v-if="canManage" label="Tambah kampanye" @click="open()" />
  </div>

  <!-- memuat: kerangka seukuran kartu supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat kampanye" class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
    <div v-for="n in 3" :key="n" class="h-72 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <div v-else-if="!items.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
    <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="sparkles" :size="26" /></span>
    <p class="font-heading text-headline-sm">Belum ada kampanye</p>
    <p class="max-w-md text-body-sm text-secondary">Buat kampanye untuk mengelompokkan diskon produk dalam satu tema dan periode, misalnya flash sale akhir pekan.</p>
    <AddButton v-if="canManage" label="Tambah kampanye" expanded class="mt-1" @click="open()" />
  </div>

  <template v-else>
    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-3" aria-label="Ringkasan kampanye">
      <li v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon :name="stat.icon" /></span>
        <span class="min-w-0">
          <span class="block text-body-sm text-secondary">{{ stat.label }}</span>
          <span class="block font-heading text-headline-sm tabular-nums">{{ stat.value }}</span>
        </span>
      </li>
    </ul>

    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="row in items" :key="row.id" class="flex flex-col overflow-hidden rounded-3xl border border-border bg-surface shadow-card" :class="row.status === 'draft' || row.status === 'ended' ? 'opacity-90' : ''">
        <div class="relative aspect-[16/7] bg-gradient-to-br from-accent-tint-20 to-accent-tint-12">
          <img v-if="row.image_url" :src="row.image_url" :alt="`Banner ${row.title}`" loading="lazy" class="size-full object-cover">
          <span v-else class="grid size-full place-items-center text-secondary" aria-hidden="true"><AdminIcon name="sparkles" :size="32" /></span>
          <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm shadow-card" :class="TONE_CLASS[toneOf(row)]">
            <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ STATUS[row.status]?.label || row.status }}
          </span>
          <span v-if="row.is_flash" class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-error px-2.5 py-1 text-label-sm text-surface shadow-card"><AdminIcon name="sparkles" :size="12" />Flash sale</span>
        </div>
        <div class="flex flex-1 flex-col gap-2 p-space-md">
          <h2 class="font-heading text-headline-sm">{{ row.title }}</h2>
          <p v-if="row.description" class="line-clamp-2 text-body-sm text-secondary">{{ row.description }}</p>
          <p class="flex items-start gap-1.5 text-body-sm"><AdminIcon name="clock" :size="16" class="mt-0.5 shrink-0 text-secondary" /><span>{{ formatDate(row.starts_at) }} <span class="text-secondary">sampai</span> {{ formatDate(row.ends_at) }}</span></p>
          <div class="mt-auto flex items-center justify-between gap-2 pt-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm"><AdminIcon name="tag" :size="12" />{{ row.items_count }} produk</span>
            <IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Kelola' : 'Lihat'" :subject="row.title" @click="open(row)" />
          </div>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="campaign ? (canManage ? 'update' : 'read') : 'create'" size="2xl" :title="campaign ? 'Kelola kampanye' : 'Tambah kampanye'" :subtitle="campaign?.title">
    <form id="campaign-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md sm:col-span-2">
          Judul
          <input v-model="form.title" required maxlength="150" :class="field">
          <span v-if="fieldErrors.title" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.title[0] }}</span>
        </label>
        <label class="block text-label-md sm:col-span-2">
          Deskripsi <span class="font-normal text-secondary">(opsional)</span>
          <textarea v-model="form.description" rows="3" maxlength="2000" :class="`${field} resize-none`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ form.description.length }}/2000</span>
        </label>
        <label class="block text-label-md">
          Mulai
          <input v-model="form.starts_at" type="datetime-local" required :class="field">
          <span v-if="fieldErrors.starts_at" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.starts_at[0] }}</span>
        </label>
        <label class="block text-label-md">
          Selesai
          <input v-model="form.ends_at" type="datetime-local" required :class="field">
          <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.ends_at[0] }}</span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.is_flash ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
          <input v-model="form.is_flash" type="checkbox" class="mt-0.5 size-4 accent-primary">
          <span><span class="block text-label-md">Flash sale</span><span class="block text-body-sm text-secondary">Menampilkan label Flash Sale dan jumlah terjual di halaman promo.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.is_active ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
          <input v-model="form.is_active" type="checkbox" class="mt-0.5 size-4 accent-primary">
          <span><span class="block text-label-md">Kampanye aktif</span><span class="block text-body-sm text-secondary">Tampil di toko selama periodenya.</span></span>
        </label>
      </fieldset>
    </form>

    <template v-if="campaign">
      <section class="mt-space-lg border-t border-border pt-space-md" aria-labelledby="cp-banner">
        <h3 id="cp-banner" class="font-heading text-headline-sm">Gambar banner</h3>
        <img v-if="campaign.image_url" :src="campaign.image_url" alt="Banner kampanye" class="mt-space-sm max-h-44 w-full rounded-2xl border border-border object-cover">
        <form v-if="canManage" class="mt-space-sm flex flex-wrap items-stretch gap-2" @submit.prevent="uploadImage">
          <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-secondary px-4 py-3 transition-colors duration-150 hover:bg-accent-tint-12 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
            <AdminIcon name="plus" :size="20" class="shrink-0 text-secondary" />
            <span class="min-w-0"><span class="block break-all text-label-md">{{ imageName || 'Pilih gambar' }}</span><span class="block text-body-sm text-secondary">JPG, JPEG, atau PNG</span></span>
            <input ref="imageInput" type="file" accept="image/jpeg,image/png" aria-label="Gambar banner" class="sr-only" @change="imageName = $event.target.files[0]?.name || ''">
          </label>
          <button :disabled="imageBusy" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`">{{ imageBusy ? 'Mengunggah...' : 'Unggah' }}</button>
        </form>
        <p v-if="imageError" role="alert" class="mt-1 text-body-sm text-error">{{ imageError }}</p>
      </section>

      <section class="mt-space-lg border-t border-border pt-space-md" aria-labelledby="cp-items">
        <h3 id="cp-items" class="font-heading text-headline-sm">Produk dalam kampanye</h3>
        <p v-if="!campaignItems.length" class="mt-space-sm rounded-2xl border border-dashed border-border p-4 text-center text-body-sm text-secondary">Belum ada produk.</p>
        <div v-else class="mt-space-sm overflow-x-auto rounded-2xl border border-border">
          <table class="w-full min-w-[560px] text-center text-body-sm">
            <caption class="sr-only">Produk dalam kampanye</caption>
            <thead class="bg-background/70 text-label-sm uppercase tracking-wide text-secondary">
              <tr>
                <th scope="col" class="whitespace-nowrap px-4 py-3">Varian</th>
                <th scope="col" class="whitespace-nowrap px-4 py-3">Untuk</th>
                <th scope="col" class="whitespace-nowrap px-4 py-3">Diskon</th>
                <th scope="col" class="whitespace-nowrap px-4 py-3">Status</th>
                <th v-if="canManage" scope="col" class="px-4 py-3"><span class="sr-only">Aksi</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="entry in campaignItems" :key="entry.id" class="transition-colors duration-150 hover:bg-accent-tint-12">
                <td class="px-4 py-3"><span class="block text-label-md">{{ entry.label }}</span><span class="block tabular-nums text-secondary">{{ rupiah.format(entry.price) }}</span></td>
                <td class="px-4 py-3">{{ AUDIENCES[entry.audience] }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-label-md tabular-nums">{{ valueLabel(entry) }}</td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="entry.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12 text-secondary'">
                    <i class="size-1.5 rounded-full" :class="entry.is_active ? 'bg-success' : 'bg-secondary'" aria-hidden="true"></i>{{ entry.is_active ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
                <td v-if="canManage" class="px-4 py-3"><IconAction icon="edit" label="Ubah diskon" :subject="entry.label" @click="editItem(entry)" /></td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="canManage" class="mt-space-md space-y-space-sm rounded-2xl border border-border bg-background/40 p-space-md" @submit.prevent="saveItem">
          <h4 class="flex items-center gap-2 text-label-md"><AdminIcon :name="editingItem ? 'edit' : 'plus'" :size="16" />{{ editingItem ? 'Ubah diskon produk' : 'Tambah produk' }}</h4>
          <p v-if="itemError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ itemError }}</p>
          <div v-if="!editingItem" class="relative">
            <label class="block text-label-md">
              Cari produk <span class="font-normal text-secondary">(SKU atau nama)</span>
              <input v-model="search" autocomplete="off" :class="field" @input="onSearch">
            </label>
            <ul v-if="results.length" class="absolute z-10 mt-1 max-h-52 w-full overflow-y-auto rounded-2xl border border-border bg-surface p-1 shadow-float">
              <li v-for="entry in results" :key="entry.id">
                <button type="button" :class="`block min-h-10 w-full rounded-xl px-3 py-2 text-left text-body-sm hover:bg-accent-tint-15 ${press}`" @click="pick(entry)">{{ entry.label }} <span class="text-secondary">({{ entry.sku }}, {{ rupiah.format(entry.price) }})</span></button>
              </li>
            </ul>
          </div>
          <p v-if="item.variant_id" class="flex items-center gap-2 rounded-2xl bg-surface px-4 py-3 text-body-sm"><AdminIcon name="tag" :size="16" class="shrink-0" /><span><strong>{{ item.label }}</strong>, harga jual <span class="tabular-nums">{{ rupiah.format(item.price) }}</span></span></p>
          <div class="grid gap-space-sm sm:grid-cols-3">
            <label class="block text-label-md">
              Untuk siapa
              <select v-model="item.audience" :class="field">
                <option v-for="(label, value) in AUDIENCES" :key="value" :value="value">{{ label }}</option>
              </select>
            </label>
            <label class="block text-label-md">
              Jenis
              <select v-model="item.type" :class="field">
                <option value="percent">Persen</option>
                <option value="fixed">Potongan rupiah</option>
              </select>
            </label>
            <label class="block text-label-md">
              {{ item.type === 'percent' ? 'Diskon (%)' : 'Potongan (Rp)' }}
              <input v-model.number="item.value" type="number" min="1" required :class="field">
              <span v-if="itemFields.value" class="mt-1 block text-body-sm font-normal text-error">{{ itemFields.value[0] }}</span>
            </label>
          </div>
          <p v-if="previewPrice" class="flex items-center gap-2 rounded-2xl bg-accent-tint-15 px-4 py-3 text-body-sm"><AdminIcon name="tag" :size="16" />Harga setelah diskon ini: <strong class="tabular-nums">{{ rupiah.format(previewPrice) }}</strong></p>
          <label class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary" :class="item.is_active ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="item.is_active" type="checkbox" class="size-4 accent-primary">
            <span class="text-label-md">Aktif</span>
          </label>
          <div class="flex flex-wrap gap-2">
            <button :disabled="itemBusy || !item.variant_id" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ itemBusy ? 'Menyimpan...' : (editingItem ? 'Simpan perubahan' : 'Tambah ke kampanye') }}</button>
            <button v-if="editingItem" type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="resetItem">Batal</button>
          </div>
        </form>
      </section>
    </template>

    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">Tutup</button>
      <button v-if="canManage" form="campaign-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : (campaign ? 'Simpan kampanye' : 'Buat kampanye') }}</button>
    </template>
  </AppModal>
</template>
