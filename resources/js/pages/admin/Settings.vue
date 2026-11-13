<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminIcon from '../../components/AdminIcon.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';
import { formatDate } from '../../utils/datetime';
import PageHeader from '../../components/ui/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { toast } from '../../composables/useToast';

const auth = useAuthStore();

// Nama layanan untuk toast hasil "Cek ke API sekarang", sesuai kunci hasil dari server.
const CHECK_NAMES = { midtrans: 'Midtrans', biteship: 'Biteship', mail: 'Email', whatsapp: 'OTP WhatsApp', turnstile: 'Turnstile', claude: 'Claude API' };

// Integrasi pembayaran dan pengiriman: hanya Super Admin (settings.manage). Rahasia tidak pernah dikirim ke sini.
const STATUS = {
    ok: { text: 'Siap', chip: 'bg-success-surface text-success' },
    warn: { text: 'Periksa', chip: 'bg-accent-tint-20 text-primary' },
    bad: { text: 'Bermasalah', chip: 'bg-error-surface text-on-error-surface' },
};
const GUIDE = {
    midtrans: {
        icon: 'wallet',
        steps: [
            'Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY di file .env server. MIDTRANS_IS_PRODUCTION=false untuk sandbox, true untuk produksi; kunci harus sesuai modenya.',
            'Di dashboard Midtrans (Settings, Configuration), isi Payment Notification URL dengan alamat webhook di atas.',
            'Setelah mengubah .env, jalankan php artisan config:clear di server, lalu tekan Cek ke API sekarang.',
        ],
    },
    biteship: {
        icon: 'truck',
        steps: [
            'Isi BITESHIP_API_KEY, BITESHIP_ORIGIN_POSTAL_CODE, dan BITESHIP_WEBHOOK_SECRET (acak, minimal 16 karakter) di file .env server.',
            'Di dashboard Biteship, daftarkan webhook dengan alamat di atas dan header X-Biteship-Secret berisi rahasia yang sama.',
            'Setelah mengubah .env, jalankan php artisan config:clear di server, lalu tekan Cek ke API sekarang.',
        ],
    },
};

const securityChecks = ref([]);
const stuckOrders = ref([]);
const services = ref([]);
const servicesCheckedAt = ref(null);
const systemChecks = ref([]);
const systemCheckedAt = ref('');
const systemError = ref('');
const systemBusy = ref(false);

// `manual`: dipicu tombol "Periksa ulang", jadi hasilnya diumumkan lewat toast. Muat awal halaman tetap diam.
// Menandai galat log yang ada sekarang sebagai sudah ditangani. Berkas log tidak diubah; hanya galat baru yang dihitung lagi.
async function acknowledgeLog() {
    systemBusy.value = true;
    try {
        await api.post('/admin/system-health/log-ack', null, { toast: 'Galat log ditandai sudah ditangani.' });
    } catch (e) {
        systemError.value = apiError(e).message;
        toast.error(systemError.value);
    }
    await loadSystemHealth();
}

async function loadSystemHealth(manual = false) {
    if (!auth.can('settings.manage')) return;
    systemBusy.value = true;
    systemError.value = '';
    try {
        const { data } = await api.get('/admin/system-health');
        systemChecks.value = data.data.checks;
        stuckOrders.value = data.data.stuck_orders;
        securityChecks.value = data.data.security;
        systemCheckedAt.value = data.data.generated_at;

        if (manual === true) {
            const bad = systemChecks.value.filter((check) => check.status === 'bad').length;
            const warn = systemChecks.value.filter((check) => check.status === 'warn').length;
            if (bad) toast.error(`Pemeriksaan selesai: ${bad} bermasalah${warn ? `, ${warn} perlu dicek` : ''}.`);
            else if (warn) toast.info(`Pemeriksaan selesai: ${warn} perlu dicek.`);
            else toast.success('Pemeriksaan selesai. Semua siap.');
        }
    } catch (e) {
        systemError.value = apiError(e).message;
        if (manual === true) toast.error(systemError.value);
    } finally {
        systemBusy.value = false;
    }
}

const integrations = ref([]);
const webhookSource = ref('app_url');
const webhookReachable = ref(true);
const tunnelUrl = ref(null);
const isLocal = ref(false);
const integrationError = ref('');
const checking = ref(false);
const copied = ref('');

