<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../utils/api';
import { apiError } from '../utils/apiError';
import { formatDate } from '../utils/datetime';
import { useAuthStore } from '../stores/auth';
import AdminIcon from './AdminIcon.vue';

// Template balasan CS (hanya pemegang izin templates.manage). Server mengisi variabel dari data pesanan; teks bisa disunting
// sebelum dipakai. CS membuka WhatsApp dengan teks terisi, menyalin teksnya, atau (pemegang izin emails.send) mengirim email
// ke pelanggan dengan menekan Kirim. Tidak ada pesan yang terkirim tanpa tindakan CS.
// url = endpoint template untuk satu data, mis. /admin/refunds/1/reply-templates.
const props = defineProps({ url: { type: String, required: true } });

const auth = useAuthStore();
const templates = ref([]);
const whatsapp = ref(null);
const chosen = ref('');
const text = ref('');
const loading = ref(false);
const error = ref('');
const copied = ref(false);

// Email: alamat tujuan selalu dari server (disamarkan di sini); isi email tidak disimpan, hanya jejak kirimnya.
const emailUrl = computed(() => props.url.replace(/reply-templates$/, 'customer-emails'));
const emailTo = ref(null);
const sentEmails = ref([]);
const emailOpen = ref(false);
const subject = ref('');
const sending = ref(false);
const emailError = ref('');
const emailOk = ref('');

const press = 'transition-[transform,background-color] duration-[160ms] ease-out-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary active:scale-[0.97] disabled:opacity-60';
const unfinished = computed(() => /\{[a-z_]+\}|\(isi [^)]*\)/.test(text.value));

