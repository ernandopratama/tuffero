// Waktu disimpan UTC di server; kolom datetime-local memakai zona waktu perangkat.
export const toLocalInput = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

export const fromLocalInput = (value) => (value ? new Date(value).toISOString() : null);

const display = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

export const formatDate = (iso) => (iso ? display.format(new Date(iso)) : '-');

const relative = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });

// "14 menit yang lalu"; lewat 7 hari kembali ke tanggal biasa supaya tidak ambigu.
export function timeAgo(iso, now = Date.now()) {
    if (!iso) return '';
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const steps = [['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            const value = Math.trunc(seconds / size);
            return Math.abs(value) > 7 && unit === 'day' ? formatDate(iso) : relative.format(value, unit);
        }
    }
    return 'baru saja';
}
