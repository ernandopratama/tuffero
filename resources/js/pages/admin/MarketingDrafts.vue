<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from '../../components/AdminIcon.vue';
import MetricsPanel from '../../components/marketing/MetricsPanel.vue';
import StoryboardPanel from '../../components/marketing/StoryboardPanel.vue';
import AddButton from '../../components/ui/AddButton.vue';
import AppModal from '../../components/ui/AppModal.vue';
import IconAction from '../../components/ui/IconAction.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import api from '../../utils/api';
import { sourceLinks } from '../../utils/sourceLinks';
import { apiError } from '../../utils/apiError';
import { useAuthStore } from '../../stores/auth';

// Draf konten AI Marketing (D-062, D-079). Marketing menyusun dan mengajukan; Pemilik atau Super Admin memutuskan; memposting tetap manual (BR-19).
// Izin sebenarnya dijaga server; tombol di sini hanya mengikuti izin supaya tidak membingungkan.
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const canManage = computed(() => auth.can('marketing_drafts.manage'));
// Catatan metrik konten punya izin sendiri (D-150): Marketing, Admin Toko, dan Super Admin mengisi; Pemilik hanya melihat.
const canRecordMetrics = computed(() => auth.can('content_metrics.manage'));
const canApprove = computed(() => auth.can('marketing_drafts.approve'));

// Jadwal hanya tanggal (tanpa jam), jadi tidak memakai formatDate yang menampilkan jam.
const day = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
// Label semua jenis (data lama masih bisa tampil); hanya dua yang bisa dipilih saat membuat draf. Caption pindah ke Generator Caption.
const TYPES = { video_script: 'Rencana video', caption: 'Caption (lama)', calendar_item: 'Kalender konten (lama)', trend_report: 'Riset tren' };
const PICKABLE = { video_script: TYPES.video_script, trend_report: TYPES.trend_report };
const PLATFORMS = { instagram: 'Instagram', tiktok: 'TikTok' };
// Asal draf: manual tidak diberi lencana. Draf AI dan Hermes tetap perlu diperiksa manusia sebelum diajukan.
const ORIGINS = { ai: 'Dibuat AI', hermes: 'Dari Hermes' };
const STATUS = {
    draft: { label: 'Draf', cls: 'bg-secondary-tint-12 text-secondary' },
    in_review: { label: 'Menunggu keputusan', cls: 'bg-warning-surface text-warning' },
    approved: { label: 'Disetujui', cls: 'bg-success-surface text-success' },
    rejected: { label: 'Ditolak', cls: 'bg-error-surface text-on-error-surface' },
    posted: { label: 'Sudah diposting', cls: 'bg-accent-tint-15 text-primary' },
};

// Lencana tegas (warna penuh) di ringkasan modal, sama dengan modal Detail pesanan.
const STATUS_SOLID = { draft: 'bg-[#64748B]', in_review: 'bg-[#B45309]', approved: 'bg-success', rejected: 'bg-error', posted: 'bg-[#4338CA]' };
const PLATFORM_SOLID = { instagram: 'bg-[#C13584]', tiktok: 'bg-[#111827]' };
const ORIGIN_SOLID = { ai: 'bg-[#6D28D9]', hermes: 'bg-[#0F766E]' };
// Kotak ringkasan: garis tegas dan bayangan solid (gaya modal Detail pesanan); di ponsel menjadi baris ringkas.
const tile = 'max-sm:flex max-sm:items-center max-sm:justify-between max-sm:gap-3 rounded-2xl border border-primary p-3 shadow-[4px_4px_0_0_var(--color-primary)] transition-[transform,box-shadow] duration-150 ease-out-strong [@media(hover:hover)]:hover:-translate-x-px [@media(hover:hover)]:hover:-translate-y-px [@media(hover:hover)]:hover:shadow-[6px_6px_0_0_var(--color-primary)]';
const tileLabel = 'text-label-sm uppercase tracking-wide';
const pill = 'inline-flex rounded-full px-3 py-1 text-label-md text-surface';

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';
const btn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const btnPrimary = `inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`;

const items = ref([]);
const frequency = ref({ videos_per_week: 0 });
const week = ref({ posts: 0, videos: 0 });
// Konten teratas per platform menurut views catatan metrik terakhir (input manual). Gagal dimuat tidak menghalangi daftar draf.
const top = ref({});
const topPlatforms = computed(() => Object.entries(top.value).filter(([, rows]) => rows.length));
const number = new Intl.NumberFormat('id-ID');
const loading = ref(true);
const loadError = ref('');
const filter = ref('');

// Pembuat draf AI (D-079): hasilnya draf biasa yang tetap harus diajukan dan disetujui.
const aiEnabled = ref(false);
const aiDialog = ref(null);
const aiBlank = { type: 'video_script', platform: '', brief: '', scheduled_on: '' };
const ai = reactive({ ...aiBlank });
const aiBusy = ref(false);
const aiError = ref('');
const aiFields = ref({});

function openAi() {
    Object.assign(ai, aiBlank);
    aiError.value = '';
    aiFields.value = {};
    aiDialog.value.showModal();
}

async function generate() {
    aiBusy.value = true;
    aiError.value = '';
    aiFields.value = {};
    try {
        await api.post('/admin/marketing-drafts/generate', { ...ai, platform: ai.platform || null, scheduled_on: ai.scheduled_on || null }, { toast: 'Draf dibuat. Periksa dulu sebelum diajukan.', timeout: 150000 });
        aiDialog.value.close();
        filter.value = 'draft';
        await load();
    } catch (e) {
        const { message, fields } = apiError(e);
        aiError.value = message;
        aiFields.value = fields;
    } finally {
        aiBusy.value = false;
    }
}

