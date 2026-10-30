# Indeks Dokumentasi

Pembaruan terakhir: 2026-10-30.

## Akar

| Dokumen | Isi |
|---|---|
| [README.md](README.md) | Kebijakan sumber kebenaran, label status, aturan pemeliharaan |
| [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) | Dokumen ini |
| [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md) | Ringkasan bisnis, peran, cakupan, target |
| [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md) | Onboarding AI/developer, status modul, urutan baca wajib |
| [PROJECT_GLOSSARY.md](PROJECT_GLOSSARY.md) | Istilah dan ID |

## Produk — `product/`

| Dokumen | Isi | Asal |
|---|---|---|
| [PRD.md](product/PRD.md) | Product Requirements v0.2 | Asli, dipindah dari `docs/` |
| [MVP_SCOPE.md](product/MVP_SCOPE.md) | MVP Scope v0.2 | Asli, dipindah dari `docs/` |
| [BUSINESS_RULES.md](product/BUSINESS_RULES.md) | Aturan bisnis terkonsolidasi + status kode | Baru |
| [REFERENCE_DOCUMENTS.md](product/REFERENCE_DOCUMENTS.md) | Ringkasan folder `TUFFERO/` + inkonsistensi antar-dokumen | Baru |

## Arsitektur — `architecture/`

| Dokumen | Isi |
|---|---|
| [SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md) | Arsitektur aktual, siklus request, alur auth, deployment, struktur folder |
| [FRONTEND_ARCHITECTURE.md](architecture/FRONTEND_ARCHITECTURE.md) | Vue SPA, halaman, homepage template, styling |
| [BACKEND_ARCHITECTURE.md](architecture/BACKEND_ARCHITECTURE.md) | Komponen backend, daftar endpoint, alur request, error handling |
| [PROPOSED_DOMAIN_ARCHITECTURE.md](architecture/PROPOSED_DOMAIN_ARCHITECTURE.md) | `PROPOSED`: modul e-commerce, browsing, checkout, alokasi gudang, siklus order, pembayaran, pengiriman |

## Database — `database/`

| Dokumen | Isi |
|---|---|
| [DATABASE.md](database/DATABASE.md) | Konfigurasi, tabel aktual, ERD aktual, ERD usulan, strategi migration |

## Frontend — `frontend/`

| Dokumen | Isi |
|---|---|
| [DESIGN_SYSTEM.md](frontend/DESIGN_SYSTEM.md) | Token, tipografi, status penerapan |

## Integrasi — `integrations/`

| Dokumen | Isi |
|---|---|
| [INTEGRATIONS.md](integrations/INTEGRATIONS.md) | Midtrans, pengiriman (J&T/SiCepat/Biteship), email, storage, AI, layanan pihak ketiga frontend |

## Keamanan — `security/`

| Dokumen | Isi |
|---|---|
| [SECURITY_REQUIREMENTS.md](security/SECURITY_REQUIREMENTS.md) | Persyaratan per area + status, alur respons insiden |
| [SECURITY_AUDIT.md](security/SECURITY_AUDIT.md) | Kontrol yang ada, temuan SEC-01 sampai SEC-23, roadmap P0–P3 |
| [THREAT_MODEL.md](security/THREAT_MODEL.md) | Aset, data pribadi, aktor, ancaman, batas kepercayaan |

## Infrastruktur — `infrastructure/`

| Dokumen | Isi |
|---|---|
| [ENVIRONMENT_AND_DEPLOYMENT.md](infrastructure/ENVIRONMENT_AND_DEPLOYMENT.md) | Setup lokal, Docker, rencana produksi, troubleshooting |

## Kualitas — `quality/`

| Dokumen | Isi |
|---|---|
| [TESTING_STRATEGY.md](quality/TESTING_STRATEGY.md) | Test yang ada, prinsip, rencana test keamanan |
| [CODE_STANDARDS.md](quality/CODE_STANDARDS.md) | Konvensi PHP, Vue, Git, dokumentasi |

## Manajemen Proyek — `project-management/`

| Dokumen | Isi | Asal |
|---|---|---|
| [DECISION_LOG.md](project-management/DECISION_LOG.md) | Keputusan D-001–D-019, asumsi, pertanyaan Q-001–Q-017 | Asli, dipindah |
| [PROJECT_STATUS.md](project-management/PROJECT_STATUS.md) | Status dan handover historis + pembaruan 2026-10-30 (§16–17) | Asli, dipindah dari `TUFFERO_PROJECT_STATUS.md` |
| [OPEN_QUESTIONS.md](project-management/OPEN_QUESTIONS.md) | Pertanyaan baru OQ-01–OQ-11 | Baru |
| [ROADMAP.md](project-management/ROADMAP.md) | `PROPOSED` urutan fase | Baru |
| [CHANGELOG.md](project-management/CHANGELOG.md) | Riwayat perubahan dokumen/proyek | Baru |

## Dokumen yang Sengaja Tidak Dibuat

Struktur panduan audit menyarankan lebih banyak file. File berikut **tidak** dibuat karena isinya akan kosong atau menduplikasi dokumen lain; buat saat fiturnya ada:

- `operations/*` (panduan admin, produk, order, inventori): belum ada fungsi admin.
- `backend/ORDER_WORKFLOW.md`, `INVENTORY_WORKFLOW.md`, `PAYMENT_WORKFLOW.md`, `SHIPPING_WORKFLOW.md`, `PROMOTION_WORKFLOW.md`: alur usulan digabung di `architecture/PROPOSED_DOMAIN_ARCHITECTURE.md`.
- `integrations/MIDTRANS.md`, `BITESHIP.md`: digabung di `integrations/INTEGRATIONS.md`.
- `database/ERD.md`, `DATA_DICTIONARY.md`, `RELATIONSHIPS.md`, `MIGRATION_STRATEGY.md`: digabung di `database/DATABASE.md`.
- `security/*` per subtopik (authentication, payment, API, dsb.): digabung di `security/SECURITY_REQUIREMENTS.md` per bagian.
- `product/USER_PERSONAS.md`, `USER_JOURNEYS.md`, `FUNCTIONAL_REQUIREMENTS.md`, `NON_FUNCTIONAL_REQUIREMENTS.md`: sudah tercakup di PRD.md §3, §5, §6.
- `infrastructure/BACKUP_RECOVERY.md`, `MONITORING.md`, `SERVER_ARCHITECTURE.md`: belum ada keputusan atau konfigurasi; rencana ada di `ENVIRONMENT_AND_DEPLOYMENT.md`.

## Sumber di Luar `docs/`

- `TUFFERO/` — dokumen bisnis/desain asli (lihat [REFERENCE_DOCUMENTS.md](product/REFERENCE_DOCUMENTS.md)).
- `CLAUDE.md` — instruksi agent: bootstrap Laravel Boost (belum dijalankan) dan aturan graphify.
- `AGENTS.md` — instruksi bootstrap Laravel Boost (belum dijalankan).
