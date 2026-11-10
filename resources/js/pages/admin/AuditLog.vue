<script setup>
import DataTable from '../../components/ui/DataTable.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { ACTIONS } from '../../utils/auditActions';
import PageHeader from '../../components/ui/PageHeader.vue';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

const COLUMNS = [
    { key: 'created_at', label: 'Waktu' },
    { key: 'actor', label: 'Pelaku' },
    { key: 'action', label: 'Aksi' },
    { key: 'target', label: 'Objek' },
    { key: 'changes', label: 'Perubahan' },
    { key: 'ip_address', label: 'IP' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/audit-logs', { filters: { action: '' }, perPage: 50 });
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Audit Log" description="Riwayat perubahan penting. Catatan ini tidak bisa diubah dari dashboard." />
    <TableToolbar v-model="search" placeholder="Cari nama pelaku">
      <FilterSelect v-model="filter.action" label="Jenis aksi" :options="ACTIONS" />
    </TableToolbar>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-lg"
    caption="Daftar audit log"
    empty-text="Belum ada catatan."
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateFormat.format(new Date(row.created_at)) }}</span></template>
    <template #cell-actor="{ row }">{{ row.actor || 'Sistem' }}</template>
    <template #cell-action="{ row }">{{ ACTIONS[row.action] || row.action }}</template>
    <template #cell-target="{ row }"><span class="whitespace-nowrap">{{ row.target }}</span></template>
    <template #cell-changes="{ row }"><span class="block max-w-xs break-words font-mono text-secondary">{{ row.changes ? JSON.stringify(row.changes) : '-' }}</span></template>
    <template #cell-ip_address="{ row }"><span class="whitespace-nowrap">{{ row.ip_address || '-' }}</span></template>
  </DataTable>
</template>
