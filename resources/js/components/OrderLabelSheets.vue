<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { code128Bars, isEncodable } from '../utils/code128';

// Satu label resi 100 x 150 mm (kertas thermal) atau lebih bila daftar item panjang. Data dari /admin/orders/{id}/label: tanpa harga dan email.
// Daftar item panjang: huruf mengecil sampai batas terbaca, lalu sisanya lanjut di label berikutnya (nomor resi tetap tercetak).
// Memancarkan `ready` setelah ukuran huruf selesai dihitung, supaya halaman induk tahu kapan aman mencetak.
const props = defineProps({ label: { type: Object, required: true } });
const emit = defineEmits(['ready']);

const logoFailed = ref(false);
const pages = ref([]);
const sheets = [];

const COURIER_LOGOS = ['jnt', 'sicepat'];
const MAX_PT = 9;
const MIN_PT = 6;
const STEP = 0.4;
const MAX_PAGES = 8;

const barcode = computed(() => (props.label && isEncodable(props.label.waybill_id) ? code128Bars(props.label.waybill_id) : null));
const courierCode = computed(() => (props.label?.courier || '').split(' ')[0].toLowerCase());
const courierLogo = computed(() => (COURIER_LOGOS.includes(courierCode.value) && !logoFailed.value ? `/images/Logo/kurir/${courierCode.value}.webp` : ''));
const printedAt = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
const displayHost = computed(() => `www.${(props.label?.website || 'https://tuffero.com').replace(/^https?:\/\/(www\.)?/, '')}`);

// QR dari baris "1"/"0" menjadi satu path: satu persegi panjang per deret kotak hitam.
const qr = computed(() => {
    const rows = props.label?.qr;
    if (!rows?.length) return null;
    const parts = [];
    rows.forEach((row, y) => {
        for (const run of row.matchAll(/1+/g)) parts.push(`M${run.index} ${y}h${run[0].length}v1h-${run[0].length}z`);
    });
    return { size: rows.length, d: parts.join('') };
});

// Label 100 x 150 mm: tinggi sah = 1,5 x lebar. Lebih dari itu berarti isinya meluap.
const overflows = async (index) => {
    await nextTick();
    const el = sheets[index];
    return !!el && el.offsetHeight > el.offsetWidth * 1.5 + 2;
};

async function fit(items) {
    let rest = [...items];
    const done = [];

    while (rest.length && done.length < MAX_PAGES) {
        const index = done.length;
        const show = (page) => { pages.value = [...done, page]; };

        let pt = MAX_PT;
        show({ items: rest, pt, more: false });
        while (pt > MIN_PT && (await overflows(index))) {
            pt = Math.max(MIN_PT, +(pt - STEP).toFixed(1));
            show({ items: rest, pt, more: false });
        }

        let keep = rest.length;
        if (await overflows(index)) {
            // Masih meluap di huruf terkecil: sisanya pindah ke label lanjutan.
            while (keep > 1) {
                keep -= 1;
                show({ items: rest.slice(0, keep), pt, more: true });
                if (!(await overflows(index))) break;
            }
        }

        // Setelah sebagian item pindah, huruf boleh membesar lagi selama masih muat.
        const more = keep < rest.length;
        while (more && pt + STEP <= MAX_PT) {
            const larger = +(pt + STEP).toFixed(1);
            show({ items: rest.slice(0, keep), pt: larger, more });
            if (await overflows(index)) break;
            pt = larger;
        }

        done.push({ items: rest.slice(0, keep), pt, more });
        rest = rest.slice(keep);
    }

    pages.value = done;
}

onMounted(async () => {
    await fit(props.label.items);
    emit('ready');
});
</script>

