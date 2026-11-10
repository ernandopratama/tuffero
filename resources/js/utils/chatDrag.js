// Maskot chat bisa dipindah: tekan dan tahan sebentar, lalu geser. Ketukan singkat tetap membuka chat.
// Dipakai widget toko (Alpine) dan admin (Vue). Ringan: tidak ada pekerjaan saat diam, dan saat digeser hanya
// satu `transform` per frame (compositor), tanpa membaca atau mengubah layout.
const KEY = 'tuffero.cs-pos';
const HOLD_MS = 300; // lama menahan sebelum bisa digeser
const SLOP = 8; // geser lebih dari ini sebelum ditahan = pengguna menggulir atau mengklik biasa
const MARGIN = 8; // jarak minimum dari tepi layar

// Posisi disimpan sebagai selisih dari posisi bawaan (kanan bawah), bukan koordinat layar:
// posisi bawaan berubah per halaman (mis. bar beli di halaman produk), selisihnya tetap masuk akal.
const load = () => {
    try {
        const { x, y } = JSON.parse(localStorage.getItem(KEY));
        return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : { x: 0, y: 0 };
    } catch {
        return { x: 0, y: 0 };
    }
};

export const enableLauncherDrag = (el) => {
    let offset = load();
    let hold = null; // menunggu tahan selesai
    let drag = null; // sedang digeser
    let suppressClick = false;
    let frame = 0;
    let resizeFrame = 0;

    const apply = () => { el.style.transform = offset.x || offset.y ? `translate3d(${offset.x}px, ${offset.y}px, 0)` : ''; };

    // Rentang selisih yang masih di dalam layar. Dibaca sekali per geseran dan per perubahan ukuran, bukan per gerakan.
    const bounds = () => {
        const r = el.getBoundingClientRect();
        const homeLeft = r.left - offset.x;
        const homeTop = r.top - offset.y;
        return {
            minX: MARGIN - homeLeft,
            maxX: document.documentElement.clientWidth - MARGIN - r.width - homeLeft,
            minY: MARGIN - homeTop,
            maxY: window.innerHeight - MARGIN - r.height - homeTop,
        };
    };
    const clamp = (value, b) => ({ x: Math.min(b.maxX, Math.max(b.minX, value.x)), y: Math.min(b.maxY, Math.max(b.minY, value.y)) });

    const cancelHold = () => {
        if (hold) clearTimeout(hold.timer);
        hold = null;
    };

    const start = () => {
        drag = { id: hold.id, x: hold.x, y: hold.y, base: { ...offset }, bounds: bounds() };
        hold = null;
        el.setPointerCapture?.(drag.id);
        el.dataset.dragging = 'true';
        // Getar singkat (Android) sebagai tanda siap digeser; Chrome menolaknya tanpa sentuhan nyata sebelumnya.
        if (navigator.userActivation?.hasBeenActive) navigator.vibrate?.(8);
    };

    const finish = () => {
        cancelAnimationFrame(frame);
        apply();
        delete el.dataset.dragging;
        try { localStorage.setItem(KEY, JSON.stringify(offset)); } catch { /* penyimpanan diblokir: posisi hanya bertahan di halaman ini */ }
        drag = null;
        // Pelepasan jari dilanjutkan klik; klik itu bukan permintaan membuka chat.
        suppressClick = true;
        setTimeout(() => { suppressClick = false; }, 400);
    };

    el.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || drag) return;
        suppressClick = false;
        cancelHold();
        hold = { id: event.pointerId, x: event.clientX, y: event.clientY, timer: setTimeout(start, HOLD_MS) };
    });

    el.addEventListener('pointermove', (event) => {
        if (hold && event.pointerId === hold.id) {
            if (Math.hypot(event.clientX - hold.x, event.clientY - hold.y) > SLOP) cancelHold();
            return;
        }
        if (!drag || event.pointerId !== drag.id) return;
        offset = clamp({ x: drag.base.x + event.clientX - drag.x, y: drag.base.y + event.clientY - drag.y }, drag.bounds);
        if (!frame) frame = requestAnimationFrame(() => { frame = 0; apply(); });
    });

    const release = (event) => {
        if (drag && event.pointerId === drag.id) finish();
        else if (hold && event.pointerId === hold.id) cancelHold();
    };
    el.addEventListener('pointerup', release);
    el.addEventListener('pointercancel', release);

    // Saat digeser dengan jari, halaman tidak boleh ikut menggulir.
    el.addEventListener('touchmove', (event) => { if (drag && event.cancelable) event.preventDefault(); }, { passive: false });
    // Tekan lama di layar sentuh memunculkan menu konteks; menu itu tidak berguna di tombol ini.
    el.addEventListener('contextmenu', (event) => event.preventDefault());
    // Capture: berjalan sebelum penangan klik milik widget, sehingga klik sesudah geseran tidak membuka chat.
    el.addEventListener('click', (event) => {
        if (!suppressClick) return;
        event.stopImmediatePropagation();
        event.preventDefault();
        suppressClick = false;
    }, true);

    // Layar berubah (putar ponsel, jendela diperkecil): tarik kembali ke dalam layar.
    const onResize = () => {
        if (resizeFrame || drag) return;
        resizeFrame = requestAnimationFrame(() => {
            resizeFrame = 0;
            offset = clamp(offset, bounds());
            apply();
        });
    };
    window.addEventListener('resize', onResize, { passive: true });

    // Pertama kali: terapkan posisi tersimpan setelah tata letak siap, lalu pastikan masih di dalam layar.
    resizeFrame = requestAnimationFrame(() => {
        resizeFrame = 0;
        offset = clamp(offset, bounds());
        apply();
    });

    return () => {
        cancelHold();
        cancelAnimationFrame(frame);
        cancelAnimationFrame(resizeFrame);
        window.removeEventListener('resize', onResize);
    };
};
