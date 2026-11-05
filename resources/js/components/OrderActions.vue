<script setup>
import { ref, watch } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { useAuthStore } from '../stores/auth';

// Tombol status pesanan. Izin sebenarnya dijaga server; ini hanya menyembunyikan yang tidak relevan.
const props = defineProps({ order: { type: Object, required: true } });
const emit = defineEmits(['updated']);

const auth = useAuthStore();
const waybill = ref(props.order.waybill_id || '');
const busy = ref(false);
const error = ref('');

watch(() => props.order.waybill_id, (value) => { waybill.value = value || ''; });

async function run(path, body = {}) {
    busy.value = true;
    error.value = '';
    try {
        await api.post(`/admin/orders/${props.order.id}/${path}`, body);
        emit('updated');
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.waybill_id?.[0] || fields.status?.[0] || message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
  <div class="space-y-space-sm">
    <button v-if="order.status === 'paid' && auth.can('orders.update_status')" type="button" :disabled="busy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" @click="run('process')">Mulai Proses</button>

    <form v-if="['processing', 'shipped'].includes(order.status) && auth.can('shipments.manage')" class="flex flex-wrap items-end gap-space-sm" @submit.prevent="run('ship', { waybill_id: waybill })">
      <label class="text-label-md">
        Nomor resi
        <input v-model="waybill" required maxlength="64" class="mt-1 block w-52 rounded border border-secondary px-3 py-2 font-mono text-body-md focus:outline-2 focus:outline-primary">
      </label>
      <button :disabled="busy" class="rounded bg-accent px-4 py-2 text-label-md hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ order.status === 'shipped' ? 'Koreksi Resi' : 'Kirim Pesanan' }}</button>
    </form>

    <button v-if="order.status === 'shipped' && auth.can('orders.update_status')" type="button" :disabled="busy" class="rounded border border-secondary px-4 py-2 text-label-md hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60" @click="run('complete')">Tandai Selesai</button>

    <p v-if="error" role="alert" class="text-body-sm text-error">{{ error }}</p>
  </div>
</template>
