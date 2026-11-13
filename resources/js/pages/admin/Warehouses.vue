<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
// Semua peran boleh melihat; hanya Super Admin, Admin Toko, dan Gudang yang boleh mengubah. Izin sebenarnya dijaga di server.
const canManage = computed(() => auth.can('warehouses.manage'));

const warehouses = ref([]);
const loading = ref(true);
const error = ref('');

const dialog = ref(null);
const target = ref(null);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const form = reactive({ name: '', city: '', address: '', postal_code: '', latitude: '', longitude: '', phone: '', is_active: true });

async function load() {
    loading.value = true;
    error.value = '';
    try {
        warehouses.value = (await api.get('/admin/warehouses')).data.data.items;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

function open(warehouse) {
    target.value = warehouse;
    Object.assign(form, {
        name: warehouse.name,
        city: warehouse.city,
        address: warehouse.address ?? '',
        postal_code: warehouse.postal_code ?? '',
        latitude: warehouse.latitude ?? '',
        longitude: warehouse.longitude ?? '',
        phone: warehouse.phone ?? '',
        is_active: warehouse.is_active,
    });
    formError.value = '';
    fieldErrors.value = {};
    dialog.value.showModal();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    // Kolom kosong dikirim null supaya data lama terhapus, bukan tertinggal.
    const blank = (value) => (value === '' || value === null ? null : value);
    const body = {
        name: form.name,
        city: form.city,
        address: blank(form.address),
        postal_code: blank(form.postal_code),
        latitude: blank(form.latitude),
        longitude: blank(form.longitude),
        phone: blank(form.phone),
        is_active: form.is_active,
    };
    try {
        await api.patch(`/admin/warehouses/${target.value.id}`, body);
        dialog.value.close();
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = fields.is_active?.[0] || message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

const input = 'mt-1 w-full rounded border border-secondary px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

onMounted(load);
</script>

<template>
  <PageHeader title="Gudang" description="Alamat, kode pos, dan HP gudang dipakai Biteship untuk membuat pengiriman; bila ada yang kosong, pengiriman tidak bisa dibuat. Kode gudang tidak bisa diubah. Gudang tidak dihapus, hanya dinonaktifkan. Hanya Super Admin, Admin Toko, dan Gudang yang bisa mengubah." />

  <p v-if="loading" role="status" class="mt-space-lg text-secondary">Memuat gudang...</p>
  <div v-else-if="error" role="alert" class="mt-space-lg rounded bg-error-surface p-space-md text-on-error-surface">
    {{ error }}
    <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
  </div>
  <p v-else-if="!warehouses.length" class="mt-space-lg text-secondary">Belum ada gudang.</p>
  <ul v-else class="mt-space-lg space-y-space-md">
    <li v-for="warehouse in warehouses" :key="warehouse.id" class="rounded-3xl border border-border bg-surface p-5 shadow-card">
      <div class="flex flex-wrap items-start justify-between gap-space-sm">
        <div class="min-w-0">
          <h2 class="font-heading text-headline-sm">{{ warehouse.name }}</h2>
          <p class="text-body-sm text-secondary">{{ warehouse.code }} · {{ warehouse.city }}</p>
        </div>
        <div class="flex items-center gap-space-sm">
          <span class="rounded-full px-2.5 py-1 text-label-sm" :class="warehouse.is_active ? 'bg-success-surface text-success' : 'bg-secondary-tint-12'">{{ warehouse.is_active ? 'Aktif' : 'Nonaktif' }}</span>
          <IconAction v-if="canManage" icon="edit" label="Ubah" :subject="warehouse.name" @click="open(warehouse)" />
        </div>
      </div>
      <dl class="mt-space-md grid gap-x-space-lg gap-y-space-sm text-body-md sm:grid-cols-2">
        <div><dt class="text-label-sm text-secondary">Alamat</dt><dd>{{ warehouse.address || '-' }}</dd></div>
        <div><dt class="text-label-sm text-secondary">Kode pos</dt><dd>{{ warehouse.postal_code || '-' }}</dd></div>
        <div><dt class="text-label-sm text-secondary">HP</dt><dd>{{ warehouse.phone || '-' }}</dd></div>
        <div><dt class="text-label-sm text-secondary">Koordinat</dt><dd>{{ warehouse.latitude && warehouse.longitude ? `${warehouse.latitude}, ${warehouse.longitude}` : '-' }}</dd></div>
      </dl>
      <p v-if="warehouse.is_active && !warehouse.shipping_ready" role="status" class="mt-space-md rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-on-warning-surface">
        Alamat, kode pos, atau HP belum lengkap. Pengiriman lewat Biteship belum bisa dibuat dari gudang ini.
      </p>
    </li>
  </ul>

  <AppModal ref="dialog" variant="update" title="Ubah gudang" :subtitle="target ? `${target.code}` : ''">
    <form v-if="target" id="warehouse-form" class="space-y-space-md" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded bg-error-surface px-3 py-2 text-body-sm text-on-error-surface">{{ formError }}</p>
      <label class="block text-label-md">
        Nama
        <input v-model="form.name" required maxlength="100" :class="input">
        <span v-if="fieldErrors.name" class="mt-1 block text-body-sm text-error">{{ fieldErrors.name[0] }}</span>
      </label>
      <label class="block text-label-md">
        Kota
        <input v-model="form.city" required maxlength="80" :class="input">
        <span v-if="fieldErrors.city" class="mt-1 block text-body-sm text-error">{{ fieldErrors.city[0] }}</span>
      </label>
      <label class="block text-label-md">
        Alamat lengkap
        <textarea v-model="form.address" rows="3" maxlength="500" :class="input"></textarea>
        <span v-if="fieldErrors.address" class="mt-1 block text-body-sm text-error">{{ fieldErrors.address[0] }}</span>
      </label>
      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Kode pos
          <input v-model="form.postal_code" inputmode="numeric" maxlength="5" :class="input">
          <span v-if="fieldErrors.postal_code" class="mt-1 block text-body-sm text-error">{{ fieldErrors.postal_code[0] }}</span>
        </label>
        <label class="block text-label-md">
          HP gudang
          <input v-model="form.phone" type="tel" maxlength="20" :class="input">
          <span v-if="fieldErrors.phone" class="mt-1 block text-body-sm text-error">{{ fieldErrors.phone[0] }}</span>
        </label>
        <label class="block text-label-md">
          Lintang (opsional)
          <input v-model="form.latitude" inputmode="decimal" :class="input">
          <span v-if="fieldErrors.latitude" class="mt-1 block text-body-sm text-error">{{ fieldErrors.latitude[0] }}</span>
        </label>
        <label class="block text-label-md">
          Bujur (opsional)
          <input v-model="form.longitude" inputmode="decimal" :class="input">
          <span v-if="fieldErrors.longitude" class="mt-1 block text-body-sm text-error">{{ fieldErrors.longitude[0] }}</span>
        </label>
      </div>
      <label class="flex items-center gap-space-sm text-label-md">
        <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary">
        Aktif (dipilih checkout untuk memenuhi pesanan)
      </label>
    </form>
    <template #footer>
      <button type="button" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary" @click="dialog.close()">Batal</button>
      <button form="warehouse-form" :disabled="saving" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </AppModal>
</template>
