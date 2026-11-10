<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from './AdminIcon.vue';
import { OVERVIEW } from '../utils/adminMenu';

// Navbar bawah admin, hanya di smartphone (di bawah 768px, lewat CSS). Tablet dan desktop tetap memakai dock dan drawer.
// Kapsul berisi Ringkasan + 3 menu pertama yang boleh dibuka role ini; sisanya ada di menu cepat yang tumbuh dari kapsul.
const props = defineProps({
    menu: { type: Array, required: true },
    isActive: { type: Function, required: true },
});

const route = useRoute();
const open = ref(false);
const panel = ref(null);
const fab = ref(null);

const built = computed(() => props.menu.map((group) => ({ ...group, items: group.items.filter((item) => item.to) })));
const tabs = computed(() => [OVERVIEW, ...built.value.flatMap((group) => group.items)].slice(0, 4));
const rest = computed(() => built.value
    .map((group) => ({ ...group, items: group.items.filter((item) => !tabs.value.includes(item)) }))
    .filter((group) => group.items.length));

function set(value) {
    open.value = value;
    document.documentElement.classList.toggle('admin-bnav-lock', value);
    document.querySelectorAll('#admin-main, #admin-topbar').forEach((el) => { el.inert = value; });
}

// Fokus ke panel, bukan ke item pertama, agar tidak ada lingkar fokus saat dibuka dengan sentuhan.
function toggle() {
    set(!open.value);
    if (open.value) nextTick(() => panel.value?.focus({ preventScroll: true }));
}

function close(refocus = false) {
    set(false);
    if (refocus) fab.value?.focus();
}

function onKeydown(e) {
    if (e.key === 'Escape' && open.value) close(true);
}

let wide;
const onWide = (e) => { if (e.matches) set(false); };

watch(() => route.fullPath, () => { if (open.value) set(false); });

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    wide = window.matchMedia('(min-width: 768px)');
    wide.addEventListener('change', onWide);
});

onBeforeUnmount(() => {
    set(false);
    document.removeEventListener('keydown', onKeydown);
    wide?.removeEventListener('change', onWide);
});
</script>

<template>
  <div class="admin-bnav-scrim" :class="{ 'is-on': open }" aria-hidden="true" @click="close()"></div>
  <div class="admin-bnav" :class="{ 'is-open': open, 'is-solo': !rest.length }">
    <div class="admin-bnav-box">
      <div
        v-if="rest.length"
        id="admin-bnav-panel"
        ref="panel"
        class="admin-bnav-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Menu lainnya"
        tabindex="-1"
        :inert="!open"
      >
        <div class="admin-bnav-scroll">
          <section v-for="(group, i) in rest" :key="group.label" class="admin-bnav-group" :style="{ '--i': i }">
            <p>{{ group.label }}</p>
            <ul>
              <li v-for="item in group.items" :key="item.label">
                <router-link :to="item.to" class="admin-bnav-tile" :aria-current="isActive(item) ? 'page' : undefined">
                  <span><AdminIcon :name="item.icon" /></span>
                  {{ item.label }}
                </router-link>
              </li>
            </ul>
          </section>
        </div>
      </div>
      <nav class="admin-bnav-tabs" aria-label="Navigasi cepat" :inert="open">
        <router-link
          v-for="item in tabs"
          :key="item.label"
          :to="item.to"
          class="admin-bnav-tab"
          :aria-current="isActive(item) ? 'page' : undefined"
        >
          <AdminIcon :name="item.icon" :size="22" />
          <span>{{ item.short || item.label }}</span>
        </router-link>
      </nav>
    </div>
    <button
      v-if="rest.length"
      ref="fab"
      type="button"
      class="admin-bnav-fab"
      aria-controls="admin-bnav-panel"
      :aria-expanded="open"
      :aria-label="open ? 'Tutup menu' : 'Buka menu lainnya'"
      @click="toggle"
    >
      <AdminIcon name="plus" :size="24" />
    </button>
  </div>
