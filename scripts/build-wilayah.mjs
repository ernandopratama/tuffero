// Membangun public/data/wilayah.json untuk pilihan alamat di checkout.
// Sumber (MIT): https://github.com/cahyadsn/wilayah db/wilayah.sql (Kepmendagri 300.2.2-2138 Tahun 2025)
// dan https://github.com/cahyadsn/wilayah_kodepos db/wilayah_kodepos.sql.
// Pakai: node scripts/build-wilayah.mjs <wilayah.sql> <wilayah_kodepos.sql>
// Hasil: [[provinsi, [[kota/kabupaten, [[kecamatan, [kode pos...]], ...]], ...]], ...]
import { readFileSync, writeFileSync } from 'node:fs';

const [wilayahSql, kodeposSql] = process.argv.slice(2);
if (!wilayahSql || !kodeposSql) throw new Error('Usage: node scripts/build-wilayah.mjs <wilayah.sql> <wilayah_kodepos.sql>');

const rows = (file) => [...readFileSync(file, 'utf8').matchAll(/\('([\d.]+)',\s*'((?:[^'\\]|\\.|'')*)'\)/g)].map(([, code, value]) => [code, value.replace(/''|\\'/g, "'").trim()]);

// Kode pos per kecamatan dari kode pos tiap desa (kode desa = kode kecamatan + 1 segmen).
const postal = new Map();
for (const [code, zip] of rows(kodeposSql)) {
    const district = code.split('.').slice(0, 3).join('.');
    if (!postal.has(district)) postal.set(district, new Set());
    postal.get(district).add(Number(zip));
}

const provinces = new Map();
const cities = new Map();
for (const [code, name] of rows(wilayahSql)) {
    const parts = code.split('.');
    if (parts.length === 1) provinces.set(code, [name, []]);
    else if (parts.length === 2) {
        const city = [name, []];
        cities.set(code, city);
        provinces.get(parts[0])[1].push(city);
    } else if (parts.length === 3) {
        cities.get(parts.slice(0, 2).join('.'))[1].push([name, [...(postal.get(code) ?? [])].sort()]);
    }
}

const byName = (a, b) => a[0].localeCompare(b[0], 'id');
const data = [...provinces.values()].sort(byName);
data.forEach(([, list]) => { list.sort(byName); list.forEach(([, districts]) => districts.sort(byName)); });

const counts = [data.length, data.reduce((n, p) => n + p[1].length, 0), data.reduce((n, p) => n + p[1].reduce((m, c) => m + c[1].length, 0), 0)];
// Pemeriksaan kasar: 38 provinsi, 514 kota/kabupaten, lebih dari 7000 kecamatan (Kepmendagri 2025).
if (counts[0] !== 38 || counts[1] !== 514 || counts[2] < 7000) throw new Error(`Unexpected counts: ${counts.join('/')}`);

writeFileSync(new URL('../public/data/wilayah.json', import.meta.url), JSON.stringify(data));
console.log(`provinsi ${counts[0]}, kota/kabupaten ${counts[1]}, kecamatan ${counts[2]}`);
