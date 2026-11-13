<script setup>
import { ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';
import DataTable from '../../components/ui/DataTable.vue';
import IconAction from '../../components/ui/IconAction.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { formatDate } from '../../utils/datetime';

const COLUMNS = [
    { key: 'name', label: 'Pelanggan' },
    { key: 'phone', label: 'WhatsApp' },
    { key: 'orders_count', label: 'Pesanan' },
    { key: 'created_at', label: 'Terdaftar' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const STATUS_OPTIONS = [{ value: '1', label: 'Aktif' }, { value: '0', label: 'Nonaktif' }];

// Warna avatar tetap per nama supaya baris mudah dikenali saat menggulir.
const AVATAR_TONES = ['bg-accent-tint-20 text-primary', 'bg-info-surface text-info', 'bg-success-surface text-success', 'bg-warning-surface text-warning', 'bg-secondary-tint-12 text-secondary'];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/customers', { filters: { is_active: '' } });

const auth = useAuthStore();
const deleteDialog = ref(null);
const toDelete = ref(null);
const deleting = ref(false);
const deleteError = ref('');

function askDelete(customer) {
    toDelete.value = customer;
    deleteError.value = '';
    deleteDialog.value.showModal();
}

async function removeCustomer() {
    deleting.value = true;
    deleteError.value = '';
    try {
        await api.delete(`/admin/customers/${toDelete.value.id}`, { toast: 'Pelanggan dihapus.' });
        deleteDialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        deleteError.value = fields.user?.[0] || message;
    } finally {
        deleting.value = false;
    }
}

const initials =(name) => (name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
const avatarTone = (name) => AVATAR_TONES[[...(name || '')].reduce((sum, ch) => sum + ch.charCodeAt(0), 0) % AVATAR_TONES.length];
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Pelanggan" description="Akun pelanggan yang terdaftar. Pesanan tamu ada di menu Pesanan." />
    <TableToolbar v-model="search" placeholder="Cari nama, email, atau WhatsApp">
      <FilterSelect v-model="filter.is_active" label="Status" :options="STATUS_OPTIONS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar pelanggan"
    empty-text="Tidak ada pelanggan yang cocok."
    min-width="min-w-[900px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-name="{ row }">
      <div class="inline-flex max-w-xs items-center gap-3 text-left">
        <span class="grid size-10 shrink-0 place-items-center rounded-full text-label-md" :class="avatarTone(row.name)" aria-hidden="true">{{ initials(row.name) }}</span>
        <span class="min-w-0">
          <span class="block truncate text-label-md">{{ row.name }}</span>
          <span class="flex items-center gap-1 text-body-sm text-secondary">
            <span class="truncate">{{ row.email }}</span>
            <AdminIcon v-if="row.email_verified" name="check" :size="14" class="shrink-0 text-success" label="Email terverifikasi" />
          </span>
        </span>
      </div>
    </template>
    <template #cell-phone="{ row }">
      <template v-if="row.phone">
        <span class="block whitespace-nowrap font-mono">{{ row.phone }}</span>
        <span class="block text-label-sm" :class="row.phone_verified ? 'text-success' : 'text-secondary'">{{ row.phone_verified ? 'Terverifikasi' : 'Belum verifikasi' }}</span>
      </template>
      <span v-else class="text-secondary">Belum diisi</span>
    </template>
    <template #cell-orders_count="{ row }">
      <span class="inline-flex min-w-9 items-center justify-center rounded-full px-2.5 py-1 text-label-md tabular-nums" :class="row.orders_count ? 'bg-accent-tint-15 text-primary' : 'text-secondary'">{{ row.orders_count }}</span>
    </template>
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDate(row.created_at) }}</span></template>
    <template #cell-is_active="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="row.is_active ? 'bg-success-surface text-success' : 'bg-error-surface text-on-error-surface'">
        <i class="size-1.5 rounded-full" :class="row.is_active ? 'bg-success' : 'bg-error'" aria-hidden="true"></i>{{ row.is_active ? 'Aktif' : 'Nonaktif' }}
      </span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center gap-1">
        <IconAction icon="eye" label="Lihat detail" :subject="row.name" :to="{ name: 'AdminCustomerDetail', params: { id: row.id } }" />
        <IconAction v-if="auth.can('users.manage') && !row.orders_count" icon="trash" tone="danger" label="Hapus pelanggan" :subject="row.name" @click="askDelete(row)" />
      </div>
    </template>
  </DataTable>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus pelanggan?" :subtitle="toDelete?.name" @close="toDelete = null">
    <div v-if="toDelete" class="space-y-2 text-body-sm">
      <p>Akun <strong class="break-all">{{ toDelete.email }}</strong> dihapus permanen dan tidak bisa dikembalikan, termasuk alamat tersimpan, klaim voucher, dan sesi loginnya.</p>
      <p class="text-secondary">Bila hanya ingin menutup akses, nonaktifkan akun dari halaman detail pelanggan.</p>
    </div>
    <p v-if="deleteError" role="alert" class="mt-3 text-body-sm text-error">{{ deleteError }}</p>
    <template #footer>
      <button type="button" class="inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="deleting" class="inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="removeCustomer">{{ deleting ? 'Menghapus...' : 'Hapus pelanggan' }}</button>
    </template>
  </AppModal>
</template>