const dialog = ref(null);
const deleteDialog = ref(null);
const decideDialog = ref(null);
const editing = ref(null);
const toDelete = ref(null);
const toDecide = ref(null);
const decision = ref('approve');
// Dua jenis draf dibedakan di modal: warna, ikon, penjelasan, dan bagian formulirnya.
const TYPE_META = {
    video_script: { icon: 'video', title: 'Rencana video', text: 'Naskah dan storyboard disusun AI, lengkap dengan jadwal dan platform posting.', tint: 'bg-[#EDE7FA] text-[#5B21B6]', stripe: 'border-[#6D28D9]', section: 'border-l-[#6D28D9]' },
    trend_report: { icon: 'chart', title: 'Riset tren', text: 'Bahan baca tim: temuan tren dan kompetitor yang bersumber. Tidak diposting.', tint: 'bg-info-surface text-info', stripe: 'border-info', section: 'border-l-info' },
};
// Kartu daftar dibedakan per jenis: ungu = rencana video, biru = riset tren (sama dengan warna di modal).
const CARD_TONE = {
    video_script: { card: 'border-[#6D28D9]/30 border-l-[#6D28D9] bg-[#F1ECFB]', chip: 'bg-[#6D28D9]' },
    trend_report: { card: 'border-info/40 border-l-info bg-info-surface/60', chip: 'bg-info' },
    default: { card: 'border-border border-l-secondary bg-surface', chip: 'bg-secondary' },
};
const TYPE_OPTIONS = Object.entries(TYPE_META).map(([value, meta]) => ({ value, label: meta.title, hint: meta.text, icon: meta.icon }));
const PLATFORM_OPTIONS = [{ value: '', label: 'Belum ditentukan' }, ...Object.entries(PLATFORMS).map(([value, label]) => ({ value, label }))];
const note = ref('');
const saving = ref(false);
const busy = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const blank = { type: 'video_script', platform: '', title: '', body: '', sources: '', source_period: '', scheduled_on: '', duration_seconds: '', music_note: '', visual_mode: 'team' };
const VISUAL_MODES = { team: 'Foto/rekaman tim', ai: 'Dibuat AI sepenuhnya' };
const DURATIONS = [15, 30, 60];
const DURATION_OPTIONS = [{ value: '', label: 'Bawaan' }, ...DURATIONS.map((seconds) => ({ value: seconds, label: `${seconds} dtk` }))];
const VISUAL_OPTIONS = [
    { value: 'team', label: VISUAL_MODES.team, hint: 'Pakai foto acuan atau rekaman tim.', icon: 'photos' },
    { value: 'ai', label: VISUAL_MODES.ai, hint: 'Butuh minimal 2 foto produk asli.', icon: 'sparkles' },
];
// Lencana storyboard di kartu draf video.
const STORYBOARD = {
    pending: { label: 'Menyusun storyboard', cls: 'bg-accent-tint-12 text-primary' },
    ready: { label: 'Storyboard siap', cls: 'bg-success-surface text-success' },
    failed: { label: 'Storyboard gagal', cls: 'bg-error-surface text-on-error-surface' },
    waiting: { label: 'Menunggu 2 foto acuan', cls: 'bg-warning-surface text-warning' },
};
// Warna penuh untuk lencana storyboard di kartu daftar (latar kartu berwarna per jenis, jadi lencana pastel tidak terbaca).
const STORYBOARD_SOLID = { pending: 'bg-[#B45309]', ready: 'bg-success', failed: 'bg-error', waiting: 'bg-[#0369A1]' };
const form = reactive({ ...blank });

// Urutan daftar: bawaan yang terbaru dibuat dulu. Pilihan diingat di peramban ini (hanya kenyamanan; boleh gagal tersimpan).
const SORTS = { created_desc: 'Terbaru dibuat', created_asc: 'Terlama dibuat', schedule_asc: 'Jadwal terdekat', schedule_desc: 'Jadwal terjauh' };
const readSort = () => {
    try {
        const saved = localStorage.getItem('draf-konten-urutan');
        return Object.hasOwn(SORTS, saved) ? saved : 'created_desc';
    } catch {
        return 'created_desc';
    }
};
const sort = ref(readSort());
watch(sort, (value) => {
    try {
        localStorage.setItem('draf-konten-urutan', value);
    } catch {
        // Tanpa penyimpanan: urutan kembali ke bawaan saat dimuat ulang.
    }
});
// Waktu dibuat sama (mis. satu kiriman Hermes) dibedakan dengan nomor Draf, jadi urutannya selalu tetap.
const byCreated = (a, b) => new Date(a.created_at) - new Date(b.created_at) || a.number - b.number;
// Draf tanpa jadwal selalu di bawah, apa pun arah urutannya.
// Pada jadwal yang sama, urutannya menurut nomor Draf naik (yang lebih dulu dibuat di atas).
const bySchedule = (direction) => (a, b) => {
    if (!a.scheduled_on || !b.scheduled_on) return (a.scheduled_on ? 0 : 1) - (b.scheduled_on ? 0 : 1) || byCreated(a, b);
    return direction * a.scheduled_on.localeCompare(b.scheduled_on) || byCreated(a, b);
};
const SORTERS = { created_desc: (a, b) => byCreated(b, a), created_asc: byCreated, schedule_asc: bySchedule(1), schedule_desc: bySchedule(-1) };
const visible = computed(() => (filter.value ? items.value.filter((row) => row.status === filter.value) : [...items.value]).sort(SORTERS[sort.value]));
const counts = computed(() => Object.fromEntries(Object.keys(STATUS).map((key) => [key, items.value.filter((row) => row.status === key).length])));
const editable = (row) => ['draft', 'rejected'].includes(row.status);
// Formulir terkunci bagi akun tanpa izin menyusun, dan bagi draf yang sedang ditinjau atau sudah disetujui.
const readOnly = computed(() => !canManage.value || (editing.value && !editable(editing.value)));
const isTrend = computed(() => form.type === 'trend_report');
const typeMeta = computed(() => TYPE_META[form.type] ?? TYPE_META.video_script);
// Kartu bagian formulir: garis kiri berwarna menandai jenis draf (ungu = rencana video, biru = riset tren).
const sectionClass = computed(() => `rounded-3xl border border-l-4 border-border bg-surface p-space-md ${typeMeta.value.section}`);
const sectionTitle = 'mb-3 flex items-center gap-2 text-label-md uppercase tracking-wide text-secondary';

