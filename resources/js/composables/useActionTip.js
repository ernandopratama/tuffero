import { reactive } from 'vue';

// Satu tooltip untuk semua tombol ikon di tabel. Dibuat satu elemen `fixed` supaya tidak terpotong kontainer
// yang bisa digulir. Tunda 300 ms pada tombol pertama; selama 300 ms setelah tooltip lepas, tombol di sebelahnya
// langsung menampilkan tooltip tanpa animasi, jadi barisan tombol terasa cepat.
export const tip = reactive({ show: false, label: '', x: 0, y: 0, below: false, instant: false });

let timer;
let warmTimer;
let warm = false;

export function showTip(el, label, { immediate = false } = {}) {
    const box = el.getBoundingClientRect();
    const below = box.top < 56;
    clearTimeout(timer);
    clearTimeout(warmTimer);
    // Tombol di tepi kanan layar: geser tooltip ke dalam supaya label panjang tidak terpotong.
    const x = Math.min(Math.max(box.left + box.width / 2, 110), window.innerWidth - 110);
    Object.assign(tip, { label, x, y: below ? box.bottom + 8 : box.top - 8, below, instant: warm });

    const go = () => { tip.show = true; warm = true; };
    if (immediate || warm) go();
    else timer = setTimeout(go, 300);
}

export function hideTip() {
    clearTimeout(timer);
    clearTimeout(warmTimer);
    tip.show = false;
    warmTimer = setTimeout(() => { warm = false; }, 300);
}
