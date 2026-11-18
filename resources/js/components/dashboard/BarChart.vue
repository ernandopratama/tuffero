<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { rupiahCompact } from '../../utils/orderLabels';
import { BAR } from './tones';

const props = defineProps({
    chart: { type: Object, required: true },
});

const last = computed(() => props.chart.labels.length - 1);
const active = ref(props.chart.labels.length - 1);
// Tooltip hanya tampil saat kolom disorot (mouse), diketuk (sentuh), atau difokus keyboard; bulan terakhir tetap ditebalkan tanpa tooltip.
const showTip = ref(false);
const point = (i) => { active.value = i; showTip.value = true; };
const reset = () => { active.value = last.value; showTip.value = false; };
// Sentuhan memicu pointerleave tepat setelah jari diangkat; tooltip sentuh ditutup lewat focusout saat mengetuk di luar grafik.
const onLeave = (e) => { if (e.pointerType !== 'touch') reset(); };
const onFocusOut = (e) => { if (!e.currentTarget.contains(e.relatedTarget)) reset(); };
// Periode berganti (mis. 18 hari menjadi 11 bulan): kolom aktif lama bisa di luar jangkauan, jadi kembali ke kolom terakhir sebelum digambar.
watch(() => props.chart.labels.length, reset);

// Sumbu tanpa awalan Rp supaya muat satu baris: 500 rb, 1,5 jt.
const axis = (v) => {
    if (props.chart.format !== 'rupiah') return Number(v).toLocaleString('id-ID');
    const sign = v < 0 ? '-' : '';
    const a = Math.abs(v);
    if (a >= 1_000_000) return `${sign}${(a / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    return a >= 1_000 ? `${sign}${(a / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb` : String(v);
};
const fmt = (v) => (props.chart.format === 'rupiah' ? rupiahCompact(v) : Number(v).toLocaleString('id-ID'));
const all = computed(() => props.chart.series.flatMap((s) => s.values));
const max = computed(() => Math.max(0, ...all.value));
// Nilai negatif (pendapatan bersih saat dana yang dikembalikan lebih besar dari penjualan) digambar di bawah garis nol.
const min = computed(() => Math.min(0, ...all.value));
const empty = computed(() => all.value.every((v) => v === 0));

// Skala "rapi": 5 selang berukuran 1, 2, 2,5, atau 5 dikali pangkat 10, dipilih yang terkecil sehingga selang di atas dan di bawah
// nol muat dalam 5. Hitungan (pesanan, pcs) memakai selang bulat; rupiah boleh 2,5.
const step = computed(() => {
    const whole = props.chart.format === 'number';
    const raw = Math.max(max.value - min.value, 1) / 5;
    const pow = Math.max(whole ? 1 : 0, 10 ** Math.floor(Math.log10(raw)));
    const mults = whole ? [1, 2, 5, 10, 20, 50, 100] : [1, 2, 2.5, 5, 10, 20, 25, 50, 100];
    const fits = (s) => Math.ceil(max.value / s) + Math.ceil(-min.value / s) <= 5;

    return mults.map((m) => m * pow).find((s) => s >= raw && fits(s));
});
const top = computed(() => step.value * 5);
// Selang di bawah nol; garis nol berada setinggi `below` selang dari dasar area.
const below = computed(() => Math.ceil(-min.value / step.value));
const baseline = computed(() => (below.value / 5) * 100);
// Dibulatkan agar 3 * 0,2 tidak tampil 0.6000000000000001.
const ticks = computed(() => [0, 1, 2, 3, 4, 5].map((i) => Number(((5 - below.value - i) * step.value).toPrecision(12))));
const barStyle = (v, i) => {
    const h = v === 0 ? 0 : Math.max(1.5, (Math.abs(v) / top.value) * 100);
    const place = v < 0 ? { top: `${100 - baseline.value}%` } : { bottom: `${baseline.value}%` };

    return { ...place, height: `${h}%`, animationDelay: `${i * 30}ms` };
};

const groups = computed(() => props.chart.labels.map((label, i) => ({ label, i, values: props.chart.series.map((s) => s.values[i]) })));
const total = computed(() => props.chart.series.map((s) => s.values.reduce((a, b) => a + b, 0)));

// Tooltip duduk di atas batang tertinggi kolom aktif, dan menempel ke kiri atau kanan pada kolom pinggir supaya tidak terpotong.
const tipStyle = computed(() => {
    const n = props.chart.labels.length;
    const tallest = baseline.value + (Math.max(0, ...groups.value[active.value].values) / top.value) * 100;
    const style = { bottom: `min(calc(${tallest}% + 0.75rem), calc(100% - 7rem))` };
    if (active.value <= 1) return { ...style, left: '0' };
    if (active.value >= n - 2) return { ...style, right: '0' };
    return { ...style, left: `${((active.value + 0.5) / n) * 100}%`, transform: 'translateX(-50%)' };
});

// Banyak kolom (mis. 92 hari): batang menyempit mengikuti lebar kolom, dan label sumbu hanya tiap beberapa kolom supaya tidak
// bertumpuk. `axis_labels` (opsional) = label pendek sumbu; label lengkap tetap di tooltip dan tabel pembaca layar.
const dense = computed(() => props.chart.labels.length > 16);
// Jumlah label sumbu mengikuti lebar area batang (kira-kira 64 px per label), supaya tidak bertumpuk di layar HP.
const plot = ref(null);
const plotWidth = ref(0);
let observer;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => { plotWidth.value = entry.contentRect.width - 56; }); // tanpa kolom sumbu pl-14
    if (!plot.value) return;
    plotWidth.value = plot.value.clientWidth - 56; // ukuran awal tanpa menunggu callback pertama
    observer.observe(plot.value);
});
onBeforeUnmount(() => observer?.disconnect());
const every = computed(() => Math.ceil(props.chart.labels.length / Math.max(2, Math.min(12, Math.floor(plotWidth.value / 64) || 12))));
const axisLabel = (g) => props.chart.axis_labels?.[g.i] ?? g.label;

const describe = (g) => `${g.label}: ${props.chart.series.map((s, k) => `${s.name} ${fmt(g.values[k])}`).join(', ')}`;
</script>

<template>
  <div>
    <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-body-sm text-secondary" aria-label="Keterangan grafik">
      <li v-for="s in chart.series" :key="s.key" class="flex items-center gap-1.5">
        <span class="size-2 rounded-full" :class="BAR[s.tone]"></span>{{ s.name }}
      </li>
    </ul>

    <div ref="plot" class="relative mt-4 h-60" @pointerleave="onLeave" @focusout="onFocusOut">
      <!-- garis bantu dan sumbu: 5 selang di area setinggi 80%, sisanya untuk label periode -->
      <div v-for="(t, i) in ticks" :key="t" class="absolute inset-x-0 flex -translate-y-1/2 items-center" :style="{ top: `${i * 16}%` }" aria-hidden="true">
        <span class="w-14 shrink-0 whitespace-nowrap pr-2 text-right text-body-sm text-secondary">{{ axis(t) }}</span>
        <span class="h-px flex-1 bg-border"></span>
      </div>

      <p v-if="empty" class="absolute inset-0 flex items-center justify-center pb-10 pl-14 text-body-sm text-secondary">Belum ada data untuk grafik ini.</p>

      <div v-else class="absolute inset-0 pl-14">
        <div class="relative h-[80%]">
          <div
            v-for="g in groups"
            :key="g.i"
            class="absolute inset-y-0 flex justify-center rounded-lg outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            :class="dense ? 'gap-px px-px' : 'gap-1'"
            :style="{ left: `${(g.i / chart.labels.length) * 100}%`, width: `${100 / chart.labels.length}%` }"
            tabindex="0"
            role="group"
            :aria-label="describe(g)"
            @pointerenter="point(g.i)"
            @focus="point(g.i)"
          >
            <span v-for="(s, k) in chart.series" :key="s.key" class="relative h-full" :class="dense ? 'max-w-3 min-w-px flex-1' : 'w-2.5 sm:w-3'">
              <span
                class="absolute inset-x-0 animate-grow transition-opacity duration-150 ease-out"
                :class="[BAR[s.tone], active === g.i ? 'opacity-100' : 'opacity-35', g.values[k] < 0 ? 'origin-top rounded-b-[4px]' : 'origin-bottom rounded-t-[4px]']"
                :style="barStyle(g.values[k], g.i)"
              ></span>
            </span>
          </div>

          <div
            class="pointer-events-none absolute z-10 w-max min-w-52 origin-bottom rounded-2xl border border-border bg-surface p-3 shadow-pop transition-[left,right,bottom,opacity,scale] duration-200 ease-out-strong motion-reduce:transition-opacity"
            :class="showTip ? 'scale-100 opacity-100' : 'scale-[0.97] opacity-0'"
            :style="tipStyle"
            aria-hidden="true"
          >
            <p class="text-label-md">{{ groups[active].label }}</p>
            <p v-for="(s, k) in chart.series" :key="s.key" class="mt-1 flex items-center justify-between gap-3 text-body-sm">
              <span class="flex items-center gap-1.5 whitespace-nowrap text-secondary"><span class="size-2 shrink-0 rounded-full" :class="BAR[s.tone]"></span>{{ s.name }}</span>
              <span class="whitespace-nowrap font-semibold">{{ fmt(groups[active].values[k]) }}</span>
            </p>
          </div>
        </div>

        <div class="relative mt-2 h-5" aria-hidden="true">
          <span
            v-for="g in groups"
            :key="g.i"
            v-show="g.i % every === 0"
            class="absolute flex justify-center whitespace-nowrap text-body-sm"
            :class="active === g.i ? 'font-semibold text-primary' : 'text-secondary'"
            :style="{ left: `${(g.i / chart.labels.length) * 100}%`, width: `${100 / chart.labels.length}%` }"
          >{{ axisLabel(g) }}</span>
        </div>
      </div>
    </div>

    <!-- sr-only di pembungkus div: tabel mengabaikan width 1px dan melebar ke isi, sehingga halaman HP bisa tergeser ke samping. -->
    <div class="sr-only">
      <table>
        <caption>{{ chart.title }}</caption>
        <thead><tr><th scope="col">Periode</th><th v-for="s in chart.series" :key="s.key" scope="col">{{ s.name }}</th></tr></thead>
        <tbody>
          <tr v-for="g in groups" :key="g.i"><th scope="row">{{ g.label }}</th><td v-for="(s, k) in chart.series" :key="s.key">{{ fmt(g.values[k]) }}</td></tr>
        </tbody>
        <tfoot><tr><th scope="row">Total</th><td v-for="(s, k) in chart.series" :key="s.key">{{ fmt(total[k]) }}</td></tr></tfoot>
      </table>
    </div>
  </div>
</template>
