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
const DOT = { ok: 'bg-success', warn: 'bg-warning', bad: 'bg-error', info: 'bg-info', neutral: 'bg-secondary' };

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/vouchers', { filters: { type: '', is_active: '' } });

const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { code: '', description: '', type: 'percent', audience: 'all', value: '', min_subtotal: 0, max_discount: '', quota: '', per_customer_limit: 1, starts_at: '', ends_at: '', is_active: false, is_listed: true, requires_claim: false, stackable: false, campaign_id: '' };
const campaigns = ref([]);
onMounted(async () => {
    try {
        campaigns.value = (await api.get('/admin/campaigns')).data.data.items;
    } catch { /* tanpa daftar kampanye, voucher tetap bisa dibuat tanpa kampanye */ }
});
const form = reactive({ ...blank });

const TYPES = [
    { key: 'percent', title: 'Persen', text: 'Potongan berupa persen dari belanja, bisa dibatasi nominal.' },
    { key: 'fixed', title: 'Potongan rupiah', text: 'Potongan tetap dalam rupiah.' },
];
const TOGGLES = [
    { key: 'is_active', title: 'Voucher aktif', text: 'Voucher nonaktif tidak bisa dipakai.' },
    { key: 'is_listed', title: 'Tampil di daftar checkout', text: 'Matikan bila hanya dibagikan lewat kode.' },
    { key: 'requires_claim', title: 'Perlu diklaim dulu', text: 'Tampil di halaman Voucher dan masuk Voucher Saya. Klaim butuh akun, jadi tidak bisa khusus Non Member; mengetik kodenya di checkout juga mengklaim.' },
    { key: 'stackable', title: 'Boleh digabung', text: 'Maksimal 2 voucher per pesanan, keduanya harus bertanda ini. Voucher kedua dihitung dari sisa setelah voucher pertama.' },
];