<template>
  <article v-for="(page, i) in pages" :key="i" :ref="(el) => { sheets[i] = el; }" class="label-sheet mx-auto">
    <header class="label-head">
      <img class="label-logo" src="/images/Logo/Logo Tuffero Bakery-Htm.webp" alt="Tuffero Bakery">
      <span class="label-vline" aria-hidden="true"></span>
      <div class="label-shop">
        <p class="label-shop-name">Tuffero Patisserie</p>
        <p class="label-small">{{ label.provider === 'biteship' ? 'Via Biteship' : 'Pengiriman manual' }}</p>
      </div>
      <img v-if="courierLogo" class="label-courier-logo" :src="courierLogo" :alt="label.courier" @error="logoFailed = true">
      <p v-else class="label-courier-text">{{ label.courier }}</p>
    </header>

    <template v-if="i === 0">
      <section class="label-barcode" aria-label="Nomor resi">
        <svg v-if="barcode" :viewBox="`0 0 ${barcode.width} 40`" preserveAspectRatio="none" role="img" :aria-label="`Barcode ${label.waybill_id}`">
          <rect v-for="(bar, n) in barcode.bars" :key="n" :x="bar.x" y="0" :width="bar.width" height="40" fill="#000" />
        </svg>
        <p class="label-waybill">{{ label.waybill_id }}</p>
        <p class="label-order">Pesanan {{ label.order_number }}</p>
      </section>

      <section class="label-box">
        <svg class="label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
        <span class="label-vline" aria-hidden="true"></span>
        <div class="label-box-text">
          <p class="label-tag">Penerima</p>
          <p class="label-name">{{ label.recipient.name }}</p>
          <p class="label-text">{{ label.recipient.phone }}</p>
          <p class="label-text">{{ label.recipient.address }}</p>
        </div>
      </section>

      <section class="label-box">
        <svg class="label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1.5-5h15L21 9" /><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /><path d="M5 12v8h14v-8" /><path d="M10 20v-5h4v5" /></svg>
        <span class="label-vline" aria-hidden="true"></span>
        <div class="label-box-text">
          <p class="label-tag">Pengirim</p>
          <p class="label-sender-name">{{ label.sender.name }}<template v-if="label.sender.phone"> - {{ label.sender.phone }}</template></p>
          <p class="label-text label-text-sm">{{ [label.sender.address, label.sender.postal_code].filter(Boolean).join(' ') }}</p>
        </div>
      </section>
    </template>

    <section v-else class="label-cont">
      <p class="label-waybill">{{ label.waybill_id }}</p>
      <p class="label-order">Pesanan {{ label.order_number }} · {{ label.recipient.name }}</p>
    </section>

    <section class="label-box label-items-box">
      <template v-if="i === 0">
        <div class="label-qr">
          <svg v-if="qr" :viewBox="`-2 -2 ${qr.size + 4} ${qr.size + 4}`" shape-rendering="crispEdges" role="img" :aria-label="label.website">
            <rect x="-2" y="-2" :width="qr.size + 4" :height="qr.size + 4" fill="#fff" />
            <path :d="qr.d" fill="#000" />
          </svg>
        </div>
        <span class="label-vline" aria-hidden="true"></span>
      </template>
      <div class="label-box-text label-items" :style="{ '--item-pt': `${page.pt}pt` }">
        <p class="label-tag">Rincian Pesanan<template v-if="pages.length > 1"> ({{ i + 1 }}/{{ pages.length }})</template></p>
        <ul>
          <li v-for="(item, n) in page.items" :key="`${item.sku}-${n}`">{{ item.quantity }} × {{ item.name }}<template v-if="item.variant"> ({{ item.variant }})</template></li>
        </ul>
        <p v-if="page.more" class="label-more">Lanjut di label {{ i + 2 }} dari {{ pages.length }} ›</p>
        <p v-else-if="label.note" class="label-note"><b>Catatan:</b> {{ label.note }}</p>
      </div>
    </section>

    <footer class="label-foot">
      <span class="label-foot-site">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" /></svg>
        {{ displayHost }}
      </span>
      <span class="label-vline" aria-hidden="true"></span>
      <span>Dicetak {{ printedAt }}</span>
    </footer>
  </article>
</template>

