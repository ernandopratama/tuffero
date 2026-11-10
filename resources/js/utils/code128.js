// Barcode Code 128 subset B (ASCII 32 sampai 126) untuk label resi. Cukup untuk nomor resi huruf, angka, dan tanda hubung.
// Pola lebar bar dan spasi bergantian (bar dulu); simbol 106 adalah penutup dengan bar akhir.
const PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const START_B = 104;
const STOP = 106;
const QUIET = 10;

export function isEncodable(text) {
    return typeof text === 'string' && text.length > 0 && [...text].every((c) => c.charCodeAt(0) >= 32 && c.charCodeAt(0) <= 126);
}

/** Urutan nilai simbol: awal, data, checksum, penutup. */
export function code128Values(text) {
    if (!isEncodable(text)) throw new Error('Teks tidak bisa dibuat barcode.');
    const data = [...text].map((c) => c.charCodeAt(0) - 32);
    const checksum = data.reduce((sum, value, i) => sum + value * (i + 1), START_B) % 103;
    return [START_B, ...data, checksum, STOP];
}

/** Batang yang digambar sebagai { x, width } dalam satuan modul, plus lebar total termasuk zona tenang. */
export function code128Bars(text) {
    const bars = [];
    let x = QUIET;
    for (const value of code128Values(text)) {
        [...PATTERNS[value]].forEach((digit, i) => {
            const width = Number(digit);
            if (i % 2 === 0) bars.push({ x, width });
            x += width;
        });
    }
    return { bars, width: x + QUIET };
}

export const CODE128_PATTERNS = PATTERNS;
