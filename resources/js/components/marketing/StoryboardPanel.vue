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
  <section aria-label="Naskah dan storyboard" class="space-y-space-md border-t border-border pt-space-md">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="flex items-center gap-1.5 font-heading text-headline-sm"><AdminIcon name="video" :size="20" />Naskah &amp; storyboard</h4>
      <span v-if="board" class="text-body-sm text-secondary tabular-nums">{{ board.scenes.length }} adegan · {{ totalSeconds }} detik</span>
    </div>

    <p v-if="error" role="alert" class="rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">{{ error }}</p>

    <p v-if="status === 'pending'" role="status" class="flex items-center gap-2 rounded-2xl bg-accent-tint-12 px-4 py-3 text-body-sm">
      <AdminIcon name="sparkles" :size="16" class="shrink-0 animate-pulse-soft text-primary" />AI sedang menyusun naskah dan storyboard. Halaman diperbarui otomatis.
    </p>
    <p v-else-if="status === 'waiting'" role="status" class="flex gap-2 rounded-2xl bg-warning-surface px-4 py-3 text-body-sm text-warning">
      <AdminIcon name="photos" :size="16" class="mt-0.5 shrink-0" />Mode dibuat AI sepenuhnya butuh minimal {{ MIN_AI_REFERENCES }} foto acuan produk asli ({{ draft.references.length }}/{{ MIN_AI_REFERENCES }}). Storyboard disusun otomatis setelah foto kedua diunggah.
    </p>
    <p v-else-if="status === 'failed'" role="alert" class="flex gap-2 rounded-2xl bg-error-surface px-4 py-3 text-body-sm text-on-error-surface">
      <AdminIcon name="alert" :size="16" class="mt-0.5 shrink-0" />{{ draft.storyboard_error || 'Storyboard gagal dibuat.' }}
    </p>
    <p v-else-if="!board" class="rounded-2xl bg-secondary-tint-12 px-4 py-3 text-body-sm">Belum ada storyboard. Storyboard dibuat otomatis untuk draf skrip video saat pembuat AI aktif.</p>

    <template v-if="board">
      <p class="rounded-2xl bg-accent-tint-12 px-4 py-3 text-body-md"><span class="text-label-md">Hook:</span> {{ board.hook }}</p>

      <ol class="space-y-2" aria-label="Adegan">
        <li v-for="(scene, index) in board.scenes" :key="index" class="rounded-2xl border border-border p-3">
          <div class="flex flex-wrap items-center gap-2 text-label-md">
            <span>Adegan {{ index + 1 }}</span>
            <span class="rounded-full bg-secondary-tint-12 px-2 py-0.5 text-label-sm tabular-nums">{{ scene.seconds }} detik</span>
            <span class="rounded-full px-2 py-0.5 text-label-sm" :class="scene.source === 'rekam' ? 'bg-warning-surface text-warning' : 'bg-secondary-tint-12'">{{ sourceLabel(scene.source) }}</span>
            <span v-if="scene.shot" class="text-label-sm font-normal text-secondary">{{ scene.shot }}</span>
          </div>
          <p class="mt-1 break-words text-body-sm">{{ scene.visual }}</p>
          <p v-if="scene.on_screen_text" class="mt-1 break-words text-body-sm"><span class="text-secondary">Teks layar:</span> {{ scene.on_screen_text }}</p>
          <p v-if="scene.voice_over" class="mt-1 break-words text-body-sm"><span class="text-secondary">Voice over:</span> {{ scene.voice_over }}</p>
          <p v-if="scene.prompt" class="mt-1 break-words text-body-sm text-secondary"><span>Prompt video AI:</span> <span lang="en">{{ scene.prompt }}</span></p>
        </li>
      </ol>

      <div v-if="board.shot_list.length" class="rounded-2xl border border-warning/40 bg-warning-surface/40 p-3">
        <h5 class="text-label-md">Perlu disiapkan tim</h5>
        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-body-sm">
          <li v-for="(item, index) in board.shot_list" :key="index" class="break-words">{{ item }}</li>
        </ul>
      </div>

      <div v-if="board.caption" class="rounded-2xl border border-border p-3">
        <h5 class="text-label-md">Caption</h5>
        <p class="mt-1 whitespace-pre-line break-words text-body-sm">{{ board.caption }}</p>
        <p v-if="board.hashtags.length" class="mt-1 break-words text-body-sm text-primary">{{ board.hashtags.join(' ') }}</p>
      </div>
      <p v-if="board.music" class="flex gap-1.5 text-body-sm"><AdminIcon name="volume" :size="16" class="mt-0.5 shrink-0 text-secondary" /><span><span class="text-secondary">Musik:</span> {{ board.music }}</span></p>
    </template>

    <div>
      <h5 class="text-label-md">Foto acuan <span class="font-normal text-secondary">({{ draft.references.length }}/{{ MAX_REFERENCES }})</span></h5>
      <ul v-if="draft.references.length" class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
        <li v-for="(reference, index) in draft.references" :key="reference.id" class="relative">
          <img :src="reference.url" :alt="`Foto acuan ${index + 1}`" loading="lazy" class="aspect-square w-full rounded-xl border border-border object-cover">
          <span class="absolute left-1 top-1 rounded-full bg-surface/90 px-1.5 text-label-sm tabular-nums">{{ index + 1 }}</span>
          <button v-if="canEdit" type="button" :disabled="busy" :aria-label="`Hapus foto acuan ${index + 1}`" :class="`absolute right-1 top-1 grid size-8 place-items-center rounded-full bg-surface/90 text-error hover:bg-error-surface ${press}`" @click="removeReference(reference)"><AdminIcon name="trash" :size="14" /></button>
        </li>
      </ul>
      <label
        v-if="canEdit && draft.references.length < MAX_REFERENCES"
        class="mt-2 flex cursor-pointer flex-col items-center gap-1 rounded-2xl border-2 border-dashed px-4 py-5 text-center transition-colors focus-within:ring-2 focus-within:ring-primary"
        :class="[dragging ? 'border-primary bg-accent-tint-12' : 'border-border hover:border-primary hover:bg-accent-tint-12', busy ? 'pointer-events-none opacity-60' : '']"
        @dragover.prevent="dragging = true"
        @dragleave="dragging = false"
        @drop.prevent="onDrop"
      >
        <span class="grid size-10 place-items-center rounded-full bg-accent-tint-15 text-primary"><AdminIcon name="photos" :size="20" /></span>
        <span class="text-label-md">Tambah foto produk asli</span>
        <span class="text-body-sm text-secondary">Klik atau seret foto ke sini · JPG atau PNG, maks. 10 MB{{ aiMode ? `, minimal ${MIN_AI_REFERENCES} untuk mode AI` : '' }}</span>
        <input ref="fileInput" type="file" accept="image/jpeg,image/png" :disabled="busy" class="sr-only" @change="upload">
      </label>
    </div>

    <div>
      <h5 class="text-label-md">Catatan untuk AI</h5>
      <ul v-if="draft.notes.length" class="mt-2 space-y-1.5">
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
      <form v-if="canEdit" class="mt-2 space-y-2" @submit.prevent="sendNote">
        <textarea v-model="note" rows="2" maxlength="1000" :disabled="busy" placeholder="Contoh: adegan 2 ganti close-up nastar dibelah, musik lebih ceria." :class="`${field} resize-y`" aria-label="Catatan untuk AI"></textarea>
        <button :disabled="busy || !note.trim()" :class="btn"><AdminIcon name="sparkles" :size="16" />{{ busy ? 'Mengirim...' : 'Kirim catatan' }}</button>
      </form>
      <p v-else-if="!draft.notes.length" class="mt-1 text-body-sm text-secondary">Belum ada catatan.</p>
      <p v-if="!canEdit && ['in_review', 'approved', 'posted'].includes(draft.status)" class="mt-1 text-body-sm text-secondary">Catatan dan foto acuan terkunci karena draf sudah diajukan atau disetujui.</p>
    </div>
  </section>
</template>
