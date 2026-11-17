<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import AddButton from '../../components/ui/AddButton.vue';
import AppModal from '../../components/ui/AppModal.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import { useAuthStore } from '../../stores/auth';

// Pelanggan yang tidak bisa membuat pesanan baru (D-122): otomatis dari retur berturut-turut, atau ditambahkan staf. Tidak ada hapus; blokir dicabut dengan catatan.
const auth = useAuthStore();
const canManage = computed(() => auth.can('customer_blocks.manage'));
// D-133: hanya Pemilik yang memutuskan; Super Admin memegang semua izin, jadi perannya diperiksa juga (server memeriksa ulang).
const canApprove = computed(() => auth.can('customer_blocks.approve') && auth.hasRole('owner'));
const rule = ref(null);

async function loadRule() {
    try {
        const { data } = await api.get('/admin/customer-blocks/rule');
        rule.value = data.data;
    } catch {
        rule.value = null;
    }
}
onMounted(loadRule);

const COLUMNS = [
    { key: 'customer', label: 'Pelanggan', cellClass: 'text-left' },
    { key: 'source', label: 'Sumber' },
    { key: 'status', label: 'Status' },
    { key: 'note', label: 'Catatan', cellClass: 'text-left' },
    { key: 'actions', label: 'Aksi' },
];
const STATUS_OPTIONS = { active: 'Aktif', released: 'Dicabut' };

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/customer-blocks', { filters: { status: 'active' } });

const dialog = ref(null);
const mode = ref('add'); // add | note | release | rule_request | rule_disable | rule_approve | rule_reject
const target = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const form = reactive({ phone: '', customer_name: '', street: '', city: '', postal_code: '', note: '' });

const TITLES = { add: 'Tambah blokir', note: 'Ubah catatan', release: 'Cabut blokir', rule_request: 'Ajukan nyalakan blokir otomatis', rule_disable: 'Matikan blokir otomatis', rule_approve: 'Setujui pengajuan', rule_reject: 'Tolak pengajuan' };
const NOTE_LABELS = { release: 'Alasan pencabutan (wajib)', rule_request: 'Alasan pengajuan (wajib)', rule_disable: 'Alasan mematikan (wajib)', rule_approve: 'Catatan Pemilik (opsional)', rule_reject: 'Alasan penolakan (wajib)' };
const NOTE_LABEL = computed(() => NOTE_LABELS[mode.value] || 'Catatan (wajib)');
const SUBMIT_LABELS = { release: 'Cabut blokir', rule_request: 'Ajukan', rule_disable: 'Matikan', rule_approve: 'Setujui', rule_reject: 'Tolak' };
const isRuleMode = computed(() => mode.value.startsWith('rule_'));

async function open(nextMode, row = null) {
    mode.value = nextMode;
    target.value = row;
    Object.assign(form, { phone: '', customer_name: '', street: '', city: '', postal_code: '', note: nextMode === 'note' ? (row.note || '') : '' });
    formError.value = '';
    fieldErrors.value = {};
    await nextTick();
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    try {
        if (mode.value === 'add') {
            const address = { street: form.street || null, city: form.city || null, postal_code: form.postal_code || null };
            await api.post('/admin/customer-blocks', { phone: form.phone, customer_name: form.customer_name || null, address, note: form.note });
        } else if (mode.value === 'note') {
            await api.patch(`/admin/customer-blocks/${target.value.id}`, { note: form.note });
        } else if (mode.value === 'rule_request') {
            await api.post('/admin/customer-blocks/rule/request', { note: form.note });
        } else if (mode.value === 'rule_disable') {
            await api.post('/admin/customer-blocks/rule/disable', { note: form.note });
        } else if (isRuleMode.value) {
            await api.post(`/admin/customer-blocks/rule/requests/${rule.value.pending.id}/decide`, { decision: mode.value === 'rule_approve' ? 'approve' : 'reject', note: form.note || null });
        } else {
            await api.post(`/admin/customer-blocks/${target.value.id}/release`, { note: form.note });
        }
        dialog.value.close();
        await Promise.all([load(), loadRule()]);
    } catch (e) {
        const { message, fields } = apiError(e);
        fieldErrors.value = fields;
        formError.value = fields.block?.[0] || fields.rule?.[0] || message;
    } finally {
        saving.value = false;
    }
}

