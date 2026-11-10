// Peta titik rumah di checkout: Leaflet + ubin OpenStreetMap, alamat lewat Nominatim.
// Dimuat dengan import() dari checkout saja supaya halaman lain tidak ikut mengunduh Leaflet.
// Kebijakan Nominatim: maksimal 1 permintaan per detik dan wajib mencantumkan atribusi OSM.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const NOMINATIM = 'https://nominatim.openstreetmap.org';
// Pusat awal sebelum alamat dikenali: Kota Malang, dekat gudang pertama.
const DEFAULT_CENTER = [-7.9666, 112.6326];
const INDONESIA = [[-11.5, 94], [6.5, 141.5]];

let last = 0;
// Antrean sederhana: setiap permintaan menunggu sampai 1,1 detik sejak permintaan sebelumnya.
const nominatim = async (path, params) => {
    const wait = Math.max(0, last + 1100 - Date.now());
    last = Date.now() + wait;
    if (wait) await new Promise((resolve) => { setTimeout(resolve, wait); });
    const query = new URLSearchParams({ format: 'jsonv2', 'accept-language': document.documentElement.lang || 'id', ...params });
    const response = await fetch(`${NOMINATIM}${path}?${query}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
};

/** @returns {Promise<{lat: number, lng: number, address: string}|null>} */
export const geocode = async (text) => {
    const [hit] = await nominatim('/search', { q: text, countrycodes: 'id', limit: '1' });
    return hit ? { lat: Number(hit.lat), lng: Number(hit.lon), address: hit.display_name } : null;
};

/** @returns {Promise<string>} */
export const reverseGeocode = async (lat, lng) => {
    const hit = await nominatim('/reverse', { lat: String(lat), lon: String(lng), zoom: '18' });
    return hit?.display_name || '';
};

// Pin bisa digeser atau dipindah dengan mengetuk peta; onPick dipanggil hanya untuk pilihan pelanggan.
export const createPinMap = (element, { label, onPick }) => {
    const map = L.map(element, { center: DEFAULT_CENTER, zoom: 12, maxBounds: INDONESIA, maxBoundsViscosity: 0.8, minZoom: 5, scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
    }).addTo(map);
    const icon = L.divIcon({ className: 'tf-map-pin', html: '<span></span>', iconSize: [32, 42], iconAnchor: [16, 40] });
    const marker = L.marker(DEFAULT_CENTER, { draggable: true, autoPan: true, icon, keyboard: true, title: label, alt: label }).addTo(map);

    const pick = (latlng) => {
        marker.setLatLng(latlng);
        onPick(latlng.lat, latlng.lng);
    };
    marker.on('dragend', () => onPick(marker.getLatLng().lat, marker.getLatLng().lng));
    map.on('click', (event) => pick(event.latlng));
    // Peta dibuat di dalam x-if, ukurannya bisa berubah setelah dibuat; tanpa ini ubin hanya tampil di pojok.
    const observer = new ResizeObserver(() => map.invalidateSize());
    observer.observe(element);

    return {
        // Dari alamat yang diketik, bukan pilihan pelanggan: tidak memanggil onPick.
        place(lat, lng, zoom = 17) {
            marker.setLatLng([lat, lng]);
            map.setView([lat, lng], zoom);
        },
        destroy() {
            observer.disconnect();
            map.remove();
        },
    };
};
