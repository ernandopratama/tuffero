<script setup>
import { computed, ref, useId, watch } from 'vue';
import FaIcon from '../FaIcon.vue';
import { PLATFORMS, PLATFORM_COLOR, PLATFORM_ICON, formatCompact, formatDay, formatNumber, formatShortDay } from '../../utils/contentMetrics';

// Grafik garis angka per hari per platform (D-150, D-151). `points`: satu titik per hari, `{ date, tiktok, instagram }`.
// Nilai kosong (null atau tidak ada) berarti hari itu tidak dicatat: garis menyambung titik yang ada dan tooltip menulis "tidak dicatat".
// Sorot (mouse), ketuk (sentuh), atau panah kiri/kanan (papan ketik) untuk detail. `fit` memotong sumbu bawah ke nilai terkecil
// (untuk followers, yang hampir selalu naik pelan dan akan tampak datar bila sumbu mulai dari 0).
const props = defineProps({
    points: { type: Array, required: true },
    platforms: { type: Array, required: true },
    height: { type: Number, default: 200 },
    // Ringkas: tanpa label sumbu Y dan garis bantu, untuk kartu Ringkasan.
    compact: { type: Boolean, default: false },
    fit: { type: Boolean, default: false },
    title: { type: String, default: 'views' },
    emptyText: { type: String, default: 'Belum ada views tercatat dalam rentang ini.' },
});

const uid = useId();
const n = computed(() => props.points.length);
const has = (v) => v !== null && v !== undefined;
const values = computed(() => props.points.flatMap((p) => props.platforms.map((name) => p[name]).filter(has)));
const peak = computed(() => Math.max(0, ...values.value));
const low = computed(() => (values.value.length ? Math.min(...values.value) : 0));
const empty = computed(() => (props.fit ? values.value.length === 0 : peak.value === 0));

// Skala rapi: 4 selang berukuran 1, 2, 5 dikali pangkat 10. Mode fit mulai dari kelipatan selang di bawah nilai terkecil.
const nice = (raw) => {
    const pow = 10 ** Math.floor(Math.log10(raw));
    return [1, 2, 5, 10].map((m) => m * pow).find((s) => s >= raw);
};
const scale = computed(() => {
    if (!props.fit) {
        const top = nice(Math.max(peak.value, 4) / 4) * 4;
        return { bottom: 0, top, step: top / 4 };
    }
    const step = nice(Math.max(peak.value - low.value, 4) / 3);
    const bottom = Math.max(0, Math.floor(low.value / step) * step);
    return { bottom, top: bottom + step * 4, step };
});
const ticks = computed(() => [4, 3, 2, 1, 0].map((i) => scale.value.bottom + scale.value.step * i));

const x = (i) => (n.value > 1 ? (i / (n.value - 1)) * 100 : 50);
const y = (v) => 100 - ((v - scale.value.bottom) / (scale.value.top - scale.value.bottom)) * 100;
const lines = computed(() => props.platforms.map((name) => {
    const drawn = props.points.map((p, i) => [i, p[name]]).filter(([, v]) => has(v));
    const line = drawn.map(([i, v], k) => `${k ? 'L' : 'M'}${x(i).toFixed(2)} ${y(v).toFixed(2)}`).join(' ');
    const first = drawn[0];
    const last = drawn[drawn.length - 1];
    const area = drawn.length > 1 ? `${line} L${x(last[0]).toFixed(2)} 100 L${x(first[0]).toFixed(2)} 100 Z` : '';
    return { name, color: PLATFORM_COLOR[name], line, area, single: drawn.length === 1 ? { at: x(first[0]), v: first[1] } : null };
}));
const labels = computed(() => (n.value > 2 ? [0, Math.floor((n.value - 1) / 2), n.value - 1] : [0, n.value - 1]).map((i) => props.points[i]?.date));

// Titik aktif: null bila tidak disorot. Periode berganti mengosongkannya supaya tidak menunjuk di luar jangkauan.
const active = ref(null);
watch(n, () => { active.value = null; });
const plot = ref(null);
function pick(event) {
    const box = plot.value.getBoundingClientRect();
    active.value = Math.round(Math.min(Math.max((event.clientX - box.left) / box.width, 0), 1) * (n.value - 1));
}
// Sentuhan memicu pointerleave tepat setelah jari diangkat; tooltip sentuh ditutup saat fokus keluar.
const leave = (event) => { if (event.pointerType !== 'touch') active.value = null; };
function key(event) {
    const step = { ArrowLeft: -1, ArrowRight: 1, Home: -n.value, End: n.value }[event.key];
    if (step === undefined) return;
    event.preventDefault();
    active.value = Math.min(Math.max((active.value ?? n.value - 1) + step, 0), n.value - 1);
}

