// Label status untuk halaman admin (Bahasa Indonesia, D-046).
export const ORDER_STATUS = {
    pending_payment: 'Menunggu bayar',
    paid: 'Dibayar',
    processing: 'Diproses',
    shipped: 'Dikirim',
    completed: 'Selesai',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    refunded: 'Dana kembali',
};

export const PAYMENT_STATUS = {
    pending: 'Menunggu',
    paid: 'Lunas',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    refund_pending: 'Perlu dikembalikan',
    refunded: 'Dikembalikan',
};

export const SHIPPING_STATUS = {
    pending: 'Belum diproses',
    packing: 'Dikemas',
    shipped: 'Dikirim',
    delivered: 'Diterima',
};

// Status paket dari Biteship (webhook, snake_case). tone: ok, info, warn, bad dipakai lencana status.
export const CARRIER_STATUS = {
    confirmed: { label: 'Menunggu kurir', tone: 'info' },
    allocated: { label: 'Kurir ditugaskan', tone: 'info' },
    picking_up: { label: 'Kurir menjemput', tone: 'info' },
    picked: { label: 'Paket diambil', tone: 'info' },
    dropping_off: { label: 'Sedang diantar', tone: 'info' },
    delivered: { label: 'Terkirim', tone: 'ok' },
    on_hold: { label: 'Tertahan', tone: 'warn' },
    courier_not_found: { label: 'Kurir belum ditemukan', tone: 'warn' },
    return_in_transit: { label: 'Dalam pengembalian', tone: 'bad' },
    returned: { label: 'Dikembalikan', tone: 'bad' },
    rejected: { label: 'Ditolak kurir', tone: 'bad' },
    cancelled: { label: 'Dibatalkan kurir', tone: 'bad' },
    disposed: { label: 'Dimusnahkan', tone: 'bad' },
};

export const TONE_CLASS = {
    ok: 'bg-success-surface text-success',
    info: 'bg-info-surface text-info',
    warn: 'bg-warning-surface text-warning',
    bad: 'bg-error-surface text-on-error-surface',
    neutral: 'bg-secondary-tint-12 text-primary',
};

export const CANCELLATION_STATUS = {
    submitted: 'Menunggu validasi CS',
    validated: 'Menunggu ACC Admin Toko',
    store_approved: 'Menunggu ACC Pemilik',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    refunded: 'Dana dikembalikan',
};

export const rupiah = (n) => `Rp${Number(n).toLocaleString('id-ID')}`;

// Rp48,2 jt untuk angka besar di kartu dan sumbu grafik; angka kecil tetap utuh.
export function rupiahCompact(n) {
    const value = Number(n);
    if (Math.abs(value) >= 1_000_000) return `Rp${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    if (Math.abs(value) >= 10_000) return `Rp${(value / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb`;
    return rupiah(value);
}

// Nada lencana status pesanan di ringkasan.
export const ORDER_STATUS_TONE = {
    pending_payment: 'warn', paid: 'ok', processing: 'neutral', shipped: 'warn', completed: 'ok', expired: 'neutral', cancelled: 'bad', refunded: 'neutral',
};
