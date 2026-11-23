<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import PressLoader from '../../components/ui/PressLoader.vue';
import StarLoader from '../../components/ui/StarLoader.vue';
import TrashButton from '../../components/ui/TrashButton.vue';
import { toast } from '../../composables/useToast';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Generator Caption: tim marketing menulis isi video atau prompt, AI membalas tiga variasi caption untuk disalin.
// Bukan draf: tidak diajukan atau disetujui, dan tidak ada yang diposting otomatis (BR-19). Izin dijaga server.
const route = useRoute();
const dateTime = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

const PLATFORMS = { instagram: 'Instagram', tiktok: 'TikTok' };
const TONES = { santai: 'Santai', hangat: 'Hangat', elegan: 'Elegan', ceria: 'Ceria' };
const MAX_BRIEF = 2000;

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block min-h-11 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-60';
const btn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const btnPrimary = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`;

const form = reactive({ platform: 'instagram', tone: '', brief: '' });
const busy = ref(false);
const error = ref('');
const fieldErrors = ref({});
const aiEnabled = ref(true);
const result = ref(null);
const history = ref([]);
const loadError = ref('');
const prefillNote = ref('');

const briefLeft = computed(() => MAX_BRIEF - form.brief.length);

async function load() {
    try {
        const { data } = await api.get('/admin/caption-generations');
        aiEnabled.value = data.data.ai_enabled;
        history.value = data.data.items;
    } catch (e) {
        loadError.value = apiError(e).message;
    }
}

// ?draf=<id>: "Buat caption" dari kartu rencana video; isi video diambil dari judul dan naskahnya.
async function prefill() {
    const id = String(route.query.draf || '');
    if (!id) return;
    try {
        const { data } = await api.get('/admin/marketing-drafts');
        const draft = data.data.items.find((row) => row.id === id);
        if (!draft) return;
        form.brief = `${draft.title}\n${draft.body}`.slice(0, MAX_BRIEF);
        if (draft.platform) form.platform = draft.platform;
        prefillNote.value = `Diisi dari rencana video "${draft.title}". Sunting bila perlu.`;
    } catch {
        // Gagal memuat draf tidak menghalangi mengetik sendiri.
    }
}

async function generate() {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        const { data } = await api.post('/admin/caption-generations', { platform: form.platform, tone: form.tone || null, brief: form.brief }, { toast: false, timeout: 120000 });
        result.value = data.data;
        history.value = [data.data, ...history.value].slice(0, 30);
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = message;
        fieldErrors.value = fields;
    } finally {
        busy.value = false;
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Caption disalin.');
    } catch {
        toast.error('Tidak bisa menyalin otomatis. Blok teksnya lalu salin manual.');
    }
}

// Bersihkan: hasil, isi video atau prompt, dan pesan di formulir dikosongkan. Platform dan gaya bahasa dibiarkan, karena biasanya sama untuk caption berikutnya.
function clearAll() {
    result.value = null;
    form.brief = '';
    error.value = '';
    fieldErrors.value = {};
    prefillNote.value = '';
}

function reuse(item) {
    Object.assign(form, { platform: item.platform, tone: item.tone || '', brief: item.brief });
    result.value = item;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function remove(item) {
    try {
        await api.delete(`/admin/caption-generations/${item.id}`, { toast: 'Riwayat dihapus.' });
        history.value = history.value.filter((row) => row.id !== item.id);
        if (result.value?.id === item.id) result.value = null;
    } catch (e) {
        error.value = apiError(e).message;
    }
}

onMounted(() => {
    load();
    prefill();
});
</script>

<template>
  <PageHeader title="Generator Caption" description="Tulis isi video atau prompt, AI membuat tiga variasi caption. Salin yang cocok lalu posting manual. Tidak perlu persetujuan." />

  <p v-if="!aiEnabled" role="alert" class="mt-space-md rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">Pembuat AI belum aktif. Hubungi Super Admin.</p>
  <p v-if="loadError" role="alert" class="mt-space-md rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ loadError }}</p>

  <div class="mt-space-lg grid items-start gap-space-lg lg:grid-cols-2">
    <form class="space-y-space-md rounded-3xl border border-border bg-surface p-space-md shadow-card" @submit.prevent="generate">
      <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>
      <p v-if="prefillNote" class="flex items-start gap-2 rounded-2xl bg-background/70 p-3 text-body-sm text-secondary"><AdminIcon name="info" :size="16" class="mt-0.5 shrink-0" />{{ prefillNote }}</p>

      <div class="grid gap-space-md sm:grid-cols-2">
        <label class="block text-label-md">
          Platform
          <select v-model="form.platform" :class="field"><option v-for="(label, key) in PLATFORMS" :key="key" :value="key">{{ label }}</option></select>
          <span v-if="fieldErrors.platform" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.platform[0] }}</span>
        </label>
        <label class="block text-label-md">
          Gaya bahasa <span class="font-normal text-secondary">(opsional)</span>
          <select v-model="form.tone" :class="field"><option value="">Bawaan brand</option><option v-for="(label, key) in TONES" :key="key" :value="key">{{ label }}</option></select>
        </label>
      </div>

      <label class="block text-label-md">
        Isi video atau prompt
        <textarea v-model="form.brief" rows="8" required :maxlength="MAX_BRIEF" placeholder="Contoh: Video unboxing hampers Natal berisi Nastar dan Macaron, suasana hangat bersama keluarga. Ajak orang pesan lewat website." :class="`${field} resize-y`"></textarea>
        <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ briefLeft }} karakter tersisa</span>
        <span v-if="fieldErrors.brief" class="block text-body-sm font-normal text-error">{{ fieldErrors.brief[0] }}</span>
      </label>

      <p class="text-body-sm text-secondary">AI hanya menyebut produk yang tayang di toko, tanpa harga, promo, atau klaim kesehatan. Tulis [HARGA] atau [PROMO] di caption bila perlu lalu isi sendiri.</p>

      <div class="flex flex-wrap items-center gap-2">
        <!-- Saat memproses hanya animasi yang tampil; teks tetap ada untuk pembaca layar. Lebar minimum menjaga tombol tidak menyusut. -->
        <button type="submit" :disabled="busy || !aiEnabled" :aria-busy="busy" :class="`${btnPrimary} min-w-40 justify-center`">
          <StarLoader v-if="busy" :size="22" />
          <AdminIcon v-else name="sparkles" :size="16" />
          <span :class="busy ? 'sr-only' : ''">{{ busy ? 'Membuat caption...' : 'Buat caption' }}</span>
        </button>
        <button v-if="form.brief || result || error" type="button" :disabled="busy" :class="btn" @click="clearAll"><AdminIcon name="x" :size="16" />Bersihkan</button>
      </div>
    </form>

    <section aria-labelledby="hasil" class="space-y-space-md">
      <h2 id="hasil" class="flex items-center gap-2 font-heading text-headline-sm"><span class="size-3 rounded-full bg-success" aria-hidden="true"></span>Hasil</h2>
      <PressLoader v-if="busy" label="Membuat caption" :size="10" :height="380" />
      <p v-else-if="!result" class="rounded-3xl border border-dashed border-border p-space-md text-center text-body-sm text-secondary">Tiga variasi caption muncul di sini.</p>
      <ol v-else class="space-y-3">
        <li v-for="(caption, index) in result.captions" :key="index" class="rounded-3xl border border-l-4 border-success/30 border-l-[#2E6B3E] bg-[#E6F4EA] p-space-md shadow-card">
          <p class="inline-flex rounded-full bg-success px-2.5 py-0.5 text-label-sm uppercase tracking-wide text-surface">Variasi {{ index + 1 }}</p>
          <p class="mt-1 whitespace-pre-line break-words text-body-md">{{ caption }}</p>
          <div class="mt-3 flex items-center justify-between gap-2">
            <span class="text-body-sm tabular-nums text-secondary">{{ caption.length }} karakter</span>
            <button type="button" :class="btn" @click="copy(caption)"><AdminIcon name="copy" :size="16" />Salin</button>
          </div>
        </li>
      </ol>
    </section>
  </div>

  <section v-if="history.length" aria-labelledby="riwayat" class="mt-space-lg">
    <h2 id="riwayat" class="flex items-center gap-2 font-heading text-headline-sm"><span class="size-3 rounded-full bg-[#4338CA]" aria-hidden="true"></span>Riwayat</h2>
    <ul class="mt-space-md grid gap-space-md md:grid-cols-2">
      <li v-for="item in history" :key="item.id" class="flex min-w-0 flex-col gap-2 rounded-3xl border border-l-4 border-[#4338CA]/30 border-l-[#4338CA] bg-[#ECEEFB] p-space-md shadow-card">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="item.platform === 'tiktok' ? 'bg-[#111827]' : 'bg-[#C13584]'">{{ PLATFORMS[item.platform] }}</span>
          <span v-if="item.tone" class="rounded-full bg-[#4338CA] px-2.5 py-1 text-label-sm text-surface">{{ TONES[item.tone] }}</span>
          <span class="text-body-sm text-secondary">{{ item.author || 'Akun dihapus' }} · {{ dateTime.format(new Date(item.created_at)) }}</span>
        </div>
        <p class="line-clamp-3 break-words text-body-sm">{{ item.brief }}</p>
        <div class="mt-auto flex items-center justify-between gap-2 pt-1">
          <button type="button" :class="btn" @click="reuse(item)">Lihat hasil</button>
          <TrashButton subject="riwayat caption" icon-only @click="remove(item)" />
        </div>
      </li>
    </ul>
  </section>
</template>
