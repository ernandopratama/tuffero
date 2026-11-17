<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminIcon from '../AdminIcon.vue';
import AppModal from '../ui/AppModal.vue';
import api from '../../utils/api';
import { sourceLinks } from '../../utils/sourceLinks';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

// Kalender draf konten untuk Ringkasan Super Admin, Pemilik, dan Marketing. Membaca daftar draf yang sama dengan
// halaman Draf Konten; draf muncul pada tanggal jadwal posting. Ganti bulan hanya di browser, tanpa permintaan baru.
const auth = useAuthStore();
const canManage = computed(() => auth.can('marketing_drafts.manage'));

const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const TYPES = { video_script: 'Skrip video', caption: 'Caption', calendar_item: 'Kalender konten', trend_report: 'Riset tren' };
const TYPE_ICONS = { video_script: 'video', caption: 'document', calendar_item: 'clock', trend_report: 'chart' };
const PLATFORMS = { instagram: 'Instagram', tiktok: 'TikTok' };
const ORIGINS = { ai: 'Dibuat AI', hermes: 'Dari Hermes' };
const STATUS = {
    draft: { label: 'Draf', dot: 'bg-[#CDBAAB]', cellDot: 'bg-secondary', cls: 'bg-secondary-tint-12 text-secondary' },
    in_review: { label: 'Menunggu keputusan', dot: 'bg-[#F2C14E]', cellDot: 'bg-warning', cls: 'bg-warning-surface text-warning' },
    approved: { label: 'Disetujui', dot: 'bg-[#6FCF8A]', cellDot: 'bg-success', cls: 'bg-success-surface text-success' },
    rejected: { label: 'Ditolak', dot: 'bg-[#F28B82]', cellDot: 'bg-error', cls: 'bg-error-surface text-on-error-surface' },
    posted: { label: 'Sudah diposting', dot: 'bg-[#7DB8E0]', cellDot: 'bg-primary', cls: 'bg-accent-tint-15 text-primary' },
};

const monthFormat = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
const longDay = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const key = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const parse = (value) => new Date(`${value}T00:00:00`);

// Hari ini menurut WIB, bukan zona waktu perangkat.
const todayKey = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const today = parse(todayKey);

const items = ref([]);
const loading = ref(true);
const error = ref('');
const cursor = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const selected = ref(todayKey);
const direction = ref(1);
const dialog = ref(null);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/marketing-drafts');
        items.value = data.data.items;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

const byDay = computed(() => {
    const map = {};
    for (const row of items.value) {
        if (row.scheduled_on) (map[row.scheduled_on] ??= []).push(row);
    }
    return map;
});

const monthKey = computed(() => key(cursor.value).slice(0, 7));
const monthLabel = computed(() => monthFormat.format(cursor.value));

// Senin sebagai hari pertama; hanya baris yang dibutuhkan bulan itu (4 sampai 6).
const cells = computed(() => {
    const first = cursor.value;
    const lead = (first.getDay() + 6) % 7;
    const total = Math.ceil((lead + new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate()) / 7) * 7;

    return Array.from({ length: total }, (_, index) => {
        const date = new Date(first.getFullYear(), first.getMonth(), index - lead + 1);
        const id = key(date);

        return { id, day: date.getDate(), outside: date.getMonth() !== first.getMonth(), today: id === todayKey, items: byDay.value[id] ?? [] };
    });
});

const inMonth = computed(() => cells.value.filter((cell) => !cell.outside).reduce((sum, cell) => sum + cell.items.length, 0));
const unscheduled = computed(() => items.value.filter((row) => !row.scheduled_on && row.type !== 'trend_report' && row.status !== 'posted').length);
const agenda = computed(() => byDay.value[selected.value] ?? []);

function shift(step) {
    direction.value = step;
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + step, 1);
}

function goToday() {
    direction.value = key(today) < `${monthKey.value}-01` ? -1 : 1;
    cursor.value = new Date(today.getFullYear(), today.getMonth(), 1);
    selected.value = todayKey;
}

// Klik tanggal membuka modal berisi draf hari itu, supaya Ringkasan tidak memanjang.
function pick(cell) {
    selected.value = cell.id;
    if (cell.outside) {
        direction.value = cell.id < `${monthKey.value}-01` ? -1 : 1;
        cursor.value = new Date(parse(cell.id).getFullYear(), parse(cell.id).getMonth(), 1);
    }
    dialog.value?.showModal();
}

const label = (cell) => `${longDay.format(parse(cell.id))}, ${cell.items.length ? `${cell.items.length} draf` : 'tidak ada draf'}`;

const press = 'transition-[transform,background-color,border-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent active:scale-[0.97]';
const iconBtn = `grid size-11 shrink-0 place-items-center rounded-full border border-background/20 bg-background/10 hover:bg-background/20 sm:size-10 ${press}`;