async function loadIntegrations() {
    if (!auth.can('settings.manage')) return;
    try {
        const { data } = await api.get('/admin/integrations');
        integrations.value = data.data.integrations;
        services.value = data.data.services;
        servicesCheckedAt.value = data.data.checked_at;
        webhookSource.value = data.data.webhook_source;
        webhookReachable.value = data.data.webhook_reachable;
        tunnelUrl.value = data.data.tunnel_url;
        isLocal.value = data.data.local;
    } catch (e) {
        integrationError.value = apiError(e).message;
    }
}

async function checkNow() {
    checking.value = true;
    integrationError.value = '';
    try {
        // { toast: false }: toast bawaan untuk setiap POST admin berbunyi "Perubahan tersimpan", padahal ini pengecekan.
        const { data } = await api.post('/admin/integrations/check', null, { toast: false });
        await loadIntegrations();

        // Hanya layanan yang benar-benar diuji; yang tidak dipakai berstatus skipped dan dilewati.
        const named = (status) => Object.entries(CHECK_NAMES).filter(([key]) => data.data[key]?.status === status).map(([, name]) => name);
        const bad = named('bad');
        const unknown = named('unknown');
        if (bad.length) toast.error(`Ditolak penyedia: ${bad.join(', ')}.`);
        else if (unknown.length) toast.info(`Tidak bisa dihubungi: ${unknown.join(', ')}.`);
        else toast.success('Semua kunci yang diuji diterima penyedia.');
    } catch (e) {
        integrationError.value = apiError(e).message;
        toast.error(integrationError.value);
    } finally {
        checking.value = false;
    }
}

async function copyUrl(url) {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = url;
        toast.success('Alamat webhook disalin.');
        setTimeout(() => { if (copied.value === url) copied.value = ''; }, 2000);
    } catch {
        integrationError.value = 'Alamat tidak bisa disalin otomatis. Salin dari kolom di atasnya.';
    }
}

