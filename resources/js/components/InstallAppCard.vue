<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AdminIcon from './AdminIcon.vue';
import { installState, onInstallChange, promptInstall } from '../pwaInstall';

// Pasang Tuffero Admin (D-144). Tampil hanya bila Super Admin menyalakan aplikasi admin.
// admin.blade.php memuat manifest untuk staf yang sudah masuk; setelah masuk lewat SPA (tanpa muat ulang) tag itu belum ada, jadi dipasang di sini.
const MANIFEST = '/admin/manifest.webmanifest';
const available = ref(false);
const state = ref(installState());
const busy = ref(false);
let stop = () => {};

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';

function addHead(tag, attrs) {
    const el = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, value));
    document.head.appendChild(el);
}

onMounted(async () => {
    stop = onInstallChange(() => { state.value = installState(); });
    if (document.querySelector('link[rel="manifest"]')) {
        available.value = true;
        return;
    }
    try {
        const res = await fetch(MANIFEST, { method: 'HEAD', credentials: 'same-origin' });
        if (!res.ok) return;
        addHead('link', { rel: 'manifest', href: MANIFEST });
        addHead('meta', { name: 'theme-color', content: '#2B1B14' });
        addHead('link', { rel: 'apple-touch-icon', href: '/images/pwa/admin-apple-180.png' });
        available.value = true;
    } catch {
        // Gagal memeriksa: kartu tetap tersembunyi.
    }
});
onBeforeUnmount(() => stop());

async function install() {
    busy.value = true;
    try {
        await promptInstall();
    } finally {
        busy.value = false;
        state.value = installState();
    }
}
</script>

<template>
  <section v-if="available" class="animate-rise rounded-card border border-border bg-surface p-6 shadow-card" style="animation-delay: 280ms" aria-labelledby="install-title">
    <div class="flex items-start gap-3">
      <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-accent-tint-15" aria-hidden="true"><AdminIcon name="sparkles" :size="18" /></span>
      <div><h2 id="install-title" class="font-heading text-headline-sm">Aplikasi Tuffero Admin</h2><p class="text-body-sm text-secondary">Pasang dashboard di layar utama HP atau laptop, lalu buka tanpa bilah browser seperti aplikasi.</p></div>
    </div>
    <div class="mt-4" aria-live="polite">
      <p v-if="state === 'installed'" class="flex items-center gap-2 rounded-2xl bg-success-surface px-4 py-3 text-body-sm text-success"><AdminIcon name="check" :size="16" />Tuffero Admin sudah terpasang di perangkat ini.</p>
      <button v-else-if="state === 'ready'" type="button" :disabled="busy" :class="`inline-flex min-h-11 items-center gap-1.5 rounded-full bg-primary px-5 text-label-md text-surface hover:bg-primary/90 ${press}`" @click="install"><AdminIcon name="plus" :size="16" />Pasang aplikasi</button>
      <p v-else-if="state === 'ios'" class="rounded-2xl bg-accent-tint-15 px-4 py-3 text-body-sm">Di iPhone atau iPad: buka halaman ini di Safari, ketuk tombol <strong>Bagikan</strong>, lalu pilih <strong>Tambahkan ke Layar Utama</strong>.</p>
      <p v-else class="rounded-2xl bg-accent-tint-15 px-4 py-3 text-body-sm">Tombol pasang belum tersedia di browser ini. Buka di Chrome atau Edge, atau aplikasi mungkin sudah terpasang di perangkat ini.</p>
    </div>
  </section>
</template>
