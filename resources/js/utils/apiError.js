// Pesan Bahasa Indonesia untuk halaman admin (D-046). `fields` berisi pesan validasi per kolom dari server.
export function apiError(e) {
    const status = e.response?.status;
    const fields = status === 422 ? e.response.data?.errors || {} : {};
    const messages = {
        401: 'Sesi berakhir. Masuk lagi.',
        403: 'Anda tidak punya izin untuk aksi ini, atau MFA akun Anda belum aktif.',
        404: 'Data tidak ditemukan.',
        419: 'Sesi berakhir. Muat ulang halaman.',
        422: e.response?.data?.message && !Object.keys(fields).length ? e.response.data.message : 'Periksa kembali isian Anda.',
        429: 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
    };

    return { message: messages[status] || 'Terjadi kesalahan. Coba lagi.', fields };
}