<style>
/* Label: hitam di atas putih supaya terbaca di printer thermal; krem hanya aksen tipis. Ukuran halaman cetak = ukuran kertas label. */
.label-sheet { --ink: #2B1B14; width: 100mm; min-height: 150mm; padding: 3mm; box-sizing: border-box; display: flex; flex-direction: column; gap: 1.7mm; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; border: 0.4mm solid var(--ink); border-radius: 3.5mm; box-shadow: 0 1px 6px #2b1b1426; }
.label-sheet + .label-sheet { margin-top: 4mm; }
.label-vline { align-self: stretch; width: 0.3mm; background: var(--ink); flex: none; }
.label-small { font-size: 7.5pt; line-height: 1.3; }

.label-head { display: flex; align-items: center; gap: 2.5mm; padding-bottom: 1.6mm; border-bottom: 0.35mm solid var(--ink); }
.label-logo { width: 20mm; aspect-ratio: 1448 / 683; height: auto; flex: none; }
.label-head .label-vline { height: 9mm; align-self: center; }
.label-shop { flex: 1; min-width: 0; }
.label-shop-name { font-size: 11.5pt; font-weight: 700; line-height: 1.15; }
.label-courier-logo { height: 8mm; width: auto; max-width: 24mm; object-fit: contain; flex: none; }
.label-courier-text { flex: none; padding: 1.2mm 3mm; border: 0.3mm solid var(--ink); border-radius: 99mm; font-size: 11pt; font-weight: 800; }

.label-barcode, .label-cont { text-align: center; }
.label-barcode svg { display: block; width: 100%; height: 15mm; }
.label-waybill { display: inline-block; margin-top: 1mm; padding: 0.4mm 4mm; border-radius: 99mm; background: #F6F0E5; font-size: 12.5pt; font-weight: 800; letter-spacing: 0.04em; line-height: 1.3; overflow-wrap: anywhere; }
.label-order { margin-top: 0.5mm; font-size: 8pt; }

.label-box { display: flex; align-items: stretch; gap: 2.5mm; padding: 1.8mm 2.2mm; border: 0.3mm solid var(--ink); border-radius: 2.5mm; }
.label-icon { align-self: center; width: 8mm; height: 8mm; flex: none; color: var(--ink); }
.label-box-text { flex: 1; min-width: 0; }
.label-tag { font-size: 8pt; font-weight: 700; color: #5b463b; }
.label-name { margin-top: 0.3mm; font-size: 12.5pt; font-weight: 800; line-height: 1.2; overflow-wrap: anywhere; }
.label-sender-name { margin-top: 0.3mm; font-size: 9.5pt; font-weight: 800; line-height: 1.25; }
.label-text { margin-top: 0.3mm; font-size: 8.5pt; line-height: 1.3; overflow-wrap: anywhere; }
.label-text-sm { font-size: 7.2pt; }

.label-items-box { flex: 1; }
.label-qr { width: 26mm; flex: none; align-self: center; }
.label-qr svg { display: block; width: 100%; height: auto; }
.label-items ul { margin-top: 0.8mm; padding-left: 3.5mm; list-style: disc; font-size: var(--item-pt, 9pt); line-height: 1.3; overflow-wrap: anywhere; }
.label-more { margin-top: 1mm; font-size: 7.5pt; font-weight: 700; }
.label-note { margin-top: 1.5mm; padding-top: 1.2mm; border-top: 0.2mm dashed var(--ink); font-size: 8pt; line-height: 1.35; }

.label-foot { display: flex; align-items: center; justify-content: space-between; gap: 3mm; padding-top: 1.4mm; border-top: 0.35mm solid var(--ink); font-size: 7.5pt; }
.label-foot .label-vline { height: 4mm; align-self: center; }
.label-foot-site { display: inline-flex; align-items: center; gap: 1.5mm; flex: 1; }
.label-foot-site svg { width: 4.5mm; height: 4.5mm; }

@media print {
    @page { size: 100mm 150mm; margin: 0; }
    html, body { background: #fff !important; }
    .no-print { display: none !important; }
    .label-screen { padding: 0 !important; min-height: 0 !important; background: #fff !important; overflow: visible !important; }
    .label-sheet { width: 100mm; height: 150mm; min-height: 0; margin: 0 !important; border-radius: 0; border: 0; box-shadow: none; overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .label-sheet + .label-sheet { break-before: page; }
}
</style>
