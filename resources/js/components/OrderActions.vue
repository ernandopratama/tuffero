<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';

// Alur pengemasan: Proses Pesanan (pilih Biteship atau resi manual) -> Cetak Resi -> Tandai Dikirim -> Tandai Selesai.
// Izin sebenarnya dijaga server; di sini hanya menyembunyikan tombol yang tidak relevan.
const props = defineProps({
    order: { type: Object, required: true },
    showWaybill: { type: Boolean, default: true },
    // Dibuka dari tombol ikon (halaman Pengiriman): langsung tampilkan pilihan cara pengiriman.
    startOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const auth = useAuthStore();
const router = useRouter();

const open = ref(props.startOpen);
const method = ref('biteship');
const waybill = ref('');
const note = ref('');
const correcting = ref(false);
const busy = ref(false);
const error = ref('');

const METHODS = [
    { key: 'biteship', title: 'Biteship', badge: 'Disarankan', text: 'Resi dibuat otomatis. Kurir menjemput di gudang.' },
    { key: 'manual', title: 'Resi manual', text: 'Isi nomor resi sendiri, misalnya dari kurir langsung.' },
];

const shipment = computed(() => props.order.shipment || null);
const waybillId = computed(() => shipment.value?.waybill_id || props.order.waybill_id || '');
const canManage = computed(() => auth.can('shipments.manage'));
const canFinish = computed(() => auth.can('orders.update_status'));
const needsArrange = computed(() => ['paid', 'processing'].includes(props.order.status) && !shipment.value);
const confirmText = computed(() => (busy.value ? 'Memproses...' : method.value === 'biteship' ? 'Proses & buat resi Biteship' : 'Proses dengan resi manual'));

async function run(path, body = {}, then = () => {}) {
    busy.value = true;
    error.value = '';
    try {
        await api.post(`/admin/orders/${props.order.id}/${path}`, body);
        then();
        emit('updated');
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.waybill_id?.[0] || fields.note?.[0] || fields.method?.[0] || fields.status?.[0] || message;
    } finally {
        busy.value = false;
    }
}

const arrange = () => run('arrange', { method: method.value, ...(method.value === 'manual' ? { waybill_id: waybill.value.trim(), note: note.value.trim() } : {}) }, () => {
    open.value = false;
    waybill.value = '';
    note.value = '';
});
const correct = () => run('ship', { waybill_id: waybill.value.trim() }, () => { correcting.value = false; });

function startCorrection() {
    waybill.value = waybillId.value;
    correcting.value = true;
}

function printLabel() {
    window.open(`${router.resolve({ name: 'AdminOrderLabel', params: { id: props.order.id } }).href}?print=1`, '_blank', 'noopener');
}

const press = 'transition-[transform,background-color,opacity] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const primary = `flex min-h-10 items-center justify-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`;
const outline = `flex min-h-10 items-center justify-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
</script>

<template>
  <div class="space-y-space-sm">
    <!-- 1. Belum ada resi: Proses Pesanan -->
    <template v-if="needsArrange && canManage">
      <button v-if="!open" type="button" :class="primary" @click="open = true"><AdminIcon name="box" :size="16" />Proses Pesanan</button>

      <form v-else class="space-y-space-sm rounded-2xl border border-accent/50 bg-accent-tint-12 p-3 motion-safe:animate-pop" @submit.prevent="arrange">
        <fieldset :disabled="busy">
          <legend class="text-label-md">Pilih cara pengiriman</legend>
          <div class="mt-2 grid gap-2 sm:grid-cols-2" role="radiogroup">
            <label
              v-for="item in METHODS"
              :key="item.key"
              class="flex cursor-pointer gap-2.5 rounded-xl border bg-surface p-3 transition-[border-color,box-shadow] duration-[160ms] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary"
              :class="method === item.key ? 'border-primary shadow-sm' : 'border-border hover:border-secondary'"
            >
              <input v-model="method" type="radio" name="arrange-method" :value="item.key" class="mt-1 accent-[var(--color-primary)]">
              <span class="min-w-0">
                <span class="flex flex-wrap items-center gap-1.5 text-label-md">{{ item.title }}<span v-if="item.badge" class="rounded-full bg-success-surface px-2 py-0.5 text-label-sm text-success">{{ item.badge }}</span></span>
                <span class="block text-body-sm text-secondary">{{ item.text }}</span>
              </span>
            </label>
          </div>

          <!-- Resi manual: nomor resi dan catatan terbuka di bawah pilihan -->
          <div class="grid transition-[grid-template-rows] duration-200 ease-out-strong motion-reduce:transition-none" :class="method === 'manual' ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
            <div class="overflow-hidden" :inert="method !== 'manual'">
              <div class="grid gap-2 pt-space-sm sm:grid-cols-2">
                <label class="text-label-md">
                  Nomor resi
                  <input v-model="waybill" :required="method === 'manual'" maxlength="64" autocomplete="off" class="mt-1 block w-full rounded-lg border border-secondary bg-surface px-3 py-2 font-mono text-body-md focus:outline-2 focus:outline-primary">
                </label>
                <label class="text-label-md">
                  Catatan <span class="font-normal text-secondary">(opsional)</span>
                  <textarea v-model="note" rows="2" maxlength="300" class="mt-1 block w-full resize-none rounded-lg border border-secondary bg-surface px-3 py-2 text-body-md focus:outline-2 focus:outline-primary"></textarea>
                </label>
              </div>
            </div>
          </div>
        </fieldset>

        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :disabled="busy" :class="outline" @click="open = false">Batal</button>
          <button type="submit" :disabled="busy" :class="primary">{{ confirmText }}</button>
        </div>
      </form>
    </template>

    <button v-else-if="order.status === 'paid' && canFinish && !canManage" type="button" :disabled="busy" :class="primary" @click="run('process')">Mulai Proses</button>

    <!-- 2. Sudah ada resi: tampilkan pilihan, lalu Cetak Resi dan langkah berikutnya -->
    <template v-if="shipment">
      <div class="flex flex-wrap items-center gap-2 text-body-sm">
        <span class="rounded-full bg-secondary-tint-12 px-2.5 py-1 text-label-sm">{{ shipment.provider === 'biteship' ? 'Biteship' : 'Resi manual' }}</span>
        <span v-if="showWaybill && waybillId" class="font-mono text-label-md">{{ waybillId }}</span>
        <span v-if="shipment.note" class="text-secondary">Catatan: {{ shipment.note }}</span>
      </div>

      <div class="flex flex-wrap gap-2">
        <button v-if="canManage && waybillId" type="button" :class="outline" @click="printLabel"><AdminIcon name="printer" :size="16" />Cetak Resi</button>
        <button v-if="order.status === 'processing' && canManage" type="button" :disabled="busy" :class="primary" @click="run('mark-shipped')"><AdminIcon name="truck" :size="16" />Tandai Dikirim</button>
        <button v-if="order.status === 'shipped' && canFinish" type="button" :disabled="busy" :class="outline" @click="run('complete')">Tandai Selesai</button>
        <button v-if="order.status === 'shipped' && shipment.provider === 'manual' && canManage && !correcting" type="button" :class="outline" @click="startCorrection">Koreksi resi</button>
      </div>

      <form v-if="correcting" class="flex flex-wrap items-end gap-2" @submit.prevent="correct">
        <label class="text-label-md">
          Nomor resi
          <input v-model="waybill" required maxlength="64" class="mt-1 block w-52 rounded-lg border border-secondary px-3 py-2 font-mono text-body-md focus:outline-2 focus:outline-primary">
        </label>
        <button type="submit" :disabled="busy" :class="primary">Simpan resi</button>
        <button type="button" :disabled="busy" :class="outline" @click="correcting = false">Batal</button>
      </form>
    </template>

    <p v-if="error" role="alert" class="text-body-sm text-error">{{ error }}</p>
  </div>
</template>
