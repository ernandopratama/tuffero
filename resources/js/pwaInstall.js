// Tombol pasang aplikasi (PWA, D-144), dipakai toko (Alpine) dan admin (Vue).
// Android, Chrome, dan Edge: dialog pasang bawaan lewat beforeinstallprompt. iPhone dan iPad tidak punya API itu: hanya petunjuk Share.
// Modul ini diimpor di entri halaman supaya pendengar terpasang sebelum browser mengirim event.
let deferred = null;
let installedNow = false;
const listeners = new Set();
const notify = () => listeners.forEach((fn) => fn());

window.addEventListener('beforeinstallprompt', (event) => {
    // Pemasangan hanya dari tombol di profil atau akun, bukan banner otomatis browser.
    event.preventDefault();
    deferred = event;
    notify();
});
window.addEventListener('appinstalled', () => {
    deferred = null;
    installedNow = true;
    notify();
});

const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const ios = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/** installed: sudah dibuka sebagai aplikasi atau baru dipasang; ready: tombol bisa langsung memasang; ios: tampilkan petunjuk; unsupported: browser lain atau sudah terpasang. */
export function installState() {
    if (standalone() || installedNow) return 'installed';
    if (deferred) return 'ready';
    return ios() ? 'ios' : 'unsupported';
}

export async function promptInstall() {
    if (!deferred) return false;
    const event = deferred;
    deferred = null;
    event.prompt();
    const { outcome } = await event.userChoice;
    notify();
    return outcome === 'accepted';
}

export function onInstallChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}
