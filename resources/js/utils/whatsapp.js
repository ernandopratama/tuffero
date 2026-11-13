// Tautan WhatsApp ke pelanggan dari nomor HP yang tersimpan (awalan 0 diganti 62). Hanya membuka aplikasi, tidak mengirim apa pun.
export function customerWaLink(phone, text) {
    const digits = String(phone || '').replace(/\D/g, '').replace(/^0/, '62');

    return digits ? `https://wa.me/${digits}?text=${encodeURIComponent(text)}` : null;
}
