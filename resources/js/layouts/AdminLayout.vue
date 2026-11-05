<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from '../components/AdminIcon.vue';
import { useAuthStore } from '../stores/auth';
import { menuFor, OVERVIEW, ROLE_LABELS } from '../utils/adminMenu';

// Widget CS hanya untuk Pemilik dan Super Admin. Dimuat terpisah supaya role lain tidak mengunduhnya.
const CustomerServiceWidget = defineAsyncComponent(() => import('../components/customer-service/CustomerServiceWidget.vue'));
const CS_WIDGET_ROLES = ['owner', 'super-admin'];

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const panelOpen = ref(false);
const profileOpen = ref(false);
const logoutFailed = ref(false);
const desktop = ref(true);
const panel = ref(null);
const panelTrigger = ref(null);
const profileWrap = ref(null);
const profileButton = ref(null);

const menu = computed(() => menuFor(auth.permissions));
const roleLabel = computed(() => ROLE_LABELS[auth.activeRole] || 'Tanpa role');
// Dock hanya berisi tautan; menu yang belum dibangun tampil sebagai "Segera" di panel menu.
const railItems = computed(() => [OVERVIEW, ...menu.value.flatMap((group) => group.items)].filter((item) => item.to));

const isActive = (item) => !!item.to && [route.name, route.meta.menu].includes(item.to.name);

// Lokasi untuk bilah atas dan judul tab: "Operasional / Pesanan".
const crumb = computed(() => {
    if (route.meta.crumb) return route.meta.crumb;
    if (route.name === 'Dashboard') return ['Utama', 'Ringkasan'];
    if (route.name === 'AccessDenied') return ['Akses', 'Akses ditolak'];
    for (const group of menu.value) {
        const found = group.items.find(isActive);
        if (found) return [group.label, found.label];
    }
    return ['Admin', 'Halaman'];
});

watch(crumb, ([, page]) => { document.title = `${page} · Admin Tuffero`; }, { immediate: true });
watch(() => route.fullPath, () => { panelOpen.value = false; profileOpen.value = false; });

// ---- tooltip dock: satu elemen fixed, jadi tidak terpotong dan tidak tertutup konten ----
// Tunda 300 ms di item pertama; pindah ke item lain langsung tampil tanpa animasi.
const tip = reactive({ show: false, label: '', x: 0, y: 0, instant: false });
let hovering = false;
let tipTimer;
let warmTimer;

function showTip(e, label) {
    const box = e.currentTarget.getBoundingClientRect();
    clearTimeout(tipTimer);
    clearTimeout(warmTimer);
    Object.assign(tip, { label, x: box.right + 12, y: box.top + box.height / 2, instant: hovering && tip.show });
    const warm = hovering;
    hovering = true;

    if (warm || e.type === 'focusin') tip.show = true;
    else tipTimer = setTimeout(() => { tip.show = true; }, 300);
}

function hideTip() {
    clearTimeout(tipTimer);
    clearTimeout(warmTimer);
    tip.show = false;
    warmTimer = setTimeout(() => { hovering = false; }, 300);
}

// ---- panel menu: drawer di mobile, panel melayang di desktop ----
async function openPanel() {
    panelOpen.value = true;
    await nextTick();
    panel.value?.querySelector('a, button')?.focus();
}

function closePanel() {
    panelOpen.value = false;
    nextTick(() => panelTrigger.value?.focus());
}

function togglePanel(trigger) {
    panelTrigger.value = trigger;
    panelOpen.value ? closePanel() : openPanel();
}

function onKeydown(e) {
    if (e.key === 'Escape') {
        if (profileOpen.value) {
            profileOpen.value = false;
            profileButton.value?.focus();
        } else if (panelOpen.value) {
            closePanel();
        }
        return;
    }

    // Di mobile panel menjadi dialog modal, jadi Tab berputar di dalamnya.
    if (e.key === 'Tab' && panelOpen.value && !desktop.value && panel.value) {
        const focusable = [...panel.value.querySelectorAll('a[href], button:not([disabled])')];
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
}

// ---- menu profil: fokus ke item pertama, panah atas dan bawah berputar, Tab menutup ----
const profileMenu = ref(null);

async function toggleProfile(viaKeyboard) {
    profileOpen.value = !profileOpen.value;
    if (profileOpen.value && viaKeyboard) {
        await nextTick();
        profileMenu.value?.querySelector('[role="menuitem"]')?.focus();
    }
}

function onProfileKeydown(e) {
    const items = [...profileMenu.value.querySelectorAll('[role="menuitem"]')];
    const at = items.indexOf(document.activeElement);
    const go = { ArrowDown: (at + 1) % items.length, ArrowUp: (at - 1 + items.length) % items.length, Home: 0, End: items.length - 1 }[e.key];

    if (go !== undefined) {
        e.preventDefault();
        items[go].focus();
    } else if (e.key === 'Tab') {
        profileOpen.value = false;
    }
}

function onPointerDown(e) {
    if (profileOpen.value && profileWrap.value && !profileWrap.value.contains(e.target)) profileOpen.value = false;
}

let query;
const syncViewport = () => { desktop.value = query.matches; };

onMounted(() => {
    query = window.matchMedia('(min-width: 1024px)');
    syncViewport();
    query.addEventListener('change', syncViewport);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('pointerdown', onPointerDown);
});

onBeforeUnmount(() => {
    query?.removeEventListener('change', syncViewport);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown);
    clearTimeout(warmTimer);
    clearTimeout(tipTimer);
});