async function load() {
    if (!auth.can('templates.manage')) return;
    loading.value = true;
    error.value = '';
    chosen.value = '';
    text.value = '';
    emailOpen.value = false;
    emailOk.value = '';
    try {
        const { data } = await api.get(props.url);
        templates.value = data.data.items;
        whatsapp.value = data.data.whatsapp;
        await loadEmails();
    } catch (e) {
        error.value = apiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function loadEmails() {
    if (!auth.can('emails.send')) return;
    try {
        const { data } = await api.get(emailUrl.value);
        emailTo.value = data.data.to;
        sentEmails.value = data.data.items;
    } catch {
        emailTo.value = null; // tanpa riwayat, tombol email disembunyikan; WhatsApp tetap bisa dipakai
    }
}

function pick() {
    const template = templates.value.find((t) => String(t.id) === chosen.value);
    text.value = template?.text || '';
    subject.value = template?.title || '';
    copied.value = false;
    emailOpen.value = false;
    emailOk.value = '';
}

async function copy() {
    try {
        await navigator.clipboard.writeText(text.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        error.value = 'Teks belum bisa disalin. Pilih teksnya lalu salin manual.';
    }
}

async function sendEmail() {
    sending.value = true;
    emailError.value = '';
    emailOk.value = '';
    try {
        await api.post(emailUrl.value, { subject: subject.value, text: text.value });
        emailOk.value = `Email terkirim ke ${emailTo.value}.`;
        emailOpen.value = false;
        await loadEmails();
    } catch (e) {
        const { message, fields } = apiError(e);
        emailError.value = fields.subject?.[0] || fields.text?.[0] || message;
    } finally {
        sending.value = false;
    }
}

const waHref = () => `https://wa.me/${whatsapp.value}?text=${encodeURIComponent(text.value)}`;

watch(() => props.url, load);
onMounted(load);
</script>

<template>
  <section v-if="auth.can('templates.manage') && (templates.length || error)" class="rounded-2xl border border-border p-4" aria-labelledby="reply-title">
    <h3 id="reply-title" class="flex items-center gap-2 text-label-md"><AdminIcon name="mail" :size="16" />Balas pelanggan</h3>
    <p class="mt-1 text-body-sm text-secondary">Pilih template, periksa teksnya, lalu buka WhatsApp, salin, atau kirim email. Tidak ada pesan terkirim tanpa Anda menekan tombolnya.</p>

    <label class="mt-space-sm block text-label-md">
      Template
      <select v-model="chosen" class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary" :disabled="loading" @change="pick">
        <option value="">Pilih template...</option>
        <option v-for="t in templates" :key="t.id" :value="String(t.id)">{{ t.title }}</option>
      </select>
    </label>

    <template v-if="chosen">
      <label class="mt-space-sm block text-label-md">
        Teks balasan
        <textarea v-model="text" rows="6" maxlength="2000" class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary"></textarea>
      </label>
      <p v-if="unfinished" role="status" class="mt-1 text-body-sm text-warning">Masih ada bagian yang perlu dilengkapi, misalnya yang berada dalam tanda kurung atau kurung kurawal.</p>
      <div class="mt-space-sm flex flex-wrap gap-2">
        <a v-if="whatsapp" :href="waHref()" target="_blank" rel="noopener noreferrer" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="chat" :size="16" />Buka WhatsApp</a>
        <button v-if="emailTo" type="button" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" :aria-expanded="emailOpen" @click="emailOpen = !emailOpen"><AdminIcon name="mail" :size="16" />Kirim email</button>
        <button type="button" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="copy"><AdminIcon :name="copied ? 'check' : 'copy'" :size="16" />{{ copied ? 'Tersalin' : 'Salin teks' }}</button>
      </div>

      <!-- Konfirmasi kirim email: alamat disamarkan, isi tampil di kolom teks di atas -->
      <form v-if="emailOpen && emailTo" class="mt-space-sm space-y-2 rounded-2xl bg-background/70 p-3" @submit.prevent="sendEmail">
        <p class="text-body-sm">Email akan dikirim ke <strong>{{ emailTo }}</strong> dengan isi seperti kolom "Teks balasan" di atas, ditambah tanda tangan Tim Customer Service Tuffero.</p>
        <label class="block text-label-md">
          Subjek
          <input v-model="subject" required maxlength="150" class="mt-1 w-full rounded-2xl border border-secondary bg-surface px-3 py-2 text-body-md font-normal focus:outline-2 focus:outline-primary">
        </label>
        <p v-if="unfinished" role="alert" class="text-body-sm text-warning">Lengkapi dulu bagian yang masih dalam tanda kurung sebelum mengirim.</p>
        <div class="flex flex-wrap gap-2">
          <button :disabled="sending || unfinished || !text.trim()" :class="`inline-flex min-h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-label-md hover:bg-accent-hover ${press}`"><AdminIcon name="check" :size="16" />{{ sending ? 'Mengirim...' : 'Kirim sekarang' }}</button>
          <button type="button" :class="`inline-flex min-h-10 items-center rounded-full border border-secondary px-4 text-label-md hover:bg-accent-tint-15 ${press}`" @click="emailOpen = false">Batal</button>
        </div>
        <p v-if="emailError" role="alert" class="text-body-sm text-error">{{ emailError }}</p>
      </form>
      <p v-if="emailOk" role="status" class="mt-2 flex items-center gap-1 text-body-sm text-success"><AdminIcon name="check" :size="14" />{{ emailOk }}</p>
    </template>

    <ul v-if="sentEmails.length" class="mt-space-sm space-y-1 border-t border-border pt-2 text-body-sm text-secondary" aria-label="Email yang sudah dikirim">
      <li v-for="mail in sentEmails" :key="mail.id" class="break-words">
        <span :class="mail.status === 'sent' ? 'text-success' : 'text-error'">{{ mail.status === 'sent' ? 'Terkirim' : 'Gagal' }}</span>
        · {{ mail.subject }} · {{ mail.sent_by }} · {{ formatDate(mail.created_at) }}
      </li>
    </ul>

    <p v-if="error" role="alert" class="mt-2 text-body-sm text-error">{{ error }}</p>
  </section>
</template>
