<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
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
import StatusBadge from '../../components/ui/StatusBadge.vue';
import FormField from '../../components/ui/FormField.vue';

const auth = useAuthStore();
const canManage = computed(() => auth.can('vouchers.manage'));
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

const COLUMNS = [
    { key: 'code', label: 'Kode', cellClass: 'text-left' },
    { key: 'discount', label: 'Diskon' },
    { key: 'audience', label: 'Untuk' },
    { key: 'used', label: 'Terpakai' },
    { key: 'valid', label: 'Berlaku' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const AUDIENCES = { all: 'Semua pelanggan', guest: 'Non Member (tanpa akun)', member: 'Member (sudah login)' };

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/vouchers', { filters: { kind: '', type: '', is_active: '' } });

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { code: '', description: '', kind: 'order', type: 'percent', audience: 'all', value: '', min_subtotal: 0, max_discount: '', quota: '', per_customer_limit: 1, limit_period: '', starts_at: '', ends_at: '', is_active: false, is_listed: true, requires_claim: false, stackable: false, campaign_id: '', provinces: [], budget: '', signup_grant: false, claim_valid_days: 30 };
const campaigns = ref([]);
// Daftar provinsi sama dengan pilihan alamat di toko (public/data/wilayah.json), supaya area voucher cocok dengan alamat pelanggan.
const provinceOptions = ref([]);
onMounted(async () => {
    try {
        campaigns.value = (await api.get('/admin/campaigns')).data.data.items;
    } catch { /* tanpa daftar kampanye, voucher tetap bisa dibuat tanpa kampanye */ }
    try {
        const response = await fetch('/data/wilayah.json');
        if (response.ok) provinceOptions.value = (await response.json()).map(([province]) => province);
    } catch { /* tanpa daftar provinsi, voucher gratis ongkir berlaku di semua wilayah */ }
});
const form = reactive({ ...blank });
const isShipping = computed(() => form.kind === 'shipping');

// D-126: gratis ongkir = potongan tetap Rp1.000 sampai Rp25.000, khusus member, ditanggung toko.
const KINDS = [
    { key: 'order', title: 'Voucher biasa', text: 'Memotong harga belanja (subtotal).' },
    { key: 'shipping', title: 'Gratis ongkir', text: 'Memotong ongkir, maksimal Rp25.000. Khusus member dengan nomor WhatsApp terverifikasi.' },
];

const TYPES = [
    { key: 'percent', title: 'Persen', text: 'Potongan berupa persen dari belanja, bisa dibatasi nominal.' },
    { key: 'fixed', title: 'Potongan rupiah', text: 'Potongan tetap dalam rupiah.' },
];
const TOGGLES = [
    { key: 'is_active', title: 'Voucher aktif', text: 'Voucher nonaktif tidak bisa dipakai.' },
    { key: 'is_listed', title: 'Tampil di daftar checkout', text: 'Matikan bila hanya dibagikan lewat kode.' },
    { key: 'requires_claim', title: 'Perlu diklaim dulu', text: 'Tampil di halaman Voucher dan masuk Voucher Saya. Klaim butuh akun, jadi tidak bisa khusus Non Member; mengetik kodenya di checkout juga mengklaim.' },
    { key: 'stackable', title: 'Boleh digabung', text: 'Untuk 2 voucher biasa dalam satu pesanan, keduanya harus bertanda ini. Voucher gratis ongkir selalu bisa dipasangkan dengan 1 voucher biasa.' },
];
// Gratis ongkir: "Boleh digabung" tidak berlaku, dan hadiah pendaftaran diatur sendiri.
const toggles = computed(() => (isShipping.value ? TOGGLES.filter((toggle) => toggle.key !== 'stackable') : TOGGLES));

const valueLabel = (voucher) => (voucher.type === 'percent' ? `${voucher.value}%` : rupiah.format(voucher.value));
const budgetPercent = (voucher) => (voucher.budget ? Math.min(100, Math.round((voucher.spent / voucher.budget) * 100)) : 0);

// Keadaan voucher untuk lencana: urutan pemeriksaan menentukan alasan yang tampil.
function stateOf(voucher) {
    const now = Date.now();
    if (!voucher.is_active) return { label: 'Nonaktif', tone: 'neutral' };
    if (voucher.ends_at && new Date(voucher.ends_at).getTime() < now) return { label: 'Berakhir', tone: 'neutral' };
    if (voucher.quota && voucher.used_count >= voucher.quota) return { label: 'Kuota habis', tone: 'bad' };
    if (voucher.starts_at && new Date(voucher.starts_at).getTime() > now) return { label: 'Akan datang', tone: 'info' };
    return { label: 'Berlaku', tone: 'ok' };
}
const usedPercent = (voucher) => (voucher.quota ? Math.min(100, Math.round((voucher.used_count / voucher.quota) * 100)) : 0);

// Pratinjau tiket di atas form: kode dan diskon seperti yang dilihat pelanggan.
const previewValue = computed(() => {
    if (form.value === '' || form.value === null) return '—';
    return form.type === 'percent' && !isShipping.value ? `${form.value}%` : rupiah.format(form.value);
});

function open(voucher = null) {
    editing.value = voucher;
    Object.assign(form, blank, voucher ? {
        ...voucher,
        max_discount: voucher.max_discount ?? '',
        quota: voucher.quota ?? '',
        description: voucher.description ?? '',
        per_customer_limit: voucher.per_customer_limit ?? '',
        campaign_id: voucher.campaign_id ?? '',
        limit_period: voucher.limit_period ?? '',
        provinces: [...(voucher.provinces ?? [])],
        budget: voucher.budget ?? '',
        claim_valid_days: voucher.claim_valid_days ?? 30,
        starts_at: toLocalInput(voucher.starts_at),
        ends_at: toLocalInput(voucher.ends_at),
    } : {});
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = {
        code: form.code,
        description: form.description || null,
        kind: form.kind,
        type: isShipping.value ? 'fixed' : form.type,
        audience: isShipping.value ? 'member' : form.audience,
        per_customer_limit: form.per_customer_limit === '' ? null : form.per_customer_limit,
        limit_period: form.limit_period || null,
        is_listed: form.is_listed,
        requires_claim: form.requires_claim,
        stackable: form.stackable,
        campaign_id: form.campaign_id || null,
        value: form.value,
        min_subtotal: form.min_subtotal === '' ? 0 : form.min_subtotal,
        quota: form.quota === '' ? null : form.quota,
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        is_active: form.is_active,
    };
    if (form.type === 'percent' && !isShipping.value) body.max_discount = form.max_discount === '' ? null : form.max_discount;
    if (isShipping.value) {
        Object.assign(body, {
            provinces: form.provinces.length ? form.provinces : null,
            budget: form.budget === '' ? null : form.budget,
            signup_grant: form.signup_grant,
            claim_valid_days: form.signup_grant ? form.claim_valid_days : null,
        });
    }
    try {
        if (editing.value) await api.patch(`/admin/vouchers/${editing.value.id}`, body);
        else await api.post('/admin/vouchers', body);
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
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-space-md">
    <PageHeader title="Voucher" description="Voucher dipakai pelanggan di checkout. Maksimal 2 per pesanan: 2 voucher biasa yang boleh digabung, atau 1 gratis ongkir dan 1 voucher biasa." />
    <AddButton v-if="canManage" label="Tambah voucher" class="mt-3.5" @click="open()" />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari kode">
      <FilterSelect v-model="filter.kind" label="Voucher" :options="{ order: 'Voucher biasa', shipping: 'Gratis ongkir' }" />
      <FilterSelect v-model="filter.type" label="Jenis" :options="{ percent: 'Persen', fixed: 'Potongan rupiah' }" />
      <FilterSelect v-model="filter.is_active" label="Status" :options="{ 1: 'Aktif', 0: 'Nonaktif' }" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar voucher"
    empty-text="Belum ada voucher."
    min-width="min-w-[960px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-code="{ row }">
      <div class="inline-flex max-w-xs items-center gap-3 text-left">
        <span v-if="row.kind === 'shipping'" class="grid size-10 shrink-0 place-items-center rounded-2xl bg-success-surface text-success" aria-hidden="true"><AdminIcon name="truck" :size="18" /></span>
        <span v-else class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="ticket" :size="18" /></span>
        <span class="min-w-0">
          <span class="block truncate font-mono text-label-md">{{ row.code }}</span>
          <span v-if="row.description" class="block truncate text-body-sm text-secondary">{{ row.description }}</span>
          <span class="mt-1 flex flex-wrap gap-1">
            <span v-if="row.kind === 'shipping'" class="rounded-full bg-success-surface px-2 py-0.5 text-label-sm text-success">Gratis Ongkir</span>
            <span v-if="row.signup_grant" class="rounded-full bg-success-surface px-2 py-0.5 text-label-sm text-success">Hadiah daftar · {{ row.claim_valid_days }} hari</span>
            <span v-if="row.provinces?.length" class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm text-secondary" :title="row.provinces.join(', ')">{{ row.provinces.length }} provinsi</span>
            <span v-if="row.requires_claim" class="rounded-full bg-info-surface px-2 py-0.5 text-label-sm text-info">Perlu klaim</span>
            <span v-if="row.stackable" class="rounded-full bg-accent-tint-20 px-2 py-0.5 text-label-sm">Bisa digabung</span>
            <span v-if="!row.is_listed" class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm text-secondary">Hanya kode</span>
          </span>
        </span>
      </div>
    </template>
    <template #cell-discount="{ row }">
      <span class="block whitespace-nowrap text-label-md tabular-nums">{{ valueLabel(row) }}</span>
      <span v-if="row.type === 'percent' && row.max_discount" class="block whitespace-nowrap text-body-sm tabular-nums text-secondary">maks {{ rupiah.format(row.max_discount) }}</span>
      <span v-if="row.min_subtotal" class="block whitespace-nowrap text-body-sm tabular-nums text-secondary">min. belanja {{ rupiah.format(row.min_subtotal) }}</span>
    </template>
    <template #cell-audience="{ row }">{{ AUDIENCES[row.audience] }}<span v-if="row.limit_period === 'month'" class="block text-body-sm text-secondary">{{ row.per_customer_limit ?? '∞' }}x per bulan</span></template>
    <template #cell-used="{ row }">
      <span class="block whitespace-nowrap text-label-md tabular-nums">{{ row.used_count }} / {{ row.quota ?? '∞' }}</span>
      <div v-if="row.quota" class="mx-auto mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-secondary-tint-12" role="progressbar" :aria-valuenow="row.used_count" aria-valuemin="0" :aria-valuemax="row.quota" :aria-label="`Terpakai ${row.used_count} dari ${row.quota}`">
        <div class="h-full origin-left rounded-full" :class="usedPercent(row) >= 100 ? 'bg-error' : 'bg-accent'" :style="{ transform: `scaleX(${usedPercent(row) / 100})` }"></div>
      </div>
      <span v-if="row.requires_claim" class="mt-1 block text-body-sm text-secondary">{{ row.claims_count }} diklaim</span>
      <template v-if="row.budget">
        <span class="mt-1 block whitespace-nowrap text-body-sm tabular-nums text-secondary">{{ rupiah.format(row.spent) }} / {{ rupiah.format(row.budget) }}</span>
        <div class="mx-auto mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-secondary-tint-12" role="progressbar" :aria-valuenow="row.spent" aria-valuemin="0" :aria-valuemax="row.budget" :aria-label="`Anggaran terpakai ${rupiah.format(row.spent)} dari ${rupiah.format(row.budget)}`">
          <div class="h-full origin-left rounded-full" :class="budgetPercent(row) >= 100 ? 'bg-error' : 'bg-success'" :style="{ transform: `scaleX(${budgetPercent(row) / 100})` }"></div>
        </div>
      </template>
    </template>
    <template #cell-valid="{ row }">
      <template v-if="row.starts_at || row.ends_at">
        <span class="block whitespace-nowrap">{{ row.starts_at ? formatDate(row.starts_at) : 'Sekarang' }}</span>
        <span class="block whitespace-nowrap text-body-sm text-secondary">sampai {{ row.ends_at ? formatDate(row.ends_at) : 'tanpa batas' }}</span>
      </template>
      <span v-else class="text-secondary">Tanpa batas waktu</span>
    </template>
    <template #cell-status="{ row }">
      <StatusBadge :tone="stateOf(row).tone">{{ stateOf(row).label }}</StatusBadge>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1"><IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Ubah' : 'Lihat'" :subject="row.code" @click="open(row)" /></div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="editing ? (canManage ? 'update' : 'read') : 'create'" size="2xl" :title="editing ? (canManage ? 'Ubah voucher' : 'Detail voucher') : 'Tambah voucher'" :subtitle="editing?.code">
    <!-- Pratinjau tiket: kode dan diskon seperti yang dilihat pelanggan -->
    <div class="relative flex items-center gap-4 overflow-hidden rounded-3xl border border-dashed px-5 py-4" :class="isShipping ? 'border-success bg-success-surface' : 'border-accent bg-accent-tint-12'" aria-hidden="true">
      <span class="grid size-12 shrink-0 place-items-center rounded-2xl" :class="isShipping ? 'bg-success text-surface' : 'bg-accent text-primary'"><AdminIcon :name="isShipping ? 'truck' : 'ticket'" :size="22" /></span>
      <div class="min-w-0 flex-1">
        <p class="truncate font-mono text-label-md uppercase">{{ form.code || 'KODEVOUCHER' }}</p>
        <p class="truncate text-body-sm text-secondary">{{ form.description || 'Keterangan voucher' }}</p>
      </div>
      <p class="font-heading text-headline-md tabular-nums">{{ previewValue }}</p>
    </div>

    <form id="voucher-form" class="mt-space-md space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="space-y-space-md">
        <fieldset class="grid gap-2 sm:grid-cols-2">
          <legend class="mb-2 text-label-md">Voucher</legend>
          <label v-for="kind in KINDS" :key="kind.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.kind === kind.key ? (kind.key === 'shipping' ? 'border-success bg-success-surface' : 'border-primary bg-accent-tint-12') : 'border-border hover:border-secondary'">
            <input v-model="form.kind" type="radio" :value="kind.key" class="mt-1 size-4 accent-primary">
            <span><span class="block text-label-md">{{ kind.title }}</span><span class="block text-body-sm text-secondary">{{ kind.text }}</span></span>
          </label>
        </fieldset>

        <div class="grid gap-space-md sm:grid-cols-2">
          <FormField label="Kode" :error="fieldErrors.code">
            <input v-model="form.code" required maxlength="40" :class="field" class="font-mono uppercase">
          </FormField>
          <FormField label="Untuk siapa" :error="fieldErrors.audience">
            <select v-if="!isShipping" v-model="form.audience" :class="field">
              <option v-for="(label, value) in AUDIENCES" :key="value" :value="value">{{ label }}</option>
            </select>
            <span v-else :class="field" class="text-secondary">{{ AUDIENCES.member }}</span>
          </FormField>
          <FormField label="Keterangan di daftar voucher" optional :error="fieldErrors.description" class="sm:col-span-2">
            <input v-model="form.description" maxlength="150" placeholder="Contoh: Diskon spesial pelanggan baru" :class="field">
          </FormField>
        </div>

        <fieldset v-if="!isShipping" class="grid gap-2 sm:grid-cols-2">
          <legend class="mb-2 text-label-md">Jenis diskon</legend>
          <label v-for="type in TYPES" :key="type.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.type === type.key ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="form.type" type="radio" :value="type.key" class="mt-1 size-4 accent-primary">
            <span><span class="block text-label-md">{{ type.title }}</span><span class="block text-body-sm text-secondary">{{ type.text }}</span></span>
          </label>
        </fieldset>

        <div class="grid gap-space-md sm:grid-cols-2">
          <FormField :label="isShipping ? 'Potongan ongkir (Rp)' : form.type === 'percent' ? 'Diskon (%)' : 'Potongan (Rp)'" :error="fieldErrors.value">
            <input v-if="isShipping" v-model.number="form.value" type="number" required min="1000" max="25000" :class="field">
            <input v-else v-model.number="form.value" type="number" required min="1" :max="form.type === 'percent' ? 100 : undefined" :class="field">
            <span v-if="isShipping" class="mt-1 block text-body-sm font-normal text-secondary">Rp1.000 sampai Rp25.000. Tidak pernah melebihi ongkir.</span>
          </FormField>
          <label v-if="form.type === 'percent' && !isShipping" class="block text-label-md">
            Batas diskon <span class="font-normal text-secondary">(Rp, opsional)</span>
            <input v-model.number="form.max_discount" type="number" min="1" :class="field">
            <span v-if="fieldErrors.max_discount" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.max_discount[0] }}</span>
          </label>
          <FormField label="Minimum belanja (Rp)" :error="fieldErrors.min_subtotal">
            <input v-model.number="form.min_subtotal" type="number" min="0" :class="field">
          </FormField>
          <FormField label="Kuota" optional :error="fieldErrors.quota">
            <input v-model.number="form.quota" type="number" min="1" :class="field">
          </FormField>
          <label class="block text-label-md">
            Batas pakai per pelanggan
            <input v-model.number="form.per_customer_limit" type="number" min="1" :class="field">
            <span class="mt-1 block text-body-sm font-normal text-secondary">{{ isShipping ? 'Dihitung per nomor WhatsApp terverifikasi.' : 'Wajib untuk voucher Semua atau Non Member (dihitung per nomor WhatsApp dan per alamat pengiriman). Kosong = tanpa batas, hanya untuk voucher khusus Member.' }}</span>
            <span v-if="fieldErrors.per_customer_limit" class="block text-body-sm font-normal text-error">{{ fieldErrors.per_customer_limit[0] }}</span>
          </label>
          <label class="block text-label-md">
            Periode batas pakai
            <select v-model="form.limit_period" :class="field">
              <option value="">Selama voucher berlaku</option>
              <option value="month">Per bulan kalender (reset tanggal 1)</option>
            </select>
          </label>
          <label v-if="isShipping" class="block text-label-md">
            Anggaran <span class="font-normal text-secondary">(Rp, opsional)</span>
            <input v-model.number="form.budget" type="number" min="1000" :class="field">
            <span class="mt-1 block text-body-sm font-normal text-secondary">Voucher berhenti otomatis bila sisa anggaran kurang dari potongan.<template v-if="editing?.budget"> Terpakai {{ rupiah.format(editing.spent ?? 0) }}.</template></span>
            <span v-if="fieldErrors.budget" class="block text-body-sm font-normal text-error">{{ fieldErrors.budget[0] }}</span>
          </label>
          <label class="block text-label-md">
            Kampanye <span class="font-normal text-secondary">(opsional)</span>
            <select v-model="form.campaign_id" :class="field">
              <option value="">Tanpa kampanye</option>
              <option v-for="entry in campaigns" :key="entry.id" :value="entry.id">{{ entry.title }}</option>
            </select>
            <span class="mt-1 block text-body-sm font-normal text-secondary">Voucher hanya berlaku selama kampanye berjalan.</span>
            <span v-if="fieldErrors.campaign_id" class="block text-body-sm font-normal text-error">{{ fieldErrors.campaign_id[0] }}</span>
          </label>
          <label class="block text-label-md">
            Mulai <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.starts_at" type="datetime-local" :class="field">
          </label>
          <FormField label="Selesai" optional :error="fieldErrors.ends_at">
            <input v-model="form.ends_at" type="datetime-local" :class="field">
          </FormField>
        </div>

        <fieldset v-if="isShipping">
          <legend class="text-label-md">Area <span class="font-normal text-secondary">({{ form.provinces.length ? `${form.provinces.length} provinsi` : 'semua wilayah' }})</span></legend>
          <p class="mt-1 text-body-sm text-secondary">Tidak ada yang dicentang = berlaku di semua wilayah. Dicek dari provinsi alamat pengiriman.</p>
          <div class="mt-2 grid max-h-48 gap-1 overflow-y-auto rounded-2xl border border-border p-3 sm:grid-cols-2">
            <label v-for="province in provinceOptions" :key="province" class="flex cursor-pointer items-center gap-2 text-body-sm">
              <input v-model="form.provinces" type="checkbox" :value="province" class="size-4 accent-primary">{{ province }}
            </label>
            <p v-if="!provinceOptions.length" class="text-body-sm text-secondary">Daftar provinsi tidak termuat.</p>
          </div>
          <span v-if="fieldErrors.provinces" class="mt-1 block text-body-sm text-error">{{ fieldErrors.provinces[0] }}</span>
        </fieldset>

        <div v-if="isShipping" class="grid gap-space-md sm:grid-cols-2">
          <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.signup_grant ? 'border-success bg-success-surface' : 'border-border hover:border-secondary'">
            <input v-model="form.signup_grant" type="checkbox" class="mt-0.5 size-4 accent-primary">
            <span><span class="block text-label-md">Hadiah pendaftaran</span><span class="block text-body-sm text-secondary">Otomatis masuk Voucher Saya untuk member baru. Member lama tidak bisa mengklaimnya.</span></span>
          </label>
          <label v-if="form.signup_grant" class="block text-label-md">
            Berlaku sejak daftar (hari)
            <input v-model.number="form.claim_valid_days" type="number" required min="1" max="365" :class="field">
            <span v-if="fieldErrors.claim_valid_days" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.claim_valid_days[0] }}</span>
          </label>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="toggle in toggles" :key="toggle.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form[toggle.key] ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="form[toggle.key]" type="checkbox" class="mt-0.5 size-4 accent-primary">
            <span><span class="block text-label-md">{{ toggle.title }}</span><span class="block text-body-sm text-secondary">{{ toggle.text }}</span></span>
          </label>
        </div>
      </fieldset>
    </form>
    <AdminComments v-if="editing" :url="`/admin/vouchers/${editing.id}/comments`" class="mt-space-md" />
    <template #footer>
      <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="dialog.close()">{{ canManage ? 'Batal' : 'Tutup' }}</button>
      <button v-if="canManage" form="voucher-form" :disabled="saving" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
