<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { formatDate } from '../utils/datetime';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';

// url = endpoint komentar target, mis. /admin/vouchers/1/comments.
// Ketik @ untuk menandai staf yang boleh membuka data ini (daftar dari server). Tanda disimpan, tetapi belum ada pemberitahuan.
const props = defineProps({ url: { type: String, required: true } });
// Jumlah catatan dikirim ke induk (lencana tab "ada pembaruan").
const emit = defineEmits(['count']);

const auth = useAuthStore();
const comments = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const staff = ref([]);
const canCs = ref(false); // pemegang izin comments.cs boleh menulis catatan yang hanya terlihat CS
const visibility = ref('staff');
const body = ref('');
const picked = ref([]); // staf yang dipilih dari daftar @ pada catatan yang sedang ditulis
const loading = ref(true);
const saving = ref(false);
const error = ref('');

const field = ref(null);
const query = ref(null); // { start, text } selama pengguna mengetik setelah @
const active = ref(0);
const listId = `mention-list-${Math.random().toString(36).slice(2, 8)}`;

const options = computed(() => {
    if (query.value === null) return [];
    const needle = query.value.text.toLowerCase();
    return staff.value.filter((user) => (visibility.value !== 'cs' || user.cs) && user.name.toLowerCase().includes(needle)).slice(0, 6);
});

// Dikirim hanya setelah data termuat, supaya induk tidak menganggap 0 sebagai jumlah sebenarnya.
watch([loading, () => meta.value.total], ([busy, total]) => { if (!busy) emit('count', total); });

async function load(page = 1) {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(props.url, { params: { page } });
        comments.value = data.data.comments;
        meta.value = data.data.meta;
        staff.value = data.data.mentionable || [];
        canCs.value = !!data.data.can_cs;
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

// Daftar muncul bila kursor berada tepat setelah "@" (di awal teks atau sesudah spasi) dan nama yang diketik belum lewat 30 huruf.
function detect() {
    const el = field.value;
    const before = body.value.slice(0, el.selectionStart);
    const match = before.match(/(?:^|\s)@([^@\n]{0,30})$/);
    query.value = match ? { start: el.selectionStart - match[1].length - 1, text: match[1] } : null;
    active.value = 0;
}

function choose(user) {
    const el = field.value;
    const { start } = query.value;
    body.value = `${body.value.slice(0, start)}@${user.name} ${body.value.slice(el.selectionStart)}`;
    if (!picked.value.some((p) => p.id === user.id)) picked.value.push(user);
    query.value = null;
    nextTick(() => {
        const caret = start + user.name.length + 2;
        el.focus();
        el.setSelectionRange(caret, caret);
    });
}

function onKeydown(e) {
    if (!options.value.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + options.value.length) % options.value.length;
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        choose(options.value[active.value]);
    } else if (e.key === 'Escape') {
        // Esc menutup daftar saja, bukan modal.
        e.preventDefault();
        e.stopPropagation();
        query.value = null;
    }
}

async function submit() {
    saving.value = true;
    error.value = '';
    // Hanya yang namanya masih ada di teks (bisa saja dihapus setelah dipilih).
    const mentions = picked.value.filter((user) => (visibility.value !== 'cs' || user.cs) && body.value.includes(`@${user.name}`)).map((user) => user.id);
    try {
        await api.post(props.url, { body: body.value, mentions, ...(canCs.value ? { visibility: visibility.value } : {}) });
        body.value = '';
        picked.value = [];
        query.value = null;
        await load(1); // catatan terbaru ada di halaman 1
    } catch (e) {
        const { message, fields } = apiError(e);
        error.value = fields.body?.[0] || fields['mentions.0']?.[0] || message;
    } finally {
        saving.value = false;
    }
}

const escapeRegex = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// Pecah isi catatan agar "@Nama" yang ditandai bisa diberi warna. Tetap teks biasa (tanpa v-html).
function segments(comment) {
    const names = (comment.mentions || []).map((m) => m.name).sort((a, b) => b.length - a.length);
    if (!names.length) return [{ text: comment.body }];
    const pattern = new RegExp(`(@(?:${names.map(escapeRegex).join('|')}))`, 'g');
    return comment.body.split(pattern).filter(Boolean).map((text) => ({ text, mention: names.some((name) => text === `@${name}`) }));
}

const mentionsMe = (comment) => (comment.mentions || []).some((m) => m.id === auth.user?.id);

watch(() => props.url, () => load());
onMounted(load);
</script>

