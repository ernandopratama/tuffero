<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../utils/api';
import { playChime, primeAudio } from '../utils/chime';
import { timeAgo } from '../utils/datetime';
import AdminIcon from './AdminIcon.vue';

// Lonceng notifikasi bilah atas. Polling 30 detik saat tab terlihat; nada ringan dan goyangan lonceng hanya
// muncul bila ada notifikasi belum dibaca yang baru (bukan saat halaman pertama dimuat).
const POLL_MS = 30000;
// Halaman tujuan per jenis. Pesanan: Gudang tidak punya halaman Pesanan, jadi dibawa ke Pengemasan.
const KINDS = {
    order: { label: 'pesanan', route: (auth) => (auth.can('orders.view') ? 'AdminOrders' : 'AdminFulfillment') },
    voucher: { label: 'voucher', route: () => 'AdminVouchers' },
    content: { label: 'konten promo', route: () => 'AdminContents' },
    video: { label: 'video', route: () => 'AdminGallery' },
    cancellation: { label: 'pembatalan', route: () => 'AdminCancellations' },
    refund: { label: 'refund', route: () => 'AdminRefunds' },
    stock: { label: 'stok', route: () => 'AdminStock' },
    security: { label: 'keamanan', route: () => 'AdminProfile' },
    draft: { label: 'draf konten', route: () => 'AdminMarketingDrafts' },
};

const router = useRouter();
const auth = useAuthStore();
const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const unread = ref(0);
const open = ref(false);
const ringing = ref(false);
const announce = ref('');
const sound = ref(true);
const wrap = ref(null);
const button = ref(null);

try { sound.value = localStorage.getItem('tf_notif_sound') !== 'off'; } catch { /* penyimpanan tidak tersedia: tetap hidup */ }

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));
const bellLabel = computed(() => (unread.value ? `Notifikasi, ${unread.value} belum dibaca` : 'Notifikasi'));

let timer;
let ringTimer;
let first = true;
let latestUnread = null;

// Judul dibuat server; notifikasi lama (sebelum ada judul) disusun dari field aslinya.
const titleOf = (item) => item.title || `${item.by} menyebut Anda di ${KINDS[item.kind]?.label || 'catatan'} ${item.label}`;

async function load(page = meta.value.current_page) {
    let latest = null;
    try {
        const { data } = await api.get('/admin/notifications', { params: { page } });
        items.value = data.data.items;
        meta.value = data.data.meta;
        unread.value = data.data.unread_count;
        latest = data.data.latest_unread_at;
    } catch {
        return; // jaringan atau sesi bermasalah: pertahankan data terakhir, coba lagi pada polling berikutnya
    }
    // Ada yang baru bila waktu notifikasi belum dibaca terbaru berubah, di halaman daftar mana pun.
    const isNew = !first && latest && latest !== latestUnread;
    latestUnread = latest;
    first = false;
    if (isNew) {
        announce.value = meta.value.current_page === 1 && items.value[0] ? `Notifikasi baru: ${titleOf(items.value[0])}` : 'Notifikasi baru';
        ringing.value = true;
        clearTimeout(ringTimer);
        ringTimer = setTimeout(() => { ringing.value = false; }, 800);
        if (sound.value) playChime();
    }
}

function toggle() {
    open.value = !open.value;
    if (open.value) load(1);
}

function close(refocus = false) {
    open.value = false;
    if (refocus) button.value?.focus();
}

async function openItem(item) {
    close();
    const kind = KINDS[item.kind];
    if (!item.read_at) {
        item.read_at = new Date().toISOString();
        unread.value = Math.max(0, unread.value - 1);
        api.post(`/admin/notifications/${item.id}/read`).catch(() => load());
    }
    if (kind) router.push({ name: kind.route(auth), query: { cari: item.label } });
}

async function readAll() {
    try {
        await api.post('/admin/notifications/read');
        await load();
    } catch { /* gagal: tombol bisa ditekan lagi */ }
}

function toggleSound() {
    sound.value = !sound.value;
    try { localStorage.setItem('tf_notif_sound', sound.value ? 'on' : 'off'); } catch { /* abaikan */ }
    if (sound.value) { primeAudio(); playChime(); }
}

const onKeydown = (e) => { if (e.key === 'Escape' && open.value) { e.stopImmediatePropagation(); close(true); } };
const onPointerDown = (e) => { if (open.value && wrap.value && !wrap.value.contains(e.target)) close(); };
const onVisible = () => { if (document.visibilityState === 'visible') load(); };