// Kolom teks tumbuh mengikuti isinya: tidak ada gulir di dalam kolom dan tidak ada teks terpotong; yang menggulir hanya modal.
// Lebar kolom baru stabil setelah modal selesai ditata (atau jendela diubah ukurannya), padahal tinggi isi bergantung pada lebar:
// ResizeObserver mengepaskan ulang tiap kali lebar kolom berubah. Hanya lebar yang dibandingkan, jadi mengubah tinggi tidak memicu putaran.
const widths = new WeakMap();
const watcher = new ResizeObserver((entries) => entries.forEach(({ target }) => {
    if (widths.get(target) !== target.clientWidth) fit(target);
}));
const fit = (el) => {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight + 2}px`;
    if (!widths.has(el)) watcher.observe(el);
    widths.set(el, el.clientWidth);
};
// Dikepaskan lagi setelah animasi masuk selesai dan setelah font web termuat: pergantian font mengubah pemenggalan baris, dan itu
// bukan perubahan lebar sehingga tidak ditangkap ResizeObserver.
const refit = () => document.querySelectorAll('dialog[open] textarea[data-autogrow]').forEach(fit);
const fitAll = () => nextTick(() => requestAnimationFrame(() => {
    refit();
    setTimeout(refit, 300);
    document.fonts?.ready.then(refit);
}));
watch(() => form.type, fitAll);

// Hasil riset (misalnya dari Hermes) ditata: paragraf dipisah baris kosong, butir bernomor ("1. ...") menjadi kartu, dan tautan
// menjadi lencana domain yang bisa diklik. Hanya tautan http(s) yang dijadikan tautan; semua teks tetap ditampilkan sebagai teks.
const URL_RE = /(https?:\/\/[^\s<>"]+)/g;
function linkify(text) {
    return text.split(URL_RE).filter(Boolean).flatMap((part) => {
        if (!/^https?:\/\//.test(part)) return [{ text: part.replace(/\*+/g, '') }];
        const url = part.replace(/[)\].,;:*]+$/, '');
        let host = url;
        try {
            host = new URL(url).hostname.replace(/^www\./, '');
        } catch {
            // URL tidak valid: ditampilkan utuh.
        }
        const tail = part.slice(url.length).replace(/\*+/g, '');
        return tail ? [{ url, host }, { text: tail }] : [{ url, host }];
    });
}
const researchBlocks = computed(() => form.body.split(/\n{2,}/).map((chunk) => chunk.trim()).filter(Boolean).map((chunk) => {
    const item = chunk.match(/^(\d+)[.)]\s+([\s\S]*)$/);
    return { number: item ? item[1] : null, parts: linkify(item ? item[2] : chunk) };
}));
// Mode lihat: draf ditampilkan sebagai bacaan, bukan formulir terkunci.
const viewing = computed(() => Boolean(editing.value) && readOnly.value);
// Nomor draf = id database: tetap, unik, dan dipakai untuk menyebut draf di grup atau rapat. Draf yang dihapus meninggalkan celah nomor.
const draftLabel = (row) => (row ? `Draf #${row.number} · ${row.title}` : '');
const dayLabel = (value) => day.format(new Date(`${value}T00:00:00`));
const sourceLinksList = computed(() => sourceLinks(form.sources));

// Storyboard disusun di antrean: selama ada yang menunggu, daftar dimuat ulang diam-diam tiap 10 detik.
let poll = null;

