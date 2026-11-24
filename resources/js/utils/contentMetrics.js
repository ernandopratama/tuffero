// Pembantu tampilan metrik konten (D-150): dipakai kartu Ringkasan dan halaman Performa Konten.
export const PLATFORMS = { tiktok: 'TikTok', instagram: 'Instagram' };
// Warna penuh lencana platform; sama dengan lencana di halaman Draf Konten.
export const PLATFORM_SOLID = { instagram: 'bg-[#C13584]', tiktok: 'bg-[#111827]' };
// Warna garis grafik dan nama ikon merek Font Awesome (FaIcon) per platform.
export const PLATFORM_COLOR = { tiktok: '#111827', instagram: '#C13584' };
export const PLATFORM_ICON = { tiktok: 'tiktok', instagram: 'instagram' };
// Tingkat dibandingkan median views konten sendiri; `normal` dan kosong tidak diberi lencana.
export const LEVELS = {
    outlier: { label: 'Menonjol', cls: 'bg-success-surface text-success' },
    low: { label: 'Di bawah normal', cls: 'bg-warning-surface text-warning' },
};

const number = new Intl.NumberFormat('id-ID');
const percent = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const day = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const shortDay = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });
const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });

export const formatNumber = (value) => (value === null || value === undefined ? '–' : number.format(value));
export const formatPercent = (value) => (value === null || value === undefined ? '–' : `${percent.format(value)}%`);
export const formatSigned = (value) => (value === null || value === undefined ? '' : `${value >= 0 ? '+' : '−'}${number.format(Math.abs(value))}`);
export const formatDay = (value) => (value ? day.format(new Date(`${value}T00:00:00`)) : '–');
export const formatShortDay = (value) => (value ? shortDay.format(new Date(`${value}T00:00:00`)) : '–');
// Angka sumbu grafik: 1,2 rb, 3,4 jt.
export const formatCompact = (value) => compact.format(value);

// Tinggi batang tren (px) sebanding dengan nilai tertinggi; minimal 4 px supaya nol tetap terlihat.
export function sparkHeights(values, max = 28) {
    if (!values?.length) return [];
    const top = Math.max(...values, 1);
    return values.map((value) => Math.max(4, Math.round((value / top) * max)));
}