onMounted(load);
</script>

<template>
  <section class="animate-rise rounded-card border border-primary bg-primary p-6 text-background shadow-card" style="animation-delay: 300ms" aria-labelledby="calendar-title">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 id="calendar-title" class="font-heading text-headline-sm">Kalender konten</h2>
        <p class="text-body-sm text-background/70">Draf muncul di tanggal jadwal posting.</p>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" :class="iconBtn" aria-label="Bulan sebelumnya" @click="shift(-1)">
          <svg class="size-5 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
        </button>
        <p class="min-w-28 text-center font-heading text-body-md capitalize sm:min-w-36" aria-live="polite">{{ monthLabel }}</p>
        <button type="button" :class="iconBtn" aria-label="Bulan berikutnya" @click="shift(1)">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
        </button>
        <button type="button" :class="`inline-flex h-11 shrink-0 items-center rounded-full bg-accent px-4 text-label-md text-primary hover:bg-accent-hover sm:h-10 ${press}`" @click="goToday">Hari ini</button>
      </div>
    </header>

    <div v-if="loading" class="h-80 animate-pulse-soft rounded-2xl bg-background/10" role="status" aria-label="Memuat kalender"></div>

    <div v-else-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">
      {{ error }}
      <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </div>

    <template v-else>
      <div class="grid grid-cols-7 gap-1 text-center text-label-sm text-background/60" aria-hidden="true">
        <span v-for="name in DAYS" :key="name" class="py-1">{{ name }}</span>
      </div>

      <Transition :name="direction > 0 ? 'cal-next' : 'cal-prev'" mode="out-in">
        <div :key="monthKey" class="grid grid-cols-7 gap-1">
          <button
            v-for="cell in cells"
            :key="cell.id"
            type="button"
            :aria-label="label(cell)"
            :aria-pressed="selected === cell.id"
            :class="[
              'flex min-h-14 flex-col items-stretch gap-1 rounded-xl border p-1.5 text-left text-primary sm:min-h-24 sm:p-2',
              press,
              selected === cell.id ? 'border-accent bg-[#E3D2AB] ring-1 ring-accent' : cell.outside ? 'border-accent/40 bg-[#F7F3EA] text-primary/50 hover:bg-[#F1EADB]' : 'border-accent bg-[#EFE9DE] hover:bg-[#E8DFCC]',
            ]"
            @click="pick(cell)"
          >
            <span :class="['grid size-6 place-items-center rounded-full text-label-sm tabular-nums', cell.today ? 'bg-accent font-medium text-primary' : '']">{{ cell.day }}</span>

            <!-- layar kecil: hanya titik status; layar lebar: judul singkat -->
            <span v-if="cell.items.length" class="flex flex-wrap gap-0.5 sm:hidden" aria-hidden="true">
              <span v-for="row in cell.items.slice(0, 4)" :key="row.id" :class="['size-1.5 rounded-full', STATUS[row.status].cellDot]"></span>
            </span>
            <span class="hidden min-w-0 flex-col gap-0.5 sm:flex" aria-hidden="true">
              <span v-for="row in cell.items.slice(0, 2)" :key="row.id" class="flex min-w-0 items-center gap-1 rounded-md bg-surface px-1.5 py-0.5 text-label-sm">
                <span :class="['size-1.5 shrink-0 rounded-full', STATUS[row.status].cellDot]"></span>
                <span class="truncate">{{ row.title }}</span>
              </span>
              <span v-if="cell.items.length > 2" class="px-1.5 text-label-sm text-primary/70">+{{ cell.items.length - 2 }} lagi</span>
            </span>
          </button>
        </div>
      </Transition>

      <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-label-sm text-background/70" aria-label="Arti warna titik">
        <li v-for="(meta, id) in STATUS" :key="id" class="flex items-center gap-1.5"><span :class="['size-2 rounded-full', meta.dot]" aria-hidden="true"></span>{{ meta.label }}</li>
      </ul>

      <p class="mt-3 text-body-sm text-background/70">
        {{ inMonth }} draf bulan ini<template v-if="unscheduled">
          · <router-link :to="{ name: 'AdminMarketingDrafts' }" class="underline underline-offset-2 hover:text-accent">{{ unscheduled }} draf belum dijadwalkan</router-link></template>
      </p>
    </template>

    <AppModal ref="dialog" variant="read" size="xl" :title="longDay.format(parse(selected))" :subtitle="agenda.length ? `${agenda.length} draf` : 'Tidak ada draf'">
      <ul v-if="agenda.length" class="space-y-3">
        <li v-for="row in agenda" :key="row.id">
          <!-- Satu draf: langsung terbuka. Beberapa draf: ketuk judulnya untuk membuka rinciannya. -->
          <details :open="agenda.length === 1" class="group rounded-2xl border border-border bg-background">
            <summary class="flex cursor-pointer list-none items-start gap-3 px-4 py-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="rounded-full px-2 py-0.5 text-label-sm" :class="STATUS[row.status].cls">{{ STATUS[row.status].label }}</span>
                  <span class="inline-flex items-center gap-1 rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm"><AdminIcon :name="TYPE_ICONS[row.type] || 'document'" :size="12" />{{ TYPES[row.type] }}</span>
                  <span v-if="row.platform" class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm">{{ PLATFORMS[row.platform] }}</span>
                  <span v-if="ORIGINS[row.origin]" class="inline-flex items-center gap-1 rounded-full bg-accent-tint-15 px-2 py-0.5 text-label-sm text-primary"><AdminIcon name="sparkles" :size="12" />{{ ORIGINS[row.origin] }}</span>
                </div>
                <p class="mt-1.5 break-words font-heading text-body-md">{{ row.title }}</p>
              </div>
              <svg class="mt-1 size-5 shrink-0 text-secondary transition-transform duration-[160ms] ease-out-strong group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
            </summary>

            <div class="space-y-3 border-t border-border px-4 py-3">
              <p class="flex items-center gap-1.5 text-body-sm text-secondary"><AdminIcon name="user" :size="16" class="shrink-0" />{{ row.origin === 'hermes' ? 'Dikirim Hermes (riset terjadwal)' : `Disusun ${row.creator || 'akun yang sudah dihapus'}` }}</p>
              <p v-if="row.review_note" class="flex gap-2 rounded-2xl bg-secondary-tint-12 px-3 py-2 text-body-sm"><AdminIcon name="info" :size="18" class="mt-0.5 shrink-0" /><span>Catatan {{ row.reviewer || 'peninjau' }}: {{ row.review_note }}</span></p>
              <p class="max-w-prose whitespace-pre-line break-words text-body-sm leading-relaxed">{{ row.body }}</p>
              <div v-if="row.sources || row.source_period" class="space-y-1.5">
                <h4 class="flex flex-wrap items-center gap-x-1.5 text-label-md"><AdminIcon name="document" :size="16" />Sumber<span v-if="row.source_period" class="font-normal text-secondary">· data {{ row.source_period }}</span></h4>
                <ul v-if="row.sources" class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-surface">
                  <li v-for="(source, index) in sourceLinks(row.sources)" :key="index">
                    <a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer" class="flex min-h-11 flex-col justify-center px-3 py-2 transition-colors duration-200 hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                      <span class="break-words text-body-sm text-primary underline-offset-2 hover:underline">{{ source.label }}</span>
                      <span v-if="source.host && source.host !== source.label" class="break-all text-label-sm text-secondary">{{ source.host }}</span>
                    </a>
                    <p v-else class="px-3 py-2 text-body-sm">{{ source.label }}</p>
                  </li>
                </ul>
              </div>
            </div>
          </details>
        </li>
      </ul>
      <div v-else class="flex flex-col items-center gap-3 py-6 text-center">
        <p class="text-body-sm text-secondary">Belum ada draf di tanggal ini.</p>
        <router-link v-if="canManage" :to="{ name: 'AdminMarketingDrafts' }" :class="`inline-flex h-10 items-center rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`">Susun draf</router-link>
      </div>
      <template v-if="agenda.length" #footer>
        <router-link :to="{ name: 'AdminMarketingDrafts' }" :class="`inline-flex h-10 items-center gap-1.5 rounded-full border border-border px-4 text-label-md hover:bg-accent-tint-15 ${press}`">Kelola di Draf Konten</router-link>
      </template>
    </AppModal>
  </section>
</template>

<style scoped>
/* Ganti bulan: geser singkat searah tombol, keluar lebih cepat dari masuk. Hanya transform dan opacity. */
.cal-next-enter-active,
.cal-prev-enter-active {
  transition: opacity 180ms cubic-bezier(0.23, 1, 0.32, 1), transform 180ms cubic-bezier(0.23, 1, 0.32, 1);
}
.cal-next-leave-active,
.cal-prev-leave-active {
  transition: opacity 100ms ease-out, transform 100ms ease-out;
}
.cal-next-enter-from { opacity: 0; transform: translateX(12px); }
.cal-next-leave-to { opacity: 0; transform: translateX(-12px); }
.cal-prev-enter-from { opacity: 0; transform: translateX(-12px); }
.cal-prev-leave-to { opacity: 0; transform: translateX(12px); }

@media (prefers-reduced-motion: reduce) {
  .cal-next-enter-from, .cal-next-leave-to, .cal-prev-enter-from, .cal-prev-leave-to { transform: none; }
}
</style>
