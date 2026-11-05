// Label tindakan audit log (Bahasa Indonesia, D-046). Dipakai halaman Audit Log dan Ringkasan.
export const ACTIONS = {
    'user.created': 'Akun dibuat',
    'user.updated': 'Akun diubah',
    'setting.updated': 'Pengaturan diubah',
    'product.created': 'Produk dibuat',
    'product.updated': 'Produk diubah',
    'product.image_added': 'Foto produk ditambah',
    'product.image_removed': 'Foto produk dihapus',
    'variant.created': 'Varian dibuat',
    'variant.updated': 'Varian diubah',
    'stock.adjusted': 'Stok diubah',
    'voucher.created': 'Voucher dibuat',
    'voucher.updated': 'Voucher diubah',
    'content.created': 'Konten dibuat',
    'content.updated': 'Konten diubah',
    'content.image_changed': 'Gambar konten diganti',
    'shipment.waybill_corrected': 'Resi dikoreksi',
    'profile.updated': 'Profil diubah',
    'profile.password_changed': 'Password diganti',
    'mfa.enrolled': 'MFA diaktifkan',
    'mfa.reset': 'MFA direset',
    'mfa.recovery_code_used': 'Kode pemulihan dipakai',
};

export const actionLabel = (action) => ACTIONS[action] || action;
