<script setup>
import { onMounted, ref } from 'vue';
import BarChart from '../../components/dashboard/BarChart.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';

// Laporan (reports.view_summary, reports.view). Rupiah hanya dikirim server bila akun memegang reports.view.
const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const STATUS = { live: 'Berjalan', upcoming: 'Akan datang', ended: 'Berakhir', inactive: 'Nonaktif' };
const CONTENT = { banner: 'Banner', text: 'Teks promo', video: 'Video' };

const data = ref(null);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data: body } = await api.get('/admin/reports');
        data.value = body.data;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const card = 'rounded-card border border-border bg-surface p-6 shadow-card';
const th = 'px-3 py-2 text-left text-label-md font-medium text-secondary';
const td = 'px-3 py-2 text-body-sm';
</script>

<template>
  <PageHeader title="Laporan" description="Penjualan, voucher, kampanye, dan konten. Data pribadi pelanggan tidak ditampilkan." />

  <div v-if="loading" class="mt-space-md h-96 animate-pulse-soft rounded-card bg-secondary-tint-12" role="status" aria-label="Memuat laporan"></div>

  <div v-else-if="error" role="alert" :class="`${card} mt-space-md`">
    <h2 class="font-heading text-headline-sm">Laporan belum bisa dimuat</h2>
    <p class="mt-1 text-secondary">{{ error }}</p>
    <button type="button" class="mt-4 inline-flex h-10 items-center rounded-full bg-accent px-5 text-label-md hover:bg-accent-hover" @click="load">Coba lagi</button>
  </div>

  <div v-else class="mt-space-md grid grid-cols-1 gap-4">
    <section :class="card" aria-labelledby="sales-title">
      <h2 id="sales-title" class="mb-4 font-heading text-headline-sm">{{ data.sales.chart.title }}</h2>
      <BarChart :chart="data.sales.chart" />
    </section>

    <section v-if="data.sales.revenue_chart" :class="card" aria-labelledby="revenue-title">
      <h2 id="revenue-title" class="mb-4 font-heading text-headline-sm">{{ data.sales.revenue_chart.title }}</h2>
      <BarChart :chart="data.sales.revenue_chart" />
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <section :class="card" aria-labelledby="top-title">
        <h2 id="top-title" class="font-heading text-headline-sm">Produk terlaris</h2>
        <p class="mb-3 text-body-sm text-secondary">30 hari terakhir{{ data.money ? ', berdasarkan pendapatan' : ', berdasarkan jumlah' }}</p>
        <p v-if="!data.top_products.length" class="text-body-sm text-secondary">Belum ada penjualan dalam 30 hari terakhir.</p>
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