<template>
  <section class="rounded-2xl border border-accent/50 bg-accent-tint-12 p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 font-heading text-headline-sm"><AdminIcon name="chat" :size="20" />Catatan internal<span v-if="meta.total" class="text-label-md text-secondary">({{ meta.total }})</span></h3>
      <span class="rounded-full bg-surface/80 px-2.5 py-1 text-label-sm text-secondary">Hanya staf</span>
    </div>
    <p class="mt-1 text-body-sm text-secondary">Catatan tidak bisa diedit atau dihapus.<template v-if="auth.can('comments.create') && staff.length"> Ketik @ untuk menandai rekan.</template></p>

    <form v-if="auth.can('comments.create')" class="mt-space-sm" @submit.prevent="submit">
      <label class="block text-label-md">
        Tulis catatan
        <span class="relative mt-1 block">
          <textarea
            ref="field"
            v-model="body"
            rows="2"
            required
            maxlength="2000"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="options.length > 0"
            :aria-controls="listId"
            :aria-activedescendant="options.length ? `${listId}-${active}` : undefined"
            class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-body-md focus:border-primary focus:outline-2 focus:outline-primary"
            @input="detect"
            @click="detect"
            @keyup.left="detect"
            @keyup.right="detect"
            @keydown="onKeydown"
            @blur="query = null"
          ></textarea>
          <ul
            v-if="options.length"
            :id="listId"
            role="listbox"
            aria-label="Rekan yang bisa ditandai"
            class="absolute inset-x-0 top-full z-10 mt-1 max-h-56 overflow-y-auto rounded-2xl border border-border bg-surface p-1 shadow-pop"
          >
            <li
              v-for="(user, i) in options"
              :id="`${listId}-${i}`"
              :key="user.id"
              role="option"
              :aria-selected="i === active"
              class="flex cursor-pointer items-center gap-2 rounded-xl px-3 py-2 text-body-sm"
              :class="i === active ? 'bg-accent-tint-20' : 'hover:bg-accent-tint-12'"
              @mousedown.prevent="choose(user)"
              @mouseenter="active = i"
            >
              <span class="flex size-6 items-center justify-center rounded-full bg-accent text-label-sm">{{ user.name.charAt(0).toUpperCase() }}</span>
              {{ user.name }}
            </li>
          </ul>
        </span>
      </label>
      <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
        <label v-if="canCs" class="flex items-center gap-2 text-label-md">
          Terlihat oleh
          <select v-model="visibility" class="rounded-full border border-border bg-surface px-3 py-1.5 text-body-sm focus:border-primary focus:outline-2 focus:outline-primary">
            <option value="staff">Semua staf</option>
            <option value="cs">Hanya CS</option>
          </select>
        </label>
        <button :disabled="saving" class="rounded-full bg-primary px-5 py-2 text-label-md text-background transition-[transform,opacity] duration-[160ms] ease-out-strong hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60">{{ saving ? 'Mengirim...' : 'Kirim catatan' }}</button>
      </div>
    </form>

    <p v-if="error" role="alert" class="mt-3 text-body-sm text-error">{{ error }}</p>
    <p v-if="loading && !comments.length" role="status" class="mt-space-sm text-body-sm text-secondary">Memuat catatan...</p>
    <p v-else-if="!comments.length && !error" class="mt-space-sm text-body-sm text-secondary">Belum ada catatan.</p>
    <ul v-else class="mt-5 space-y-2" :aria-busy="loading">
      <li v-for="comment in comments" :key="comment.id" class="rounded-xl bg-surface p-3 text-body-sm" :class="mentionsMe(comment) ? 'ring-2 ring-accent' : ''">
        <span class="text-label-md">{{ comment.author }}</span>
        <span class="ml-space-sm text-secondary">{{ formatDate(comment.created_at) }}</span>
        <span v-if="comment.visibility === 'cs'" class="ml-space-sm rounded-full bg-primary px-2 py-0.5 text-label-sm text-background">Hanya CS</span>
        <span v-if="mentionsMe(comment)" class="ml-space-sm rounded-full bg-accent px-2 py-0.5 text-label-sm">Anda disebut</span>
        <p class="mt-1 break-words whitespace-pre-line"><template v-for="(part, i) in segments(comment)" :key="i"><span v-if="part.mention" class="rounded bg-accent-tint-20 px-1 font-semibold">{{ part.text }}</span><template v-else>{{ part.text }}</template></template></p>
      </li>
    </ul>

    <nav v-if="meta.last_page > 1" class="mt-space-sm flex items-center justify-between gap-2 text-label-sm text-secondary" aria-label="Halaman catatan">
      <button type="button" :disabled="loading || meta.current_page <= 1" class="flex size-9 items-center justify-center rounded-full bg-surface/80 transition-colors hover:bg-surface focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40" aria-label="Catatan lebih baru" @click="load(meta.current_page - 1)">
        <AdminIcon name="chevron-down" :size="16" class="rotate-90" />
      </button>
      <span>Halaman {{ meta.current_page }} dari {{ meta.last_page }} · {{ meta.total }} catatan</span>
      <button type="button" :disabled="loading || meta.current_page >= meta.last_page" class="flex size-9 items-center justify-center rounded-full bg-surface/80 transition-colors hover:bg-surface focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40" aria-label="Catatan lebih lama" @click="load(meta.current_page + 1)">
        <AdminIcon name="chevron-down" :size="16" class="-rotate-90" />
      </button>
    </nav>
  </section>
</template>
