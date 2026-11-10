// Data wilayah (public/data/wilayah.json, dibangun oleh scripts/build-wilayah.mjs) dan pengurai alamat lengkap untuk checkout.
// Hanya membantu mengisi; nilai tetap teks biasa yang bisa diubah pelanggan dan divalidasi server.

const norm = (text) => ` ${String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim()} `;
const has = (haystack, name) => haystack.includes(norm(name));

// Singkatan umum di alamat Indonesia, diubah ke bentuk panjang sebelum dicocokkan.
const ABBREVIATIONS = [[/ kab /g, ' kabupaten '], [/ kec /g, ' kecamatan '], [/ prov /g, ' provinsi '], [/ kota adm /g, ' kota administrasi ']];
const PROVINCE_ALIASES = {
    jakarta: 'Daerah Khusus Ibukota Jakarta', dki: 'Daerah Khusus Ibukota Jakarta', yogyakarta: 'Daerah Istimewa Yogyakarta', jogja: 'Daerah Istimewa Yogyakarta', diy: 'Daerah Istimewa Yogyakarta',
    jatim: 'Jawa Timur', jateng: 'Jawa Tengah', jabar: 'Jawa Barat', ntb: 'Nusa Tenggara Barat', ntt: 'Nusa Tenggara Timur', sumut: 'Sumatera Utara', sumbar: 'Sumatera Barat', sumsel: 'Sumatera Selatan',
    kalbar: 'Kalimantan Barat', kalteng: 'Kalimantan Tengah', kalsel: 'Kalimantan Selatan', kaltim: 'Kalimantan Timur', kaltara: 'Kalimantan Utara', sulut: 'Sulawesi Utara', sulteng: 'Sulawesi Tengah',
    sulsel: 'Sulawesi Selatan', sultra: 'Sulawesi Tenggara', sulbar: 'Sulawesi Barat', babel: 'Kepulauan Bangka Belitung', kepri: 'Kepulauan Riau',
};
const cityBase = (name) => name.replace(/^(kabupaten|kota)( administrasi| adm\.?)?\s+/i, '');
const longest = (list, key = 'name') => list.sort((a, b) => b[key].length - a[key].length)[0];
const same = (a, b) => norm(a) === norm(b);

let loading = null;

// Satu berkas untuk semua provinsi (~70 KB gzip), dimuat sekali saat halaman checkout dibuka.
export const loadRegions = () => {
    loading ??= fetch('/data/wilayah.json').then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    }).then((data) => {
        const provinces = [];
        const cities = [];
        const districts = [];
        data.forEach(([province, cityList]) => {
            provinces.push({ name: province });
            cityList.forEach(([city, districtList]) => {
                cities.push({ name: city, province, base: cityBase(city) });
                districtList.forEach(([district, postal]) => districts.push({ name: district, city, province, postal: postal.map(String) }));
            });
        });
        return { provinces, cities, districts };
    });
    loading.catch(() => { loading = null; });
    return loading;
};

export const findDistrict = (regions, form) => regions?.districts.find((d) => same(d.name, form.district) && same(d.city, form.city)) ?? null;

// Pilihan dropdown dengan pencarian. Kecamatan dan kota dibatasi ke provinsi terpilih; kecamatan di kota terpilih tampil lebih dulu.
export const regionOptions = (regions, field, form, query) => {
    if (!regions) return [];
    const q = norm(query).trim();
    let list;
    if (field === 'province') list = regions.provinces.map((p) => ({ key: p.name, label: p.name, hint: '', value: { province: p.name } }));
    else if (field === 'city') {
        list = regions.cities.filter((c) => !form.province || same(c.province, form.province))
            .map((c) => ({ key: `${c.province}|${c.name}`, label: c.name, hint: form.province ? '' : c.province, value: { city: c.name, province: c.province } }));
    } else {
        list = regions.districts.filter((d) => !form.province || same(d.province, form.province))
            .map((d) => ({ key: `${d.city}|${d.name}`, label: d.name, hint: form.province ? d.city : `${d.city}, ${d.province}`, value: { district: d.name, city: d.city, province: d.province }, here: same(d.city, form.city) }));
        list.sort((a, b) => (b.here ? 1 : 0) - (a.here ? 1 : 0));
    }
    if (q) {
        list = list.filter((o) => norm(o.label).includes(q) || norm(o.hint).includes(q));
        // "malang" menampilkan Kota/Kabupaten Malang sebelum Pemalang.
        const starts = (o) => (norm(cityBase(o.label)).startsWith(` ${q}`) ? 1 : 0);
        list.sort((a, b) => starts(b) - starts(a));
    }
    // shortcut: maksimal 60 baris tampil, cukup karena pelanggan mengetik untuk mencari; virtual list bila perlu semua.
    return list.slice(0, 60);
};

