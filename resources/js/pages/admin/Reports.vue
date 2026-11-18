<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../../stores/auth';
import BarChart from '../../components/dashboard/BarChart.vue';
import DownloadButton from '../../components/ui/DownloadButton.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Laporan (reports.view_summary, reports.view). Rupiah hanya dikirim server bila akun memegang reports.view.
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const STATUS = { live: 'Berjalan', upcoming: 'Akan datang', ended: 'Berakhir', inactive: 'Nonaktif' };
const CONTENT = { banner: 'Banner', text: 'Teks promo', video: 'Video' };

const auth = useAuthStore();
const data = ref(null);
const loading = ref(true);
const error = ref('');

// Periode tampil: tanpa parameter server memakai bulan ini sampai hari ini, per hari. Semua bagian berbasis waktu dan unduhan
// rekap mengikuti periode yang sudah diterapkan (data.period), bukan isian yang belum diterapkan.
const GROUPS = [
    { value: 'day', label: 'Per hari' },
    { value: 'week', label: 'Per minggu' },
    { value: 'month', label: 'Per bulan' },
    { value: 'year', label: 'Per tahun' },
];
const filter = ref({ from: '', to: '', group: 'day' });
const filterError = ref('');
const reloading = ref(false);
const period = computed(() => data.value?.period ?? null);
const periodParams = computed(() => (period.value ? { from: period.value.from, to: period.value.to, group: period.value.group } : {}));
const dateFmt = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const periodText = computed(() => {
    if (!period.value) return '';
    const from = dateFmt.format(new Date(`${period.value.from}T00:00:00Z`));
    const to = dateFmt.format(new Date(`${period.value.to}T00:00:00Z`));
    return `${from === to ? from : `${from} – ${to}`}, per ${period.value.name}`;
});

async function load(params) {
    const first = !data.value;
    if (first) loading.value = true;
    else reloading.value = true;
    error.value = '';
    filterError.value = '';
    try {
        const { data: body } = await api.get('/admin/reports', { params });
        data.value = body.data;
        filter.value = { from: body.data.period.from, to: body.data.period.to, group: body.data.period.group };
    } catch (e) {
        if (first) error.value = apiError(e).message;
        else {
            const err = apiError(e);
            filterError.value = Object.values(err.fields).flat()[0] || err.message;
        }
    } finally {
        loading.value = false;
        reloading.value = false;
    }
}

const applyFilter = () => load({ ...filter.value });

// Ekspor penjualan untuk finance (reports.export): ringkasan dulu, lalu unduh lewat axios supaya sesi dan CSRF sama dengan halaman lain.
const today = new Date().toISOString().slice(0, 10);
const exportRange = ref({ from: today.slice(0, 8) + '01', to: today });
const exportInfo = ref(null);
const exporting = ref(false);
const exportError = ref('');

async function previewExport() {
    exportError.value = '';
    exportInfo.value = null;
    try {
        exportInfo.value = (await api.get('/admin/reports/sales-export/summary', { params: exportRange.value })).data.data;
    } catch (e) {
        exportError.value = apiError(e).message;
    }
}

async function downloadExport() {
    exporting.value = true;
    exportError.value = '';
    try {
        const response = await api.get('/admin/reports/sales-export', { params: exportRange.value, responseType: 'blob' });
        saveBlob(response.data, `penjualan-finance-${exportRange.value.from}-sd-${exportRange.value.to}.xlsx`);
    } catch (e) {
        exportError.value = e.response?.data instanceof Blob ? 'Gagal mengunduh. Periksa tanggal dan coba lagi.' : apiError(e).message;
    } finally {
        exporting.value = false;
    }
}

function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// Rekap Laporan sebagai xlsx (reports.view): angka yang sama dengan tabel atau grafik di layar. Satu status per jenis.
const reportExporting = ref({});
const reportExportError = ref({});

async function downloadReport(kind, name) {
    reportExporting.value = { ...reportExporting.value, [kind]: true };
    reportExportError.value = { ...reportExportError.value, [kind]: '' };
    try {
        const p = periodParams.value;
        const response = await api.get(`/admin/reports/${kind}-export`, { params: p, responseType: 'blob' });
        saveBlob(response.data, `${name}-${p.group}-${p.from}-sd-${p.to}.xlsx`);
    } catch (e) {
        const message = e.response?.data instanceof Blob ? 'Gagal mengunduh. Coba lagi sebentar lagi.' : apiError(e).message;
        reportExportError.value = { ...reportExportError.value, [kind]: message };
    } finally {
        reportExporting.value = { ...reportExporting.value, [kind]: false };
    }
}

