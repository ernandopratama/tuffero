<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import AppModal from '../../components/ui/AppModal.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { ORDER_STATUS, ORDER_STATUS_TONE, TONE_CLASS, rupiah } from '../../utils/orderLabels';
import { useAuthStore } from '../../stores/auth';

const route = useRoute();
const auth = useAuthStore();
const customer = ref(null);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const actionError = ref('');
const confirmDialog = ref(null);

const date = (value) => new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });

const press = 'transition-[transform,background-color,color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

// Ringkasan dari data yang sudah dikirim API: jumlah pesanan seluruhnya, pesanan terakhir, dan alamat tersimpan.
const stats = computed(() => customer.value && [
    { icon: 'receipt', label: 'Total pesanan', value: customer.value.orders_count },
    { icon: 'clock', label: 'Pesanan terakhir', value: customer.value.orders[0] ? date(customer.value.orders[0].created_at) : 'Belum ada' },
    { icon: 'pin', label: 'Alamat tersimpan', value: customer.value.addresses.length },
]);

const contacts = computed(() => customer.value && [
    { icon: 'mail', label: 'Email', value: customer.value.email, verified: customer.value.email_verified },
    { icon: 'chat', label: 'WhatsApp', value: customer.value.phone, verified: customer.value.phone_verified },
]);

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get(`/admin/customers/${route.params.id}`);
        customer.value = data.data;
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function setActive(next) {
    saving.value = true;
    actionError.value = '';
    try {
        const { data } = await api.patch(`/admin/customers/${customer.value.id}`, { is_active: next }, { toast: next ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.' });
        customer.value = { ...customer.value, ...data.data };
        confirmDialog.value?.close();
    } catch (e) {
        actionError.value = apiError(e).message;
    } finally {
        saving.value = false;
    }
}

// Menonaktifkan langsung mengeluarkan pelanggan, jadi minta konfirmasi dulu. Mengaktifkan tidak perlu.
function toggleActive() {
    if (customer.value.is_active) confirmDialog.value.showModal();
    else setActive(true);
}

onMounted(load);
</script>

<template>
  <RouterLink :to="{ name: 'AdminCustomers' }" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-border bg-surface px-4 text-label-md hover:bg-accent-tint-15 ${press}`">
    <AdminIcon name="chevron-down" :size="16" class="rotate-90" />Kembali ke Pelanggan
  </RouterLink>

  <!-- memuat: kerangka seukuran isi supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat pelanggan" class="mt-space-lg space-y-space-md">
    <div class="h-14 w-2/3 animate-pulse-soft rounded-2xl bg-secondary-tint-12"></div>
    <div class="grid gap-space-md sm:grid-cols-3">
      <div v-for="n in 3" :key="n" class="h-24 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
    </div>
    <div class="h-64 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <template v-else>
    <div class="mt-space-md flex flex-wrap items-end justify-between gap-space-md">
      <PageHeader :title="customer.name" :description="`Terdaftar sejak ${date(customer.created_at)}`" icon="user" group="Pelanggan" />
      <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-label-sm" :class="customer.is_active ? 'bg-success-surface text-success' : 'bg-error-surface text-on-error-surface'">
          <i class="size-1.5 rounded-full" :class="customer.is_active ? 'bg-success' : 'bg-error'" aria-hidden="true"></i>{{ customer.is_active ? 'Akun aktif' : 'Akun nonaktif' }}
        </span>
        <button
          v-if="auth.can('customers.deactivate')"
          type="button"
          :disabled="saving"
          :class="[`inline-flex min-h-10 items-center gap-1.5 rounded-full px-4 text-label-md ${press}`, customer.is_active ? 'border border-error text-error hover:bg-error-surface' : 'bg-accent hover:bg-accent-hover']"
          @click="toggleActive"
        >
          <AdminIcon :name="customer.is_active ? 'x' : 'check'" :size="16" />{{ customer.is_active ? 'Nonaktifkan akun' : 'Aktifkan akun' }}
        </button>
      </div>
    </div>

    <p v-if="actionError" role="alert" class="mt-space-md rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ actionError }}</p>

    <ul class="mt-space-lg grid gap-space-md sm:grid-cols-3">
      <li v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-3xl border border-border bg-surface p-space-md shadow-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon :name="stat.icon" /></span>
        <span class="min-w-0">
          <span class="block text-body-sm text-secondary">{{ stat.label }}</span>
          <span class="block truncate font-heading text-headline-sm tabular-nums">{{ stat.value }}</span>
        </span>
      </li>
    </ul>

    <div class="mt-space-lg grid items-start gap-space-lg lg:grid-cols-3">
      <div class="space-y-space-lg">
        <section class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="cd-contact">
          <h2 id="cd-contact" class="font-heading text-headline-sm">Kontak</h2>
          <dl class="mt-space-md space-y-3">
            <div v-for="item in contacts" :key="item.label" class="flex items-start gap-3">
              <span class="grid size-9 shrink-0 place-items-center rounded-full bg-secondary-tint-12 text-secondary" aria-hidden="true"><AdminIcon :name="item.icon" :size="18" /></span>
              <div class="min-w-0">
                <dt class="text-body-sm text-secondary">{{ item.label }}</dt>
                <dd class="break-all text-label-md">{{ item.value || 'Belum diisi' }}</dd>
                <dd v-if="item.value" class="mt-0.5 inline-flex items-center gap-1 text-label-sm" :class="item.verified ? 'text-success' : 'text-warning'">
                  <AdminIcon :name="item.verified ? 'check' : 'alert'" :size="14" />{{ item.verified ? 'Terverifikasi' : 'Belum terverifikasi' }}
                </dd>
              </div>
            </div>
          </dl>
          <p class="mt-space-md flex items-start gap-2 rounded-2xl bg-background/70 p-3 text-body-sm text-secondary">
            <AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />Email dan WhatsApp pelanggan tidak bisa diubah dari sini.
          </p>
        </section>

        <section class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="cd-address">
          <h2 id="cd-address" class="font-heading text-headline-sm">Alamat tersimpan</h2>
          <p v-if="!customer.addresses.length" class="mt-space-sm text-body-sm text-secondary">Belum ada alamat.</p>
          <ul v-else class="mt-space-md space-y-3 text-body-sm">
            <li v-for="address in customer.addresses" :key="`${address.label}-${address.street}`" class="rounded-2xl border p-space-md" :class="address.is_default ? 'border-accent bg-accent-tint-12' : 'border-border'">
              <p class="flex flex-wrap items-center gap-2 text-label-md">
                <AdminIcon name="pin" :size="16" class="text-secondary" />{{ address.label }}
                <span v-if="address.is_default" class="rounded-full bg-accent px-2 py-0.5 text-label-sm">Utama</span>
              </p>
              <p class="mt-1">{{ address.recipient_name }} <span class="font-mono text-secondary">{{ address.phone }}</span></p>
              <p class="break-words text-secondary">{{ address.street }}, {{ address.district }}, {{ address.city }}, {{ address.province }} {{ address.postal_code }}</p>
            </li>
          </ul>
        </section>
      </div>

      <section class="overflow-hidden rounded-3xl border border-border bg-surface shadow-card lg:col-span-2" aria-labelledby="cd-orders">
        <div class="flex flex-wrap items-baseline justify-between gap-2 px-space-lg pt-space-lg">
          <h2 id="cd-orders" class="font-heading text-headline-sm">Pesanan terbaru</h2>
          <p class="text-body-sm text-secondary">10 pesanan terakhir. Rincian ada di menu Pesanan.</p>
        </div>
        <p v-if="!customer.orders.length" class="px-space-lg py-12 text-center text-body-sm text-secondary">Belum ada pesanan dari akun ini.</p>
        <div v-else class="mt-space-md overflow-x-auto">
          <table class="w-full min-w-[520px] text-center text-body-sm">
            <caption class="sr-only">Pesanan terbaru {{ customer.name }}</caption>
            <thead class="bg-background/70 text-label-sm uppercase tracking-wide text-secondary">
              <tr>
                <th scope="col" class="px-4 py-3">Nomor</th>
                <th scope="col" class="px-4 py-3">Tanggal</th>
                <th scope="col" class="px-4 py-3">Status</th>
                <th scope="col" class="px-4 py-3">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="order in customer.orders" :key="order.order_number" class="transition-colors duration-150 hover:bg-accent-tint-12">
                <td class="whitespace-nowrap px-4 py-3 font-mono">{{ order.order_number }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-secondary">{{ date(order.created_at) }}</td>
                <td class="px-4 py-3"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-label-sm" :class="TONE_CLASS[ORDER_STATUS_TONE[order.status] || 'neutral']">{{ ORDER_STATUS[order.status] || order.status }}</span></td>
                <td class="whitespace-nowrap px-4 py-3 text-label-md tabular-nums">{{ rupiah(order.total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <AppModal ref="confirmDialog" variant="delete" size="md" title="Nonaktifkan akun?" :subtitle="customer.name">
      <p class="text-body-sm">Pelanggan langsung keluar dan tidak bisa masuk sampai akunnya diaktifkan lagi. Perubahan ini tercatat di audit log.</p>
      <p v-if="actionError" role="alert" class="mt-3 text-body-sm text-error">{{ actionError }}</p>
      <template #footer>
        <button type="button" :disabled="saving" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="confirmDialog.close()">Batal</button>
        <button type="button" :disabled="saving" :class="`inline-flex min-h-10 items-center rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="setActive(false)">{{ saving ? 'Menonaktifkan...' : 'Nonaktifkan' }}</button>
      </template>
    </AppModal>
  </template>
</template>
