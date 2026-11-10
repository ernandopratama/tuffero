import { pageData } from './site';

const pad = (n) => String(n).padStart(2, '0');

// Hitung mundur kampanye: berhenti di nol dan berganti teks "sudah berakhir". Tanpa animasi, hanya teks yang berganti tiap detik.
export const countdown = () => ({
    words: {},
    end: 0,
    label: '',
    left: 0,
    timer: null,

    init() {
        this.words = pageData('tf-promo-data');
        this.end = new Date(this.$el.dataset.end).getTime();
        this.label = this.$el.dataset.label;
        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    },
    destroy() { clearInterval(this.timer); },
    tick() {
        this.left = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
        if (this.left === 0) clearInterval(this.timer);
    },
    get text() {
        if (this.left === 0) return this.words.ended;
        const days = Math.floor(this.left / 86400);
        const clock = [Math.floor(this.left / 3600) % 24, Math.floor(this.left / 60) % 60, this.left % 60].map(pad).join(':');
        return this.label + ' ' + (days > 0 ? this.words.days.replace('{d}', days) + ' ' : '') + clock;
    },
});
