<script setup>
import { computed, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import RefundDetail from '../../components/refund/RefundDetail.vue';
import DataTable from '../../components/ui/DataTable.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TableToolbar from '../../components/ui/TableToolbar.vue';
import { useDataTable } from '../../composables/useDataTable';
import { formatDate } from '../../utils/datetime';
import { REFUND_REASON, REFUND_STATUS_TONE, TONE_CLASS, refundStatusLabel, rupiah } from '../../utils/orderLabels';

// Refund barang bermasalah dan retur produk utuh. Alur: CS menghubungi pelanggan dan memeriksa video, Pemilik memutuskan,
// CS mencatat transfer manual. Sistem tidak menyetujui apa pun dan tidak memindahkan uang.
const COLUMNS = [
    { key: 'refund_number', label: 'Pengajuan' },
    { key: 'order_number', label: 'Pesanan' },
    { key: 'reason_type', label: 'Masalah' },
    { key: 'requested_amount', label: 'Jumlah' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Aksi' },
];
const DOT = { ok: 'bg-success', warn: 'bg-warning', info: 'bg-info', bad: 'bg-error', neutral: 'bg-secondary' };

// Penyaring cepat per tahap, urut sesuai alur. Angka dari server dan tidak ikut pencarian.
const TABS = [
    { value: '', label: 'Semua' },
    { value: 'submitted', label: 'Perlu diproses' },
    { value: 'awaiting_evidence', label: 'Menunggu video' },
    { value: 'under_review', label: 'Menunggu keputusan' },
    { value: 'approved', label: 'Menunggu dana' },
    { value: 'refunded', label: 'Selesai' },
    { value: 'rejected', label: 'Ditolak' },
    { value: 'cancelled', label: 'Dibatalkan' },
];

const { rows, meta, page, perPage, search, filter, loading, error, load } = useDataTable('/admin/refunds', { filters: { status: '' } });

const detail = ref(null);
const counts = computed(() => meta.value.counts || {});
const countOf = (tab) => (tab.value === '' ? Object.values(counts.value).reduce((sum, n) => sum + Number(n), 0) : Number(counts.value[tab.value] || 0));
const toneOf = (row) => REFUND_STATUS_TONE[row.status] || 'neutral';
const isReturn = (row) => row.reason_type === 'return_unopened';

const press = 'transition-[transform,background-color,color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]';
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Refund" description="CS menghubungi pelanggan dan memeriksa video unboxing, Pemilik memutuskan, CS mencatat transfer manual. Sistem tidak menyetujui apa pun dan tidak memindahkan uang." />
    <TableToolbar v-model="search" placeholder="Cari nomor refund, pesanan, atau nama" />
  </div>

  <!-- aturan yang berlaku, ringkas -->
  <details class="mt-space-md rounded-2xl border border-border bg-surface px-4 py-3 text-body-sm shadow-card">
    <summary class="flex min-h-8 cursor-pointer items-center gap-2 text-label-md"><AdminIcon name="info" :size="16" />Aturan refund yang berlaku</summary>
    <ul class="mt-2 grid gap-1.5 text-secondary sm:grid-cols-2">
      <li><strong class="text-primary">Retur produk utuh:</strong> paling lambat 3 hari kalender setelah paket diterima, konfirmasi ke CS dulu, produk utuh dan belum dibuka (D-097).</li>
      <li><strong class="text-primary">Barang rusak, salah kirim, kurang, tidak layak:</strong> paling lambat 7 hari setelah diterima (D-043).</li>
      <li><strong class="text-primary">Video unboxing wajib:</strong> jelas mulai dari nomor resi, paket masih tersegel, sampai produk di dalamnya.</li>
      <li><strong class="text-primary">Proses sampai dana diterima:</strong> paling lama 7 hari sejak pengajuan. Lewat batas, sistem hanya menandai.</li>
    </ul>
  </details>

  <!-- penyaring per tahap -->
  <div class="mt-space-md -mx-1 overflow-x-auto px-1 pb-1" role="group" aria-label="Saring menurut tahap">
    <div class="flex w-max gap-2">
      <button
        v-for="tab in TABS"
        :key="tab.value"
        type="button"
        :aria-pressed="filter.status === tab.value"
        :class="`inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-full border px-4 text-label-md ${press} ${filter.status === tab.value ? 'border-primary bg-accent text-primary' : 'border-border bg-surface hover:bg-accent-tint-15'}`"
        @click="filter.status = tab.value"
      >
        {{ tab.label }}
        <span class="min-w-6 rounded-full px-1.5 py-0.5 text-center text-label-sm tabular-nums" :class="filter.status === tab.value ? 'bg-surface/70' : 'bg-secondary-tint-12'">{{ countOf(tab) }}</span>
      </button>
    </div>
  </div>

  <DataTable
    v-model:page="page"
    v-model:per-page="perPage"
    class="mt-space-md"
    caption="Daftar pengajuan refund"
    empty-text="Belum ada pengajuan refund."
    min-width="min-w-[880px]"
    :columns="COLUMNS"
    :rows="rows"
    :meta="meta"
    :loading="loading"
    :error="error"
    @retry="load"
  >
    <template #cell-refund_number="{ row }">
      <span class="block whitespace-nowrap font-mono">{{ row.refund_number }}</span>
      <span class="block whitespace-nowrap text-body-sm text-secondary">Diajukan {{ formatDate(row.created_at) }}</span>
    </template>
    <template #cell-order_number="{ row }">
      <span class="block whitespace-nowrap font-mono">{{ row.order_number }}</span>
      <span class="block whitespace-nowrap text-body-sm text-secondary">{{ row.customer_name }}</span>
    </template>
    <template #cell-reason_type="{ row }">
      <span class="block whitespace-nowrap">{{ REFUND_REASON[row.reason_type] || row.reason_type }}</span>
      <span class="block whitespace-nowrap text-label-sm text-secondary">{{ isReturn(row) ? 'Retur, 3 hari' : 'Klaim, 7 hari' }}</span>
    </template>
    <template #cell-requested_amount="{ row }">
      <span class="block whitespace-nowrap text-label-md tabular-nums">{{ rupiah(row.total_refund) }}</span>
      <span v-if="row.shipping_amount > 0" class="block whitespace-nowrap text-label-sm text-secondary">termasuk ongkir</span>
      <span v-else-if="row.approved_amount !== null && row.approved_amount !== row.requested_amount" class="block whitespace-nowrap text-label-sm text-secondary">diminta {{ rupiah(row.requested_amount) }}</span>
    </template>
    <template #cell-status="{ row }">
      <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[toneOf(row)]">
        <i class="size-1.5 rounded-full" :class="DOT[toneOf(row)]" aria-hidden="true"></i>{{ refundStatusLabel(row.status, row.refund_method) }}
      </span>
      <span v-if="row.is_overdue" class="mt-1 block text-label-sm text-error">Lewat batas proses</span>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-center">
        <IconAction icon="eye" label="Buka rincian" :subject="row.refund_number" @click="detail.show(row.id)" />
      </div>
    </template>
  </DataTable>

  <RefundDetail ref="detail" @changed="load" />
</template>