async function logout() {
    logoutFailed.value = false;
    try {
        await auth.logout();
        router.push({ name: 'Login' });
    } catch (e) {
        logoutFailed.value = true;
    }
}

const initials = computed(() => (auth.user?.name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
</script>

<template>
  <div class="min-h-screen bg-background font-sans text-body-md text-primary">
    <a href="#admin-main" class="sr-only z-[60] rounded-full bg-primary px-4 py-2 text-label-md text-background focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus-visible:outline-2 focus-visible:outline-accent">Lewati ke konten</a>
    <p class="sr-only" role="status" aria-live="polite">{{ crumb[1] }}</p>

    <!-- Bilah atas melayang -->
    <div class="sticky top-0 z-30 px-3 pt-3 lg:px-5 lg:pt-4">
    <header class="flex h-14 items-center gap-2 rounded-full border border-border bg-surface/95 pl-2 pr-2 shadow-card backdrop-blur-md sm:gap-3 lg:pl-3">
      <button
        type="button"
        class="flex size-10 shrink-0 items-center justify-center rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] lg:hidden"
        :aria-expanded="panelOpen"
        aria-controls="admin-menu-panel"
        aria-label="Buka menu"
        @click="togglePanel($event.currentTarget)"
      >
        <AdminIcon name="menu" />
      </button>

      <router-link :to="{ name: 'Dashboard' }" class="flex shrink-0 items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Tuffero, ke Ringkasan">
        <img src="/images/Logo/Tuffero Script Logo Black - NoBckgrnd.webp" alt="" class="h-8 w-auto lg:ml-1">
      </router-link>

      <nav class="mx-auto hidden min-w-0 items-center gap-2 rounded-full border border-border bg-background px-4 py-2 text-label-md md:flex" aria-label="Lokasi">
        <span class="truncate text-secondary">{{ crumb[0] }}</span>
        <span class="text-secondary" aria-hidden="true">/</span>
        <span class="truncate" aria-current="page">{{ crumb[1] }}</span>
      </nav>

      <div ref="profileWrap" class="relative ml-auto md:ml-0">
        <button
          ref="profileButton"
          type="button"
          class="flex h-10 items-center gap-2 rounded-full py-1 pl-1 pr-2 transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] sm:pr-3"
          aria-haspopup="menu"
          :aria-expanded="profileOpen"
          @click="toggleProfile($event.detail === 0)"
          @keydown.down.prevent="!profileOpen && toggleProfile(true)"
        >
          <span class="flex size-8 items-center justify-center rounded-full bg-primary text-label-md text-accent" aria-hidden="true">{{ initials }}</span>
          <span class="hidden text-left sm:block">
            <span class="block text-body-sm leading-tight text-secondary">Selamat datang</span>
            <span class="block max-w-40 truncate text-label-md leading-tight">{{ auth.user?.name }}</span>
          </span>
          <AdminIcon name="chevron-down" :size="16" class="hidden text-secondary sm:block" />
        </button>

        <Transition enter-active-class="animate-pop" leave-active-class="transition-opacity duration-100 ease-out" leave-to-class="opacity-0">
          <div ref="profileMenu" v-if="profileOpen" role="menu" aria-label="Akun" class="absolute right-0 top-full z-50 mt-2 w-72 origin-top-right rounded-3xl border border-border bg-surface p-2 shadow-pop" @keydown="onProfileKeydown">
            <div class="px-3 pb-3 pt-2" role="presentation">
              <p class="truncate text-label-md">{{ auth.user?.name }}</p>
              <p class="truncate text-body-sm text-secondary">{{ auth.user?.email }}</p>
              <p class="mt-2 inline-block rounded-full bg-accent-tint-20 px-3 py-1 text-label-md">{{ roleLabel }}</p>
            </div>
            <div class="border-t border-border pt-2" role="presentation">
              <router-link
                :to="{ name: 'AdminProfile' }"
                role="menuitem"
                class="flex h-11 items-center gap-3 rounded-2xl px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]"
                :class="route.name === 'AdminProfile' ? 'bg-accent' : ''"
                :aria-current="route.name === 'AdminProfile' ? 'page' : undefined"
              >
                <AdminIcon name="user" />
                Profil
              </router-link>
              <router-link
                v-if="auth.can('settings.manage')"
                :to="{ name: 'AdminSettings' }"
                role="menuitem"
                class="flex h-11 items-center gap-3 rounded-2xl px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]"
                :class="route.name === 'AdminSettings' ? 'bg-accent' : ''"
                :aria-current="route.name === 'AdminSettings' ? 'page' : undefined"
              >
                <AdminIcon name="sliders" />
                Pengaturan
              </router-link>
            </div>
            <div class="mt-2 border-t border-border pt-2" role="presentation">
              <button
                type="button"
                role="menuitem"
                class="flex h-11 w-full items-center gap-3 rounded-2xl px-3 text-left text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]"
                @click="logout"
              >
                <AdminIcon name="logout" />
                Keluar
              </button>
              <p v-if="logoutFailed" role="alert" class="px-3 pb-1 pt-1 text-body-sm text-error">Gagal keluar. Coba lagi.</p>
            </div>
          </div>
        </Transition>
      </div>
    </header>
    </div>

    <div class="flex items-start gap-4 px-3 pb-8 pt-4 lg:px-5">
      <!-- Dock ikon (desktop) -->
      <aside class="sticky top-[92px] z-20 hidden max-h-[calc(100vh-108px)] shrink-0 flex-col gap-3 lg:flex [@media(max-height:820px)]:gap-2" aria-label="Navigasi cepat">
        <!-- Perluas menu di paling atas, tema di bawahnya -->
        <div class="flex shrink-0 flex-col gap-2 rounded-[28px] border border-border bg-surface p-2 shadow-card [@media(max-height:820px)]:gap-1.5">
          <div @pointerenter="showTip($event, 'Perluas menu')" @pointerleave="hideTip" @focusin="showTip($event, 'Perluas menu')" @focusout="hideTip">
            <button
              type="button"
              class="flex size-10 items-center justify-center rounded-2xl text-secondary transition-[transform,background-color,color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] [@media(max-height:820px)]:size-9"
              :aria-expanded="panelOpen"
              aria-controls="admin-menu-panel"
              aria-label="Perluas menu"
              @click="hideTip(); togglePanel($event.currentTarget)"
            >
              <AdminIcon name="expand" />
            </button>
          </div>

          <!-- Tema gelap dan terang: tombolnya sudah ada, fungsinya masih akan datang -->
          <div @pointerenter="showTip($event, 'Tema gelap dan terang · Segera')" @pointerleave="hideTip" @focusin="showTip($event, 'Tema gelap dan terang · Segera')" @focusout="hideTip">
            <button
              type="button"
              class="flex size-10 cursor-not-allowed items-center justify-center rounded-2xl text-secondary/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary [@media(max-height:820px)]:size-9"
              aria-disabled="true"
              aria-label="Tema gelap dan terang, segera hadir"
              @click.prevent
            >
              <AdminIcon name="sun" />
            </button>
          </div>
        </div>

        <!-- Bila daftar lebih tinggi dari layar, hanya bagian ini yang menggulir; tooltip fixed jadi tidak ikut terpotong -->
        <nav class="flex min-h-0 flex-col gap-2 overflow-y-auto overscroll-contain rounded-[28px] border border-border bg-surface p-2 shadow-card [scrollbar-width:none] [@media(max-height:820px)]:gap-1.5" aria-label="Menu admin" @scroll.passive="hideTip">
          <router-link
            v-for="item in railItems"
            :key="item.label"
            v-slot="{ navigate }"
            :to="item.to"
            custom
          >
            <div class="shrink-0" @pointerenter="showTip($event, item.label)" @pointerleave="hideTip" @focusin="showTip($event, item.label)" @focusout="hideTip">
              <a
                :href="router.resolve(item.to).href"
                class="flex size-10 items-center justify-center rounded-2xl transition-[transform,background-color,color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] [@media(max-height:820px)]:size-9"
                :class="isActive(item) ? 'bg-accent text-primary' : 'text-secondary hover:bg-accent-tint-15 hover:text-primary'"
                :aria-current="isActive(item) ? 'page' : undefined"
                :aria-label="item.label"
                @click="hideTip(); navigate($event)"
              >
                <AdminIcon :name="item.icon" />
              </a>
            </div>
          </router-link>
        </nav>
      </aside>

      <span
        class="pointer-events-none fixed z-[60] hidden -translate-y-1/2 whitespace-nowrap rounded-xl bg-primary px-3 py-1.5 text-label-md text-background shadow-pop lg:block"
        :class="[tip.show ? 'translate-x-0 opacity-100' : '-translate-x-1 opacity-0', tip.instant ? 'transition-none' : 'transition-[opacity,transform] duration-150 ease-out-strong']"
        :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
        role="presentation"
        aria-hidden="true"
      >{{ tip.label }}</span>

      <main id="admin-main" tabindex="-1" class="min-w-0 flex-1 focus:outline-none">
        <div class="mx-auto max-w-[1328px]">
          <router-view></router-view>
        </div>
      </main>
    </div>

    <!-- Menu diperluas: panel melayang di desktop, drawer di mobile -->
    <Transition enter-active-class="transition-opacity duration-200 ease-out-strong" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150 ease-out-strong" leave-to-class="opacity-0">
      <div v-if="panelOpen" class="fixed inset-0 z-40 bg-primary/40 lg:bg-transparent" aria-hidden="true" @click="closePanel"></div>
    </Transition>

    <Transition
      enter-active-class="transition-[opacity,transform] duration-300 ease-drawer"
      enter-from-class="-translate-x-4 opacity-0"
      leave-active-class="transition-[opacity,transform] duration-200 ease-out-strong"
      leave-to-class="-translate-x-2 opacity-0"
    >
      <aside
        v-if="panelOpen"
        id="admin-menu-panel"
        ref="panel"
        class="fixed inset-y-3 left-3 z-50 flex w-72 max-w-[calc(100vw-1.5rem)] origin-left flex-col rounded-[28px] border border-border bg-surface shadow-float"
        :role="desktop ? 'navigation' : 'dialog'"
        :aria-modal="desktop ? undefined : 'true'"
        aria-label="Menu admin"
      >
        <div class="flex items-center justify-between px-5 pb-2 pt-5">
          <img src="/images/Logo/Tuffero Script Logo Black - NoBckgrnd.webp" alt="Tuffero" class="h-8 w-auto">
          <button
            type="button"
            class="flex size-10 items-center justify-center rounded-full text-secondary transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97]"
            aria-label="Tutup menu"
            @click="closePanel"
          >
            <AdminIcon name="collapse" />
          </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-4 [scrollbar-color:var(--color-border)_transparent] [scrollbar-width:thin]" aria-label="Semua menu">
          <router-link
            :to="OVERVIEW.to"
            class="mt-1 flex h-11 items-center gap-3 rounded-2xl px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]"
            :class="isActive(OVERVIEW) ? 'bg-accent' : ''"
            :aria-current="isActive(OVERVIEW) ? 'page' : undefined"
          >
            <AdminIcon :name="OVERVIEW.icon" />
            {{ OVERVIEW.label }}
          </router-link>

          <div v-for="group in menu" :key="group.label" class="mt-4">
            <p class="px-3 pb-1 text-label-sm uppercase text-secondary">{{ group.label }}</p>
            <ul class="flex flex-col gap-0.5">
              <li v-for="item in group.items" :key="item.label">
                <router-link
                  v-if="item.to"
                  :to="item.to"
                  class="flex h-11 items-center gap-3 rounded-2xl px-3 text-label-md transition-[transform,background-color] duration-[160ms] ease-out-strong hover:bg-accent-tint-15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.98]"
                  :class="isActive(item) ? 'bg-accent' : ''"
                  :aria-current="isActive(item) ? 'page' : undefined"
                >
                  <AdminIcon :name="item.icon" />
                  {{ item.label }}
                </router-link>
                <div v-else class="flex h-11 items-center gap-3 rounded-2xl px-3 text-label-md text-secondary">
                  <AdminIcon :name="item.icon" />
                  {{ item.label }}
                  <span class="ml-auto rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm normal-case">Segera</span>
                </div>
              </li>
            </ul>
          </div>
        </nav>
      </aside>
    </Transition>

    <CustomerServiceWidget v-if="CS_WIDGET_ROLES.includes(auth.activeRole)" />
  </div>
</template>
