// Isian alamat bersama untuk checkout dan Alamat Saya (D-074, D-077): isi otomatis dari alamat lengkap,
// dropdown wilayah dengan pencarian, dan peta titik rumah. Komponen pemakai wajib punya `form` (street, district,
// city, province, postal_code), `errors`, dan `words.search` + `words.map`.
import { findDistrict, loadRegions, mapQueries, parseAddress, regionOptions } from './address';

const sameText = (a, b) => String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();

// Gabung beberapa objek komponen tanpa menjalankan getter-nya (spread biasa menyalin hasil getter, bukan getter-nya).
export const mix = (...parts) => Object.defineProperties({}, Object.assign({}, ...parts.map((part) => Object.getOwnPropertyDescriptors(part))));

/** @param {string} prefix awalan id isian, misalnya "co" untuk checkout atau "ad" untuk Alamat Saya. */
export const addressPicker = (prefix) => {
    // Objek Leaflet dan timer di luar data Alpine: proxy reaktif merusak Leaflet.
    let pinMap = null;
    let mapApi = null;
    let parseTimer = null;
    let geoTimer = null;
    let geoRequest = 0;

    return {
        fieldPrefix: prefix,
        regions: null,
        combo: '',
        comboQuery: '',
        comboActive: 0,
        // Nilai yang terakhir diisi otomatis dari alamat lengkap; hanya nilai ini (atau yang kosong) yang boleh ditimpa lagi.
        auto: {},
        pin: null,
        pinAddress: '',
        pinState: '',
        pinManual: false,
        mapFailed: false,

        // Dipanggil dari init() komponen pemakai.
        initAddressPicker() {
            this.$watch('form.street', () => {
                clearTimeout(parseTimer);
                parseTimer = setTimeout(() => this.autofill(), 500);
            });
            ['form.street', 'form.district', 'form.city', 'form.province'].forEach((key) => this.$watch(key, () => this.scheduleGeocode()));
            // Dibekukan supaya Alpine tidak membungkus ribuan data wilayah dengan proxy reaktif.
            loadRegions().then((regions) => { this.regions = Object.freeze(regions); this.autofill(); }).catch(() => { /* dropdown tetap menerima ketikan bebas */ });
        },

        // Alamat lengkap -> provinsi, kecamatan, kota/kabupaten, kode pos. Isian yang diubah pelanggan tidak ditimpa.
        autofill() {
            const found = parseAddress(this.form.street, this.regions);
            const before = [this.form.district, this.form.city];
            Object.entries(found).forEach(([key, value]) => {
                if (this.form[key] && this.form[key] !== this.auto[key]) return;
                this.form[key] = value;
                this.auto[key] = value;
                delete this.errors[key];
            });
            if (before[0] !== this.form.district || before[1] !== this.form.city) this.resetPin();
        },

        // Dropdown dengan pencarian (pola combobox ARIA). Ketikan bebas tetap bisa dipakai bila wilayah tidak ada di data.
        comboOpen(field) { return this.combo === field; },
        comboValue(field) { return this.combo === field ? this.comboQuery : this.form[field]; },
        comboPlaceholder(field) { return this.form[field] || this.words.search[field]; },
        comboActiveId(field) { return this.combo === field && this.comboOptions(field).length ? `${this.fieldPrefix}-${field}-opt-${this.comboActive}` : ''; },
        comboOptionId(field, i) { return `${this.fieldPrefix}-${field}-opt-${i}`; },
        comboClass(i) { return i === this.comboActive ? 'is-active' : ''; },
        comboSelected(field, option) { return sameText(option.label, this.form[field]); },
        get regionsLoading() { return !this.regions; },
        openCombo(field) {
            if (this.combo === field) return;
            this.combo = field;
            this.comboQuery = '';
            this.comboActive = 0;
        },
        closeCombo(field) { if (this.combo === field) this.combo = ''; },
        toggleCombo(field) {
            if (this.combo === field) this.combo = '';
            else {
                this.openCombo(field);
                document.getElementById(`${this.fieldPrefix}-${field}`)?.focus();
            }
        },
        typeCombo(field, event) {
            this.combo = field;
            this.comboQuery = event.target.value;
            this.comboActive = 0;
        },
        comboOptions(field) {
            const list = regionOptions(this.regions, field, this.form, this.comboQuery);
            const text = this.comboQuery.trim();
            if (text && !list.some((option) => sameText(option.label, text))) {
                list.push({ key: '__free', label: this.words.search.use.replace(':text', text), hint: '', value: { [field]: text } });
            }
            return list;
        },
        comboEmpty(field) { return this.combo === field && !this.regionsLoading && !this.comboOptions(field).length; },
        comboKey(field, event) {
            const count = this.comboOptions(field).length;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                this.openCombo(field);
                if (count) this.comboActive = (this.comboActive + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            } else if (event.key === 'Enter' && this.combo === field) {
                event.preventDefault();
                const option = this.comboOptions(field)[this.comboActive];
                if (option) this.pickCombo(field, option);
            } else if (event.key === 'Escape' || event.key === 'Tab') {
                this.closeCombo(field);
            }
        },
        pickCombo(field, option) {
            const form = this.form;
            const before = [form.district, form.city];
            const next = { ...option.value };
            // Pilihan di atas tidak boleh menyisakan pilihan di bawahnya yang sudah tidak cocok.
            if (field === 'province' && form.city && !this.regions?.cities.some((c) => sameText(c.name, form.city) && sameText(c.province, next.province))) {
                Object.assign(next, { city: '', district: '' });
            }
            if (field === 'city' && form.district && this.regions && !findDistrict(this.regions, { district: form.district, city: next.city })) next.district = '';
            Object.entries(next).forEach(([key, value]) => {
                form[key] = value;
                delete this.auto[key];
                delete this.errors[key];
            });
            const district = findDistrict(this.regions, form);
            // Kode pos lama yang bukan milik kecamatan ini diganti (bila hanya satu) atau dikosongkan supaya dipilih dari saran.
            if (district && !district.postal.includes(form.postal_code)) {
                form.postal_code = district.postal.length === 1 ? district.postal[0] : '';
                delete this.auto.postal_code;
                delete this.errors.postal_code;
            }
            this.combo = '';
            if (before[0] !== form.district || before[1] !== form.city) this.resetPin();
        },
        // Saran kode pos untuk kecamatan terpilih (bisa lebih dari satu).
        get postalOptions() { return findDistrict(this.regions, this.form)?.postal ?? []; },

        // Peta titik rumah. Pin dari alamat yang diketik hanya disimpan bila jalan/kelurahan ditemukan; pin yang dipilih pelanggan selalu disimpan.
        async mountMap(element) {
            try {
                mapApi = await import('./map');
                pinMap?.destroy();
                pinMap = mapApi.createPinMap(element, { label: this.words.map.label, onPick: (lat, lng) => this.pickPin(lat, lng) });
                if (this.pin) pinMap.place(this.pin.lat, this.pin.lng);
                else this.scheduleGeocode(0);
            } catch {
                this.mapFailed = true;
            }
        },
        // Untuk form yang ditutup (x-if): peta lama dilepas sebelum form dibuka lagi.
        unmountMap() {
            clearTimeout(geoTimer);
            geoRequest += 1;
            pinMap?.destroy();
            pinMap = null;
        },
        get pinText() { return this.pinState ? this.words.map[this.pinState] : this.pinAddress; },
        get hasPinText() { return !!this.pinText; },
        resetPin() {
            this.pinManual = false;
            this.pin = null;
            this.pinAddress = '';
        },
        // Titik yang sudah tersimpan (alamat akun): dipakai apa adanya, peta tidak menggantinya dari alamat yang diketik.
        usePin(lat, lng) {
            if (lat === null || lat === undefined || lng === null || lng === undefined) {
                this.resetPin();
                return;
            }
            pinMap?.place(Number(lat), Number(lng));
            this.pickPin(Number(lat), Number(lng));
        },
        // Bentuk yang dikirim ke server; null berarti tanpa titik.
        pinPayload() {
            return this.pin
                ? { latitude: Number(this.pin.lat.toFixed(7)), longitude: Number(this.pin.lng.toFixed(7)) }
                : { latitude: null, longitude: null };
        },
        scheduleGeocode(delay = 1200) {
            if (this.pinManual || !pinMap) return;
            clearTimeout(geoTimer);
            geoTimer = setTimeout(() => this.locateAddress(), delay);
        },
        async locateAddress() {
            const queries = mapQueries(this.form);
            if (!queries.length) return;
            const request = ++geoRequest;
            this.pinState = 'finding';
            try {
                for (const { q, precise } of queries) {
                    const hit = await mapApi.geocode(q);
                    if (request !== geoRequest || this.pinManual) return;
                    if (!hit) continue;
                    pinMap?.place(hit.lat, hit.lng, precise ? 17 : 14);
                    // Hanya hasil yang memuat jalan atau kelurahan disimpan sebagai titik; hasil kecamatan atau kota hanya memusatkan peta.
                    this.pin = precise ? { lat: hit.lat, lng: hit.lng } : null;
                    this.pinAddress = precise ? hit.address : '';
                    this.pinState = precise ? '' : 'notFound';
                    return;
                }
                this.pinState = 'notFound';
            } catch {
                if (request === geoRequest) this.pinState = 'notFound';
            }
        },
        async pickPin(lat, lng) {
            const request = ++geoRequest;
            this.pinManual = true;
            this.pin = { lat, lng };
            this.pinAddress = '';
            this.pinState = 'finding';
            delete this.errors.latitude;
            delete this.errors.longitude;
            try {
                mapApi ??= await import('./map');
                const address = await mapApi.reverseGeocode(lat, lng);
                if (request !== geoRequest) return;
                this.pinAddress = address;
                this.pinState = address ? '' : 'none';
            } catch {
                if (request === geoRequest) this.pinState = 'none';
            }
        },
    };
};
