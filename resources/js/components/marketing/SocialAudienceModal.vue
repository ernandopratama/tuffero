<script setup>
import { nextTick, reactive, ref } from 'vue';
import FaIcon from '../FaIcon.vue';
import AppModal from '../ui/AppModal.vue';
import FormField from '../ui/FormField.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { PLATFORMS, PLATFORM_ICON } from '../../utils/contentMetrics';
import { AUDIENCE_PRESETS, AUDIENCE_TITLES, jakartaMonth } from '../../utils/socialMetrics';

// Demografi followers satu bulan (D-151 butir 5): usia, gender, dan lokasi dalam persen, disalin dari insight akun.
// Menyimpan platform dan bulan yang sama menggantikan snapshot lama. Baris tanpa persen dibuang.
const emit = defineEmits(['saved']);

const modal = ref(null);
const busy = ref(false);
const error = ref('');
const fieldErrors = ref({});
const form = reactive({ platform: 'instagram', month: jakartaMonth(), is_test: false, age: [], gender: [], locations: [] });

const rows = (labels) => labels.map((label) => ({ label, percent: '' }));
const field = 'block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary';

function open(snapshot = null, platform = 'instagram') {
    error.value = '';
    fieldErrors.value = {};
    Object.assign(form, { platform: snapshot ? snapshot.platform : platform, month: snapshot ? snapshot.month.slice(0, 7) : jakartaMonth(), is_test: snapshot?.is_test ?? false });
    for (const group of Object.keys(AUDIENCE_PRESETS)) {
        form[group] = snapshot?.data?.[group]?.length ? snapshot.data[group].map((row) => ({ label: row.label, percent: row.percent })) : rows(AUDIENCE_PRESETS[group]);
    }
    nextTick(() => modal.value.showModal());
}

const addRow = (group) => { if (form[group].length < 12) form[group].push({ label: '', percent: '' }); };
const removeRow = (group, index) => form[group].splice(index, 1);

async function save() {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    const body = { platform: form.platform, month: form.month, is_test: form.is_test };
    for (const group of Object.keys(AUDIENCE_PRESETS)) {
        body[group] = form[group].filter((row) => row.percent !== '' && row.percent !== null).map((row) => ({ label: row.label, percent: Number(String(row.percent).replace(',', '.')) }));
    }
    try {
        await api.put('/admin/social-audience', body, { toast: 'Demografi disimpan.' });
        modal.value.close();
        emit('saved');
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = message;
        fieldErrors.value = fields;
        // Pesan galat ada di atas formulir; gulirkan ke sana supaya terlihat walau modal sedang di bagian bawah.
        nextTick(() => document.getElementById('social-audience-form')?.querySelector('[role=alert]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    } finally {
        busy.value = false;
    }
}

defineExpose({ open });
</script>

<template>
  <AppModal ref="modal" variant="create" size="2xl" title="Catat demografi followers" subtitle="Satu catatan per platform dan bulan. Isi persen dari insight, kosongkan baris yang tidak ada.">
    <form id="social-audience-form" class="space-y-space-lg" @submit.prevent="save">
      <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>

      <div class="grid gap-3 sm:grid-cols-2">
        <div role="radiogroup" aria-label="Platform" class="grid grid-cols-2 gap-2 sm:col-span-2">
          <label
            v-for="(label, key) in PLATFORMS"
            :key="key"
            class="relative flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 rounded-2xl border px-4 py-2 text-label-md transition-[background-color,border-color,transform] duration-[160ms] ease-out-strong active:scale-[0.97]"
            :class="form.platform === key ? 'border-primary bg-accent-tint-15' : 'border-border bg-surface hover:border-secondary'"
          >
            <input v-model="form.platform" type="radio" name="audience-platform" :value="key" class="peer sr-only">
            <FaIcon :name="PLATFORM_ICON[key]" :size="16" />{{ label }}
            <span class="pointer-events-none absolute -inset-0.5 rounded-[inherit] ring-primary peer-focus-visible:ring-2" aria-hidden="true"></span>
          </label>
        </div>
        <FormField label="Bulan" :error="fieldErrors.month">
          <input v-model="form.month" type="month" required :max="jakartaMonth()" class="mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
        </FormField>
        <label class="flex min-h-11 cursor-pointer items-center gap-3 self-end text-label-md">
          <input v-model="form.is_test" type="checkbox" class="size-5 accent-primary">Data tes
        </label>
      </div>

      <section v-for="(title, group) in AUDIENCE_TITLES" :key="group" class="space-y-2">
        <h3 class="font-heading text-body-lg">{{ title }}</h3>
        <p v-if="fieldErrors[group]" role="alert" class="text-body-sm text-error">{{ Array.isArray(fieldErrors[group]) ? fieldErrors[group][0] : fieldErrors[group] }}</p>
        <ul class="space-y-2">
          <li v-for="(row, index) in form[group]" :key="`${group}-${index}`" class="flex items-center gap-2">
            <input v-model="row.label" type="text" maxlength="60" :aria-label="`${title}, nama baris ${index + 1}`" :placeholder="group === 'locations' ? 'mis. Jakarta' : 'Nama'" :class="field">
            <span class="relative w-28 shrink-0">
              <input v-model="row.percent" type="text" inputmode="decimal" :aria-label="`${title}, persen baris ${index + 1}`" placeholder="0" :class="`${field} pr-8 text-right`">
              <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center text-body-sm text-secondary" aria-hidden="true">%</span>
            </span>
            <button type="button" class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-error-surface hover:text-error focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" :aria-label="`Hapus baris ${index + 1} ${title}`" @click="removeRow(group, index)"><FaIcon name="xmark" :size="14" /></button>
          </li>
        </ul>
        <button v-if="form[group].length < 12" type="button" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full px-3 text-label-md text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="addRow(group)"><FaIcon name="plus" :size="12" />Tambah baris</button>
      </section>
    </form>

    <template #footer>
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center rounded-full border border-secondary px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]" @click="modal.close()">Batal</button>
      <button type="submit" form="social-audience-form" :disabled="busy" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full bg-accent px-5 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60">
        <FaIcon name="check" :size="14" />{{ busy ? 'Menyimpan...' : 'Simpan demografi' }}
      </button>
    </template>
  </AppModal>
</template>
