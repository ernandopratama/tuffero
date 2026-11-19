<script setup>
import { computed, ref } from 'vue';
import AdminIcon from '../AdminIcon.vue';
import api from '../../utils/api';
import { apiError } from '../../utils/apiError';

// Naskah dan storyboard otomatis untuk draf skrip video. Tidak ada tombol "buat": storyboard disusun di antrean saat draf masuk
// (termasuk kiriman Hermes hari Senin), dan disusun ulang setiap tim menambah foto acuan atau mengirim catatan untuk AI.
const props = defineProps({
    draft: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const MAX_REFERENCES = 6;
const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100';
const field = 'mt-1 block w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary disabled:opacity-70';
const btn = `inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`;
const SOURCE = { ai: 'Dibuat AI (pendukung)', rekam: 'Perlu direkam tim' };

const board = computed(() => props.draft.storyboard);
const status = computed(() => props.draft.storyboard_status);
const totalSeconds = computed(() => (board.value?.scenes ?? []).reduce((sum, scene) => sum + scene.seconds, 0));
const sourceLabel = (source) => SOURCE[source] ?? (source.startsWith('ai dari') ? `AI dari ${source.slice(8).replace('foto', 'foto acuan')}` : `Dari ${source.replace('foto', 'foto acuan')}`);
const aiMode = computed(() => props.draft.visual_mode === 'ai');
// Lebar batang durasi tiap adegan, sebanding dengan porsinya dari total (minimal 8% supaya tetap terlihat).
const share = (scene) => (totalSeconds.value ? Math.max(8, Math.round((scene.seconds / totalSeconds.value) * 100)) : 8);
// Warna penuh penanda sumber adegan: perlu direkam tim, dibuat AI, atau dari foto acuan.
const sourceClass = (source) => (source === 'rekam' ? 'bg-[#B45309]' : source === 'ai' ? 'bg-[#6D28D9]' : 'bg-[#0F766E]');
const panel = 'rounded-3xl border border-border bg-surface p-space-md';
const panelTitle = 'mb-2 flex items-center gap-2 text-label-sm uppercase tracking-wide text-secondary';
const MIN_AI_REFERENCES = 2;

const note = ref('');
const busy = ref(false);
const error = ref('');
const fileInput = ref(null);

async function run(request) {
    busy.value = true;
    error.value = '';
    try {
        await request();
        emit('changed');
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        busy.value = false;
    }
}

function sendNote() {
    if (!note.value.trim()) return;
    run(async () => {
        await api.post(`/admin/marketing-drafts/${props.draft.id}/notes`, { body: note.value }, { toast: 'Catatan terkirim. Storyboard disusun ulang.' });
        note.value = '';
    });
}

const dragging = ref(false);

function onDrop(event) {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (!file || !['image/jpeg', 'image/png'].includes(file.type)) {
        error.value = 'Foto harus berformat JPG atau PNG.';
        return;
    }
    upload(file);
}

function upload(source) {
    const file = source instanceof File ? source : fileInput.value?.files?.[0];
    if (!file) return;
    run(async () => {
        const body = new FormData();
        body.append('image', file);
        try {
            await api.post(`/admin/marketing-drafts/${props.draft.id}/references`, body, { toast: 'Foto acuan ditambahkan. Storyboard disusun ulang.' });
        } finally {
            fileInput.value.value = '';
        }
    });
}

// Ubah catatan di tempat; menyimpan atau menghapus catatan juga menyusun ulang storyboard.
const editingNote = ref(null);
const editText = ref('');

function startEdit(item) {
    editingNote.value = item.id;
    editText.value = item.body;
}

function saveNote(item) {
    if (!editText.value.trim()) return;
    run(async () => {
        await api.patch(`/admin/marketing-drafts/${props.draft.id}/notes/${item.id}`, { body: editText.value }, { toast: 'Catatan diubah. Storyboard disusun ulang.' });
        editingNote.value = null;
    });
}

function removeNote(item) {
    run(() => api.delete(`/admin/marketing-drafts/${props.draft.id}/notes/${item.id}`, { toast: 'Catatan dihapus. Storyboard disusun ulang.' }));
}

function removeReference(reference) {
    run(() => api.delete(`/admin/marketing-drafts/${props.draft.id}/references/${reference.id}`, { toast: 'Foto acuan dihapus.' }));
}
</script>

<template>
  <section aria-label="Naskah dan storyboard" class="mt-space-lg space-y-space-md">
    <!-- Kepala: judul, ringkasan adegan dan durasi, lalu status penyusunan -->
    <div class="rounded-3xl border border-border bg-gradient-to-br from-accent-tint-12 to-background/60 p-4 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h4 class="flex items-center gap-2 font-heading text-headline-sm"><span class="grid size-10 place-items-center rounded-full bg-primary text-surface" aria-hidden="true"><AdminIcon name="video" :size="20" /></span>Naskah &amp; storyboard</h4>
        <ul v-if="board" class="flex flex-wrap gap-2 text-label-md" aria-label="Ringkasan storyboard">
          <li class="inline-flex items-center gap-1.5 rounded-full border border-primary bg-surface px-3 py-1 tabular-nums shadow-[2px_2px_0_0_var(--color-primary)]">{{ board.scenes.length }} adegan</li>
          <li class="inline-flex items-center gap-1.5 rounded-full border border-primary bg-surface px-3 py-1 tabular-nums shadow-[2px_2px_0_0_var(--color-primary)]"><AdminIcon name="clock" :size="14" />{{ totalSeconds }} detik</li>
        </ul>
      </div>

      <p v-if="error" role="alert" class="mt-space-sm rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>
      <p v-if="status === 'pending'" role="status" class="mt-space-sm flex items-center gap-2 rounded-2xl bg-surface/80 px-4 py-3 text-body-sm">
        <AdminIcon name="sparkles" :size="16" class="shrink-0 animate-pulse-soft text-primary" />AI sedang menyusun naskah dan storyboard. Halaman diperbarui otomatis.
      </p>
      <p v-else-if="status === 'waiting'" role="status" class="mt-space-sm flex gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
        <AdminIcon name="photos" :size="16" class="mt-0.5 shrink-0" />Mode dibuat AI sepenuhnya butuh minimal {{ MIN_AI_REFERENCES }} foto acuan produk asli ({{ draft.references.length }}/{{ MIN_AI_REFERENCES }}). Storyboard disusun otomatis setelah foto kedua diunggah.
      </p>
      <p v-else-if="status === 'failed'" role="alert" class="mt-space-sm flex gap-2 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">
        <AdminIcon name="alert" :size="16" class="mt-0.5 shrink-0" />{{ draft.storyboard_error || 'Storyboard gagal dibuat.' }}
      </p>
      <p v-else-if="!board" class="mt-space-sm rounded-2xl bg-surface/80 px-4 py-3 text-body-sm">Belum ada storyboard. Storyboard dibuat otomatis untuk draf skrip video saat pembuat AI aktif.</p>

      <!-- Hook: kalimat pembuka sebagai kutipan besar -->
      <blockquote v-if="board" class="mt-space-md border-l-4 border-primary pl-4">
        <p class="text-label-sm uppercase tracking-wide text-secondary">Hook 3 detik pertama</p>
        <p class="mt-1 break-words font-heading text-headline-sm leading-snug">{{ board.hook }}</p>
      </blockquote>
    </div>

    <!-- Dua kolom di layar lebar: adegan di kiri, bahan pendukung di kanan -->
    <div class="grid items-start gap-space-md lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
      <div class="min-w-0 space-y-space-md">
        <!-- Adegan: garis waktu vertikal dengan batang durasi -->
        <ol v-if="board" class="relative space-y-space-sm border-l-2 border-border pl-5 sm:pl-6" aria-label="Adegan">
          <li v-for="(scene, index) in board.scenes" :key="index" class="relative animate-rise" :style="{ animationDelay: `${Math.min(index, 6) * 40}ms` }">
            <span class="absolute -left-[1.95rem] top-3 grid size-7 place-items-center rounded-full border-2 border-surface bg-primary text-label-sm tabular-nums text-surface sm:-left-[2.2rem]" aria-hidden="true">{{ index + 1 }}</span>
            <article class="rounded-2xl border border-border bg-surface p-3 transition-[border-color,box-shadow] duration-150 ease-out-strong [@media(hover:hover)]:hover:border-primary [@media(hover:hover)]:hover:shadow-card sm:p-4">
              <header class="flex flex-wrap items-center gap-2">
                <h5 class="text-label-md">Adegan {{ index + 1 }}</h5>
                <span class="rounded-full px-2.5 py-0.5 text-label-sm text-surface" :class="sourceClass(scene.source)">{{ sourceLabel(scene.source) }}</span>
                <span v-if="scene.shot" class="rounded-full bg-secondary-tint-12 px-2.5 py-0.5 text-label-sm">{{ scene.shot }}</span>
                <span class="ml-auto text-label-md tabular-nums">{{ scene.seconds }} dtk</span>
              </header>
              <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-secondary-tint-12" role="presentation"><div class="h-full rounded-full bg-primary" :style="{ width: `${share(scene)}%` }"></div></div>
              <p class="mt-2 break-words text-body-md">{{ scene.visual }}</p>
              <dl v-if="scene.on_screen_text || scene.voice_over" class="mt-2 grid gap-2 sm:grid-cols-2">
                <div v-if="scene.on_screen_text" class="rounded-xl bg-background/70 px-3 py-2"><dt class="flex items-center gap-1 text-label-sm text-secondary"><AdminIcon name="document" :size="12" />Teks layar</dt><dd class="mt-0.5 break-words text-body-sm">{{ scene.on_screen_text }}</dd></div>
                <div v-if="scene.voice_over" class="rounded-xl bg-background/70 px-3 py-2"><dt class="flex items-center gap-1 text-label-sm text-secondary"><AdminIcon name="volume" :size="12" />Voice over</dt><dd class="mt-0.5 break-words text-body-sm">{{ scene.voice_over }}</dd></div>
              </dl>
              <details v-if="scene.prompt" class="group mt-2">
                <summary class="flex min-h-9 cursor-pointer list-none items-center gap-1.5 text-label-sm text-secondary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"><AdminIcon name="sparkles" :size="12" />Prompt video AI<AdminIcon name="chevron-down" :size="12" class="transition-transform duration-150 group-open:rotate-180" /></summary>
                <p class="mt-1 break-words rounded-xl bg-secondary-tint-12 px-3 py-2 text-body-sm" lang="en">{{ scene.prompt }}</p>
              </details>
            </article>
          </li>
        </ol>
      </div>

      <div class="min-w-0 space-y-space-md">
        <section v-if="board && board.shot_list.length" :class="[panel, 'border-warning/50 bg-warning-surface/40']" aria-label="Perlu disiapkan tim">
          <h5 :class="panelTitle"><AdminIcon name="photos" :size="14" />Perlu disiapkan tim</h5>
          <ul class="space-y-1.5 text-body-sm">
            <li v-for="(item, index) in board.shot_list" :key="index" class="flex gap-2 break-words"><AdminIcon name="check" :size="16" class="mt-0.5 shrink-0 text-warning" />{{ item }}</li>
          </ul>
        </section>

        <section v-if="board && (board.caption || board.music)" :class="panel" aria-label="Caption dan musik">
          <template v-if="board.caption">
            <h5 :class="panelTitle"><AdminIcon name="chat" :size="14" />Caption</h5>
            <p class="whitespace-pre-line break-words text-body-sm">{{ board.caption }}</p>
            <ul v-if="board.hashtags.length" class="mt-2 flex flex-wrap gap-1.5" aria-label="Hashtag">
              <li v-for="tag in board.hashtags" :key="tag" class="rounded-full bg-info-surface px-2.5 py-0.5 text-label-sm text-info">{{ tag }}</li>
            </ul>
          </template>
          <p v-if="board.music" class="flex gap-1.5 text-body-sm" :class="board.caption ? 'mt-space-sm border-t border-border pt-space-sm' : ''"><AdminIcon name="volume" :size="16" class="mt-0.5 shrink-0 text-secondary" /><span><span class="text-secondary">Musik:</span> {{ board.music }}</span></p>
        </section>

        <section :class="panel" aria-label="Foto acuan">
          <h5 :class="panelTitle"><AdminIcon name="photos" :size="14" />Foto acuan <span class="font-normal normal-case tracking-normal">({{ draft.references.length }}/{{ MAX_REFERENCES }})</span></h5>
          <ul v-if="draft.references.length" class="grid grid-cols-3 gap-2">
            <li v-for="(reference, index) in draft.references" :key="reference.id" class="relative">
              <img :src="reference.url" :alt="`Foto acuan ${index + 1}`" loading="lazy" class="aspect-square w-full rounded-xl border border-border object-cover">
              <span class="absolute left-1 top-1 rounded-full bg-surface/90 px-1.5 text-label-sm tabular-nums">{{ index + 1 }}</span>
              <button v-if="canEdit" type="button" :disabled="busy" :aria-label="`Hapus foto acuan ${index + 1}`" :class="`absolute right-1 top-1 grid size-8 place-items-center rounded-full bg-surface/90 text-error hover:bg-error-surface ${press}`" @click="removeReference(reference)"><AdminIcon name="trash" :size="14" /></button>
            </li>
          </ul>
          <label
            v-if="canEdit && draft.references.length < MAX_REFERENCES"
            class="flex cursor-pointer flex-col items-center gap-1 rounded-2xl border-2 border-dashed px-4 py-5 text-center transition-colors focus-within:ring-2 focus-within:ring-primary"
            :class="[draft.references.length ? 'mt-2' : '', dragging ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-primary hover:bg-accent-tint-12', busy ? 'pointer-events-none opacity-60' : '']"
            @dragover.prevent="dragging = true"
            @dragleave="dragging = false"
            @drop.prevent="onDrop"
          >
            <span class="grid size-10 place-items-center rounded-full bg-accent-tint-15 text-primary"><AdminIcon name="photos" :size="20" /></span>
            <span class="text-label-md">Tambah foto produk asli</span>
            <span class="text-body-sm text-secondary">Klik atau seret foto ke sini · JPG atau PNG, maks. 10 MB{{ aiMode ? `, minimal ${MIN_AI_REFERENCES} untuk mode AI` : '' }}</span>
            <input ref="fileInput" type="file" accept="image/jpeg,image/png" :disabled="busy" class="sr-only" @change="upload">
          </label>
          <p v-else-if="!draft.references.length" class="text-body-sm text-secondary">Belum ada foto acuan.</p>
        </section>

        <section :class="panel" aria-label="Catatan untuk AI">
          <h5 :class="panelTitle"><AdminIcon name="sparkles" :size="14" />Catatan untuk AI</h5>
          <ul v-if="draft.notes.length" class="space-y-1.5">
            <li v-for="item in draft.notes" :key="item.id" class="rounded-2xl bg-secondary-tint-12 px-3 py-2 text-body-sm">
              <div class="flex items-center justify-between gap-2">
                <span class="text-label-sm text-secondary">{{ item.author || 'Tanpa nama' }}</span>
                <span v-if="canEdit && editingNote !== item.id" class="flex gap-1">
                  <button type="button" :disabled="busy" :aria-label="`Ubah catatan dari ${item.author || 'tanpa nama'}`" :class="`grid size-8 place-items-center rounded-full hover:bg-accent-tint-15 ${press}`" @click="startEdit(item)"><AdminIcon name="edit" :size="14" /></button>
                  <button type="button" :disabled="busy" :aria-label="`Hapus catatan dari ${item.author || 'tanpa nama'}`" :class="`grid size-8 place-items-center rounded-full text-error hover:bg-error-surface ${press}`" @click="removeNote(item)"><AdminIcon name="trash" :size="14" /></button>
                </span>
              </div>
              <form v-if="editingNote === item.id" class="mt-1 space-y-2" @submit.prevent="saveNote(item)">
                <textarea v-model="editText" rows="2" maxlength="1000" :disabled="busy" :class="`${field} resize-y`" aria-label="Ubah catatan"></textarea>
                <div class="flex gap-2">
                  <button :disabled="busy || !editText.trim()" :class="btn"><AdminIcon name="check" :size="16" />{{ busy ? 'Menyimpan...' : 'Simpan' }}</button>
                  <button type="button" :disabled="busy" :class="btn" @click="editingNote = null">Batal</button>
                </div>
              </form>
              <p v-else class="whitespace-pre-line break-words">{{ item.body }}</p>
            </li>
          </ul>
          <form v-if="canEdit" class="space-y-2" :class="draft.notes.length ? 'mt-2' : ''" @submit.prevent="sendNote">
            <textarea v-model="note" rows="2" maxlength="1000" :disabled="busy" placeholder="Contoh: adegan 2 ganti close-up nastar dibelah, musik lebih ceria." :class="`${field} resize-y`" aria-label="Catatan untuk AI"></textarea>
            <button :disabled="busy || !note.trim()" :class="btn"><AdminIcon name="sparkles" :size="16" />{{ busy ? 'Mengirim...' : 'Kirim catatan' }}</button>
          </form>
          <p v-else-if="!draft.notes.length" class="text-body-sm text-secondary">Belum ada catatan.</p>
          <p v-if="!canEdit && ['in_review', 'approved', 'posted'].includes(draft.status)" class="mt-1 text-body-sm text-secondary">Catatan dan foto acuan terkunci karena draf sudah diajukan atau disetujui.</p>
        </section>
      </div>
    </div>
  </section>
</template>