const GROUPS = {
    contact: { label: 'Kontak', icon: 'chat', text: 'Kontak toko yang tampil ke pelanggan.' },
    reviews: { label: 'Ulasan toko', icon: 'sparkles', text: 'Rating dan jumlah ulasan di halaman produk.' },
    discount: { label: 'Diskon', icon: 'tag', text: 'Batas potongan harga.' },
    inventory: { label: 'Stok', icon: 'warehouse', text: 'Peringatan stok menipis.' },
    checkout: { label: 'Checkout', icon: 'wallet', text: 'Batas waktu bayar dan tahan stok.' },
    antifraud: { label: 'Anti-penyalahgunaan', icon: 'shield', text: 'Pembatasan pesanan per nomor HP.' },
    refund: { label: 'Refund', icon: 'undo', text: 'Batas waktu klaim dan proses refund.' },
    cancellation: { label: 'Pembatalan', icon: 'x', text: 'Batas waktu proses pembatalan.' },
    marketing: { label: 'Konten marketing', icon: 'megaphone', text: 'Target frekuensi konten per minggu (D-062). Hanya Super Admin yang bisa mengubahnya.' },
};
const FIELDS = {
    'contact.whatsapp_number': { label: 'Nomor WhatsApp', hint: 'Format internasional, contoh +6281234567890. Tampil ke pelanggan.', input: 'tel' },
    'contact.admin_email': { label: 'Email admin', hint: 'Tampil ke pelanggan.', input: 'email' },
    'reviews.rating': { label: 'Rating toko (1 sampai 5)', hint: 'Contoh 4.6. Tampil di halaman produk bersama ulasan; samakan dengan sumbernya, mis. Google Maps.', input: 'number' },
    'reviews.count': { label: 'Jumlah ulasan toko', hint: 'Tampil bersama rating.', input: 'number' },
    'antifraud.max_unpaid_orders': { label: 'Maksimal pesanan belum dibayar per nomor HP', hint: 'Pesanan belum dibayar menahan stok. Nomor yang mencapai batas harus membayar atau menunggu pesanan kedaluwarsa.', input: 'number' },
    'antifraud.max_orders_per_day': { label: 'Maksimal pesanan per nomor HP dalam 24 jam', hint: 'Dihitung bergulir 24 jam terakhir.', input: 'number' },
    'discount.max_percent': { label: 'Batas total diskon produk (% dari harga jual)', hint: 'Diskon produk yang berlaku bersamaan ditumpuk, tetapi totalnya tidak boleh melebihi batas ini. Contoh 50: harga Rp100.000 paling murah Rp50.000.', input: 'number' },
    'inventory.low_stock_threshold': { label: 'Batas peringatan stok menipis', hint: 'Admin Toko dan Gudang diberi tahu sekali saat stok tersedia sebuah varian turun ke di bawah angka ini. Bawaan 10, sama dengan label menipis di Ringkasan.', input: 'number' },
    'checkout.reservation_minutes': { label: 'Batas bayar dan tahan stok (menit)', hint: 'Berlaku untuk pesanan baru. 60 menit = 1 jam.', input: 'number' },
    'refund.claim_window_hours': { label: 'Batas klaim refund (jam)', hint: 'Dihitung sejak barang diterima. 168 jam = 7 hari.', input: 'number' },
    'refund.return_window_days': { label: 'Batas retur produk utuh (hari kalender)', hint: 'Dihitung sejak paket diterima (D-097). Bawaan 3. Klaim barang rusak atau salah kirim memakai batas klaim di atas.', input: 'number' },
    'refund.review_sla_hours': { label: 'Batas tinjauan refund (jam)', hint: 'Boleh kosong sampai kebijakan refund disetujui.', input: 'number' },
    'refund.payout_days_max': { label: 'Batas proses refund (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
    'refund.terms_version': { label: 'Versi syarat refund', hint: 'Diisi otomatis setiap Syarat dan Ketentuan diubah (menu Syarat dan Ketentuan). Tidak perlu diubah di sini.', input: 'text' },
    'cancellation.process_days_max': { label: 'Batas proses pembatalan (hari)', hint: 'Sejak pengajuan sampai dana diterima pelanggan.', input: 'number' },
    'marketing.posts_per_week': { label: 'Target posting per minggu', hint: 'Caption dan kalender konten. Bawaan 3. Tampil di halaman Draf Konten sebagai target, tidak memblokir apa pun.', input: 'number' },
    'marketing.videos_per_week': { label: 'Target video per minggu', hint: 'Skrip video. Bawaan 2 (D-062: 1 sampai 2 video). Hanya target tampilan.', input: 'number' },
};

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

const settings = ref([]);
const loading = ref(true);
const loadError = ref('');
const rows = reactive({});

const groups = computed(() => Object.entries(GROUPS).map(([key, meta]) => ({
    key,
    ...meta,
    items: settings.value.filter((setting) => setting.group === key && FIELDS[setting.key]),
})).filter((group) => group.items.length > 0));

const isDirty = (key) => String(rows[key].value) !== String(rows[key].saved);

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api.get('/admin/settings');
        settings.value = data.data.settings;
        settings.value.forEach((setting) => {
            rows[setting.key] = { value: setting.value ?? '', saved: setting.value ?? '', busy: false, error: '', success: false, updatedAt: setting.updated_at };
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
        row.updatedAt = data.data.updated_at ?? new Date().toISOString();
        row.success = true;
    } catch (e) {
        const { message, fields } = apiError(e);
        row.error = fields.value?.[0] || message;
    } finally {
        row.busy = false;
    }
}

onMounted(() => {
    load();
    loadIntegrations();
    loadSystemHealth();
});
</script>

<template>
  <PageHeader title="Pengaturan" description="Setiap perubahan tercatat di audit log. Admin Toko hanya melihat dan mengubah kontak." />

  <!-- memuat: kerangka seukuran isi supaya halaman tidak melompat -->
  <div v-if="loading" role="status" aria-label="Memuat pengaturan" class="mt-space-lg space-y-space-md">
    <div v-for="n in 3" :key="n" class="h-52 animate-pulse-soft rounded-3xl bg-secondary-tint-12"></div>
  </div>

  <div v-else-if="loadError" role="alert" class="mt-space-lg flex flex-wrap items-center gap-3 rounded-2xl bg-error-surface p-4 text-on-error-surface">
    <AdminIcon name="alert" />
    <span class="flex-1">{{ loadError }}</span>
    <button type="button" :class="`rounded-full border border-on-error-surface px-4 py-1.5 text-label-md hover:bg-error/10 ${press}`" @click="load">Coba lagi</button>
  </div>

  <div v-else class="mt-space-lg">
    <div class="space-y-space-lg">
      <section v-if="auth.can('settings.manage')" id="sistem" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="title-system">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="warehouse" :size="18" /></span>
            <div>
              <h2 id="title-system" class="font-heading text-headline-sm">Kesehatan sistem</h2>
              <p class="text-body-sm text-secondary">Database, cache, penyimpanan, disk, konfigurasi, penjadwal, dan antrean. Semua dicek di server ini.</p>
            </div>
          </div>
          <button type="button" :disabled="systemBusy" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="loadSystemHealth(true)">
            <AdminIcon name="check" :size="16" />{{ systemBusy ? 'Memeriksa...' : 'Periksa ulang' }}
          </button>
        </div>

        <p v-if="systemError" role="alert" class="mt-space-sm flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ systemError }}</p>

        <ul class="mt-space-md divide-y divide-border border-t border-border">
          <li v-for="check in systemChecks" :key="check.key" class="flex flex-wrap items-start gap-x-3 gap-y-1 py-space-sm">
            <span class="w-28 shrink-0 text-label-md">{{ check.label }}</span>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-label-md" :class="STATUS[check.status].chip">{{ STATUS[check.status].text }}</span>
            <span class="min-w-0 flex-1 basis-60 text-body-sm text-secondary">{{ check.detail }}</span>
            <button v-if="check.key === 'log_errors' && check.status !== 'ok'" type="button" :disabled="systemBusy" :class="`shrink-0 rounded-full border border-border px-3 py-1 text-label-md hover:bg-accent-tint-15 ${press}`" @click="acknowledgeLog">Tandai sudah ditangani</button>
          </li>
        </ul>
        <p v-if="systemCheckedAt" class="mt-space-sm text-label-sm text-secondary">Diperiksa {{ formatDate(systemCheckedAt) }}</p>
      </section>

      <section v-if="auth.can('settings.manage') && securityChecks.length" id="keamanan" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="title-security">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="shield" :size="18" /></span>
          <div>
            <h2 id="title-security" class="font-heading text-headline-sm">Keamanan akun</h2>
            <p class="text-body-sm text-secondary">Hanya jumlah dan sebaran. Email, IP, dan password tidak ditampilkan. Batasnya usulan dan tidak memblokir apa pun.</p>
          </div>
        </div>

        <ul class="mt-space-md divide-y divide-border border-t border-border">
          <li v-for="item in securityChecks" :key="item.key" class="flex flex-wrap items-start gap-x-3 gap-y-1 py-space-sm">
            <span class="w-56 shrink-0 text-label-md">{{ item.label }}</span>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-label-md" :class="STATUS[item.status].chip">{{ STATUS[item.status].text }}</span>
            <span class="min-w-0 flex-1 basis-60 text-body-sm text-secondary">{{ item.detail }}</span>
          </li>
        </ul>
        <router-link :to="{ name: 'AdminAudit' }" :class="`mt-space-sm inline-flex rounded-full border border-border px-3 py-1 text-label-md hover:bg-accent-tint-15 ${press}`">Buka audit log</router-link>
      </section>

      <section v-if="auth.can('settings.manage') && stuckOrders.length" id="macet" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="title-stuck">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="receipt" :size="18" /></span>
          <div>
            <h2 id="title-stuck" class="font-heading text-headline-sm">Pesanan macet</h2>
            <p class="text-body-sm text-secondary">Hanya menandai pesanan yang patut dilihat. Batas waktunya usulan dan belum diputuskan tim, jadi bukan SLA. Tidak ada yang diubah otomatis.</p>
          </div>
        </div>

        <ul class="mt-space-md divide-y divide-border border-t border-border">
          <li v-for="item in stuckOrders" :key="item.key" class="flex flex-wrap items-start gap-x-3 gap-y-1 py-space-sm">
            <span class="w-56 shrink-0 text-label-md">{{ item.label }}</span>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-label-md" :class="STATUS[item.status].chip">{{ STATUS[item.status].text }}</span>
            <span class="min-w-0 flex-1 basis-60 text-body-sm text-secondary">{{ item.detail }}</span>
            <router-link v-if="item.count" :to="{ name: item.to }" :class="`shrink-0 rounded-full border border-border px-3 py-1 text-label-md hover:bg-accent-tint-15 ${press}`">Buka</router-link>
          </li>
        </ul>
      </section>

      <section v-if="auth.can('settings.manage')" id="integrasi" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" aria-labelledby="title-integrations">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="shield" :size="18" /></span>
            <div>
              <h2 id="title-integrations" class="font-heading text-headline-sm">Integrasi pembayaran dan pengiriman</h2>
              <p class="text-body-sm text-secondary">Status Midtrans dan Biteship dari konfigurasi, riwayat webhook, dan cek API terakhir. Kunci dan rahasia tidak pernah ditampilkan.</p>
            </div>
          </div>
          <button type="button" :disabled="checking" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`" @click="checkNow">
            <AdminIcon name="check" :size="16" />{{ checking ? 'Memeriksa...' : 'Cek ke API sekarang' }}
          </button>
        </div>

        <p v-if="integrationError" role="alert" class="mt-space-sm flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ integrationError }}</p>

        <!-- Tunnel hanya ada di environment lokal; backend mengirim tunnel_url = null di tempat lain. -->
        <p v-if="tunnelUrl" role="status" class="mt-space-sm flex flex-wrap items-center gap-x-2 gap-y-1 rounded-2xl bg-success-surface p-3 text-body-sm text-success">
          <i class="size-2 rounded-full bg-success" aria-hidden="true"></i>Tunnel lokal aktif: <strong class="break-all">{{ tunnelUrl }}</strong>
          <span class="w-full text-secondary">Alamatnya berganti setiap tunnel dijalankan ulang, jadi tempel ulang alamat webhook di dashboard Midtrans dan Biteship.</span>
        </p>
        <p v-else-if="isLocal" role="note" class="mt-space-sm flex items-start gap-1.5 rounded-2xl bg-accent-tint-15 p-3 text-body-sm"><AdminIcon name="alert" :size="14" class="mt-0.5 shrink-0" />Tunnel lokal tidak terdeteksi, jadi Midtrans dan Biteship tidak bisa mengirim webhook ke komputer ini. Jalankan php artisan dev:tunnel (atau composer dev), lalu muat ulang halaman ini.</p>
        <p v-else-if="!webhookReachable" role="note" class="mt-space-sm flex items-start gap-1.5 rounded-2xl bg-accent-tint-15 p-3 text-body-sm"><AdminIcon name="alert" :size="14" class="mt-0.5 shrink-0" />APP_URL masih menunjuk localhost, jadi Midtrans dan Biteship tidak bisa mengirim webhook ke alamat di bawah. Isi APP_URL dengan domain environment ini, lalu jalankan php artisan config:clear.</p>
        <p v-else class="mt-space-sm text-body-sm text-secondary">Alamat webhook mengikuti APP_URL environment ini (dev dan production masing-masing memakai domainnya sendiri).</p>

        <div v-for="item in integrations" :key="item.key" class="mt-space-md border-t border-border pt-space-md">
          <div class="flex flex-wrap items-center gap-2">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-secondary-tint-12" aria-hidden="true"><AdminIcon :name="GUIDE[item.key].icon" :size="16" /></span>
            <h3 class="font-heading text-title-md">{{ item.title }}</h3>
            <span class="rounded-full px-2.5 py-1 text-label-md" :class="STATUS[item.status].chip">{{ STATUS[item.status].text }}</span>
          </div>

          <ul v-if="item.issues.length" class="mt-space-sm space-y-1">
            <li v-for="issue in item.issues" :key="issue" class="flex items-start gap-1.5 text-body-sm"><AdminIcon name="alert" :size="14" class="mt-0.5 shrink-0" />{{ issue }}</li>
          </ul>
          <p v-else class="mt-space-sm flex items-center gap-1.5 text-body-sm text-success"><AdminIcon name="check" :size="14" />Kunci, mode, dan webhook siap.</p>

          <dl class="mt-space-sm grid gap-1 text-body-sm sm:grid-cols-[12rem_1fr]">
            <dt class="text-secondary">Cek API terakhir</dt>
            <dd>{{ item.checked_at ? `${formatDate(item.checked_at)}: ${item.live?.message}` : 'Belum pernah. Tekan Cek ke API sekarang.' }}</dd>
            <dt class="text-secondary">{{ item.last_label }}</dt>
            <dd>{{ item.last_at ? formatDate(item.last_at) : 'Belum ada' }}</dd>
            <dt class="text-secondary">Webhook ditolak (24 jam)</dt>
            <dd>{{ item.rejected_24h }}</dd>
          </dl>

          <div class="mt-space-sm">
            <label :for="`webhook-${item.key}`" class="block text-label-md">Alamat webhook</label>
            <div class="mt-1 flex gap-2">
              <input :id="`webhook-${item.key}`" :value="item.webhook_url" readonly class="block min-w-0 flex-1 rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md">
              <button type="button" :class="`inline-flex min-h-11 shrink-0 items-center rounded-full border border-border px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="copyUrl(item.webhook_url)">{{ copied === item.webhook_url ? 'Tersalin' : 'Salin' }}</button>
            </div>
          </div>

          <details class="mt-space-sm text-body-sm">
            <summary class="cursor-pointer text-label-md">Cara menangani</summary>
            <ol class="mt-1 list-decimal space-y-1 pl-5 text-secondary">
              <li v-for="step in GUIDE[item.key].steps" :key="step">{{ step }}</li>
            </ol>
          </details>
        </div>

        <div v-if="services.length" class="mt-space-md border-t border-border pt-space-md">
          <h3 class="font-heading text-title-md">Layanan pendukung</h3>
          <p class="text-body-sm text-secondary">Email, OTP WhatsApp, Turnstile, dan Claude API. Layanan yang memang belum dipakai tidak dihitung masalah.</p>
          <ul class="mt-space-sm divide-y divide-border">
            <li v-for="svc in services" :key="svc.key" class="flex flex-wrap items-start gap-x-3 gap-y-1 py-space-sm">
              <span class="w-28 shrink-0 text-label-md">{{ svc.title }}</span>
              <span class="shrink-0 rounded-full px-2.5 py-1 text-label-md" :class="STATUS[svc.status].chip">{{ STATUS[svc.status].text }}</span>
              <span class="min-w-0 flex-1 basis-60 text-body-sm text-secondary">
                <template v-if="svc.issues.length">{{ svc.issues.join('. ') }}.</template>
                <template v-else>{{ svc.note || 'Konfigurasi lengkap.' }}</template>
                <template v-if="svc.live && svc.live.status !== 'skipped'"> Cek API: {{ svc.live.message }}.</template>
              </span>
            </li>
          </ul>
          <p class="mt-space-sm text-label-sm text-secondary">{{ servicesCheckedAt ? `Cek API terakhir ${formatDate(servicesCheckedAt)}.` : 'Cek API belum pernah dijalankan. Tekan Cek ke API sekarang.' }}</p>
        </div>
      </section>

      <section v-for="group in groups" :key="group.key" class="rounded-3xl border border-border bg-surface p-space-lg shadow-card" :aria-labelledby="`title-${group.key}`">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon :name="group.icon" :size="18" /></span>
          <div><h2 :id="`title-${group.key}`" class="font-heading text-headline-sm">{{ group.label }}</h2><p class="text-body-sm text-secondary">{{ group.text }}</p></div>
        </div>

        <form
          v-for="setting in group.items"
          :key="setting.key"
          class="mt-space-md grid gap-space-sm border-t border-border pt-space-md sm:grid-cols-[1fr_minmax(0,20rem)] sm:gap-space-lg"
          @submit.prevent="save(setting.key)"
        >
          <div class="min-w-0">
            <label :for="setting.key" class="block text-label-md">{{ FIELDS[setting.key].label }}</label>
            <p :id="`${setting.key}-hint`" class="mt-1 text-body-sm text-secondary">{{ FIELDS[setting.key].hint }}</p>
            <p v-if="rows[setting.key].updatedAt" class="mt-1 text-label-sm text-secondary">Terakhir diubah {{ formatDate(rows[setting.key].updatedAt) }}</p>
          </div>
          <div class="min-w-0">
            <div class="flex gap-2">
              <input
                :id="setting.key"
                v-model="rows[setting.key].value"
                :type="FIELDS[setting.key].input"
                step="any"
                :aria-invalid="!!rows[setting.key].error"
                :aria-describedby="`${setting.key}-hint`"
                class="block min-w-0 flex-1 rounded-2xl border bg-surface px-3 py-2 text-body-md transition-colors duration-150 focus:outline-2 focus:outline-primary"
                :class="rows[setting.key].error ? 'border-error' : isDirty(setting.key) ? 'border-primary' : 'border-secondary'"
                @input="rows[setting.key].success = false"
              >
              <button
                :disabled="rows[setting.key].busy || !isDirty(setting.key)"
                :class="`inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover disabled:bg-secondary-tint-12 disabled:text-secondary ${press}`"
              >
                <AdminIcon name="check" :size="16" />{{ rows[setting.key].busy ? 'Menyimpan...' : 'Simpan' }}
              </button>
            </div>
            <p v-if="rows[setting.key].error" role="alert" class="mt-1.5 flex items-center gap-1 text-body-sm text-error"><AdminIcon name="alert" :size="14" />{{ rows[setting.key].error }}</p>
            <p v-else-if="isDirty(setting.key)" class="mt-1.5 flex items-center gap-1.5 text-body-sm text-warning"><i class="size-1.5 rounded-full bg-warning" aria-hidden="true"></i>Belum disimpan</p>
            <p v-else-if="rows[setting.key].success" role="status" class="mt-1.5 flex items-center gap-1 text-body-sm text-success"><AdminIcon name="check" :size="14" />Tersimpan.</p>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
