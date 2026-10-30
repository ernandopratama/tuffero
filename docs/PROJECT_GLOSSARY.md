# Glosarium

| Istilah | Arti di proyek Tuffero |
|---|---|
| B2C | Penjualan langsung ke konsumen akhir; model bisnis Tuffero (D-005) |
| Guest checkout | Checkout tanpa membuat akun; cukup nama penerima, email, HP, alamat |
| SKU | Kode unik per varian produk |
| Varian | Turunan produk (mis. ukuran/rasa) dengan SKU, harga, berat, stok sendiri |
| Gudang (warehouse) | Lokasi stok fisik. Malang aktif saat peluncuran; Jakarta rencana |
| Satu order satu gudang | Seluruh item satu pesanan dipenuhi dari gudang yang sama; tidak ada split (D-010) |
| Reservasi stok | Penahanan stok sementara selama pembayaran pending; durasi belum diputuskan (Q-006) |
| Snapshot | Salinan harga, nama item, SKU, alamat, total pada saat order dibuat, tidak berubah walau data produk berubah |
| Idempoten | Operasi yang aman diulang: hasil sama walau request/notifikasi datang lebih dari sekali |
| Webhook / HTTP notification | Request dari Midtrans atau kurir ke server Tuffero untuk memberi tahu perubahan status |
| `signature_key` | Hash SHA-512 di notifikasi Midtrans untuk membuktikan asal notifikasi |
| Midtrans | Payment gateway terpilih (D-015) |
| Snap | Produk pembayaran hosted Midtrans |
| Biteship | Agregator API pengiriman; provider pengiriman MVP (D-020) |
| J&T, SiCepat, Anteraja | Perusahaan kurir |
| Resi | Nomor pelacakan pengiriman |
| Coming Soon | Label untuk fitur yang disiapkan tetapi belum aktif (D-011, D-012) |
| RBAC | Role-Based Access Control; di kode memakai `spatie/laravel-permission` |
| Sanctum | Paket Laravel untuk autentikasi SPA (cookie sesi) dan token API |
| SPA | Single Page Application; frontend Vue 3 di `resources/js` |
| Backeri | Template HTML pihak ketiga (Awaiken) yang dipakai homepage sementara; lisensi belum diverifikasi |
| suntrack-mysql | Proyek internal lain yang arsitekturnya dijadikan acuan (bukan kode bisnisnya) |
| UAT | User Acceptance Test oleh pemilik bisnis sebelum produksi |
| UU PDP | UU No. 27/2022 tentang Pelindungan Data Pribadi |
| `IMPLEMENTED` / `PLANNED` / `PROPOSED` | Label status; definisi di [README.md](README.md) §3 |
| D-xxx, A-xxx, Q-xxx | ID keputusan, asumsi, pertanyaan di Decision Log |
| OQ-xx, INC-xx, BR-xx, SEC-xx | ID pertanyaan terbuka baru, inkonsistensi dokumen, aturan bisnis, temuan keamanan |
