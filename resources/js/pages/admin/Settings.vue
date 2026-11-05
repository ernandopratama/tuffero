<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

const GROUPS = { contact: 'Kontak', reviews: 'Ulasan toko', checkout: 'Checkout', antifraud: 'Anti-penyalahgunaan', refund: 'Refund', cancellation: 'Pembatalan' };
const FIELDS = {
    'contact.whatsapp_number': { label: 'Nomor WhatsApp', hint: 'Format internasional, contoh +6281234567890. Tampil ke pelanggan.', input: 'tel' },
    'contact.admin_email': { label: 'Email admin', hint: 'Tampil ke pelanggan.', input: 'email' },
    'reviews.rating': { label: 'Rating toko (1 sampai 5)', hint: 'Contoh 4.6. Tampil di halaman produk bersama ulasan; samakan dengan sumbernya, mis. Google Maps.', input: 'number' },
    'reviews.count': { label: 'Jumlah ulasan toko', hint: 'Tampil bersama rating.', input: 'number' },
    'antifraud.max_unpaid_orders': { label: 'Maksimal pesanan belum dibayar per nomor HP', hint: 'Pesanan belum dibayar menahan stok. Nomor yang mencapai batas harus membayar atau menunggu pesanan kedaluwarsa.', input: 'number' },
    'antifraud.max_orders_per_day': { label: 'Maksimal pesanan per nomor HP dalam 24 jam', hint: 'Dihitung bergulir 24 jam terakhir.', input: 'number' },
    'checkout.reservation_minutes': { label: 'Batas bayar dan tahan stok (menit)', hint: 'Berlaku untuk pesanan baru. 60 menit = 1 jam.', input: 'number' },
    'refund.claim_window_hours': { label: 'Batas klaim refund (jam)', hint: 'Dihitung sejak barang diterima. 168 jam = 7 hari.', input: 'number' },
    'refund.review_sla_hours': { label: 'Batas tinjauan refund (jam)', hint: 'Boleh kosong sampai kebijakan refund disetujui.', input: 'number' },
    'refund.payout_days_max': { label: 'Batas proses refund (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
    'refund.terms_version': { label: 'Versi syarat refund', hint: 'Boleh kosong sampai teks syarat disetujui.', input: 'text' },
    'cancellation.process_days_max': { label: 'Batas proses pembatalan (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
};

const settings = ref([]);
const loading = ref(true);
const loadError = ref('');
const rows = reactive({});

const groups = computed(() => Object.entries(GROUPS).map(([key, label]) => ({
    key,
    label,
    items: settings.value.filter((setting) => setting.group === key),
})).filter((group) => group.items.length > 0));

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/settings');
        settings.value = data.data.settings;
        settings.value.forEach((setting) => {
            rows[setting.key] = { value: setting.value ?? '', saved: setting.value ?? '', busy: false, error: '', success: false };
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
  <h1 class="font-heading text-headline-lg-mobile lg:text-headline-lg">Pengaturan</h1>
  <p class="mt-space-sm text-secondary">Setiap perubahan tercatat di audit log. Admin Toko hanya melihat dan mengubah kontak.</p>

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat pengaturan...</p>
  <div v-else-if="loadError" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ loadError }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <template v-else>
    <section v-for="group in groups" :key="group.key" class="mt-space-lg rounded-lg border border-border bg-surface p-space-lg">
      <h2 class="font-heading text-headline-sm">{{ group.label }}</h2>
      <form
        v-for="setting in group.items"
        :key="setting.key"
        class="mt-space-md border-t border-border pt-space-md first-of-type:border-t-0 first-of-type:pt-0"
        @submit.prevent="save(setting.key)"
      >
        <label :for="setting.key" class="block text-label-md">{{ FIELDS[setting.key].label }}</label>
        <div class="mt-1 flex flex-col gap-space-sm sm:flex-row">
          <input
            :id="setting.key"
            v-model="rows[setting.key].value"
            :type="FIELDS[setting.key].input"
            step="any"
            :aria-invalid="!!rows[setting.key].error"
            :aria-describedby="`${setting.key}-hint`"
            class="w-full rounded border border-secondary bg-surface px-3 py-2 focus:outline-2 focus:outline-primary sm:max-w-md"
            @input="rows[setting.key].success = false"
          >
          <button
            :disabled="rows[setting.key].busy || String(rows[setting.key].value) === String(rows[setting.key].saved)"
            class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
          >
            {{ rows[setting.key].busy ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
        <p :id="`${setting.key}-hint`" class="mt-1 text-body-sm text-secondary">{{ FIELDS[setting.key].hint }}</p>
        <p v-if="rows[setting.key].error" role="alert" class="mt-1 text-body-sm text-error">{{ rows[setting.key].error }}</p>
        <p v-else-if="rows[setting.key].success" role="status" class="mt-1 text-body-sm text-success">Tersimpan.</p>
      </form>
    </section>
  </template>
</template>
