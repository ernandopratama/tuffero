# Changelog Dokumentasi dan Proyek

## 2026-10-30 — Keputusan D-020 (Biteship) dan D-021 (frontend hybrid)

Keputusan: pengiriman MVP lewat Biteship, disetujui Ko Rifan; frontend hybrid (toko Blade + Tailwind + Alpine, admin Vue SPA), diputuskan Ernando. Hanya dokumentasi yang diubah; kode belum dimigrasi.

| File | Perubahan |
|---|---|
| `CLAUDE.md` | §4: Biteship mengacu D-020, provider lewat `ShippingService`. §5: daftar teknologi dan aturan frontend hybrid |
| `docs/project-management/DECISION_LOG.md` | v0.3: bagian 1a dengan D-020 dan D-021; riwayat v0.3. Baris D-014 dan Q-017 tidak dihapus |
| `docs/product/PRD.md` | Catatan bertanggal di bawah FR-05 |
| `docs/product/MVP_SCOPE.md` | Catatan bertanggal di bagian Pengiriman |
| `docs/project-management/OPEN_QUESTIONS.md` | OQ-01 dan OQ-02 ditandai selesai |
| `docs/product/BUSINESS_RULES.md` | Tambah BR-22; hapus baris provider dari "belum diputuskan" |
| `docs/product/REFERENCE_DOCUMENTS.md` | INC-01, INC-03, INC-08 ditandai selesai |
| `docs/AI_PROJECT_CONTEXT.md`, `PROJECT_OVERVIEW.md`, `PROJECT_GLOSSARY.md` | Stack, pengiriman, dan daftar keputusan terbuka disesuaikan |
| `docs/architecture/FRONTEND_ARCHITECTURE.md`, `PROPOSED_DOMAIN_ARCHITECTURE.md` | Arah hybrid dan provider Biteship |
| `docs/frontend/DESIGN_SYSTEM.md`, `docs/integrations/INTEGRATIONS.md` | Status Biteship dan arah frontend |
| `docs/project-management/ROADMAP.md`, `PROJECT_STATUS.md` | Fase 0 dan 4; catatan §16 |

## 2026-10-30 — Audit proyek dan reorganisasi dokumentasi

**Dokumen dipindah (isi tidak diubah, hash MD5 identik sebelum/sesudah):**

| Lama | Baru |
|---|---|
| `docs/PRD.md` | `docs/product/PRD.md` |
| `docs/MVP_SCOPE.md` | `docs/product/MVP_SCOPE.md` |
| `docs/DECISION_LOG.md` | `docs/project-management/DECISION_LOG.md` |
| `docs/TUFFERO_PROJECT_STATUS.md` | `docs/project-management/PROJECT_STATUS.md` |

**Dokumen diperbarui:**

- `project-management/PROJECT_STATUS.md`: path referensi disesuaikan dengan lokasi baru; ditambah bagian 16 (perubahan stack 2026-10-30) dan 17 (status audit). Isi lama tidak dihapus.

**Dokumen baru:**

`README.md`, `DOCUMENTATION_INDEX.md`, `PROJECT_OVERVIEW.md`, `AI_PROJECT_CONTEXT.md`, `PROJECT_GLOSSARY.md`, `product/BUSINESS_RULES.md`, `product/REFERENCE_DOCUMENTS.md`, `architecture/SYSTEM_OVERVIEW.md`, `architecture/FRONTEND_ARCHITECTURE.md`, `architecture/BACKEND_ARCHITECTURE.md`, `architecture/PROPOSED_DOMAIN_ARCHITECTURE.md`, `database/DATABASE.md`, `frontend/DESIGN_SYSTEM.md`, `integrations/INTEGRATIONS.md`, `security/SECURITY_REQUIREMENTS.md`, `security/SECURITY_AUDIT.md`, `security/THREAT_MODEL.md`, `infrastructure/ENVIRONMENT_AND_DEPLOYMENT.md`, `quality/TESTING_STRATEGY.md`, `quality/CODE_STANDARDS.md`, `project-management/OPEN_QUESTIONS.md`, `project-management/ROADMAP.md`, `project-management/CHANGELOG.md`.

**Tidak diubah:** `DECISION_LOG.md`, `PRD.md`, `MVP_SCOPE.md`, folder `TUFFERO/`, seluruh kode aplikasi.

## 2026-10-30 — Perubahan kode sebelum audit (sesi pengembangan)

Dicatat agar riwayat lengkap; detail di [PROJECT_STATUS.md](PROJECT_STATUS.md) §16.

- Frontend dipindah ke Vue 3 SPA (vue-router, Pinia, axios); homepage Backeri di-port ke `pages/Home.vue`.
- Backend disamakan dengan arsitektur suntrack-mysql: Sanctum, spatie/laravel-permission, Larastan, API `/api/v1`, `ApiResponse`, `BaseRepository`, `SecurityHeadersMiddleware`.
- Docker: `Dockerfile`, `docker-compose.yml`, `docker/nginx.conf`, `.dockerignore`.
- `.env.example`: koneksi DB default menjadi `pgsql`.
