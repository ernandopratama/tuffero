<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import PageHeader from '../../components/ui/PageHeader.vue';

const GROUPS = {
    contact: { label: 'Kontak', icon: 'chat', text: 'Kontak toko yang tampil ke pelanggan.' },
    reviews: { label: 'Ulasan toko', icon: 'sparkles', text: 'Rating dan jumlah ulasan di halaman produk.' },
    discount: { label: 'Diskon', icon: 'tag', text: 'Batas potongan harga.' },
    inventory: { label: 'Stok', icon: 'warehouse', text: 'Peringatan stok menipis.' },
    checkout: { label: 'Checkout', icon: 'wallet', text: 'Batas waktu bayar dan tahan stok.' },
    antifraud: { label: 'Anti-penyalahgunaan', icon: 'shield', text: 'Pembatasan pesanan per nomor HP.' },
    refund: { label: 'Refund', icon: 'undo', text: 'Batas waktu klaim dan proses refund.' },
    cancellation: { label: 'Pembatalan', icon: 'x', text: 'Batas waktu proses pembatalan.' },
};
const FIELDS = {
    'contact.whatsapp_number': { label: 'Nomor WhatsApp', hint: 'Format internasional, contoh +6281234567890. Tampil ke pelanggan.', input: 'tel' },
    'contact.admin_email': { label: 'Email admin', hint: 'Tampil ke pelanggan.', input: 'email' },
    'reviews.rating': { label: 'Rating toko (1 sampai 5)', hint: 'Contoh 4.6. Tampil di halaman produk bersama ulasan; samakan dengan sumbernya, mis. Google Maps.', input: 'number' },
    'reviews.count': { label: 'Jumlah ulasan toko', hint: 'Tampil bersama rating.', input: 'number' },
    'antifraud.max_unpaid_orders': { label: 'Maksimal pesanan belum dibayar per nomor HP', hint: 'Pesanan belum dibayar menahan stok. Nomor yang mencapai batas harus membayar atau menunggu pesanan kedaluwarsa.', input: 'number' },
    'antifraud.max_orders_per_day': { label: 'Maksimal pesanan per nomor HP dalam 24 jam', hint: 'Dihitung bergulir 24 jam terakhir.', input: 'number' },
    'discount.max_percent': { label: 'Batas total diskon produk (% dari harga jual)', hint: 'Diskon produk yang berlaku bersamaan ditumpuk, tetapi totalnya tidak boleh melebihi batas ini. Contoh 50: harga Rp100.000 paling murah Rp50.000.', input: 'number' },
    'inventory.low_stock_threshold': { label: 'Batas peringatan stok menipis', hint: 'Admin Toko dan Gudang diberi tahu sekali saat stok tersedia sebuah varian turun ke di bawah angka ini. Bawaan 10, sama dengan label menipis di Ringkasan.', input: 'number' },
    'checkout.reservation_minutes': { label: 'Batas bayar dan tahan stok (menit)', hint: 'Berlaku untuk pesanan baru. 60 menit = 1 jam.', input: 'number' },
    'refund.claim_window_hours': { label: 'Batas klaim refund (jam)', hint: 'Dihitung sejak barang diterima. 168 jam = 7 hari.', input: 'number' },
    'refund.review_sla_hours': { label: 'Batas tinjauan refund (jam)', hint: 'Boleh kosong sampai kebijakan refund disetujui.', input: 'number' },
    'refund.payout_days_max': { label: 'Batas proses refund (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
    'refund.terms_version': { label: 'Versi syarat refund', hint: 'Boleh kosong sampai teks syarat disetujui.', input: 'text' },
    'cancellation.process_days_max': { label: 'Batas proses pembatalan (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
};

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

const settings = ref([]);
const loading = ref(true);
const loadError = ref('');
const rows = reactive({});

const groups = computed(() => Object.entries(GROUPS).map(([key, meta]) => ({
    key,
    ...meta,
    items: settings.value.filter((setting) => setting.group === key && FIELDS[setting.key]),
})).filter((group) => group.items.length > 0));

const isDirty = (key) => String(rows[key].value) !== String(rows[key].saved);

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/settings');
        settings.value = data.data.settings;
        settings.value.forEach((setting) => {
            rows[setting.key] = { value: setting.value ?? '', saved: setting.value ?? '', busy: false, error: '', success: false, updatedAt: setting.updated_at };
        });
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function save(key) {
    const row = rows[key];
    row.busy = true;
    row.error = '';
    row.success = false;
    try {
        const value = row.value === '' ? null : row.value;
        const { data } = await api.patch(`/admin/settings/${encodeURIComponent(key)}`, { value });
        row.saved = data.data.value ?? '';
        row.value = row.saved;
        row.updatedAt = data.data.updated_at ?? new Date().toISOString();
        row.success = true;
    } catch (e) {
        const { message, fields } = apiError(e);
        row.error = fields.value?.[0] || message;
    } finally {
        row.busy = false;
    }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Pengaturan" description="Setiap perubahan tercatat di audit log. Admin Toko hanya melihat dan mengubah kontak." />

  <!-- memuat: kerangka seukuran isi supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat pengaturan" class="mt-space-lg space-y-space-md">
    <div v-for="n in 3" :key="n" class="h-52 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <div v-else class="mt-space-lg">
    <div class="space-y-space-lg">
      <section v-for="group in groups" :key="group.key" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" :aria-labelledby="`title-${group.key}`">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon :name="group.icon" :size="18" /></span>
          <div><h2 :id="`title-${group.key}`" class="font-heading text-headline-sm">{{ group.label }}</h2><p class="text-body-sm text-secondary">{{ group.text }}</p></div>
        </div>

        <form
          v-for="setting in group.items"
          :key="setting.key"
          class="mt-space-md grid gap-space-sm border-t border-border pt-space-md sm:grid-cols-[1fr_minmax(0,20rem)] sm:gap-space-lg"
          @submit.prevent="save(setting.key)"
        >
          <div class="min-w-0">
            <label :for="setting.key" class="block text-label-md">{{ FIELDS[setting.key].label }}</label>
            <p :id="`${setting.key}-hint`" class="mt-1 text-body-sm text-secondary">{{ FIELDS[setting.key].hint }}</p>
            <p v-if="rows[setting.key].updatedAt" class="mt-1 text-label-sm text-secondary">Terakhir diubah {{ formatDate(rows[setting.key].updatedAt) }}</p>
          </div>
          <div class="min-w-0">
            <div class="flex gap-2">
              <input
                :id="setting.key"
                v-model="rows[setting.key].value"
                :type="FIELDS[setting.key].input"
                step="any"
                :aria-invalid="!!rows[setting.key].error"
                :aria-describedby="`${setting.key}-hint`"
                class="block min-w-0 flex-1 rounded-2xl border bg-surface px-3 py-2 text-body-md transition-colors duration-150 focus:outline-2 focus:outline-primary"
                :class="rows[setting.key].error ? 'border-error' : isDirty(setting.key) ? 'border-primary' : 'border-secondary'"
                @input="rows[setting.key].success = false"
              >
              <button
                :disabled="rows[setting.key].busy || !isDirty(setting.key)"
                :class="`inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover disabled:bg-secondary-tint-12 disabled:text-secondary ${press}`"
              >
                <AdminIcon name="check" :size="16" />{{ rows[setting.key].busy ? 'Menyimpan...' : 'Simpan' }}
              </button>
            </div>
            <p v-if="rows[setting.key].error" role="alert" class="mt-1.5 flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ rows[setting.key].error }}</p>
            <p v-else-if="isDirty(setting.key)" class="mt-1.5 flex items-center gap-1.5 text-body-sm text-warning"><i class="size-1.5 rounded-full bg-warning" aria-hidden="true"></i>Belum disimpan</p>
            <p v-else-if="rows[setting.key].success" role="status" class="mt-1.5 flex items-center gap-1 text-body-sm text-success"><AdminIcon name="check" :size="14" />Tersimpan.</p>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
