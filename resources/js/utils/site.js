import { reactive } from 'vue';
// Fakta dan tautan bersama halaman toko. Sumber: jawaban tim 2026-11-03 (OQ-21).
export const TIKTOK_URL = 'https://www.tiktok.com/@tuffero.patisserie';
export const TIKTOK_HANDLE = '@tuffero.patisserie';
export const INSTAGRAM_URL = 'https://www.instagram.com/tuffero.bakery/';
export const INSTAGRAM_HANDLE = '@tuffero.bakery';
export const MAPS_URL = 'https://www.google.com/maps/place/Tuffero+Patisserie+(Rumah+Produksi+Macaron+Tuffero)/@-7.9266161,112.5846427,17z';
// Tanpa kunci API; hanya dimuat setelah pengguna meminta peta.
export const MAP_LAT = -7.9266161;
export const MAP_LNG = 112.5846427;
export const MAPS_DIRECTIONS_URL = `https://www.google.com/maps/dir/?api=1&destination=${MAP_LAT},${MAP_LNG}`;
export const MAPS_EMBED_URL = 'https://www.google.com/maps?q=-7.9266161,112.5846427&z=16&output=embed';
// Satu nomor untuk telepon dan WhatsApp (OQ-24). Nilai awal sama dengan seeder; loadContact() menimpanya dengan contact.whatsapp_number dari dashboard.
export const contact = reactive({ whatsapp: '+6281231481755', email: '' });
const waDigits = () => contact.whatsapp.replace(/\D/g, '');
export const phoneDisplay = () => (contact.whatsapp.startsWith('+62') ? `0${waDigits().slice(2)}`.replace(/(\d{4})(?=\d)/g, '$1-') : contact.whatsapp);
export const phoneHref = () => `tel:+${waDigits()}`;
export const whatsappUrl = () => `https://wa.me/${waDigits()}`;
export async function loadContact() {
    try {
        const res = await fetch('/api/v1/settings/public', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const { settings } = (await res.json()).data;
        if (settings['contact.whatsapp_number']) contact.whatsapp = settings['contact.whatsapp_number'];
        if (settings['contact.admin_email']) contact.email = settings['contact.admin_email'];
    } catch { /* tanpa jaringan: nilai awal tetap dipakai */ }
}

export const ROUTES = { about: '/tentang-kami', services: '/layanan', products: '/produk', offers: '/#offers', gallery: '/galeri', contact: '/kontak', account: '/masuk', profile: '/akun', orders: '/akun/pesanan', addresses: '/akun/alamat', cart: '/keranjang', checkout: '/checkout', track: '/cek-pesanan' };
export const NAV_LINKS = ['/', ROUTES.about, ROUTES.services, ROUTES.products, ROUTES.offers, ROUTES.gallery, ROUTES.contact];
export const FOOTER_LINKS = ['/', ROUTES.about, ROUTES.services, ROUTES.products, ROUTES.offers];
// Jangkar baris layanan; urutannya sama dengan kartu Layanan di landing.
export const SERVICE_IDS = ['pesan-online', 'hampers', 'oleh-oleh', 'kolaborasi'];

// Tuffero berdiri 8 Juni 2021. Tahun berkarya dihitung dari tanggal itu agar tidak basi.
const today = new Date();
export const YEARS = today.getFullYear() - 2021 - (today < new Date(today.getFullYear(), 5, 8) ? 1 : 0);
// Rating Google Maps: 4,6 dari 28 ulasan saat diambil 2026-11-03.
export const RATING = '4,6';

// Jam operasional dari tim (OQ-21). Senin = indeks 0; null = tutup. Angka = jam, waktu Malang (WIB).
export const OPEN_HOURS = [[9, 16], [9, 16], [9, 16], [9, 16], [9, 16], [9, 16], null];
export const openStatus = (date = new Date()) => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Jakarta', weekday: 'short', hour: 'numeric', hourCycle: 'h23' }).formatToParts(date).map((x) => [x.type, x.value]));
    const day = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(parts.weekday);
    const hours = OPEN_HOURS[day];
    const hour = Number(parts.hour);
    return { day, open: !!hours && hour >= hours[0] && hour < hours[1] };
};

export const productPhoto = (name) => `/images/Produk/${name}_result.webp`;