</template>

<style>
/* Gerak sama dengan navbar bawah toko: panel tumbuh dari kapsul lewat clip-path; hanya clip-path, transform, opacity, dan filter yang dianimasikan. */
.admin-bnav, .admin-bnav-scrim { display: none; }
@media (max-width: 767px) {
    html.admin-bnav-lock { overflow: hidden; }

    .admin-bnav { --bn-h: 64px; --bn-ease: cubic-bezier(0.32, 0.72, 0, 1); position: fixed; left: 12px; right: 12px; bottom: calc(12px + env(safe-area-inset-bottom)); z-index: 45; display: flex; align-items: flex-end; gap: 10px; }
    /* drop-shadow mengikuti bentuk hasil clip-path, jadi garis tepi tetap ada saat kapsul berubah jadi panel. */
    .admin-bnav-box { position: relative; flex: 1; min-width: 0; height: var(--bn-h); filter: drop-shadow(0 0 1px rgb(43 27 20 / 0.28)) drop-shadow(0 12px 24px rgb(43 27 20 / 0.14)); }
    .admin-bnav-panel { position: absolute; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; max-height: calc(100dvh - 120px - env(safe-area-inset-bottom)); background: var(--color-surface); outline: none; clip-path: inset(calc(100% - var(--bn-h)) 0 0 0 round 32px); transition: clip-path 260ms var(--bn-ease); }
    .admin-bnav.is-open .admin-bnav-panel { clip-path: inset(0 0 0 0 round 28px); transition-duration: 380ms; }
    .admin-bnav-scroll { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 16px 12px 12px; scrollbar-width: none; }

    .admin-bnav-group + .admin-bnav-group { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--color-border); }
    .admin-bnav-group { opacity: 0; filter: blur(4px); transform: translateY(8px); transition: opacity 120ms ease, filter 120ms ease, transform 120ms ease; }
    .admin-bnav.is-open .admin-bnav-group { opacity: 1; filter: none; transform: none; transition-duration: 280ms; transition-timing-function: var(--ease-out-strong); transition-delay: calc(80ms + 40ms * min(var(--i, 0), 4)); }
    .admin-bnav-group > p { margin: 0 0 10px 4px; color: var(--color-secondary); font-size: 11px; font-weight: 600; line-height: 1.2; letter-spacing: 0.08em; text-transform: uppercase; }
    .admin-bnav-group ul { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px 4px; margin: 0; padding: 0; list-style: none; }
    .admin-bnav-tile { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--color-primary); font-size: 11px; font-weight: 500; line-height: 14px; text-align: center; -webkit-tap-highlight-color: transparent; }
    .admin-bnav-tile > span { display: grid; place-items: center; width: min(48px, 100%); aspect-ratio: 1; border-radius: 16px; background: var(--color-background); color: var(--color-secondary); transition: transform 160ms var(--ease-out-strong), background-color 150ms ease; }
    .admin-bnav-tile:active > span { transform: scale(0.92); }
    .admin-bnav-tile[aria-current] > span { background: var(--color-accent); color: var(--color-primary); }

    .admin-bnav-tabs { position: absolute; left: 0; right: 0; bottom: 0; z-index: 1; display: flex; gap: 2px; height: var(--bn-h); padding: 6px; border-radius: 32px; transition: opacity 220ms ease, filter 220ms ease, transform 260ms var(--ease-out-strong); }
    /* Tanpa menu cepat (role dengan menu sedikit) kapsul tetap punya latar sendiri. */
    .admin-bnav.is-solo .admin-bnav-tabs { background: var(--color-surface); }
    .admin-bnav.is-open .admin-bnav-tabs { opacity: 0; filter: blur(6px); transform: scale(0.96); pointer-events: none; transition-duration: 140ms; }
    .admin-bnav-tab { flex: 1 1 0; display: flex; align-items: center; justify-content: center; min-width: 0; overflow: hidden; border-radius: 26px; color: var(--color-secondary); font-size: 12px; font-weight: 600; line-height: 16px; letter-spacing: 0.1px; -webkit-tap-highlight-color: transparent; transition: flex-grow 320ms var(--bn-ease), transform 160ms var(--ease-out-strong), color 150ms ease, background-color 150ms ease; }
    /* Tab aktif melebar (2,4 : 1) dan menampilkan ikon + nama berdampingan; tab lain mengecil jadi ikon saja.
       Nama tetap ada di DOM (max-width 0) sehingga pembaca layar masih membacanya. */
    .admin-bnav-tab > svg { flex: none; }
    .admin-bnav-tab[aria-current] { flex-grow: 2.4; }
    .admin-bnav-tab > span { flex: none; max-width: 0; margin-left: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0; transition: max-width 320ms var(--bn-ease), margin-left 320ms var(--bn-ease), opacity 160ms ease; }
    .admin-bnav-tab[aria-current] > span { max-width: 88px; margin-left: 6px; opacity: 1; transition-delay: 0s, 0s, 90ms; }
    .admin-bnav-tab:active { transform: scale(0.94); }
    .admin-bnav-tab[aria-current] { background: var(--color-accent); color: var(--color-primary); }

    .admin-bnav-fab { flex: none; display: grid; place-items: center; width: 56px; height: 56px; margin-bottom: 4px; padding: 0; border: 0; border-radius: 50%; background: var(--color-primary); color: var(--color-accent); cursor: pointer; box-shadow: 0 12px 24px -4px rgb(43 27 20 / 0.3); -webkit-tap-highlight-color: transparent; touch-action: manipulation; transition: transform 160ms var(--ease-out-strong); }
    .admin-bnav-fab:active { transform: scale(0.94); }
    .admin-bnav-fab svg { transition: transform 300ms var(--bn-ease); }
    .admin-bnav.is-open .admin-bnav-fab svg { transform: rotate(45deg); }
    .admin-bnav a:focus-visible, .admin-bnav button:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

    .admin-bnav-scrim { position: fixed; inset: 0; z-index: 44; display: block; background: rgb(43 27 20 / 0.4); opacity: 0; visibility: hidden; transition: opacity 240ms ease, visibility 0s linear 240ms; }
    .admin-bnav-scrim.is-on { opacity: 1; visibility: visible; transition-delay: 0s; }

    /* Turun saat keyboard terbuka untuk kolom di halaman. */
    body:has(input:focus, textarea:focus, select:focus) .admin-bnav:not(.is-open) { opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(calc(100% + 16px + env(safe-area-inset-bottom))); transition: transform 220ms var(--ease-out-strong), opacity 160ms ease, visibility 0s linear 220ms; }
    .admin-bnav { transition: transform 280ms var(--bn-ease), opacity 200ms ease, visibility 0s; }
}
/* HP sangat sempit (<= 359px): tombol bulat dan jarak diperkecil agar tab yang tidak aktif tetap selebar 44px (batas sentuh). */
@media (max-width: 359px) {
    .admin-bnav { gap: 8px; }
    .admin-bnav-fab { width: 52px; height: 52px; }
    .admin-bnav-tabs { padding: 4px; }
    .admin-bnav-tab { font-size: 11px; }
    .admin-bnav-tab[aria-current] { flex-grow: 2; }
}
@media (max-width: 767px) and (prefers-reduced-motion: reduce) {
    .admin-bnav, .admin-bnav-panel, .admin-bnav-tabs, .admin-bnav-tab, .admin-bnav-tab > svg, .admin-bnav-tab > span, .admin-bnav-fab, .admin-bnav-fab svg, .admin-bnav-tile > span { transition-property: opacity, visibility; }
    .admin-bnav-group, .admin-bnav.is-open .admin-bnav-tabs { filter: none; transform: none; }
}
</style>
