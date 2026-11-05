import { h } from 'vue';

// Ikon garis 24 x 24 untuk kontak dan media sosial; warna mengikuti teks.
const line = (...children) => ({ line: true, children });
const ICONS = {
    instagram: line(['rect', { x: 3, y: 3, width: 18, height: 18, rx: 5 }], ['circle', { cx: 12, cy: 12, r: 4 }], ['circle', { cx: 17.5, cy: 6.5, r: 0.8, fill: 'currentColor' }]),
    tiktok: { children: [['path', { d: 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z' }]] },
    pin: line(['path', { d: 'M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z' }], ['circle', { cx: 12, cy: 10, r: 3 }]),
    phone: line(['path', { d: 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z' }]),
    question: line(['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01' }]),
    mail: line(['rect', { x: 2, y: 4, width: 20, height: 16, rx: 2 }], ['path', { d: 'm22 7-10 6L2 7' }]),
    lock: line(['rect', { x: 4, y: 11, width: 16, height: 10, rx: 2 }], ['path', { d: 'M8 11V7a4 4 0 0 1 8 0v4' }]),
    clock: line(['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M12 6v6l4 2' }]),
    play: { children: [['path', { d: 'M8 5v14l11-7z' }]] },
    eye: line(['path', { d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z' }], ['circle', { cx: 12, cy: 12, r: 3 }]),
    bag: line(['path', { d: 'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z' }], ['path', { d: 'M3 6h18M16 10a4 4 0 0 1-8 0' }]),
    info: line(['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M12 16v-4M12 8h.01' }]),
    gift: line(['rect', { x: 3, y: 8, width: 18, height: 4, rx: 1 }], ['path', { d: 'M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C9 3 12 8 12 8s3-5 4.5-5a2.5 2.5 0 0 1 0 5' }]),
    truck: line(['path', { d: 'M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2M15 18H9M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14' }], ['circle', { cx: 17, cy: 18, r: 2 }], ['circle', { cx: 7, cy: 18, r: 2 }]),
    headset: line(['path', { d: 'M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3' }]),
    send: line(['path', { d: 'M14.54 21.69a.5.5 0 0 0 .94-.03l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.03.94l7.93 3.18a2 2 0 0 1 1.11 1.11ZM21.85 2.15 10.95 13.05' }]),
    minus: line(['path', { d: 'M5 12h14' }]),
    close: line(['path', { d: 'M18 6 6 18M6 6l12 12' }]),
    eyeOff: line(['path', { d: 'M9.88 9.88a3 3 0 1 0 4.24 4.24' }], ['path', { d: 'M10.73 5.08A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a13 13 0 0 1-1.67 2.68' }], ['path', { d: 'M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.39-1.61' }], ['path', { d: 'M2 2l20 20' }]),
};

const TfLineIcon = (props) => {
    const icon = ICONS[props.name];
    const attrs = icon.line
        ? { fill: 'none', stroke: 'currentColor', 'stroke-width': 1.8, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }
        : { fill: 'currentColor' };
    return h('svg', { viewBox: '0 0 24 24', width: props.size, height: props.size, 'aria-hidden': 'true', ...attrs }, icon.children.map(([tag, a]) => h(tag, a)));
};
TfLineIcon.props = { name: String, size: { type: Number, default: 18 } };

export default TfLineIcon;
