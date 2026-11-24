// Pembantu tampilan performa akun media sosial (D-151 tahap 1): nama kolom, kelompok isian per platform, format waktu tonton.
// Label mengikuti istilah di layar insight Instagram dan TikTok supaya tim bisa menyalin angka satu per satu.

export const PERIODS = [
    { value: 1, label: 'Harian', noun: 'harian' },
    { value: 7, label: 'Mingguan', noun: 'mingguan' },
    { value: 30, label: 'Bulanan', noun: 'bulanan' },
];
export const periodNoun = (days) => PERIODS.find((period) => period.value === days)?.noun ?? `${days} hari`;

// Kelompok isian per platform. `key` = kolom di server; `icon` = ikon Font Awesome (FaIcon); `unit: 'time'` = detik, diisi lewat satuan.
export const GROUPS = {
    instagram: [
        { title: 'Followers', fields: [
            { key: 'followers_total', label: 'Total followers', required: true, icon: 'users' },
            { key: 'followers_gained', label: 'Followers bertambah', icon: 'user-plus' },
            { key: 'followers_lost', label: 'Followers berkurang', icon: 'user-minus' },
        ] },
        { title: 'Tontonan dan jangkauan', fields: [
            { key: 'views', label: 'Views', icon: 'eye' },
            { key: 'accounts_reached', label: 'Accounts reached', icon: 'bullseye', hint: 'Akun unik, tidak dijumlah antarhari' },
            { key: 'accounts_engaged', label: 'Accounts engaged', icon: 'hand-pointer', hint: 'Akun unik, tidak dijumlah antarhari' },
        ] },
        { title: 'Interaksi dan kunjungan', fields: [
            { key: 'interactions', label: 'Interactions', icon: 'heart' },
            { key: 'profile_visits', label: 'Profile visits', icon: 'users' },
            { key: 'link_taps', label: 'External link taps', icon: 'link' },
        ] },
    ],
    tiktok: [
        { title: 'Followers', fields: [
            { key: 'followers_total', label: 'Total followers', required: true, icon: 'users' },
            { key: 'followers_gained', label: 'Followers bertambah', icon: 'user-plus' },
            { key: 'followers_lost', label: 'Followers berkurang', icon: 'user-minus' },
            { key: 'account_likes_total', label: 'Total likes akun', icon: 'heart' },
        ] },
        { title: 'Tontonan', fields: [
            { key: 'views', label: 'Video views', icon: 'eye' },
            { key: 'profile_visits', label: 'Profile views', icon: 'users' },
            { key: 'total_viewers', label: 'Total viewers', icon: 'bullseye' },
            { key: 'unique_viewers', label: 'Unique viewers', icon: 'bullseye', hint: 'Akun unik, tidak dijumlah antarhari' },
        ] },
        { title: 'Interaksi', fields: [
            { key: 'likes', label: 'Likes', icon: 'heart' },
            { key: 'comments', label: 'Comments', icon: 'comment' },
            { key: 'shares', label: 'Shares', icon: 'share' },
        ] },
        { title: 'Waktu tonton', fields: [
            { key: 'watch_time_seconds', label: 'Total watch time', icon: 'stopwatch', unit: 'time' },
            { key: 'avg_watch_seconds', label: 'Average watch time', icon: 'stopwatch', hint: 'Dalam detik, dibulatkan', suffix: 'detik' },
        ] },
    ],
};

export const fieldsOf = (platform) => (GROUPS[platform] ?? []).flatMap((group) => group.fields);

// Satuan input waktu tonton. Disimpan di server dalam detik.
export const TIME_UNITS = [{ value: 3600, label: 'jam' }, { value: 60, label: 'menit' }, { value: 1, label: 'detik' }];

// 360000 detik menjadi "100 jam", 5400 menjadi "1,5 jam", 90 menjadi "1,5 menit".
export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '–';
    const unit = seconds >= 3600 ? [3600, 'jam'] : seconds >= 60 ? [60, 'menit'] : [1, 'detik'];
    return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(seconds / unit[0])} ${unit[1]}`;
}

// Hari ini (offset 0) atau beberapa hari lalu menurut WIB, `Y-m-d`: sama dengan batas tanggal yang dipakai server.
export function jakartaDay(back = 0) {
    const day = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Jakarta' }));
    day.setDate(day.getDate() - back);
    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
}

export const jakartaMonth = () => jakartaDay(0).slice(0, 7);

const monthFormat = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
export const formatMonth = (value) => (value ? monthFormat.format(new Date(`${value.slice(0, 7)}-01T00:00:00`)) : '–');

// Susunan awal demografi: kelompok usia dan gender yang umum di insight; lokasi dikosongkan dan diisi sendiri.
export const AUDIENCE_PRESETS = {
    age: ['13-17', '18-24', '25-34', '35-44', '45-54', '55+'],
    gender: ['Perempuan', 'Laki-laki'],
    locations: ['', '', '', '', ''],
};
export const AUDIENCE_TITLES = { age: 'Usia', gender: 'Gender', locations: 'Lokasi' };
