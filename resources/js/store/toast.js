// Toast toko (kanan atas) lewat Alpine.store('toast'). Tiap aksi punya toast sendiri, bertumpuk, dan hilang setelah 2 detik.
// Kursor di atas toast menahan waktunya. Timer disimpan di luar state reaktif.
const DURATION = 2000;
const LEAVE_MS = 200;
const MAX_VISIBLE = 5;

export const toastStore = (words = {}) => {
    const timers = new Map();
    let seq = 0;

    return {
        words,
        items: [],
        show(text, type = 'success') {
            const item = {
                id: ++seq,
                type,
                text,
                title: words[type] || '',
                role: type === 'error' ? 'alert' : 'status',
                leaving: false,
                paused: false,
                remaining: DURATION,
                startedAt: 0,
                get isSuccess() { return this.type === 'success'; },
                get isError() { return this.type === 'error'; },
                get isInfo() { return this.type === 'info'; },
                get cls() { return `is-${this.type}${this.leaving ? ' is-leaving' : ''}${this.paused ? ' is-paused' : ''}`; },
                get style() { return `--toast-ms: ${DURATION}ms`; },
            };
            const live = this.items.filter((t) => !t.leaving);
            if (live.length >= MAX_VISIBLE) this.close(live[0].id);
            this.items = [...this.items, item];
            this.run(this.items[this.items.length - 1]);
        },
        find(id) { return this.items.find((t) => t.id === id); },
        run(item) {
            item.startedAt = Date.now();
            timers.set(item.id, setTimeout(() => this.close(item.id), item.remaining));
        },
        pause(id) {
            const item = this.find(id);
            if (!item || item.leaving || item.paused) return;
            clearTimeout(timers.get(id));
            item.remaining = Math.max(0, item.remaining - (Date.now() - item.startedAt));
            item.paused = true;
        },
        resume(id) {
            const item = this.find(id);
            if (!item || item.leaving || !item.paused) return;
            item.paused = false;
            this.run(item);
        },
        close(id) {
            const item = this.find(id);
            if (!item || item.leaving) return;
            clearTimeout(timers.get(id));
            timers.delete(id);
            item.leaving = true;
            setTimeout(() => { this.items = this.items.filter((t) => t.id !== id); }, LEAVE_MS);
        },
    };
};
