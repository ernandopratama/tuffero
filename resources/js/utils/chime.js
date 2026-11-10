// Nada notifikasi ringan lewat Web Audio (tanpa berkas suara): dua nada sinus pendek, kecil volumenya.
// Browser menolak suara sebelum pengguna berinteraksi, jadi konteks audio disiapkan pada interaksi pertama
// dan nada dilewati diam-diam bila belum siap.
let context;

export function primeAudio() {
    try {
        context ??= new (window.AudioContext || window.webkitAudioContext)();
        if (context.state === 'suspended') context.resume();
    } catch {
        context = null;
    }
}

export function playChime() {
    if (!context || context.state !== 'running') return;
    const now = context.currentTime;
    [[880, 0], [1318.5, 0.11]].forEach(([frequency, delay]) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, now + delay);
        gain.gain.linearRampToValueAtTime(0.05, now + delay + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.35);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(now + delay);
        oscillator.stop(now + delay + 0.4);
    });
}