// Pencarian peta (Nominatim) gagal bila ada "Kec.", nomor rumah, RT/RW, atau kode pos. Sisakan nama jalan dan kelurahan saja,
// lalu buat daftar pencarian dari yang paling rinci. `precise` = hasilnya cukup dekat rumah untuk disimpan sebagai titik.
export const mapQueries = ({ street, district, city, province }) => {
    const region = [district, city, province].filter(Boolean);
    const skip = new Set(region.map((name) => norm(name).trim()).concat(region.map((name) => norm(cityBase(name)).trim())));
    const parts = String(street || '').split(',')
        .map((part) => part.replace(/\b(no|nomor|blok|blk|rt|rw|kav|gg)\b\.?\s*[\w/.-]*/gi, ' ').replace(/\b\d+[a-z]?\b/gi, ' ').replace(/\s+/g, ' ').trim())
        .filter((part) => part.length > 2 && !/^(kec|kecamatan|kab|kabupaten|kota|prov|provinsi)\b/i.test(part) && !skip.has(norm(part).trim()));
    const join = (list) => list.filter(Boolean).join(', ');
    const list = [
        ...(parts.length ? [join([...parts, ...region])] : []),
        ...(parts.length > 1 ? [join([parts[0], ...region]), join([parts[parts.length - 1], ...region])] : []),
    ].map((q) => ({ q, precise: true }));
    list.push({ q: join(region), precise: false }, { q: join([city, province]), precise: false });
    const seen = new Set();
    return list.filter(({ q }) => q && city && !seen.has(q) && seen.add(q));
};

// Mengurai alamat lengkap yang diketik menjadi provinsi, kota/kabupaten, kecamatan, dan kode pos. Bagian yang tidak yakin dibiarkan kosong.
export const parseAddress = (text, regions) => {
    const result = {};
    if (!regions || !text) return result;
    let t = norm(text);
    ABBREVIATIONS.forEach(([pattern, value]) => { t = t.replace(pattern, value); });

    const postal = String(text).match(/\b\d{5}\b/g);
    if (postal) result.postal_code = postal[postal.length - 1];

    let province = longest(regions.provinces.filter((p) => has(t, p.name)))?.name;
    province ??= Object.entries(PROVINCE_ALIASES).find(([alias]) => t.includes(` ${alias} `))?.[1];

    // "Kota Batu" atau "Kabupaten Malang" lebih pasti daripada nama tanpa awalan.
    const inProvince = (item) => !province || item.province === province;
    const cityCandidates = regions.cities.filter(inProvince);
    // Nama tanpa awalan ("Malang") hanya dipakai bila provinsinya dikenali, supaya "Jl. Batu Ampar" tidak dianggap Kota Batu.
    let city = longest(cityCandidates.filter((c) => has(t, c.name)), 'name')
        ?? (province ? longest(cityCandidates.filter((c) => has(t, c.base)), 'base') : undefined);
    let rest = t;
    if (city) rest = rest.replace(norm(city.name), ' ').replace(norm(city.base), ' ');
    if (province) rest = rest.replace(norm(province), ' ');

    // Kecamatan: "Kec. X" di mana saja dalam provinsi, atau nama saja bila kotanya sudah dikenali.
    const pool = regions.districts.filter((d) => (city ? d.city === city.name : inProvince(d)));
    let district = longest(pool.filter((d) => t.includes(` kecamatan${norm(d.name)}`)));
    if (!district && city) district = longest(pool.filter((d) => has(rest, d.name)));
    if (!district && !city) {
        const explicit = regions.districts.filter((d) => inProvince(d) && t.includes(` kecamatan${norm(d.name)}`));
        if (explicit.length === 1) [district] = explicit;
    }

    if (district) {
        result.district = district.name;
        city ??= regions.cities.find((c) => c.name === district.city);
        if (!result.postal_code && district.postal.length === 1) [result.postal_code] = district.postal;
    }
    if (city) {
        result.city = city.name;
        province ??= city.province;
    }
    if (province) result.province = province;
    return result;
};