const valueLabel = (voucher) => (voucher.type === 'percent' ? `${voucher.value}%` : rupiah.format(voucher.value));

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
    return form.type === 'percent' ? `${form.value}%` : rupiah.format(form.value);
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
        type: form.type,
        audience: form.audience,
        per_customer_limit: form.per_customer_limit === '' ? null : form.per_customer_limit,
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
    if (form.type === 'percent') body.max_discount = form.max_discount === '' ? null : form.max_discount;
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
    <PageHeader title="Voucher" description="Voucher dipakai pelanggan di checkout. Satu voucher produk atau toko per pesanan; voucher ongkir menyusul di versi berikutnya." />
    <AddButton v-if="canManage" label="Tambah voucher" class="mt-3.5" @click="open()" />
  </div>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari kode">
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
        <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="ticket" :size="18" /></span>
        <span class="min-w-0">
          <span class="block truncate font-mono text-label-md">{{ row.code }}</span>
          <span v-if="row.description" class="block truncate text-body-sm text-secondary">{{ row.description }}</span>
          <span class="mt-1 flex flex-wrap gap-1">
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
    <template #cell-audience="{ row }">{{ AUDIENCES[row.audience] }}</template>
    <template #cell-used="{ row }">
      <span class="block whitespace-nowrap text-label-md tabular-nums">{{ row.used_count }} / {{ row.quota ?? '∞' }}</span>
      <div v-if="row.quota" class="mx-auto mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-secondary-tint-12" role="progressbar" :aria-valuenow="row.used_count" aria-valuemin="0" :aria-valuemax="row.quota" :aria-label="`Terpakai ${row.used_count} dari ${row.quota}`">
        <div class="h-full origin-left rounded-full" :class="usedPercent(row) >= 100 ? 'bg-error' : 'bg-accent'" :style="{ transform: `scaleX(${usedPercent(row) / 100})` }"></div>
      </div>
      <span v-if="row.requires_claim" class="mt-1 block text-body-sm text-secondary">{{ row.claims_count }} diklaim</span>
    </template>
    <template #cell-valid="{ row }">
      <template v-if="row.starts_at || row.ends_at">
        <span class="block whitespace-nowrap">{{ row.starts_at ? formatDate(row.starts_at) : 'Sekarang' }}</span>
        <span class="block whitespace-nowrap text-body-sm text-secondary">sampai {{ row.ends_at ? formatDate(row.ends_at) : 'tanpa batas' }}</span>
      </template>
      <span v-else class="text-secondary">Tanpa batas waktu</span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[stateOf(row).tone]">
        <i class="size-1.5 rounded-full" :class="DOT[stateOf(row).tone]" aria-hidden="true"></i>{{ stateOf(row).label }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1"><IconAction :icon="canManage ? 'edit' : 'eye'" :label="canManage ? 'Ubah' : 'Lihat'" :subject="row.code" @click="open(row)" /></div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="editing ? (canManage ? 'update' : 'read') : 'create'" size="2xl" :title="editing ? (canManage ? 'Ubah voucher' : 'Detail voucher') : 'Tambah voucher'" :subtitle="editing?.code">
    <!-- Pratinjau tiket: kode dan diskon seperti yang dilihat pelanggan -->
    <div class="relative flex items-center gap-4 overflow-hidden rounded-3xl border border-dashed border-accent bg-accent-tint-12 px-5 py-4" aria-hidden="true">
      <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-accent text-primary"><AdminIcon name="ticket" :size="22" /></span>
      <div class="min-w-0 flex-1">
        <p class="truncate font-mono text-label-md uppercase">{{ form.code || 'KODEVOUCHER' }}</p>
        <p class="truncate text-body-sm text-secondary">{{ form.description || 'Keterangan voucher' }}</p>
      </div>
      <p class="font-heading text-headline-md tabular-nums">{{ previewValue }}</p>
    </div>

    <form id="voucher-form" class="mt-space-md space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="!canManage" class="space-y-space-md">
        <div class="grid gap-space-md sm:grid-cols-2">
          <label class="block text-label-md">
            Kode
            <input v-model="form.code" required maxlength="40" :class="field" class="font-mono uppercase">
            <span v-if="fieldErrors.code" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.code[0] }}</span>
          </label>
          <label class="block text-label-md">
            Untuk siapa
            <select v-model="form.audience" :class="field">
              <option v-for="(label, value) in AUDIENCES" :key="value" :value="value">{{ label }}</option>
            </select>
            <span v-if="fieldErrors.audience" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.audience[0] }}</span>
          </label>
          <label class="block text-label-md sm:col-span-2">
            Keterangan di daftar voucher <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.description" maxlength="150" placeholder="Contoh: Diskon spesial pelanggan baru" :class="field">
            <span v-if="fieldErrors.description" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.description[0] }}</span>
          </label>
        </div>

        <fieldset class="grid gap-2 sm:grid-cols-2">
          <legend class="mb-2 text-label-md">Jenis diskon</legend>
          <label v-for="type in TYPES" :key="type.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form.type === type.key ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
            <input v-model="form.type" type="radio" :value="type.key" class="mt-1 size-4 accent-primary">
            <span><span class="block text-label-md">{{ type.title }}</span><span class="block text-body-sm text-secondary">{{ type.text }}</span></span>
          </label>
        </fieldset>

        <div class="grid gap-space-md sm:grid-cols-2">
          <label class="block text-label-md">
            {{ form.type === 'percent' ? 'Diskon (%)' : 'Potongan (Rp)' }}
            <input v-model.number="form.value" type="number" required min="1" :max="form.type === 'percent' ? 100 : undefined" :class="field">
            <span v-if="fieldErrors.value" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.value[0] }}</span>
          </label>
          <label v-if="form.type === 'percent'" class="block text-label-md">
            Batas diskon <span class="font-normal text-secondary">(Rp, opsional)</span>
            <input v-model.number="form.max_discount" type="number" min="1" :class="field">
            <span v-if="fieldErrors.max_discount" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.max_discount[0] }}</span>
          </label>
          <label class="block text-label-md">
            Minimum belanja (Rp)
            <input v-model.number="form.min_subtotal" type="number" min="0" :class="field">
            <span v-if="fieldErrors.min_subtotal" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.min_subtotal[0] }}</span>
          </label>
          <label class="block text-label-md">
            Kuota <span class="font-normal text-secondary">(opsional)</span>
            <input v-model.number="form.quota" type="number" min="1" :class="field">
            <span v-if="fieldErrors.quota" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.quota[0] }}</span>
          </label>
          <label class="block text-label-md">
            Batas pakai per pelanggan
            <input v-model.number="form.per_customer_limit" type="number" min="1" :class="field">
            <span class="mt-1 block text-body-sm font-normal text-secondary">Kosong = tanpa batas.</span>
            <span v-if="fieldErrors.per_customer_limit" class="block text-body-sm font-normal text-error">{{ fieldErrors.per_customer_limit[0] }}</span>
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
          <label class="block text-label-md">
            Selesai <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="form.ends_at" type="datetime-local" :class="field">
            <span v-if="fieldErrors.ends_at" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.ends_at[0] }}</span>
          </label>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="toggle in TOGGLES" :key="toggle.key" class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition-colors duration-150 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary has-[:disabled]:cursor-default" :class="form[toggle.key] ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-secondary'">
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
