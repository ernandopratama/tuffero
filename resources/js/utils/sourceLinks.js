// Sumber draf konten disimpan sebagai teks bebas ("Judul - https://..." atau tautan saja, satu per baris).
// Hanya tautan http(s) yang dijadikan tautan; baris lain tampil sebagai teks biasa.
export function sourceLinks(text) {
    return (text || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
        const url = line.match(/https?:\/\/[^\s)]+/)?.[0] ?? null;
        const label = url ? line.replace(url, '').replace(/[\s\-–:()]+$/, '').trim() : line;
        let host = '';
        try { host = url ? new URL(url).hostname.replace(/^www\./, '') : ''; } catch { host = ''; }
        return { url, label: label || host || line, host };
    });
}