onMounted(load);

const card = 'rounded-card border border-border bg-surface p-6 shadow-card';
const th = 'px-3 py-2 text-left text-label-md font-medium text-secondary';
const td = 'px-3 py-2 text-body-sm';
// Kolom Periode tetap terlihat saat tabel rekap digulir ke samping di layar sempit.
const stickyCol = 'sticky left-0 z-[1] bg-surface';
</script>

<template>
  <PageHeader title="Laporan" description="Penjualan, voucher, kampanye, dan konten. Data pribadi pelanggan tidak ditampilkan." />

  <div v-if="loading" class="mt-space-md h-96 animate-pulse-soft rounded-card bg-secondary-tint-12" role="status" aria-label="Memuat laporan"></div>

  <div v-else-if="error" role="alert" :class="`${card} mt-space-md`">
    <h2 class="font-heading text-headline-sm">Laporan belum bisa dimuat</h2>
    <p class="mt-1 text-secondary">{{ error }}</p>
    <button type="button" class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover" @click="load()">Coba lagi</button>
  </div>

  <div v-else class="mt-space-md grid grid-cols-1 gap-4">
    <section :class="card" aria-labelledby="period-title">
      <h2 id="period-title" class="font-heading text-headline-sm">Periode laporan</h2>
      <p class="mb-3 text-body-sm text-secondary">Berlaku untuk penjualan, pendapatan, rekap retur, pesanan dibatalkan, dan produk terlaris, termasuk berkas unduhannya. Waktu Jakarta; paling panjang 366 hari, per hari 92 hari.</p>
      <form class="grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap" @submit.prevent="applyFilter">
        <label class="text-label-md text-secondary">Dari<input v-model="filter.from" type="date" required :max="filter.to || undefined" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 text-body-sm sm:w-auto" /></label>
        <label class="text-label-md text-secondary">Sampai<input v-model="filter.to" type="date" required :min="filter.from || undefined" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 text-body-sm sm:w-auto" /></label>
        <label class="text-label-md text-secondary">Kelompok
          <select v-model="filter.group" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 pr-8 text-body-sm sm:w-auto">
            <option v-for="g in GROUPS" :key="g.value" :value="g.value">{{ g.label }}</option>
          </select>
        </label>
        <button type="submit" class="inline-flex h-10 cursor-pointer items-center justify-center rounded-full bg-primary px-5 text-label-md text-background transition-[transform,opacity] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60" :disabled="reloading">{{ reloading ? 'Memuat...' : 'Terapkan' }}</button>
      </form>
      <p v-if="filterError" role="alert" class="mt-3 text-body-sm text-error">{{ filterError }}</p>
      <p v-else class="mt-3 text-body-sm" aria-live="polite">Menampilkan <span class="font-semibold">{{ periodText }}</span>.</p>
    </section>

    <section :class="card" aria-labelledby="sales-title">
      <h2 id="sales-title" class="mb-4 font-heading text-headline-sm">{{ data.sales.chart.title }}</h2>
      <BarChart :chart="data.sales.chart" />
    </section>

    <section v-if="data.sales.revenue_chart" :class="card" aria-labelledby="revenue-title">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 id="revenue-title" class="font-heading text-headline-sm">{{ data.sales.revenue_chart.title }}</h2>
        <DownloadButton :loading="reportExporting.revenue" :hint="`${data.sales.revenue_chart.labels.length} ${period.name}, format xlsx`" @click="downloadReport('revenue', 'pendapatan')" />
      </div>
      <p v-if="reportExportError.revenue" role="alert" class="-mt-2 mb-3 text-body-sm text-error">{{ reportExportError.revenue }}</p>
      <BarChart :chart="data.sales.revenue_chart" />
    </section>

    <section v-if="auth.can('reports.export')" :class="card" aria-labelledby="export-title">
      <h2 id="export-title" class="font-heading text-headline-sm">Ekspor penjualan untuk finance</h2>
      <p class="mb-3 text-body-sm text-secondary">Format template impor tagihan penjualan (xlsx). Hanya pesanan yang dibayar dan tidak dibatalkan; berisi nama, alamat, dan telepon pelanggan, jadi simpan dengan hati-hati. Pengunduhan dicatat di log audit.</p>
      <div class="grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap">
        <label class="text-label-md text-secondary">Dari<input v-model="exportRange.from" type="date" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 text-body-sm sm:w-auto" @change="exportInfo = null" /></label>
        <label class="text-label-md text-secondary">Sampai<input v-model="exportRange.to" type="date" class="mt-1 block h-10 w-full rounded-full border border-border bg-surface px-3 text-body-sm sm:w-auto" @change="exportInfo = null" /></label>
        <button type="button" class="inline-flex h-10 items-center justify-center rounded-full border border-border px-5 text-label-md hover:bg-secondary-tint-12" @click="previewExport">Periksa dulu</button>
        <DownloadButton :loading="exporting" :disabled="!exportInfo || !exportInfo.rows" :hint="exportInfo ? `${exportInfo.rows} baris, format xlsx` : ''" @click="downloadExport" />
      </div>
      <p v-if="exportInfo" class="mt-3 text-body-sm">{{ exportInfo.orders }} pesanan, {{ exportInfo.rows }} baris.<span v-if="exportInfo.no_fee" class="text-error"> {{ exportInfo.no_fee }} pesanan tanpa tarif biaya transaksi (metode bayar tidak ada di daftar tarif); kolom biayanya dikosongkan.</span></p>
      <p v-if="exportError" role="alert" class="mt-3 text-body-sm text-error">{{ exportError }}</p>
    </section>

    <section v-if="data.returns" :class="card" aria-labelledby="returns-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="returns-title" class="font-heading text-headline-sm">Rekap retur</h2>
        <DownloadButton :loading="reportExporting.returns" :hint="`${data.returns.length} ${period.name}, format xlsx`" @click="downloadReport('returns', 'rekap-retur')" />
      </div>
      <p v-if="reportExportError.returns" role="alert" class="mt-2 text-body-sm text-error">{{ reportExportError.returns }}</p>
      <p class="mb-3 text-body-sm text-secondary">Pengajuan dihitung di periode diajukan; dana dihitung di periode dikembalikan (sama dengan Dana dikembalikan di atas). Retur = produk utuh, klaim = barang bermasalah.</p>
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead><tr><th scope="col" :class="`${th} ${stickyCol}`">Periode</th><th scope="col" :class="`${th} text-right`">Pengajuan</th><th scope="col" :class="`${th} text-right`">Retur</th><th scope="col" :class="`${th} text-right`">Klaim</th><th scope="col" :class="`${th} text-right`">Ditolak</th><th scope="col" :class="`${th} text-right`">Kirim ulang</th><th scope="col" :class="`${th} text-right`">Voucher</th><th scope="col" :class="`${th} text-right`">Refund</th><th scope="col" :class="`${th} text-right`">Dana retur</th><th scope="col" :class="`${th} text-right`">Dana klaim</th><th scope="col" :class="`${th} text-right`">Total dana</th></tr></thead>
          <tbody>
            <tr v-for="row in data.returns" :key="row.label" class="border-t border-border">
              <th scope="row" :class="`${td} ${stickyCol} whitespace-nowrap text-left font-medium`">{{ row.label }}</th>
              <td :class="`${td} text-right tabular-nums`">{{ row.submitted }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.returns }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.claims }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.rejected }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.resend }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.voucher }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.refunded_count }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ rupiah.format(row.refunded_return) }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ rupiah.format(row.refunded_claim) }}</td>
              <td :class="`${td} text-right font-medium tabular-nums`">{{ rupiah.format(row.refunded_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="data.cancellations" :class="card" aria-labelledby="cancel-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="cancel-title" class="font-heading text-headline-sm">Pesanan dibatalkan</h2>
        <DownloadButton :loading="reportExporting.cancellations" :hint="`${data.cancellations.rows.length} ${period.name}, format xlsx`" @click="downloadReport('cancellations', 'pesanan-dibatalkan')" />
      </div>
      <p v-if="reportExportError.cancellations" role="alert" class="mt-2 text-body-sm text-error">{{ reportExportError.cancellations }}</p>
      <p class="mb-3 text-body-sm text-secondary">Data tersendiri, dihitung di periode pembatalan. Jumlah menunggu dana di bawah tidak terikat periode. Pesanan batal tidak masuk Pendapatan kotor maupun bersih, dan dananya tidak dihitung di Dana dikembalikan di atas. {{ data.cancellations.pending.count }} pesanan ({{ rupiah.format(data.cancellations.pending.value) }}) menunggu dana dikembalikan.</p>
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead><tr><th scope="col" :class="`${th} ${stickyCol}`">Periode</th><th scope="col" :class="`${th} text-right`">Dibatalkan</th><th scope="col" :class="`${th} text-right`">Belum dibayar</th><th scope="col" :class="`${th} text-right`">Sudah dibayar</th><th scope="col" :class="`${th} text-right`">Nilai terbayar</th><th scope="col" :class="`${th} text-right`">Dana dikembalikan</th></tr></thead>
          <tbody>
            <tr v-for="row in data.cancellations.rows" :key="row.label" class="border-t border-border">
              <th scope="row" :class="`${td} ${stickyCol} whitespace-nowrap text-left font-medium`">{{ row.label }}</th>
              <td :class="`${td} text-right tabular-nums`">{{ row.cancelled }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.unpaid }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.paid }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ rupiah.format(row.paid_value) }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ rupiah.format(row.refunded) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <section :class="card" aria-labelledby="top-title">
        <h2 id="top-title" class="font-heading text-headline-sm">Produk terlaris</h2>
        <p class="mb-3 text-body-sm text-secondary">{{ periodText }}{{ data.money ? ', berdasarkan pendapatan' : ', berdasarkan jumlah' }}</p>
        <p v-if="!data.top_products.length" class="text-body-sm text-secondary">Belum ada penjualan di periode ini.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead><tr><th scope="col" :class="th">Produk</th><th scope="col" :class="`${th} text-right`">Terjual</th><th v-if="data.money" scope="col" :class="`${th} text-right`">Pendapatan</th></tr></thead>
            <tbody>
              <tr v-for="row in data.top_products" :key="row.name" class="border-t border-border">
                <td :class="td">{{ row.name }}</td>
                <td :class="`${td} text-right tabular-nums`">{{ row.qty }}</td>
                <td v-if="data.money" :class="`${td} text-right tabular-nums`">{{ rupiah.format(row.revenue) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section :class="card" aria-labelledby="content-title">
        <h2 id="content-title" class="font-heading text-headline-sm">Konten promo</h2>
        <p class="mb-3 text-body-sm text-secondary">Tayang dan draf menurut jenis</p>
        <p v-if="!data.contents.length" class="text-body-sm text-secondary">Belum ada konten.</p>
        <table v-else class="w-full">
          <thead><tr><th scope="col" :class="th">Jenis</th><th scope="col" :class="`${th} text-right`">Tayang</th><th scope="col" :class="`${th} text-right`">Draf</th></tr></thead>
          <tbody>
            <tr v-for="row in data.contents" :key="row.type" class="border-t border-border">
              <td :class="td">{{ CONTENT[row.type] || row.type }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.published }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.draft }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>

    <section :class="card" aria-labelledby="voucher-title">
      <h2 id="voucher-title" class="font-heading text-headline-sm">Voucher</h2>
      <p class="mb-3 text-body-sm text-secondary">{{ data.vouchers.active }} aktif, {{ data.vouchers.ending_soon }} berakhir dalam 7 hari. Urut dari yang paling banyak dipakai.</p>
      <p v-if="!data.vouchers.rows.length" class="text-body-sm text-secondary">Belum ada voucher.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <thead><tr><th scope="col" :class="th">Kode</th><th scope="col" :class="th">Potongan</th><th scope="col" :class="`${th} text-right`">Terpakai</th><th scope="col" :class="th">Status</th><th scope="col" :class="th">Berakhir</th></tr></thead>
          <tbody>
            <tr v-for="row in data.vouchers.rows" :key="row.code" class="border-t border-border">
              <td :class="`${td} font-medium`">{{ row.code }}</td>
              <td :class="td">{{ row.value }}</td>
              <td :class="`${td} text-right tabular-nums`">{{ row.used }}{{ row.quota ? ` / ${row.quota}` : '' }}</td>
              <td :class="td">{{ STATUS[row.status] }}</td>
              <td :class="td">{{ row.ends_at ? formatDate(row.ends_at) : 'Tanpa batas' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section :class="card" aria-labelledby="campaign-title">
      <h2 id="campaign-title" class="mb-3 font-heading text-headline-sm">Kampanye</h2>
      <p v-if="!data.campaigns.length" class="text-body-sm text-secondary">Belum ada kampanye.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <thead><tr><th scope="col" :class="th">Kampanye</th><th scope="col" :class="th">Periode</th><th scope="col" :class="th">Status</th></tr></thead>
          <tbody>
            <tr v-for="row in data.campaigns" :key="row.title + row.starts_at" class="border-t border-border">
              <td :class="td">{{ row.title }}<span v-if="row.is_flash" class="ml-2 rounded-full bg-error-surface px-2 py-0.5 text-label-md text-on-error-surface">Flash</span></td>
              <td :class="td">{{ formatDate(row.starts_at) }} sampai {{ formatDate(row.ends_at) }}</td>
              <td :class="td">{{ STATUS[row.status] }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