const input = 'mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-space-md">
    <PageHeader title="Pelanggan Diblokir" description="Nomor atau alamat di daftar ini tidak bisa membuat pesanan baru. Blokir otomatis muncul bila tiga pesanan terakhir satu nomor semuanya berujung retur produk utuh yang disetujui (D-122, bila dinyalakan dengan persetujuan Pemilik, D-133). Retur dan pesanan yang sedang berjalan tidak terpengaruh. Blokir tidak dihapus, hanya dicabut dengan catatan." />
    <AddButton v-if="canManage" label="Tambah blokir" @click="open('add')" />
  </div>

  <section v-if="rule" class="mt-space-md rounded border border-secondary p-space-md" aria-labelledby="rule-title">
    <div class="flex flex-wrap items-start justify-between gap-space-md">
      <div>
        <h2 id="rule-title" class="text-label-lg">Blokir otomatis retur berturut-turut</h2>
        <p class="mt-1 text-body-sm text-secondary">Menyalakan aturan ini diajukan Super Admin atau Admin Toko dengan catatan, lalu disetujui Pemilik (D-133). Blokir yang sudah ada tetap berlaku walau aturan mati.</p>
      </div>
      <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="rule.enabled ? 'bg-error-surface text-on-error-surface' : 'bg-secondary-tint-12 text-secondary'">{{ rule.enabled ? 'Menyala' : 'Mati' }}</span>
    </div>

    <div v-if="rule.pending" class="mt-space-md rounded bg-accent-tint-15 p-space-sm text-body-sm" role="status">
      <p class="text-label-md">Menunggu keputusan Pemilik</p>
      <p>Diajukan {{ rule.pending.requested_by || '-' }} pada {{ formatDate(rule.pending.requested_at) }}: {{ rule.pending.note }}</p>
      <div v-if="canApprove" class="mt-space-sm flex flex-wrap gap-space-sm">
        <button type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="open('rule_approve')">Setujui</button>
        <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="open('rule_reject')">Tolak</button>
      </div>
    </div>
    <p v-else-if="rule.last_decision" class="mt-space-md text-body-sm text-secondary">
      Pengajuan terakhir {{ rule.last_decision.status === 'approved' ? 'disetujui' : 'ditolak' }} {{ rule.last_decision.decided_by || '-' }} pada {{ formatDate(rule.last_decision.decided_at) }}<template v-if="rule.last_decision.decision_note">: {{ rule.last_decision.decision_note }}</template>.
    </p>

    <div v-if="canManage && !rule.pending" class="mt-space-md">
      <button v-if="!rule.enabled" type="button" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="open('rule_request')">Ajukan nyalakan</button>
      <button v-else type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="open('rule_disable')">Matikan</button>
    </div>
  </section>

  <div class="mt-space-md flex justify-end">
    <TableToolbar v-model="search" placeholder="Cari nama atau nomor">
      <FilterSelect v-model="filter.status" label="Status" :options="STATUS_OPTIONS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pelanggan diblokir"
    empty-text="Belum ada pelanggan yang diblokir."
    min-width="min-w-[860px]"
    stack
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-customer="{ row }">
      <span class="block font-mono text-label-md">{{ row.phone }}</span>
      <span class="block text-body-sm">{{ row.customer_name }}</span>
      <span v-if="row.address_summary" class="block text-body-sm text-secondary">{{ row.address_summary }}</span>
      <span v-if="row.ip_address" class="block font-mono text-label-sm text-secondary">IP {{ row.ip_address }} (bukti)</span>
    </template>
    <template #cell-source="{ row }">
      <span class="block text-label-md">{{ row.source === 'auto' ? 'Otomatis' : 'Manual' }}</span>
      <span v-if="row.orders.length" class="block text-body-sm text-secondary">{{ row.orders.join(', ') }}</span>
      <span class="block text-body-sm text-secondary">{{ formatDate(row.blocked_at) }}</span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="row.is_active ? 'bg-error-surface text-on-error-surface' : 'bg-secondary-tint-12 text-secondary'">{{ row.is_active ? 'Diblokir' : 'Dicabut' }}</span>
      <span v-if="!row.is_active" class="mt-1 block text-body-sm text-secondary">{{ formatDate(row.released_at) }} oleh {{ row.released_by || '-' }}</span>
    </template>
    <template #cell-note="{ row }">
      <span class="text-body-sm">{{ row.note || (row.source === 'auto' ? 'Retur produk utuh berturut-turut' : '-') }}</span>
      <span v-if="row.release_note" class="mt-1 block text-body-sm text-secondary">Pencabutan: {{ row.release_note }}</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1 max-md:justify-start">
        <template v-if="canManage && row.is_active">
          <IconAction icon="edit" label="Ubah catatan" :subject="row.phone" @click="open('note', row)" />
          <IconAction icon="undo" label="Cabut blokir" :subject="row.phone" @click="open('release', row)" />
        </template>
        <span v-else class="text-secondary">-</span>
      </div>
    </template>
  </DataTable>

  <AppModal ref="dialog" :variant="mode === 'add' ? 'create' : 'update'" :title="TITLES[mode]" :subtitle="target && !isRuleMode ? target.phone : ''">
    <form id="block-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <p v-if="mode === 'rule_request'" class="text-body-sm text-secondary">Aturan baru menyala setelah Pemilik menyetujui. Sebaiknya Syarat dan Ketentuan serta Kebijakan Privasi sudah menyebut daftar blokir.</p>
      <p v-if="mode === 'release'" class="text-body-sm text-secondary">Pelanggan ini bisa membuat pesanan lagi. Untuk blokir otomatis, hitungan retur mulai dari awal.</p>
      <template v-if="mode === 'add'">
        <label class="block text-label-md">
          Nomor WhatsApp
          <input v-model="form.phone" required inputmode="tel" maxlength="20" placeholder="0812xxxxxxxx" :class="input">
          <span v-if="fieldErrors.phone" class="mt-1 block text-body-sm text-error">{{ fieldErrors.phone[0] }}</span>
        </label>
        <label class="block text-label-md">
          Nama <span class="font-normal text-secondary">(opsional, hanya bukti)</span>
          <input v-model="form.customer_name" maxlength="120" :class="input">
        </label>
        <fieldset class="space-y-space-sm">
          <legend class="text-label-md">Alamat <span class="font-normal text-secondary">(opsional; memblokir pesanan ke alamat yang sama)</span></legend>
          <input v-model="form.street" maxlength="255" placeholder="Jalan dan nomor" aria-label="Jalan dan nomor" :class="input">
          <div class="grid gap-space-sm sm:grid-cols-2">
            <input v-model="form.city" maxlength="100" placeholder="Kota" aria-label="Kota" :class="input">
            <input v-model="form.postal_code" inputmode="numeric" maxlength="5" placeholder="Kode pos" aria-label="Kode pos" :class="input">
          </div>
          <span v-if="fieldErrors['address.postal_code']" class="block text-body-sm text-error">{{ fieldErrors['address.postal_code'][0] }}</span>
        </fieldset>
      </template>
      <label class="block text-label-md">
        {{ NOTE_LABEL }}
        <textarea v-model="form.note" :required="mode !== 'rule_approve'" rows="3" maxlength="255" :class="input"></textarea>
        <span v-if="fieldErrors.note" class="mt-1 block text-body-sm text-error">{{ fieldErrors.note[0] }}</span>
      </label>
    </form>
    <template #footer>
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
      <button form="block-form" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : SUBMIT_LABELS[mode] || 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
