# Standard Operating Procedure (SOP): Git & GitHub Workflow Tim

Panduan ini disusun untuk memberikan standar alur kerja (*workflow*), penamaan branch, format commit, alur **CI/CD**, serta tata cara Pull Request (PR) dan Merge. Tujuannya adalah menjaga riwayat kode (*repository history*) tetap bersih, aman, serta otomatisasi pengujian dan penyebaran aplikasi (*deployment*) seiring berjalannya proyek.

> **Status penerapan di repo MSS Billing**
>
> - Branch `staging` **belum dibuat**. Saat ini hanya ada `main`. Buat sekali dengan `git checkout -b staging main && git push -u origin staging`.
> - **CI sudah ada**: [.github/workflows/ci.yml](../.github/workflows/ci.yml) menjalankan Pint, build, dan PHPUnit pada setiap PR dan push ke `main`/`staging`. Tetap jalankan pemeriksaan di bagian 3 di laptop sebelum push agar CI tidak merah.
> - **Template PR sudah ada**: [.github/pull_request_template.md](../.github/pull_request_template.md) otomatis mengisi deskripsi PR sesuai bagian 4.
> - **CD (auto-deploy) belum ada.** Deploy ke server masih manual lewat `deploy.sh` (lihat [README](../README.md#deploy-ke-server)).
> - Aturan "dilarang push langsung ke `main`/`staging`" baru benar-benar terkunci setelah *branch protection* diaktifkan di GitHub (Settings → Branches): wajib PR, minimal 1 approval, status check **CI / Pint, Build & Test** wajib lulus, dan larang force push.

## 1. Strategi Branching (Branching Strategy)

Untuk menjaga stabilitas kode, kita membagi branch menjadi **Branch Utama** (permanen) dan **Branch Pendukung** (sementara).

### A. Branch Utama (Permanent Branches)

* **`main`**
  * **Fungsi:** Kode yang sudah stabil dan siap tayang di lingkungan *Production*.
  * **Aturan:** Dilarang melakukan *direct commit* atau *push* langsung. Perubahan hanya bisa masuk melalui PR dari `staging` atau `hotfix`.
  * **CI/CD:** Pemicu otomatis *Deployment to Production*.

* **`staging`**
  * **Fungsi:** Tempat penggabungan seluruh fitur baru sebelum rilis. Digunakan untuk proses UAT (*User Acceptance Testing*) atau *testing* internal.
  * **Aturan:** Dilarang melakukan *direct commit* atau *push* langsung. Perubahan masuk hanya melalui Pull Request.
  * **CI/CD:** Pemicu otomatis *Deployment to Staging/Development Environment*.

### B. Branch Pendukung (Temporary Branches)

Dibuat saat mulai mengerjakan tugas dan **wajib dihapus** setelah berhasil di-merge.

* **`feature/<nama-fitur>`**
  * **Kapan digunakan:** Membuat fitur baru.
  * **Contoh:** `feature/laporan-pengeluaran`, `feature/notifikasi-whatsapp`
  * **CI/CD:** Memicu otomatis proses pengujian (Linter & Test) saat diajukan sebagai PR.

* **`bugfix/<nama-bug>`**
  * **Kapan digunakan:** Memperbaiki bug yang ditemukan di branch `staging`.
  * **Contoh:** `bugfix/total-tagihan-prorate`, `bugfix/dropdown-router-isolir`

* **`hotfix/<deskripsi-singkat>`**
  * **Kapan digunakan:** Perbaikan darurat atas *bug* kritis yang ditemukan langsung di *Production* (`main`).
  * **Contoh:** `hotfix/security-patch-auth`

---

## 2. Standar Commit Messages (Conventional Commits)

Format commit harus konsisten agar seluruh tim dapat memahami perubahan kode tanpa membuka baris kodenya satu per satu.

### Format Penulisan:
```
<tipe>: <deskripsi singkat dalam kalimat aktif>
```

### Tipe Commit yang Digunakan:

| Tipe | Kapan Digunakan | Contoh Pesan |
| :--- | :--- | :--- |
| **`feat`** | Menambah fitur baru | `feat: tambah menu laporan pengeluaran` |
| **`fix`** | Memperbaiki bug | `fix: dropdown router di Template Isolir hanya menampilkan nama router` |
| **`refactor`** | Perubahan struktur kode tanpa merubah fungsi | `refactor: pindahkan rumus saldo mitra ke RekapCabang` |
| **`style`** | Perubahan UI/Styling, CSS, format, spasi | `style: rapikan padding tombol di navbar` |
| **`docs`** | Perubahan dokumen, README, atau komentar kode | `docs: tambah panduan SOP Git ke README` |
| **`chore`** | Update dependency, konfigurasi build, atau tool | `chore: update vite ke v8` |
| **`test`** | Menambah atau memperbaiki test | `test: tambah test akses cabang untuk penarikan saldo` |

> ❌ **Hindari:** `git commit -m "fix bug"`, `git commit -m "update code"`, atau `git commit -m "perbaikan"`

---

## 3. Integrasi CI/CD (Continuous Integration & Continuous Deployment)

Setiap aksi pada repositori GitHub akan terhubung langsung dengan *pipeline* otomatisasi (misal: GitHub Actions).

### A. Continuous Integration (CI) — Pengujian Otomatis
Setiap kali ada **Pull Request (PR)** yang diajukan ke branch `staging` atau `main`:
1. **Linter & Code Style Check:** Memastikan format dan gaya penulisan kode PHP sesuai standar tim (`vendor/bin/pint --test`).
2. **Build Check:** Memastikan aset frontend dapat di-*build* tanpa error (`npm run build`).
3. **Automated Testing:** Menjalankan seluruh test PHPUnit (`php artisan test --compact`).

CI ini ada di [.github/workflows/ci.yml](../.github/workflows/ci.yml). Jalankan juga ketiga perintah di atas di laptop sebelum push. Untuk merapikan format otomatis: `vendor/bin/pint --dirty`.

> ⛔ **Aturan PR & CI:** PR **TIDAK BISA DI-MERGE** jika status CI menunjukkan tanda gagal (*failed status checks*). Developer wajib memperbaiki error tersebut terlebih dahulu.

### B. Continuous Deployment (CD) — Rilis Otomatis
Proses deployment dilakukan secara otomatis tanpa intervensi manual SSH ke server:

1. **Auto-Deploy to Staging:**
   * **Trigger:** Ketika PR di-merge ke branch `staging`.
   * **Target:** Server Staging / Dev.
   * **Tujuan:** Untuk ditinjau oleh QA / Product Manager / Tim internal.

2. **Auto-Deploy to Production:**
   * **Trigger:** Ketika PR (dari `staging` atau `hotfix`) di-merge ke branch `main`.
   * **Target:** Server Production.
   * **Tujuan:** Rilis fitur resmi ke pengguna akhir.

Selama CD belum tersedia, deploy dilakukan manual di server dengan `sudo bash deploy.sh` (atau langkah aaPanel di README), **hanya** dari branch `main`.

---

## 4. Aturan & Standar Pull Request (PR)

Pull Request (PR) adalah proses peninjauan kode (*Code Review*) dan validasi CI sebelum perubahan diizinkan masuk ke branch utama.

### Aturan Wajib PR:
1. **Fokus & Spesifik:** 1 PR hanya untuk 1 fitur/tugas. Hindari menggabungkan beberapa fitur berbeda dalam 1 PR.
2. **Reviewer Minimum:** Setiap PR wajib mendapatkan minimal **1 approval** dari anggota tim lain / Lead Developer.
3. **Syarat Merge:**
   * ✅ Min. 1 Code Review Approval.
   * ✅ Seluruh pipeline CI (*Linter, Test, Build*) lulus (Green).
   * ✅ Tidak ada *merge conflict*.
4. **Migration & Permission:** Bila PR menambah migration atau permission baru (`app/Support/HakAkses.php`), sebutkan di deskripsi agar reviewer menjalankan `php artisan migrate` dan `php artisan db:seed --class=HakAksesSeeder`.

### Format Deskripsi PR:

Setiap pembuat PR wajib mengisi template deskripsi berikut:

```markdown
## Deskripsi Perubahan
[Jelaskan secara singkat apa yang dibuat/diperbaiki]

## Perubahan Utama
- Menambahkan halaman Laporan Pengeluaran
- Menambahkan permission `laporan.pengeluaran`

## Cara Testing Manual
1. Jalankan `composer run dev`
2. Login sebagai Super Admin, buka `/laporan/pengeluaran`
3. Tambah satu data pengeluaran dan cek totalnya

## Status CI Checks
- [x] Pint Pass
- [x] Build Pass
- [x] PHPUnit Pass

## Screenshot (Opsional)
[Lampirkan screenshot jika ada perubahan tampilan/UI]
```

---

## 5. Strategi Merging (Penggabungan Kode)

Untuk menjaga *commit history* di branch `staging` dan `main` tetap rapi dan tidak berantakan:

1. **Gunakan `Squash and Merge` (Sangat Disarankan)**
   * Menggabungkan seluruh commit berulang selama pengerjaan di branch fitur (misal: *"fix typo"*, *"debug API"*, *"hampir selesai"*) menjadi **1 commit bersih** saat di-merge ke branch tujuan.
2. **Hindari Direct Push & Force Push (`git push -f`)**
   * *Force push* pada branch bersama (`main` / `staging`) dilarang keras karena dapat merusak atau menghapus riwayat kerja anggota tim lain.

---

## 6. Panduan Alur Kerja Harian (Step-by-Step)

Berikut panduan langkah demi langkah untuk developer dalam mengerjakan tugas harian:

```bash
# 1. Pastikan lokal branch staging berada di kondisi terbaru
git checkout staging
git pull origin staging
#    Lalu sinkronkan dependency & database (lihat README "Setelah git pull")

# 2. Buat branch fitur baru dari staging
git checkout -b feature/nama-fitur-anda

# 3. Lakukan pengkodean dan commit secara berkala
git add .
git commit -m "feat: tambah validasi form pelanggan"

# 4. Sebelum push, jalankan pemeriksaan yang sama dengan CI
vendor/bin/pint --dirty
php artisan test --compact
npm run build

# 5. Push branch fitur ke GitHub
git push origin feature/nama-fitur-anda

# 6. Buka GitHub -> Buat Pull Request (PR) dari branch `feature/...` ke `staging`

# 7. Tunggu Pipeline CI berjalan otomatis di GitHub PR (Lint, Test, Build)
#    Jika CI Gagal (Red), perbaiki di lokal, commit, dan push kembali ke branch yang sama.

# 8. Minta rekan tim untuk melakukan Code Review.

# 9. Setelah di-approve & CI Green (Lulus), lakukan "Squash and Merge" via GitHub UI.
#    -> CD akan berjalan otomatis men-deploy ke Server Staging.

# 10. Hapus branch fitur di GitHub dan lokal setelah di-merge:
git checkout staging
git pull origin staging
git branch -d feature/nama-fitur-anda
```

> Catatan: `git add .` aman karena `.env`, `vendor/`, `node_modules/`, `public/build` dan `database/database.sqlite` sudah ada di `.gitignore`. Tetap periksa `git status` sebelum commit.

---
*Dokumen ini merupakan standar baku tim. Silakan ajukan diskusi jika terdapat alur yang perlu disesuaikan dengan kebutuhan proyek.*
