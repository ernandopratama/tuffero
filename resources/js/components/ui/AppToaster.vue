<script setup>
import { closeToast, pauseToast, resumeToast, toasts, TOAST_MS } from '../../composables/useToast';
import AdminIcon from '../AdminIcon.vue';

const icons = { success: 'check', error: 'x', info: 'info' };
</script>

<template>
  <div class="app-toaster" aria-live="polite">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="app-toast"
      :class="[`is-${t.type}`, { 'is-leaving': t.leaving, 'is-paused': t.paused }]"
      :style="{ '--toast-ms': `${TOAST_MS}ms` }"
      :role="t.type === 'error' ? 'alert' : 'status'"
      @mouseenter="pauseToast(t.id)"
      @mouseleave="resumeToast(t.id)"
      @focusin="pauseToast(t.id)"
      @focusout="resumeToast(t.id)"
    >
      <span class="app-toast-icon" aria-hidden="true"><AdminIcon :name="icons[t.type] || 'check'" :size="20" /></span>
      <div class="app-toast-body">
        <strong>{{ t.title }}</strong>
        <p>{{ t.text }}</p>
      </div>
      <button type="button" class="app-toast-close" aria-label="Tutup notifikasi" @click="closeToast(t.id)"><AdminIcon name="x" :size="14" /></button>
      <span class="app-toast-timer" aria-hidden="true"></span>
    </div>
  </div>
</template>

<style>
.app-toaster { position: fixed; top: 16px; right: 16px; z-index: 1000; display: flex; flex-direction: column; gap: 10px; width: min(420px, calc(100vw - 32px)); pointer-events: none; }
.app-toast { --toast-accent: #2E6B3E; --toast-surface: #F2F8F2; --toast-line: #B9D8BE; position: relative; display: grid; grid-template-columns: 40px 1fr 28px; align-items: center; gap: 14px; overflow: hidden; padding: 14px 14px 17px 16px; border: 1px solid var(--toast-line); border-radius: 12px; background: var(--toast-surface); color: #2B1B14; box-shadow: 0 12px 32px -12px #2B1B1440, 0 2px 6px #2B1B1414; pointer-events: auto; opacity: 1; transform: none; transform-origin: top right; transition: opacity 240ms ease, transform 240ms cubic-bezier(0.23, 1, 0.32, 1); }
@starting-style { .app-toast { opacity: 0; transform: translateX(16px) scale(0.97); } }
.app-toast.is-leaving { opacity: 0; transform: translateX(16px) scale(0.97); pointer-events: none; transition-duration: 180ms; }
.app-toast.is-error { --toast-accent: #A63A2B; --toast-surface: #FCF1EE; --toast-line: #EBC1B7; }
.app-toast.is-info { --toast-accent: #8A6A2E; --toast-surface: #FBF6EA; --toast-line: #E2CF9F; }
.app-toast::before { content: ''; position: absolute; top: 0; right: 0; width: 180px; height: 100%; background: linear-gradient(-62deg, transparent 0 22%, color-mix(in srgb, var(--toast-accent) 14%, transparent) 22% 31%, transparent 31% 38%, color-mix(in srgb, var(--toast-accent) 9%, transparent) 38% 46%, transparent 46%); pointer-events: none; }
.app-toast-icon { position: relative; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 999px; background: var(--toast-accent); color: #fff; }
.app-toast-body { position: relative; min-width: 0; }
.app-toast-body strong { display: block; font-size: 15px; line-height: 22px; font-weight: 700; color: var(--toast-accent); }
.app-toast-body p { margin: 0; font-size: 14px; line-height: 20px; overflow-wrap: anywhere; }
.app-toast-close { position: relative; display: grid; place-items: center; width: 28px; height: 28px; padding: 0; border: 0; border-radius: 999px; background: var(--toast-accent); color: #fff; cursor: pointer; transition: transform 140ms cubic-bezier(0.23, 1, 0.32, 1), opacity 150ms ease; }
.app-toast-close::after { content: ''; position: absolute; inset: -8px; }
.app-toast-close:active { transform: scale(0.9); }
.app-toast-close:focus-visible { outline: 2px solid #2B1B14; outline-offset: 2px; }
@media (hover: hover) and (pointer: fine) { .app-toast-close:hover { opacity: 0.85; } }
.app-toast-timer { position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: var(--toast-accent); transform-origin: left center; animation: app-toast-timer var(--toast-ms, 2000ms) linear forwards; }
.app-toast.is-paused .app-toast-timer { animation-play-state: paused; }
@keyframes app-toast-timer { from { transform: scaleX(1); } to { transform: scaleX(0); } }
@media (max-width: 599px) { .app-toaster { top: 12px; right: 12px; width: calc(100vw - 24px); } .app-toast { grid-template-columns: 36px 1fr 28px; gap: 12px; padding: 12px 12px 15px; } .app-toast-icon { width: 36px; height: 36px; } }
@media (prefers-reduced-motion: reduce) { @starting-style { .app-toast { transform: none; } } .app-toast.is-leaving { transform: none; } }
</style>