async function load(quiet = false) {
    clearTimeout(poll);
    if (!quiet) loading.value = true;
    loadError.value = '';
    try {
        const [{ data }, topResult] = await Promise.all([api.get('/admin/marketing-drafts'), api.get('/admin/marketing-metrics/top').catch(() => null)]);
        if (topResult) top.value = topResult.data.data.platforms;
        items.value = data.data.items;
        frequency.value = data.data.frequency;
        aiEnabled.value = data.data.ai_enabled;
        week.value = data.data.week;
        // Draf yang sedang dibuka ikut diperbarui (storyboard, foto, catatan); isi formulir tidak ditimpa.
        if (editing.value) editing.value = items.value.find((row) => row.id === editing.value.id) ?? editing.value;
        if (items.value.some((row) => row.storyboard_status === 'pending')) poll = setTimeout(() => load(true), 10000);
        // Tautan "Buka" dari kalender Ringkasan: ?draf=ID membuka draf itu sekali, lalu parameternya dibuang.
        const target = items.value.find((row) => String(row.id) === String(route.query.draf));
        if (target) {
            router.replace({ query: {} });
            open(target);
        }
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

// Daftar draf dibatasi 200 terbaru; draf teratas yang lebih lama tidak ada di daftar, jadi diberi tahu, bukan diam.
const topMissing = ref(false);
function openById(id) {
    const row = items.value.find((item) => item.id === id);
    topMissing.value = !row;
    if (row) open(row);
}

function open(row = null) {
    formError.value = '';
    fieldErrors.value = {};
    editing.value = row;
    Object.assign(form, blank, row ? {
        type: row.type,
        platform: row.platform ?? '',
        title: row.title,
        body: row.body,
        sources: row.sources ?? '',
        source_period: row.source_period ?? '',
        scheduled_on: row.scheduled_on ?? '',
        duration_seconds: row.duration_seconds ?? '',
        music_note: row.music_note ?? '',
        visual_mode: row.visual_mode ?? 'team',
    } : {});
    dialog.value.showModal();
    fitAll();
}

async function save() {
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    const body = { ...form, platform: form.platform || null, sources: form.sources || null, source_period: form.source_period || null, scheduled_on: form.scheduled_on || null, duration_seconds: form.duration_seconds || null, music_note: form.music_note || null };
    // Riset tren bukan konten untuk diunggah: platform, durasi, musik, dan sumber visual tidak berlaku.
    if (isTrend.value) Object.assign(body, { platform: null, duration_seconds: null, music_note: null, visual_mode: 'team' });
    try {
        if (editing.value) await api.patch(`/admin/marketing-drafts/${editing.value.id}`, body);
        else await api.post('/admin/marketing-drafts', body);
        dialog.value.close();
        await load(true);
    } catch (e) {
        const { message, fields } = apiError(e);
        formError.value = message;
        fieldErrors.value = fields;
    } finally {
        saving.value = false;
    }
}

async function move(row, action) {
    busy.value = true;
    try {
        await api.post(`/admin/marketing-drafts/${row.id}/move`, { action });
        await load(true);
    } catch (e) {
        loadError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

function askDecide(row, kind) {
    toDecide.value = row;
    decision.value = kind;
    note.value = '';
    formError.value = '';
    decideDialog.value.showModal();
}

async function confirmDecide() {
    busy.value = true;
    formError.value = '';
    try {
        await api.post(`/admin/marketing-drafts/${toDecide.value.id}/decide`, { decision: decision.value, note: note.value || null });
        decideDialog.value.close();
        await load(true);
    } catch (e) {
        formError.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

function askDelete(row) {
    toDelete.value = row;
    deleteDialog.value.showModal();
}

async function confirmDelete() {
    busy.value = true;
    try {
        await api.delete(`/admin/marketing-drafts/${toDelete.value.id}`, { toast: 'Draf dihapus.' });
        deleteDialog.value.close();
        await load(true);
    } catch (e) {
        loadError.value = apiError(e).message;
        deleteDialog.value.close();
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    load();
    window.addEventListener('resize', fitAll);
});
onBeforeUnmount(() => {
    clearTimeout(poll);
    watcher.disconnect();
    window.removeEventListener('resize', fitAll);
});
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-space-md">
    <PageHeader title="Draf Konten" description="Rencana video (naskah, storyboard, dan tanggal posting) dan riset tren. Marketing menyusun dan mengajukan, Pemilik memutuskan. Memposting tetap dilakukan manual. Caption dibuat di Generator Caption." />
    <div v-if="canManage" class="flex flex-wrap items-center gap-2">
      <button type="button" :disabled="!aiEnabled" :title="aiEnabled ? '' : 'Pembuat draf AI belum aktif. Hubungi Super Admin.'" :class="btn" @click="openAi"><AdminIcon name="sparkles" :size="16" />Buat dengan AI</button>
      <AddButton label="Tambah draf" @click="open()" />
    </div>
  </div>

  <div v-if="loading" role="status" aria-label="Memuat draf" class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
    <div v-for="n in 3" :key="n" class="h-48 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Muat ulang</button>
  </div>

  <template v-else>
    <ul class="mt-space-lg grid grid-cols-2 gap-3 sm:gap-space-md" aria-label="Ringkasan minggu ini">
      <li class="rounded-3xl border border-border bg-surface p-3 shadow-card sm:p-space-md">
        <span class="block text-body-sm text-secondary">Video minggu ini</span>
        <span class="block font-heading text-headline-sm tabular-nums">{{ week.videos }}/{{ frequency.videos_per_week }}</span>
        <span class="block text-body-sm text-secondary">Rencana video yang dijadwalkan</span>
      </li>
      <li class="rounded-3xl border border-border bg-surface p-3 shadow-card sm:p-space-md">
        <span class="block text-body-sm text-secondary">Menunggu keputusan</span>
        <span class="block font-heading text-headline-sm tabular-nums">{{ counts.in_review }}</span>
        <span class="block text-body-sm text-secondary">Target minggu diatur Super Admin di Pengaturan</span>
      </li>
    </ul>

    <section v-if="topPlatforms.length" aria-labelledby="top-content" class="mt-space-md rounded-3xl border border-border bg-surface p-3 shadow-card sm:p-space-md">
      <h2 id="top-content" class="flex items-center gap-2 text-body-sm text-secondary"><AdminIcon name="chart" :size="16" />Konten teratas (views catatan terakhir)</h2>
      <div class="mt-2 grid gap-space-md md:grid-cols-2">
        <div v-for="[platform, rows] in topPlatforms" :key="platform">
          <span class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="PLATFORM_SOLID[platform]">{{ PLATFORMS[platform] }}</span>
          <ol class="mt-2 space-y-1">
            <li v-for="(row, index) in rows" :key="row.draft_id" class="flex items-baseline gap-2 text-body-sm">
              <span class="w-5 shrink-0 tabular-nums text-secondary">{{ index + 1 }}.</span>
              <button type="button" class="min-w-0 flex-1 truncate text-left underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :title="row.title" @click="openById(row.draft_id)">#{{ row.number }} {{ row.title }}</button>
              <span class="shrink-0 tabular-nums">{{ number.format(row.views) }} views</span>
              <span v-if="row.engagement_rate !== null" class="shrink-0 tabular-nums text-secondary">{{ row.engagement_rate.toLocaleString('id-ID') }}%</span>
            </li>
          </ol>
        </div>
      </div>
      <p v-if="topMissing" role="status" class="mt-2 rounded-2xl bg-warning-surface px-3 py-2 text-body-sm text-warning">Draf itu lebih lama dari 200 draf terbaru, jadi tidak ada di daftar ini.</p>
    </section>

    <div class="mt-space-md flex flex-wrap items-center justify-between gap-x-space-md gap-y-2">
    <!-- Ponsel: chip filter satu baris yang bisa digeser (tinggi sentuh 40px), bukan beberapa baris kecil -->
    <div class="flex gap-2 max-sm:-mx-3 max-sm:overflow-x-auto max-sm:px-3 max-sm:pb-1 max-sm:[scrollbar-width:none] sm:flex-wrap" role="group" aria-label="Filter status">
      <button type="button" :aria-pressed="filter === ''" :class="`inline-flex min-h-10 shrink-0 items-center whitespace-nowrap rounded-full border px-3 text-label-md ${press} ${filter === '' ? 'border-primary bg-accent-tint-15' : 'border-border hover:bg-accent-tint-12'}`" @click="filter = ''">Semua ({{ items.length }})</button>
      <button v-for="(meta, key) in STATUS" :key="key" type="button" :aria-pressed="filter === key" :class="`inline-flex min-h-10 shrink-0 items-center whitespace-nowrap rounded-full border px-3 text-label-md ${press} ${filter === key ? 'border-primary bg-accent-tint-15' : 'border-border hover:bg-accent-tint-12'}`" @click="filter = key">{{ meta.label }} ({{ counts[key] }})</button>
    </div>
    <label class="flex items-center gap-2 text-label-md max-sm:w-full max-sm:justify-between">
      Urutan
      <select v-model="sort" class="h-10 cursor-pointer rounded-full border border-border bg-surface px-3 text-body-sm text-primary focus:border-primary focus:outline-2 focus:outline-offset-1 focus:outline-primary">
        <option v-for="(label, key) in SORTS" :key="key" :value="key">{{ label }}</option>
      </select>
    </label>
    </div>

    <div v-if="!visible.length" class="mt-space-lg flex flex-col items-center gap-3 rounded-3xl border border-dashed border-border bg-surface px-6 py-14 text-center">
      <span class="grid size-14 place-items-center rounded-2xl bg-accent-tint-15 text-primary" aria-hidden="true"><AdminIcon name="edit" :size="26" /></span>
      <p class="font-heading text-headline-sm">{{ filter ? 'Tidak ada draf dengan status ini' : 'Belum ada draf' }}</p>
      <p class="max-w-md text-body-sm text-secondary">Susun skrip video, caption, atau kalender konten, lalu ajukan ke Pemilik untuk disetujui.</p>
      <AddButton v-if="canManage && !filter" label="Tambah draf" expanded class="mt-1" @click="open()" />
    </div>

    <ul v-else class="mt-space-lg grid gap-space-md sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="row in visible" :key="row.id" class="flex min-w-0 flex-col gap-2 rounded-3xl border border-l-4 p-space-md shadow-card" :class="(CARD_TONE[row.type] || CARD_TONE.default).card">
        <p class="text-body-lg font-semibold tabular-nums text-primary">Draf #{{ row.number }}</p>
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="STATUS_SOLID[row.status]">{{ STATUS[row.status].label }}</span>
          <span class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="(CARD_TONE[row.type] || CARD_TONE.default).chip">{{ TYPES[row.type] }}</span>
          <span v-if="row.platform" class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="PLATFORM_SOLID[row.platform] || 'bg-secondary'">{{ PLATFORMS[row.platform] }}</span>
          <span v-if="ORIGINS[row.origin]" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-label-sm text-surface" :class="ORIGIN_SOLID[row.origin] || 'bg-secondary'"><AdminIcon name="sparkles" :size="12" />{{ ORIGINS[row.origin] }}</span>
          <span v-if="STORYBOARD[row.storyboard_status]" class="rounded-full px-2.5 py-1 text-label-sm text-surface" :class="STORYBOARD_SOLID[row.storyboard_status]">{{ STORYBOARD[row.storyboard_status].label }}</span>
        </div>
        <h2 class="font-heading text-headline-sm break-words">{{ row.title }}</h2>
        <p class="line-clamp-3 whitespace-pre-line break-words text-body-sm text-secondary">{{ row.body }}</p>
        <p v-if="row.scheduled_on" class="flex items-center gap-1.5 text-body-sm"><AdminIcon name="clock" :size="16" class="shrink-0 text-secondary" />Jadwal {{ day.format(new Date(`${row.scheduled_on}T00:00:00`)) }}</p>
        <p class="flex items-center gap-1.5 text-body-sm text-secondary"><AdminIcon name="plus" :size="16" class="shrink-0" />Dibuat {{ day.format(new Date(row.created_at)) }}</p>
        <p v-if="row.review_note" class="rounded-2xl bg-secondary-tint-12 px-3 py-2 text-body-sm">Catatan {{ row.reviewer || 'peninjau' }}: {{ row.review_note }}</p>
        <p class="text-body-sm text-secondary">{{ row.origin === 'hermes' ? 'Dikirim Hermes (riset terjadwal)' : `Disusun ${row.creator || 'akun yang sudah dihapus'}` }}</p>

        <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-2">
          <div class="flex flex-wrap gap-2">
            <button v-if="canManage && editable(row)" type="button" :disabled="busy" :class="btnPrimary" @click="move(row, 'submit')">Ajukan</button>
            <button v-if="canManage && row.status === 'in_review'" type="button" :disabled="busy" :class="btn" @click="move(row, 'withdraw')">Tarik kembali</button>
            <button v-if="canApprove && row.status === 'in_review'" type="button" :disabled="busy" :class="btnPrimary" @click="askDecide(row, 'approve')">Setujui</button>
            <button v-if="canApprove && row.status === 'in_review'" type="button" :disabled="busy" :class="btn" @click="askDecide(row, 'reject')">Tolak</button>
            <router-link v-if="canManage && row.type === 'video_script'" :to="{ name: 'AdminCaptionGenerator', query: { draf: row.id } }" :class="btn"><AdminIcon name="sparkles" :size="16" />Buat caption</router-link>
            <button v-if="canManage && row.status === 'approved' && row.type !== 'trend_report'" type="button" :disabled="busy" :class="btnPrimary" @click="move(row, 'post')">Tandai sudah diposting</button>
          </div>
          <div class="flex items-center gap-1">
            <IconAction v-if="canManage && editable(row)" icon="trash" tone="danger" label="Hapus" :subject="row.title" @click="askDelete(row)" />
            <IconAction :icon="canManage && editable(row) ? 'edit' : 'eye'" :label="canManage && editable(row) ? 'Ubah' : 'Lihat'" :subject="row.title" @click="open(row)" />
          </div>
        </div>
      </li>
    </ul>
  </template>

  <AppModal ref="dialog" :variant="editing ? (readOnly ? 'read' : 'update') : 'create'" size="4xl" :title="editing ? `${readOnly ? 'Lihat' : 'Ubah'} Draf #${editing.number}` : 'Tambah draf'" :subtitle="editing?.title">
    <!-- ===== Mode lihat: draf dibaca, bukan formulir terkunci. Dua jenis dibedakan warna, ikon, dan bagian isinya. ===== -->
    <article v-if="viewing" class="space-y-space-md text-body-sm" aria-label="Isi draf">
      <!-- Ringkasan: judul besar di kiri, tiga kotak status di kanan -->
      <div class="grid gap-space-md rounded-3xl border border-border bg-gradient-to-br from-accent-tint-12 to-background/60 p-4 sm:p-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center">
        <div class="flex min-w-0 items-start gap-3">
          <span class="grid size-11 shrink-0 place-items-center rounded-full" :class="typeMeta.tint" aria-hidden="true"><AdminIcon :name="typeMeta.icon" :size="22" /></span>
          <div class="min-w-0">
            <p class="text-label-sm uppercase tracking-wide text-secondary">{{ typeMeta.title }}</p>
            <h3 class="mt-1 break-words font-heading text-headline-md leading-tight">{{ form.title }}</h3>
            <p class="mt-1 text-secondary">{{ typeMeta.text }}</p>
          </div>
        </div>
        <dl class="grid gap-3 sm:grid-cols-3">
          <div :class="[tile, 'bg-info-surface']">
            <dt :class="[tileLabel, 'text-info']">Status</dt>
            <dd class="mt-1.5 max-sm:mt-0"><span :class="[pill, STATUS_SOLID[editing.status]]">{{ STATUS[editing.status].label }}</span></dd>
          </div>
          <div :class="[tile, 'bg-accent-tint-20']">
            <dt :class="[tileLabel, 'text-primary']">{{ isTrend ? 'Jenis' : 'Platform' }}</dt>
            <dd class="mt-1.5 max-sm:mt-0"><span :class="[pill, isTrend ? 'bg-info' : (PLATFORM_SOLID[editing.platform] || 'bg-secondary')]">{{ isTrend ? 'Riset tren' : (PLATFORMS[editing.platform] || 'Belum ditentukan') }}</span></dd>
          </div>
          <div :class="[tile, 'bg-success-surface']">
            <dt :class="[tileLabel, 'text-success']">Asal draf</dt>
            <dd class="mt-1.5 max-sm:mt-0"><span :class="[pill, ORIGIN_SOLID[editing.origin] || 'bg-secondary']">{{ ORIGINS[editing.origin] || 'Disusun manual' }}</span></dd>
          </div>
        </dl>
      </div>

      <p v-if="editing.review_note" class="flex gap-2 rounded-2xl bg-secondary-tint-12 px-4 py-3"><AdminIcon name="info" :size="18" class="mt-0.5 shrink-0" /><span>Catatan {{ editing.reviewer || 'peninjau' }}: {{ editing.review_note }}</span></p>

      <!-- Dua kolom di layar lebar: isi dan sumber di kiri, rincian di kanan -->
      <div class="grid items-start gap-space-md lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-space-md animate-rise">
          <section :aria-label="isTrend ? 'Ringkasan riset' : 'Hook dan ide'" class="rounded-2xl border border-border bg-accent-tint-12 p-4">
            <h4 class="mb-2 text-label-sm uppercase tracking-wide text-secondary">{{ isTrend ? 'Ringkasan riset' : 'Hook dan ide' }}</h4>
            <!-- Riset tren: ditata per butir dengan tautan berupa lencana domain. Video: teks apa adanya. -->
            <ol v-if="isTrend" class="space-y-2.5" aria-label="Temuan riset">
              <li v-for="(block, index) in researchBlocks" :key="index" class="flex gap-3 rounded-2xl bg-surface/80 p-3" :class="block.number ? 'border border-border' : ''">
                <span v-if="block.number" class="grid size-7 shrink-0 place-items-center rounded-full bg-info text-label-sm tabular-nums text-surface" aria-hidden="true">{{ block.number }}</span>
                <p class="min-w-0 flex-1 whitespace-pre-line break-words text-body-md leading-relaxed">
                  <template v-for="(part, i) in block.parts" :key="i"><a v-if="part.url" :href="part.url" target="_blank" rel="noopener noreferrer" :title="part.url" class="mx-0.5 inline-flex items-center rounded-full bg-info-surface px-2 py-0.5 align-baseline text-label-sm text-info underline-offset-2 transition-colors duration-200 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ part.host }}</a><template v-else>{{ part.text }}</template></template>
                </p>
              </li>
            </ol>
            <p v-else class="max-w-prose whitespace-pre-line break-words text-body-md leading-relaxed">{{ form.body }}</p>
          </section>

          <section v-if="sourceLinksList.length || (!isTrend && form.source_period)" aria-label="Sumber" class="rounded-2xl border border-border p-4">
            <h4 class="flex items-center gap-1.5 font-heading text-headline-sm"><AdminIcon name="document" :size="18" />Sumber ({{ sourceLinksList.length }})</h4>
            <p v-if="!isTrend && form.source_period" class="mt-1 text-secondary">Data {{ form.source_period }}</p>
            <ul v-if="sourceLinksList.length" class="mt-space-sm divide-y divide-border overflow-hidden rounded-2xl border border-border">
              <li v-for="(source, index) in sourceLinksList" :key="index">
                <a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer" class="flex min-h-11 flex-col justify-center px-4 py-2 transition-colors duration-200 hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                  <span class="break-words text-body-sm text-primary underline-offset-2 hover:underline">{{ source.label }}</span>
                  <span v-if="source.host && source.host !== source.label" class="break-all text-label-sm text-secondary">{{ source.host }}</span>
                </a>
                <p v-else class="px-4 py-2 text-body-sm">{{ source.label }}</p>
              </li>
            </ul>
          </section>
        </div>

        <div class="min-w-0 space-y-space-md animate-rise" style="animation-delay: 60ms">
          <section class="rounded-2xl border border-border p-4" aria-label="Rincian draf">
            <h4 class="font-heading text-headline-sm">Rincian</h4>
            <dl class="mt-space-sm divide-y divide-border">
              <div v-if="form.scheduled_on" class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="clock" :size="14" />{{ isTrend ? 'Jadwal tinjau' : 'Jadwal posting' }}</dt><dd class="text-right text-label-md">{{ dayLabel(form.scheduled_on) }}</dd></div>
              <div v-if="!isTrend" class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="video" :size="14" />Durasi</dt><dd class="text-right text-label-md">{{ form.duration_seconds || 30 }} detik</dd></div>
              <div v-if="!isTrend" class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="photos" :size="14" />Sumber visual</dt><dd class="text-right text-label-md">{{ VISUAL_MODES[form.visual_mode] }}</dd></div>
              <div v-if="isTrend && form.source_period" class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="document" :size="14" />Periode data</dt><dd class="text-right text-label-md">{{ form.source_period }}</dd></div>
              <div v-if="!isTrend && form.music_note" class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="volume" :size="14" />Musik</dt><dd class="text-right text-label-md">{{ form.music_note }}</dd></div>
              <div class="flex items-start justify-between gap-3 py-2"><dt class="flex items-center gap-1.5 text-secondary"><AdminIcon name="user" :size="14" />Disusun oleh</dt><dd class="text-right text-label-md">{{ editing.origin === 'hermes' ? 'Hermes (riset terjadwal)' : (editing.creator || 'Akun dihapus') }}</dd></div>
            </dl>
          </section>
        </div>
      </div>
    </article>

    <!-- ===== Mode ubah: pilih jenis dulu, lalu hanya bagian yang relevan yang tampil. ===== -->
    <form v-else id="draft-form" class="space-y-space-lg" @submit.prevent="save">
      <p v-if="formError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
      <fieldset :disabled="readOnly" class="space-y-space-lg">
        <!-- Draf baru: pilih jenis. Draf yang diubah: hanya jenisnya sendiri yang tampil (jenis tidak bisa berganti). -->
        <div v-if="!editing">
          <p class="mb-2 text-label-md">Jenis draf</p>
          <SegmentedControl v-model="form.type" variant="card" label="Jenis draf" :options="TYPE_OPTIONS" :disabled="readOnly" />
        </div>
        <div v-else class="flex items-center gap-3 rounded-2xl border-l-4 p-3" :class="[typeMeta.tint, typeMeta.stripe]">
          <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface/70" aria-hidden="true"><AdminIcon :name="typeMeta.icon" :size="20" /></span>
          <div class="min-w-0">
            <p class="text-label-md">{{ typeMeta.title }}</p>
            <p class="text-body-sm opacity-80">{{ typeMeta.text }}</p>
          </div>
        </div>

        <Transition name="draft-swap" mode="out-in" @after-enter="fitAll">
          <div :key="form.type" class="grid items-start gap-space-md lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <!-- ----- Rencana video ----- -->
            <template v-if="!isTrend">
              <section :class="[sectionClass, 'min-w-0 lg:row-span-2']" aria-labelledby="sec-rencana">
                <h4 id="sec-rencana" :class="sectionTitle"><AdminIcon name="edit" :size="16" />Rencana</h4>
                <div class="space-y-space-md">
                  <label class="block text-label-md">
                    Judul
                    <input v-model="form.title" required maxlength="150" placeholder="Contoh: Unboxing hampers Natal" :class="field">
                    <span class="mt-1 flex justify-between gap-2 text-label-sm font-normal text-secondary"><span class="text-error">{{ fieldErrors.title?.[0] }}</span><span class="tabular-nums">{{ form.title.length }}/150</span></span>
                  </label>
                  <label class="block text-label-md">
                    Hook dan ide
                    <textarea v-model="form.body" required rows="5" maxlength="5000" data-autogrow style="overflow-y: hidden" @input="fit($event.target)" placeholder="Hook: kalimat pembuka 3 detik pertama. CTA: ajakan di akhir video." :class="`${field} resize-y`"></textarea>
                    <span class="mt-1 flex justify-between gap-2 text-label-sm font-normal text-secondary"><span class="text-error">{{ fieldErrors.body?.[0] }}</span><span class="tabular-nums">{{ form.body.length }}/5000</span></span>
                  </label>
                </div>
              </section>

              <section :class="[sectionClass, 'min-w-0 lg:col-start-2']" aria-labelledby="sec-jadwal">
                <h4 id="sec-jadwal" :class="sectionTitle"><AdminIcon name="clock" :size="16" />Jadwal dan platform</h4>
                <div class="space-y-space-md">
                  <div>
                    <p class="mb-2 text-label-md">Platform <span class="font-normal text-secondary">(opsional)</span></p>
                    <SegmentedControl v-model="form.platform" label="Platform" :options="PLATFORM_OPTIONS" :disabled="readOnly" />
                  </div>
                  <div class="grid gap-space-md">
                    <label class="block text-label-md">
                      Jadwal posting <span class="font-normal text-secondary">(opsional)</span>
                      <input v-model="form.scheduled_on" type="date" :class="field">
                    </label>
                    <div>
                      <p class="mb-2 text-label-md">Durasi video <span class="font-normal text-secondary">(bawaan 30 detik)</span></p>
                      <SegmentedControl v-model="form.duration_seconds" label="Durasi video" :options="DURATION_OPTIONS" :disabled="readOnly" />
                    </div>
                  </div>
                </div>
              </section>

              <section :class="[sectionClass, 'min-w-0 lg:col-start-2']" aria-labelledby="sec-produksi">
                <h4 id="sec-produksi" :class="sectionTitle"><AdminIcon name="photos" :size="16" />Produksi</h4>
                <div class="space-y-space-md">
                  <div>
                    <p class="mb-2 text-label-md">Sumber visual</p>
                    <SegmentedControl v-model="form.visual_mode" variant="card" label="Sumber visual" :options="VISUAL_OPTIONS" :disabled="readOnly" />
                    <p class="mt-2 text-body-sm text-secondary">{{ form.visual_mode === 'ai' ? 'Seluruh video dibuat AI dari foto produk asli. Wajib minimal 2 foto acuan; storyboard baru disusun setelah foto kedua diunggah.' : 'Adegan produk memakai foto acuan atau rekaman tim; AI hanya untuk adegan suasana.' }}</p>
                  </div>
                  <label class="block text-label-md">
                    Musik <span class="font-normal text-secondary">(opsional)</span>
                    <input v-model="form.music_note" maxlength="300" placeholder="Contoh: akustik hangat, atau kosongkan agar AI menyarankan" :class="field">
                    <span v-if="fieldErrors.music_note" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.music_note[0] }}</span>
                  </label>
                  <p class="flex items-start gap-2 rounded-2xl bg-accent-tint-12 px-3 py-2 text-body-sm"><AdminIcon name="sparkles" :size="16" class="mt-0.5 shrink-0" />Naskah dan storyboard dibuat otomatis oleh AI setelah draf disimpan, dan disusun ulang bila durasi atau musik diubah.</p>
                </div>
              </section>
            </template>

            <!-- ----- Riset tren ----- -->
            <template v-else>
              <section :class="[sectionClass, 'min-w-0 lg:row-span-2']" aria-labelledby="sec-riset">
                <h4 id="sec-riset" :class="sectionTitle"><AdminIcon name="chart" :size="16" />Isi riset</h4>
                <div class="space-y-space-md">
                  <label class="block text-label-md">
                    Judul
                    <input v-model="form.title" required maxlength="150" placeholder="Contoh: Riset tren mingguan 23 Nov 2026" :class="field">
                    <span class="mt-1 flex justify-between gap-2 text-label-sm font-normal text-secondary"><span class="text-error">{{ fieldErrors.title?.[0] }}</span><span class="tabular-nums">{{ form.title.length }}/150</span></span>
                  </label>
                  <label class="block text-label-md">
                    Temuan dan ringkasan
                    <textarea v-model="form.body" required rows="11" maxlength="5000" data-autogrow style="overflow-y: hidden" @input="fit($event.target)" placeholder="Tulis temuan tren, kompetitor, dan pelajaran untuk Tuffero. Tiap klaim harus punya sumber di bawah." :class="`${field} resize-y`"></textarea>
                    <span class="mt-1 flex justify-between gap-2 text-label-sm font-normal text-secondary"><span class="text-error">{{ fieldErrors.body?.[0] }}</span><span class="tabular-nums">{{ form.body.length }}/5000</span></span>
                  </label>
                </div>
              </section>

              <section :class="[sectionClass, 'min-w-0 lg:col-start-2']" aria-labelledby="sec-sumber">
                <h4 id="sec-sumber" :class="sectionTitle"><AdminIcon name="document" :size="16" />Sumber data <span class="ml-1 rounded-full bg-warning-surface px-2 py-0.5 text-label-sm normal-case tracking-normal text-warning">Wajib</span></h4>
                <div class="grid gap-space-md">
                  <label class="block text-label-md">
                    Tautan sumber
                    <textarea v-model="form.sources" rows="5" maxlength="1000" required data-autogrow style="overflow-y: hidden" @input="fit($event.target)" placeholder="Satu tautan per baris, contoh: https://contoh.com/artikel" :class="`${field} resize-y break-all text-body-sm`"></textarea>
                    <span class="mt-1 flex justify-between gap-2 text-label-sm font-normal text-secondary"><span :class="fieldErrors.sources ? 'text-error' : ''">{{ fieldErrors.sources?.[0] || `${sourceLinksList.length} tautan terdeteksi` }}</span><span class="tabular-nums">{{ form.sources.length }}/1000</span></span>
                  </label>
                  <label class="block text-label-md">
                    Periode data
                    <input v-model="form.source_period" maxlength="100" required placeholder="Contoh: 1 sampai 7 November 2026" :class="field">
                    <span v-if="fieldErrors.source_period" class="mt-1 block text-body-sm font-normal text-error">{{ fieldErrors.source_period[0] }}</span>
                  </label>
                </div>
              </section>

              <section :class="[sectionClass, 'min-w-0 lg:col-start-2']" aria-labelledby="sec-tinjau">
                <h4 id="sec-tinjau" :class="sectionTitle"><AdminIcon name="clock" :size="16" />Jadwal tinjau <span class="font-normal normal-case tracking-normal">(opsional)</span></h4>
                <label class="block text-label-md sm:max-w-xs">
                  Tanggal ditinjau tim
                  <input v-model="form.scheduled_on" type="date" :class="field">
                </label>
                <p class="mt-2 text-body-sm text-secondary">Riset tren adalah bahan baca tim, bukan konten untuk diunggah: tidak ada platform, durasi, atau status diposting.</p>
              </section>
            </template>
          </div>
        </Transition>

        <p class="text-body-sm text-secondary">Jangan menulis klaim kesehatan, alergen, atau halal tanpa data resmi (OQ-20).</p>
      </fieldset>
    </form>
    <StoryboardPanel v-if="editing && editing.type === 'video_script'" :draft="editing" :can-edit="canManage && editable(editing)" @changed="load(true)" />
    <MetricsPanel v-if="editing && editing.status === 'posted'" :draft="editing" :can-edit="canRecordMetrics" @changed="load(true)" />
    <template #footer>
      <button type="button" :class="btn" @click="dialog.close()">Tutup</button>
      <button v-if="!readOnly" form="draft-form" :disabled="saving" :class="btnPrimary"><AdminIcon name="check" :size="16" />{{ saving ? 'Menyimpan...' : (editing ? 'Simpan' : 'Buat draf') }}</button>
    </template>
  </AppModal>

  <AppModal ref="aiDialog" variant="create" size="lg" title="Buat draf dengan AI" subtitle="Claude menulis satu draf. Periksa dan sunting sebelum diajukan.">
    <form id="ai-form" class="space-y-space-md" @submit.prevent="generate">
      <p v-if="aiError" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ aiError }}</p>
      <fieldset :disabled="aiBusy" class="space-y-space-md">
        <div class="grid gap-space-md sm:grid-cols-3">
          <label class="block text-label-md">
            Jenis
            <select v-model="ai.type" :class="field"><option v-for="(label, key) in PICKABLE" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Platform <span class="font-normal text-secondary">(opsional)</span>
            <select v-model="ai.platform" :class="field"><option value="">Tidak ditentukan</option><option v-for="(label, key) in PLATFORMS" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block text-label-md">
            Jadwal posting <span class="font-normal text-secondary">(opsional)</span>
            <input v-model="ai.scheduled_on" type="date" :class="field">
          </label>
        </div>
        <label class="block text-label-md">
          Brief
          <textarea v-model="ai.brief" required rows="5" minlength="10" maxlength="1000" placeholder="Contoh: caption Instagram untuk Nastar Sultan jelang Natal, tema hadiah keluarga." :class="`${field} resize-y`"></textarea>
          <span class="mt-1 block text-right text-label-sm font-normal tabular-nums text-secondary">{{ ai.brief.length }}/1000</span>
          <span v-if="aiFields.brief" class="block text-body-sm font-normal text-error">{{ aiFields.brief[0] }}</span>
        </label>
        <p v-if="ai.type === 'trend_report'" class="text-body-sm text-secondary">Riset tren mencari di web untuk 7 hari terakhir dan menyertakan sumbernya. Bisa memakan waktu sampai 2 menit. Tanpa sumber yang bisa dikutip, draf tidak dibuat.</p>
        <p class="text-body-sm text-secondary">AI hanya melihat brief ini dan daftar nama produk tayang. Tidak ada data pesanan, pelanggan, harga, atau stok yang dikirim.</p>
      </fieldset>
    </form>
    <template #footer>
      <button type="button" :disabled="aiBusy" :class="btn" @click="aiDialog.close()">Batal</button>
      <button form="ai-form" :disabled="aiBusy" :class="btnPrimary"><AdminIcon name="sparkles" :size="16" />{{ aiBusy ? 'AI sedang menulis...' : 'Buat draf' }}</button>
    </template>
  </AppModal>

  <AppModal ref="decideDialog" variant="update" size="md" :title="decision === 'approve' ? 'Setujui draf?' : 'Tolak draf?'" :subtitle="draftLabel(toDecide)">
    <p v-if="formError" role="alert" class="mb-3 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ formError }}</p>
    <p class="text-body-sm">{{ decision === 'approve' ? 'Setelah disetujui, tim marketing boleh memposting secara manual. Sistem tidak memposting otomatis.' : 'Draf kembali ke Marketing untuk diperbaiki. Tulis alasannya.' }}</p>
    <label class="mt-3 block text-label-md">
      Catatan <span class="font-normal text-secondary">{{ decision === 'approve' ? '(opsional)' : '(wajib)' }}</span>
      <textarea v-model="note" rows="3" maxlength="500" :class="`${field} resize-none`"></textarea>
    </label>
    <template #footer>
      <button type="button" :disabled="busy" :class="btn" @click="decideDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="btnPrimary" @click="confirmDecide"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : (decision === 'approve' ? 'Setujui' : 'Tolak') }}</button>
    </template>
  </AppModal>

  <AppModal ref="deleteDialog" variant="delete" size="md" title="Hapus draf?" :subtitle="draftLabel(toDelete)">
    <p class="text-body-sm">Draf ini hilang dari daftar dan tidak bisa dikembalikan dari dashboard. Penghapusan tercatat di Audit Log.</p>
    <template #footer>
      <button type="button" :disabled="busy" :class="btn" @click="deleteDialog.close()">Batal</button>
      <button type="button" :disabled="busy" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-error px-4 text-label-md text-surface hover:bg-error/90 ${press}`" @click="confirmDelete"><AdminIcon name="trash" :size="16" />{{ busy ? 'Menghapus...' : 'Hapus' }}</button>
    </template>
  </AppModal>
</template>

<style scoped>
/* Ganti jenis draf: bagian formulir masuk naik 6 px sambil memudar (ease-out, 180 ms), keluar lebih cepat (120 ms). Hanya opacity dan transform. */
.draft-swap-enter-active { transition: opacity 180ms cubic-bezier(0.23, 1, 0.32, 1), transform 180ms cubic-bezier(0.23, 1, 0.32, 1); }
.draft-swap-leave-active { transition: opacity 120ms ease-out; }
.draft-swap-enter-from { opacity: 0; transform: translateY(6px); }
.draft-swap-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
  .draft-swap-enter-active, .draft-swap-leave-active { transition: opacity 120ms ease-out; }
  .draft-swap-enter-from { transform: none; }
}
</style>
