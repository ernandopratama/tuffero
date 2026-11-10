import { reactive } from 'vue';

// Toast area admin (kanan atas). Tiap aksi punya toast sendiri, bertumpuk, hilang setelah 2 detik; kursor di atas toast menahan waktunya.
// Desain sama dengan toast toko (resources/js/store/toast.js).
export const TOAST_MS = 2000;
const LEAVE_MS = 200;
const MAX_VISIBLE = 5;
const TITLES = { success: 'Berhasil', error: 'Gagal', info: 'Info' };

export const toasts = reactive([]);
const timers = new Map();
let seq = 0;

const find = (id) => toasts.find((t) => t.id === id);

function run(item) {
    item.startedAt = Date.now();
    timers.set(item.id, setTimeout(() => closeToast(item.id), item.remaining));
}

export function closeToast(id) {
    const item = find(id);
    if (!item || item.leaving) return;
    clearTimeout(timers.get(id));
    timers.delete(id);
    item.leaving = true;
    setTimeout(() => {
        const index = toasts.findIndex((t) => t.id === id);
        if (index >= 0) toasts.splice(index, 1);
    }, LEAVE_MS);
}

export function pauseToast(id) {
    const item = find(id);
    if (!item || item.leaving || item.paused) return;
    clearTimeout(timers.get(id));
    item.remaining = Math.max(0, item.remaining - (Date.now() - item.startedAt));
    item.paused = true;
}

export function resumeToast(id) {
    const item = find(id);
    if (!item || item.leaving || !item.paused) return;
    item.paused = false;
    run(item);
}

export function showToast(text, type = 'success') {
    const live = toasts.filter((t) => !t.leaving);
    if (live.length >= MAX_VISIBLE) closeToast(live[0].id);
    toasts.push(reactive({ id: ++seq, type, text, title: TITLES[type] || '', leaving: false, paused: false, remaining: TOAST_MS, startedAt: 0 }));
    run(find(seq));
}

export const toast = {
    success: (text) => showToast(text, 'success'),
    error: (text) => showToast(text, 'error'),
    info: (text) => showToast(text, 'info'),
};
