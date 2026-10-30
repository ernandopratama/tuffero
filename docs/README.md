# Dokumentasi Tuffero

Folder ini adalah basis pengetahuan resmi proyek Tuffero E-commerce. Mulai dari [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) untuk daftar lengkap dokumen, atau [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md) jika Anda AI assistant atau developer baru.

## 1. Kebijakan Sumber Kebenaran (Source of Truth)

Setiap jenis informasi punya satu sumber utama. Jika dua sumber berbeda, sumber di kolom kanan yang menang untuk topik tersebut.

| Topik | Sumber kebenaran |
|---|---|
| Aturan bisnis | Keputusan yang disetujui di [project-management/DECISION_LOG.md](project-management/DECISION_LOG.md) |
| Kebutuhan produk | [product/PRD.md](product/PRD.md) |
| Cakupan peluncuran | [product/MVP_SCOPE.md](product/MVP_SCOPE.md) |
| Apa yang sudah diimplementasikan | Source code di repository (bukan dokumen) |
| Struktur data saat ini | Skema database aktual dan folder `database/migrations` |
| Desain sistem terverifikasi dan arsitektur masa depan yang disetujui | Dokumen di [architecture/](architecture/) |
| Ekspektasi keamanan | [security/SECURITY_REQUIREMENTS.md](security/SECURITY_REQUIREMENTS.md) |
| Temuan keamanan dan status perbaikannya | [security/SECURITY_AUDIT.md](security/SECURITY_AUDIT.md) |
| Progres pekerjaan | [project-management/PROJECT_STATUS.md](project-management/PROJECT_STATUS.md) |
| Perubahan penting dan alasannya | [project-management/DECISION_LOG.md](project-management/DECISION_LOG.md) |
| Identitas visual | `TUFFERO/Tuffero_Design_System.txt`, diringkas di [frontend/DESIGN_SYSTEM.md](frontend/DESIGN_SYSTEM.md) |

Urutan prioritas antar-dokumen produk: dokumen bertanggal lebih baru menggantikan yang lama. PRD v0.2 dan MVP Scope v0.2 (26 Oktober 2026) menggantikan PDF v0.1 di `TUFFERO/`.

## 2. Jika Kode dan Dokumen Bertentangan

1. Catat perbedaannya: file sumber, baris/kelas terkait, dan dokumen yang bertentangan.
2. Jangan mengubah kebutuhan bisnis secara diam-diam agar cocok dengan kode.
3. Jika perbedaan memengaruhi perilaku bisnis (harga, stok, pembayaran, pengiriman, akses data), minta klarifikasi pemilik bisnis atau technical lead sebelum mengubah apa pun.
4. Catat pertanyaan di [project-management/OPEN_QUESTIONS.md](project-management/OPEN_QUESTIONS.md).
5. Jika kode yang benar dan dokumen yang usang, perbarui dokumen dan catat di [project-management/CHANGELOG.md](project-management/CHANGELOG.md).

## 3. Label Status

Gunakan label ini secara konsisten di semua dokumen.

| Label | Arti |
|---|---|
| `IMPLEMENTED` | Ada di kode, berjalan, dan diverifikasi (test, perintah, atau pembacaan kode) |
| `PARTIALLY IMPLEMENTED` | Sebagian ada di kode; bagian yang hilang disebutkan |
| `PLANNED` | Disetujui di PRD/MVP/Decision Log, belum ada di kode |
| `PROPOSED` | Usulan teknis dari dokumentasi ini; belum disetujui pemilik bisnis/technical lead |
| `NOT IMPLEMENTED` | Tidak ada di kode dan belum direncanakan secara spesifik |
| `UNKNOWN` | Belum bisa diverifikasi; alasannya disebutkan |
| `NOT VERIFIED` | Khusus keamanan: pemeriksaan tidak dapat dilakukan dengan aman atau belum dilakukan |

Jangan pernah menulis fitur `PLANNED` atau `PROPOSED` seolah-olah `IMPLEMENTED`.

## 4. Aturan Pemeliharaan

- Perbarui dokumen pada perubahan yang sama dengan perubahan kode yang memengaruhinya.
- Setiap klaim implementasi harus menyebut file sumber.
- Setiap perubahan dokumen dicatat di [project-management/CHANGELOG.md](project-management/CHANGELOG.md).
- Keputusan baru dicatat di Decision Log dengan ID, tanggal, dan siapa yang memutuskan.
- Jangan menulis isi `.env`, credential, API key, token, atau data pribadi ke dokumen mana pun.
- Diagram memakai Mermaid. Diagram untuk fitur yang belum ada wajib diberi judul `PROPOSED`.
- Dokumen asli PRD, MVP Scope, Decision Log, dan Project Status tidak boleh ditulis ulang. Tambahkan bagian baru dengan tanggal; jangan menghapus isi lama.

## 5. Bahasa

Dokumen ditulis dalam Bahasa Indonesia agar konsisten dengan dokumen bisnis yang sudah ada. Istilah teknis, nama kelas, perintah, dan path tetap dalam bahasa aslinya.
