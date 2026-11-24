<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import FaIcon from '../FaIcon.vue';
import AppModal from '../ui/AppModal.vue';
import FormField from '../ui/FormField.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { PLATFORMS, PLATFORM_ICON } from '../../utils/contentMetrics';
import { GROUPS, PERIODS, TIME_UNITS, fieldsOf, jakartaDay } from '../../utils/socialMetrics';

// Catat atau ubah satu catatan performa akun (D-151): pilih platform dan periode, lalu salin angka dari layar insight.
// Kolom yang tidak ada di platform terpilih disembunyikan dan dikirim kosong. Kosong berarti tidak tersedia, bukan 0.
const emit = defineEmits(['saved']);

const modal = ref(null);
const editing = ref(null);
const busy = ref(false);
const error = ref('');
const fieldErrors = ref({});

const ALL = [...fieldsOf('instagram'), ...fieldsOf('tiktok')].map((field) => field.key);
const blank = (platform = 'instagram', period = 1) => ({
    platform, period_days: period, period_end: jakartaDay(1), measured_on: jakartaDay(0), is_test: false, note: '',
    ...Object.fromEntries([...new Set(ALL)].map((key) => [key, ''])),
});
const form = reactive(blank());
// Total watch time disalin dalam jam, menit, atau detik; server menyimpan detik.
const watchUnit = ref(3600);
const watchValue = ref('');

const groups = computed(() => GROUPS[form.platform] ?? []);
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';

function open(record = null, defaults = {}) {
    editing.value = record;
    error.value = '';
    fieldErrors.value = {};
    Object.assign(form, blank(defaults.platform, defaults.period));
    watchValue.value = '';
    watchUnit.value = 3600;
    if (record) {
        for (const key of Object.keys(form)) form[key] = record[key] ?? (typeof form[key] === 'boolean' ? false : '');
        if (record.watch_time_seconds !== null && record.watch_time_seconds !== undefined) {
            watchUnit.value = record.watch_time_seconds >= 3600 ? 3600 : record.watch_time_seconds >= 60 ? 60 : 1;
            watchValue.value = String(Number((record.watch_time_seconds / watchUnit.value).toFixed(2)));
        }
    }
    nextTick(() => modal.value.showModal());
}

// Periode 1 hari: hari terakhir periode dan tanggal ukur biasanya kemarin dan hari ini; mengganti platform tidak mengubah tanggal.
watch(() => form.platform, () => { fieldErrors.value = {}; });