const current = computed(() => (active.value === null ? null : props.points[active.value]));
const tipStyle = computed(() => {
    const at = x(active.value ?? 0);
    const shift = at < 15 ? '0%' : at > 85 ? '-100%' : '-50%';
    return { left: `${at}%`, transform: `translateX(${shift})` };
});
const show = (v) => (has(v) ? formatNumber(v) : 'tidak dicatat');
const describe = (p) => `${formatDay(p.date)}: ${props.platforms.map((name) => `${PLATFORMS[name]} ${show(p[name])}`).join(', ')}`;
const summary = computed(() => {
    if (!n.value) return `Grafik ${props.title} kosong`;
    return `Grafik ${props.title} per hari, ${formatDay(props.points[0].date)} sampai ${formatDay(props.points[n.value - 1].date)}. Terakhir ${describe(props.points[n.value - 1])}. Gunakan panah kiri dan kanan untuk tiap hari.`;
});
</script>

<template>
  <div>
    <div v-if="empty" class="grid place-items-center rounded-2xl bg-background px-4 text-center text-body-sm text-secondary" :style="{ height: `${height}px` }">
      {{ emptyText }}
    </div>

    <div v-else class="flex gap-3">
      <div v-if="!compact" class="flex flex-col justify-between text-right text-label-sm tabular-nums text-secondary" :style="{ height: `${height}px` }" aria-hidden="true">
        <span v-for="tick in ticks" :key="tick" class="-my-2 leading-4">{{ formatCompact(tick) }}</span>
      </div>

      <div class="min-w-0 flex-1">
        <div
          ref="plot"
          class="relative cursor-crosshair touch-pan-y rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
          :style="{ height: `${height}px` }"
          tabindex="0"
          role="group"
          :aria-label="summary"
          @pointermove="pick"
          @pointerdown="pick"
          @pointerleave="leave"
          @keydown="key"
          @blur="active = null"
        >
          <template v-if="!compact">
            <span v-for="tick in ticks" :key="tick" class="pointer-events-none absolute inset-x-0 border-t border-dashed border-border" :style="{ top: `${y(tick)}%` }" aria-hidden="true"></span>
          </template>

          <svg class="trend-reveal absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient v-for="item in lines" :id="`${uid}-${item.name}`" :key="item.name" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="item.color" stop-opacity="0.18" />
                <stop offset="100%" :stop-color="item.color" stop-opacity="0" />
              </linearGradient>
            </defs>
            <g v-for="item in lines" :key="item.name">
              <path v-if="item.area" :d="item.area" :fill="`url(#${uid}-${item.name})`" />
              <path v-if="item.line" :d="item.line" fill="none" :stroke="item.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </g>
          </svg>

          <!-- Satu titik saja (belum ada garis): tampil sebagai bulatan -->
          <template v-for="item in lines" :key="`one-${item.name}`">
            <span v-if="item.single" class="pointer-events-none absolute size-2.5 -translate-x-1/2 translate-y-1/2 rounded-full border-2 border-surface" :style="{ left: `${item.single.at}%`, bottom: `${100 - y(item.single.v)}%`, backgroundColor: item.color }" aria-hidden="true"></span>
          </template>

          <template v-if="current">
            <span class="pointer-events-none absolute inset-y-0 w-px bg-primary/25" :style="{ left: `${x(active)}%` }" aria-hidden="true"></span>
            <template v-for="item in lines" :key="item.name">
              <span
                v-if="has(current[item.name])"
                class="pointer-events-none absolute size-2.5 -translate-x-1/2 translate-y-1/2 rounded-full border-2 border-surface shadow-pop"
                :style="{ left: `${x(active)}%`, bottom: `${100 - y(current[item.name])}%`, backgroundColor: item.color }"
                aria-hidden="true"
              ></span>
            </template>
            <div class="pointer-events-none absolute -top-2 z-10 min-w-36 -translate-y-full rounded-2xl bg-primary px-3 py-2 text-surface shadow-pop" :style="tipStyle" aria-hidden="true">
              <p class="text-label-sm text-surface/70">{{ formatDay(current.date) }}</p>
              <p v-for="item in lines" :key="item.name" class="mt-0.5 flex items-center gap-2 text-label-md tabular-nums">
                <FaIcon :name="PLATFORM_ICON[item.name]" :size="12" />
                <span class="flex-1">{{ PLATFORMS[item.name] }}</span>
                <span :class="has(current[item.name]) ? '' : 'text-surface/60'">{{ show(current[item.name]) }}</span>
              </p>
            </div>
          </template>
        </div>

        <div class="mt-2 flex justify-between text-label-sm tabular-nums text-secondary" aria-hidden="true">
          <span v-for="(date, i) in labels" :key="`${date}-${i}`">{{ formatShortDay(date) }}</span>
        </div>
      </div>
    </div>

    <p class="sr-only" aria-live="polite">{{ current ? describe(current) : '' }}</p>
  </div>
</template>

<style scoped>
/* Garis tergambar dari kiri sekali saat tampil (clip-path, tanpa ubah tata letak). Reduced motion dipendekkan global di admin.css. */
.trend-reveal {
  animation: trend-reveal 600ms cubic-bezier(0.23, 1, 0.32, 1) both;
}
@keyframes trend-reveal {
  from { clip-path: inset(0 100% 0 0); }
  to { clip-path: inset(0 0 0 0); }
}
</style>
