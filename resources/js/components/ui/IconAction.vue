<script setup>
import { RouterLink } from 'vue-router';
import { hideTip, showTip } from '../../composables/useActionTip';
import AdminIcon from '../AdminIcon.vue';

// Tombol aksi baris tabel: ikon + tooltip. `label` = aksi singkat (isi tooltip); `subject` = baris yang dituju, hanya untuk
// nama aksesibel ("Ubah <subject>"), supaya pembaca layar tahu tombol mana yang dimaksud.
// Tanpa `to`/`href` jadi <button>; `to` = tautan router; `href` = tautan luar (tab baru).
// Area sentuh 48 px (tombol 40 px + perluasan tak terlihat).
const props = defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    subject: { type: String, default: '' },
    tone: { type: String, default: 'default', validator: (v) => ['default', 'primary', 'danger'].includes(v) },
    to: { type: [Object, String], default: null },
    href: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const TONES = {
    default: 'text-secondary hover:bg-accent-tint-15 hover:text-primary',
    primary: 'bg-accent text-primary hover:bg-accent-hover',
    danger: 'text-error hover:bg-error-surface',
};

// Sentuhan tidak punya hover: tooltip hanya untuk mouse, pena, dan fokus keyboard.
const onEnter = (e) => { if (e.pointerType !== 'touch' && !props.disabled) showTip(e.currentTarget, props.label); };
const onFocus = (e) => { if (e.currentTarget.matches(':focus-visible')) showTip(e.currentTarget, props.label, { immediate: true }); };
</script>

<template>
  <component
    :is="to ? RouterLink : href ? 'a' : 'button'"
    :to="to || undefined"
    :href="href || undefined"
    :type="to || href ? undefined : 'button'"
    :target="href ? '_blank' : undefined"
    :rel="href ? 'noopener noreferrer' : undefined"
    :disabled="!to && !href && disabled ? true : undefined"
    class="relative inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-full transition-[transform,background-color,color] duration-[160ms] ease-out-strong after:absolute after:-inset-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-40"
    :class="TONES[tone]"
    :aria-label="[label, subject, href ? '(tab baru)' : ''].filter(Boolean).join(' ')"
    @pointerenter="onEnter"
    @pointerleave="hideTip"
    @focus="onFocus"
    @blur="hideTip"
    @click="hideTip"
  >
    <AdminIcon :name="icon" :size="20" />
  </component>
</template>