async function save() {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    const mine = new Set(fieldsOf(form.platform).map((item) => item.key));
    const body = { platform: form.platform, period_days: form.period_days, period_end: form.period_end, measured_on: form.measured_on, is_test: form.is_test, note: form.note || null };
    for (const key of ALL) {
        if (!mine.has(key) || key === 'watch_time_seconds') continue;
        body[key] = form[key] === '' ? null : form[key];
    }
    if (mine.has('watch_time_seconds')) {
        body.watch_time_seconds = watchValue.value === '' ? null : Math.round(Number(String(watchValue.value).replace(',', '.')) * watchUnit.value);
    }
    try {
        if (editing.value) await api.patch(`/admin/social-metrics/${editing.value.id}`, body, { toast: 'Catatan akun diubah.' });
        else await api.post('/admin/social-metrics', body, { toast: 'Catatan akun disimpan.' });
        modal.value.close();
        emit('saved');
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = message;
        fieldErrors.value = { ...fields, ...(fields.watch_time_seconds ? { watch: fields.watch_time_seconds } : {}) };
        // Pesan galat ada di atas formulir; gulirkan ke sana supaya terlihat walau modal sedang di bagian bawah.
        nextTick(() => document.getElementById('social-record-form')?.querySelector('[role=alert]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    } finally {
        busy.value = false;
    }
}

defineExpose({ open });
</script>

<template>
  <AppModal ref="modal" :variant="editing ? 'update' : 'create'" size="3xl" :title="editing ? 'Ubah catatan akun' : 'Catat performa akun'" :subtitle="editing ? `${PLATFORMS[editing.platform]}, periode sampai ${editing.period_end}` : 'Salin angka dari insight akun, satu platform sekali catat.'">
    <form id="social-record-form" class="space-y-space-lg" @submit.prevent="save">
      <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>

      <fieldset class="grid gap-3 sm:grid-cols-2">
        <legend class="sr-only">Platform dan periode</legend>
        <div role="radiogroup" aria-label="Platform" class="grid grid-cols-2 gap-2 sm:col-span-2">
          <label
            v-for="(label, key) in PLATFORMS"
            :key="key"
            class="relative flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 rounded-2xl border px-4 py-2 text-label-md transition-[background-color,border-color,transform] duration-[160ms] ease-out-strong active:scale-[0.97]"
            :class="[form.platform === key ? 'border-primary bg-accent-tint-15' : 'border-border bg-surface hover:border-secondary', editing ? 'cursor-not-allowed opacity-60' : '']"
          >
            <input v-model="form.platform" type="radio" name="platform" :value="key" :disabled="Boolean(editing)" class="peer sr-only">
            <FaIcon :name="PLATFORM_ICON[key]" :size="16" />{{ label }}
            <span class="pointer-events-none absolute -inset-0.5 rounded-[inherit] ring-primary peer-focus-visible:ring-2" aria-hidden="true"></span>
          </label>
        </div>

        <FormField label="Periode" :error="fieldErrors.period_days" hint="Rentang angka yang Anda salin.">
          <select v-model.number="form.period_days" :class="field">
            <option v-for="period in PERIODS" :key="period.value" :value="period.value">{{ period.value === 1 ? '1 hari' : `${period.value} hari terakhir` }}</option>
          </select>
        </FormField>
        <FormField label="Hari terakhir periode" :error="fieldErrors.period_end" hint="Hari terakhir yang ikut dihitung insight.">
          <input v-model="form.period_end" type="date" required :max="jakartaDay(0)" :class="field">
        </FormField>
        <FormField label="Tanggal ukur" :error="fieldErrors.measured_on" hint="Kapan angka ini disalin." class="sm:col-span-2">
          <input v-model="form.measured_on" type="date" required :max="jakartaDay(0)" :class="field">
        </FormField>
      </fieldset>

      <section v-for="group in groups" :key="group.title" class="space-y-3">
        <h3 class="font-heading text-body-lg">{{ group.title }}</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <template v-for="item in group.fields" :key="item.key">
            <FormField v-if="item.unit === 'time'" :label="item.label" :error="fieldErrors.watch || fieldErrors[item.key]" optional hint="Kosongkan bila tidak tersedia.">
              <span class="mt-1 flex gap-2">
                <input v-model="watchValue" type="text" inputmode="decimal" placeholder="mis. 12,5" class="block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
                <select v-model.number="watchUnit" class="rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary" aria-label="Satuan waktu tonton">
                  <option v-for="unit in TIME_UNITS" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                </select>
              </span>
            </FormField>
            <FormField v-else :label="item.label" :error="fieldErrors[item.key]" :optional="!item.required" :hint="item.hint">
              <input v-model.number="form[item.key]" type="number" inputmode="numeric" min="0" step="1" :required="item.required" :class="field">
            </FormField>
          </template>
        </div>
      </section>

      <section class="space-y-3 rounded-2xl border border-border bg-background/60 p-4">
        <FormField label="Catatan" :error="fieldErrors.note" optional hint="Mis. ada kampanye atau posting viral pada hari itu.">
          <input v-model="form.note" type="text" maxlength="255" :class="field">
        </FormField>
        <label class="flex min-h-11 cursor-pointer items-start gap-3 text-label-md">
          <input v-model="form.is_test" type="checkbox" class="mt-1 size-5 accent-primary">
          <span>
            Tandai sebagai data tes
            <span class="block text-body-sm font-normal text-secondary">Tidak ikut ringkasan dan rangkuman. Bisa dihapus sekaligus nanti.</span>
          </span>
        </label>
      </section>
    </form>

    <template #footer>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center rounded-full border border-secondary px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="modal.close()">Batal</button>
      <button type="submit" form="social-record-form" :disabled="busy" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-accent px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60">
        <FaIcon name="check" :size="14" />{{ busy ? 'Menyimpan...' : (editing ? 'Simpan perubahan' : 'Simpan catatan') }}
      </button>
    </template>
  </AppModal>
</template>