onMounted(() => {
    load();
    timer = setInterval(() => { if (document.visibilityState === 'visible') load(); }, POLL_MS);
    document.addEventListener('visibilitychange', onVisible);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('pointerdown', onPointerDown);
    // Siapkan audio pada interaksi pertama mana pun (aturan autoplay browser).
    document.addEventListener('pointerdown', primeAudio, { once: true });
    document.addEventListener('keydown', primeAudio, { once: true });
});

onBeforeUnmount(() => {
    clearInterval(timer);
    clearTimeout(ringTimer);
    document.removeEventListener('visibilitychange', onVisible);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown);
});
</script>

<template>
  <div ref="wrap" class="relative">
    <button
      ref="button"
      type="button"
      class="relative flex size-10 items-center justify-center rounded-full text-secondary transition-[transform,background-color,color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
      :aria-expanded="open"
      aria-controls="notification-panel"
      :aria-label="bellLabel"
      @click="toggle"
    >
      <AdminIcon name="bell" :class="ringing ? 'motion-safe:animate-ring' : ''" />
      <span v-if="unread" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 animate-pop items-center justify-center rounded-full bg-error px-1 text-label-sm text-surface ring-2 ring-surface" aria-hidden="true">{{ badge }}</span>
    </button>
    <span class="sr-only" role="status" aria-live="polite">{{ announce }}</span>

    <Transition enter-active-class="animate-pop" leave-active-class="transition-opacity duration-100 ease-out" leave-to-class="opacity-0">
      <section v-if="open" id="notification-panel" aria-label="Notifikasi" class="absolute right-0 top-full z-50 mt-2 w-[min(24rem,calc(100vw-1.5rem))] origin-top-right rounded-3xl border border-border bg-surface shadow-float">
        <header class="flex items-center gap-2 border-b border-border px-4 py-3">
          <div class="min-w-0 flex-1">
            <h2 class="font-heading text-headline-sm">Notifikasi</h2>
            <p class="text-label-sm text-secondary">{{ meta.total }} notifikasi<template v-if="unread"> · {{ unread }} belum dibaca</template></p>
          </div>
          <button v-if="unread" type="button" class="rounded-full px-3 py-1.5 text-label-md text-secondary transition-colors hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="readAll">Tandai semua dibaca</button>
          <button
            type="button"
            class="flex size-9 items-center justify-center rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary active:scale-[0.97]"
            :aria-pressed="sound"
            :aria-label="sound ? 'Suara notifikasi menyala' : 'Suara notifikasi mati'"
            @click="toggleSound"
          >
            <AdminIcon :name="sound ? 'volume' : 'volume-off'" :size="18" />
          </button>
        </header>

        <p v-if="!items.length" class="px-4 py-10 text-center text-body-sm text-secondary">Belum ada notifikasi.</p>
        <ul v-else class="divide-y divide-border p-1">
          <li v-for="item in items" :key="item.id">
            <button type="button" class="flex w-full items-start gap-3 rounded-2xl px-3 py-3 text-left transition-colors hover:bg-accent-tint-12 focus-visible:outline-2 focus-visible:outline-primary" @click="openItem(item)">
              <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :class="item.read_at ? 'bg-transparent' : 'bg-error'" :aria-label="item.read_at ? undefined : 'Belum dibaca'"></span>
              <span class="min-w-0 flex-1 text-body-sm">
                <span class="block text-label-md">{{ titleOf(item) }}</span>
                <span class="mt-0.5 line-clamp-2 block break-words text-secondary">{{ item.excerpt }}</span>
                <span class="mt-1 block text-label-sm text-secondary">{{ timeAgo(item.created_at) }}</span>
              </span>
            </button>
          </li>
        </ul>

        <footer v-if="meta.last_page > 1" class="flex items-center justify-between gap-2 border-t border-border px-3 py-2 text-label-sm text-secondary">
          <button type="button" :disabled="meta.current_page <= 1" class="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40 disabled:hover:bg-transparent" aria-label="Halaman sebelumnya" @click="load(meta.current_page - 1)">
            <AdminIcon name="chevron-down" :size="16" class="rotate-90" />
          </button>
          <span>Halaman {{ meta.current_page }} dari {{ meta.last_page }}</span>
          <button type="button" :disabled="meta.current_page >= meta.last_page" class="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40 disabled:hover:bg-transparent" aria-label="Halaman berikutnya" @click="load(meta.current_page + 1)">
            <AdminIcon name="chevron-down" :size="16" class="-rotate-90" />
          </button>
        </footer>
      </section>
    </Transition>
  </div>
</template>
