// Label status untuk halaman admin (Bahasa Indonesia, D-046).
export const ORDER_STATUS = {
    awaiting_approval: 'Menunggu persetujuan',
    rejected: 'Ditolak admin',
    pending_payment: 'Menunggu pembayaran',
    paid: 'Dibayar',
    processing: 'Diproses',
    shipped: 'Dikirim',
    completed: 'Selesai',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    refunded: 'Dana dikembalikan',
};

export const PAYMENT_STATUS = {
    pending: 'Menunggu pembayaran',
    paid: 'Lunas',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    refund_pending: 'Dana perlu dikembalikan',
    refunded: 'Dana dikembalikan',
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
    in_transit: { label: 'Dalam pengiriman', tone: 'info' },
    dropping_off: { label: 'Sedang diantar', tone: 'info' },
    delivered: { label: 'Diterima', tone: 'ok' },
    on_hold: { label: 'Tertahan', tone: 'warn' },
    courier_not_found: { label: 'Kurir belum ditemukan', tone: 'warn' },
    return_in_transit: { label: 'Paket dalam pengembalian', tone: 'bad' },
    returned: { label: 'Paket dikembalikan', tone: 'bad' },
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
    approved: 'Disetujui',
    rejected: 'Ditolak',
    refunded: 'Dana dikembalikan',
};

// Refund barang bermasalah dan retur produk utuh (D-031, D-097).
export const REFUND_STATUS = {
    submitted: 'Perlu diproses',
    awaiting_evidence: 'Menunggu video',
    under_review: 'Menunggu keputusan',
    approved: 'Menunggu dana',
    refunded: 'Dana dikembalikan',
    rejected: 'Ditolak',
    cancelled: 'Dibatalkan',
};
// Status "Dana dikembalikan" memakai kata yang sesuai cara penyelesaiannya (transfer, kirim ulang, atau voucher).
export const REFUND_CHOICE = { refund: 'Dana dikembalikan', resend: 'Kirim ulang', voucher: 'Voucher' };
export const refundStatusLabel = (status, method) => (status === 'refunded' ? ({ resend: 'Kirim ulang selesai', voucher: 'Voucher diberikan' }[method] || REFUND_STATUS.refunded) : REFUND_STATUS[status]);
export const REFUND_STATUS_TONE = { submitted: 'warn', awaiting_evidence: 'warn', under_review: 'info', approved: 'info', refunded: 'ok', rejected: 'bad', cancelled: 'neutral' };
export const REFUND_REASON = {
    damaged: 'Barang rusak',
    wrong_item: 'Salah kirim',
    missing_item: 'Barang kurang',
    not_fit_for_consumption: 'Tidak layak konsumsi',
    return_unopened: 'Retur produk utuh',
    other: 'Lainnya',
};

// Tanda minus di depan Rp (-Rp7.000), bukan di antara Rp dan angka. Pendapatan bersih boleh negatif.
export const rupiah = (n) => `${Number(n) < 0 ? '-' : ''}Rp${Math.abs(Number(n)).toLocaleString('id-ID')}`;

// Rp48,2 jt untuk angka besar di kartu dan sumbu grafik; angka kecil tetap utuh.
export function rupiahCompact(n) {
    const value = Number(n);
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);
    if (abs >= 1_000_000) return `${sign}Rp${(abs / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    if (abs >= 10_000) return `${sign}Rp${(abs / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb`;
    return rupiah(value);
}

// Nada lencana status pesanan di ringkasan.
export const ORDER_STATUS_TONE = {
    awaiting_approval: 'bad', rejected: 'neutral', pending_payment: 'warn', paid: 'ok', processing: 'neutral', shipped: 'warn', completed: 'ok', expired: 'neutral', cancelled: 'bad', refunded: 'neutral',
};
