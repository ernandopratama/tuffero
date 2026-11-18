<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { code128Bars, isEncodable } from '../utils/code128';

// Satu label resi 100 x 150 mm (kertas thermal) atau lebih bila daftar item panjang. Data dari /admin/orders/{id}/label: tanpa harga dan email.
// Tata letak mengikuti resi marketplace: kurir, kode layanan, dan QR di atas; pengirim dan penerima; berat dan tanggal kirim; barcode resi; tabel barang.
// Daftar item panjang: huruf mengecil sampai batas terbaca, lalu sisanya lanjut di label berikutnya. Label lanjutan tidak memuat data penerima.
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
const weightKg = computed(() => `${((props.label?.weight_gram || 0) / 1000).toFixed(3)} KG`);
const shipDate = computed(() => (props.label?.ship_date || '').split('-').reverse().join('-'));
const senderPlace = computed(() => [props.label?.sender?.city, props.label?.sender?.address].filter(Boolean).join(', '));
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
    <template v-if="i === 0">
      <header class="label-head">
        <img v-if="courierLogo" class="label-courier-logo" :src="courierLogo" :alt="label.courier" @error="logoFailed = true">
        <p v-else class="label-courier-text">{{ courierCode.toUpperCase() }}</p>
        <p class="label-service">{{ label.service_code }}</p>
        <div class="label-qr">
          <svg v-if="qr" :viewBox="`-2 -2 ${qr.size + 4} ${qr.size + 4}`" shape-rendering="crispEdges" role="img" :aria-label="`QR ${label.waybill_id}`">
            <rect x="-2" y="-2" :width="qr.size + 4" :height="qr.size + 4" fill="#fff" />
            <path :d="qr.d" fill="#000" />
          </svg>
        </div>
      </header>

      <section class="label-parties">
        <p class="label-row"><span><b>Pengirim:</b> {{ label.sender.name }}</span><span v-if="label.sender.phone">{{ label.sender.phone }}</span></p>
        <p class="label-sender-place">{{ senderPlace }}</p>
        <p class="label-row label-row-to"><span><b>Penerima:</b> {{ label.recipient.name }}</span><span>{{ label.recipient.phone }}</span></p>
        <p class="label-address">{{ label.recipient.address }}</p>
      </section>

      <section class="label-meta">
        <p><span>Berat</span> {{ weightKg }}</p>
        <p><span>Kirim</span> {{ shipDate }}</p>
      </section>

      <section class="label-barcode" aria-label="Nomor resi">
        <p v-if="label.routing_code" class="label-routing">{{ label.routing_code }}</p>
        <svg v-if="barcode" :viewBox="`0 0 ${barcode.width} 40`" preserveAspectRatio="none" role="img" :aria-label="`Barcode ${label.waybill_id}`">
          <rect v-for="(bar, n) in barcode.bars" :key="n" :x="bar.x" y="0" :width="bar.width" height="40" fill="#000" />
        </svg>
        <p class="label-waybill">{{ label.waybill_id }}</p>
        <p class="label-order"><b>Order Id:</b> {{ label.order_number }}</p>
      </section>
    </template>

    <section v-else class="label-cont">
      <p class="label-waybill">{{ label.waybill_id }}</p>
      <p class="label-order"><b>Order Id:</b> {{ label.order_number }}</p>
    </section>

    <section class="label-items" :style="{ '--item-pt': `${page.pt}pt` }">
      <table>
        <thead>
          <tr><th scope="col">Nama Produk</th><th scope="col">Varian</th><th scope="col">SKU</th><th scope="col" class="label-qty">Qty</th></tr>
        </thead>
        <tbody>
          <tr v-for="(item, n) in page.items" :key="`${item.sku}-${n}`">
            <td>{{ item.name }}</td>
            <td>{{ item.variant }}</td>
            <td>{{ item.sku }}</td>
            <td class="label-qty">{{ item.quantity }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="page.more" class="label-more">Lanjut di label {{ i + 2 }} dari {{ pages.length }} ›</p>
      <template v-else>
        <p class="label-total"><b>Qty Total:</b> {{ label.qty_total }}</p>
        <p v-if="label.note" class="label-note"><b>Catatan:</b> {{ label.note }}</p>
      </template>
    </section>

    <footer class="label-foot">
      <span>{{ displayHost }}</span>
      <span>Dicetak {{ printedAt }}<template v-if="pages.length > 1"> · {{ i + 1 }}/{{ pages.length }}</template></span>
    </footer>
  </article>
</template>

<style>
/* Label: hitam di atas putih supaya terbaca di printer thermal (tanpa abu-abu, karena abu-abu tercetak bintik). Ukuran halaman cetak = ukuran kertas label.
   Bentuk modern dari sudut membulat, kartu per blok, chip, dan angka rata (tabular); susunan blok tidak berubah. */
.label-sheet { --r: 3mm; width: 100mm; min-height: 150mm; padding: 3mm; box-sizing: border-box; display: flex; flex-direction: column; gap: 1.4mm; font-family: system-ui, 'Segoe UI', Roboto, Arial, Helvetica, sans-serif; font-variant-numeric: tabular-nums; color: #000; background: #fff; border: 0.5mm solid #000; border-radius: 5mm; box-shadow: 0 1px 6px #2b1b1426; }
.label-sheet + .label-sheet { margin-top: 4mm; }
.label-sheet b { font-weight: 700; }

.label-head { display: flex; align-items: center; justify-content: space-between; gap: 2.5mm; padding: 0; border: 0; }
.label-courier-logo { height: 9mm; width: auto; max-width: 28mm; object-fit: contain; flex: none; }
.label-courier-text { flex: none; font-size: 15pt; font-weight: 800; letter-spacing: 0.02em; }
.label-service { flex: none; padding: 1.8mm 4.5mm; border-radius: 2.5mm; background: #000; color: #fff; text-align: center; font-size: 15pt; font-weight: 800; line-height: 1; letter-spacing: 0.06em; }
.label-qr { width: 15mm; flex: none; padding: 0.8mm; border: 0.4mm solid #000; border-radius: 2mm; box-sizing: content-box; }
.label-qr svg { display: block; width: 100%; height: auto; }

.label-parties { padding: 1.5mm 2.5mm; border: 0.4mm solid #000; border-radius: var(--r); }
.label-row { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; font-size: 8.5pt; line-height: 1.3; font-weight: 600; }
.label-row > span:first-child { overflow-wrap: anywhere; }
.label-row > span:last-child { flex: none; font-weight: 700; }
.label-row b { display: inline-block; margin-right: 1mm; font-size: 6.5pt; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.label-row-to { margin-top: 1.2mm; padding-top: 1.2mm; border-top: 0.25mm dashed #000; }
.label-sender-place { margin-top: 0.2mm; font-size: 6.8pt; line-height: 1.25; text-transform: uppercase; letter-spacing: 0.02em; overflow-wrap: anywhere; }
.label-address { margin-top: 0.6mm; font-size: 11pt; font-weight: 800; line-height: 1.2; letter-spacing: -0.005em; overflow-wrap: anywhere; }

.label-meta { display: flex; gap: 2mm; padding: 0; border: 0; font-size: 9pt; font-weight: 800; }
.label-meta p { flex: 1; display: flex; align-items: baseline; justify-content: space-between; gap: 2mm; padding: 0.9mm 2.5mm; border: 0.4mm solid #000; border-radius: 99mm; }
.label-meta span { margin: 0; font-size: 6.5pt; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }

.label-barcode, .label-cont { text-align: center; }
.label-barcode { padding: 1.5mm 2.5mm; border: 0.4mm solid #000; border-radius: var(--r); }
.label-barcode svg { display: block; width: 100%; height: 12mm; }
.label-routing { margin-bottom: 1.2mm; font-size: 14pt; font-weight: 800; letter-spacing: 0.06em; line-height: 1.1; overflow-wrap: anywhere; }
.label-waybill { margin-top: 0.8mm; font-size: 13.5pt; font-weight: 800; letter-spacing: 0.06em; line-height: 1.2; overflow-wrap: anywhere; }
.label-order { margin-top: 0.4mm; font-size: 7.5pt; font-weight: 600; }
.label-order b { font-size: 6.5pt; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.label-cont { padding: 2mm 2.5mm; border: 0.4mm solid #000; border-radius: var(--r); }

.label-items { flex: 1; padding: 1.5mm 2.5mm; border: 0.4mm solid #000; border-radius: var(--r); font-size: var(--item-pt, 9pt); line-height: 1.25; }
.label-items table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.label-items th { text-align: left; padding-bottom: 1mm; border-bottom: 0.5mm solid #000; font-size: 0.72em; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.label-items td { padding: 0.6mm 1mm 0.6mm 0; vertical-align: top; overflow-wrap: anywhere; border-bottom: 0.2mm dotted #000; }
.label-items tbody tr:last-child td { border-bottom: 0; }
.label-items th:nth-child(1), .label-items td:nth-child(1) { width: 38%; font-weight: 700; }
.label-items th:nth-child(2), .label-items td:nth-child(2) { width: 22%; }
.label-items th:nth-child(3), .label-items td:nth-child(3) { width: 28%; }
.label-items .label-qty { width: 12%; text-align: right; padding-right: 0; font-weight: 800; }
.label-total { width: fit-content; margin: 1.5mm 0 0 auto; padding: 0.7mm 2.5mm; border-radius: 99mm; background: #000; color: #fff; font-size: 0.95em; }
.label-total b { font-size: 0.78em; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
.label-more { margin-top: 1.2mm; font-size: 7.5pt; font-weight: 700; }
.label-note { margin-top: 1.5mm; padding-top: 1.2mm; border-top: 0.25mm dashed #000; font-size: 8pt; line-height: 1.35; }

.label-foot { display: flex; justify-content: space-between; gap: 3mm; padding: 0 1mm; border: 0; font-size: 6.5pt; letter-spacing: 0.02em; }

@media print {
    @page { size: 100mm 150mm; margin: 0; }
    html, body { background: #fff !important; }
    .no-print { display: none !important; }
    .label-screen { padding: 0 !important; min-height: 0 !important; background: #fff !important; overflow: visible !important; }
    .label-sheet { width: 100mm; height: 150mm; min-height: 0; margin: 0 !important; border: 0; border-radius: 0; box-shadow: none; overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .label-sheet + .label-sheet { break-before: page; }
}
</style>
