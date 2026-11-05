# Audit Performa MSS Billing

Branch: `perf/audit-optimasi` (dibuat dari `main` commit `4974538`)
Tanggal audit: 6 November 2026
Status: **Tahap 3 selesai — menunggu persetujuan sebelum perbaikan kode (Tahap 4).**

## 1. Ringkasan

Stack: Laravel 13 (PHP ^8.4.1), Inertia v3 (`inertiajs/inertia-laravel` ^3.5, `@inertiajs/react` ^3.9), React 19, Vite 8, reactstrap 9, ApexCharts 8, spatie/laravel-permission 8. Server memakai SQLite (lihat `README.md` › Deploy), dengan session, cache, dan queue juga di database (`.env.example:30,41,43`).

Penyebab utama website terasa berat, berurutan dari dampak terbesar:

1. **Setiap kali website dibuka, sidebar meminta data 34 halaman sekaligus** (prefetch `mount`). Di lokal ini setara 394 query dan ±224 KB JSON per buka halaman, sebelum pengguna mengklik apa pun. Klik dan filter pengguna harus antre di belakang permintaan ini.
2. **Perhitungan keuangan dijalankan berulang**: permintaan grafik tren di Laporan ikut menghitung ulang seluruh laporan, dan saldo/kewajiban mitra dihitung 5 query per bulan per permintaan (185 query untuk satu permintaan di Pembayaran Masuk).
3. **SQLite memakai pengaturan bawaan** (mode jurnal rollback) padahal session & cache juga ditulis ke database yang sama di hampir setiap request, sehingga request saling menunggu kunci database.
4. **Data yang terus tumbuh dikirim utuh ke browser**: daftar pelanggan mengirim semua pelanggan beserta seluruh riwayat pembayarannya, dan Dashboard memuat semua pembayaran 12 bulan ke PHP hanya untuk dijumlahkan.

Kabar baiknya: tidak ditemukan Debugbar/Telescope/`dd()`/`console.log`, halaman sudah di-code-split, ApexCharts dimuat terpisah, hampir tidak ada N+1 query, dan cache config/route/view/event sudah dibuat oleh `deploy.sh`.

## 2. Pengukuran awal (Tahap 1)

### 2.1 Bundle (`npm run build`)

| Metrik | Nilai |
|---|---|
| Jumlah file JS / CSS hasil build | 61 / 1 |
| Total JS | 1.709,5 KB (gzip 478,1 KB) |
| Total CSS hasil build | 41,1 KB (gzip 9,1 KB) |
| CSS tema Cuba (`public/cuba/css/app.css`, dimuat terpisah di semua halaman) | 1.692 KB (gzip 217,3 KB) |

Chunk terbesar:

| File | Raw | Gzip | Keterangan |
|---|---|---|---|
| `react-apexcharts.esm-*.js` | 910,8 KB | 264,9 KB | Hanya dimuat di halaman bergrafik (lazy, `Components/Chart.jsx:4`) |
| `jsx-runtime-*.js` | 333,1 KB | 102,3 KB | Vendor: React, React DOM, Inertia, reactstrap |
| `app-*.js` | 129,3 KB | 25,7 KB | Entry aplikasi + layout |
| `DataTable-*.js` | 60,3 KB | 16,5 KB | Komponen tabel bersama |

### 2.2 Test

`php artisan test --compact`: **241 test lulus, 2.839 assertion** (11,6 detik).

### 2.3 Query dan waktu respons per halaman (lokal)

Cara ukur: skrip PHP memanggil HTTP kernel langsung sebagai Super Admin dengan header Inertia, median dari 3 percobaan setelah 1 pemanasan. Database **lokal (MySQL)** dengan data kecil: 7 lokasi, 30 pelanggan, 55 pembayaran, 8 pelanggan instansi, 16 pembayaran instansi. Angka waktu di server dengan data asli akan lebih besar; **jumlah query** adalah pembanding yang paling bisa diandalkan.

Catatan: database lokal sudah memiliki indeks dari migration sesi sebelumnya (`add_performance_indexes`, kini disimpan di stash). Jumlah query tidak terpengaruh; waktu lokal sedikit lebih baik dari kondisi asli.

| Halaman | Query | Waktu (ms) | Data (KB) |
|---|---|---|---|
| `/dashboard` | 12 | 19 | 1,3 |
| `/dashboard-cabang` | 9 | 23 | 4,6 |
| `/income-summary` | 6 | 13 | 4,7 |
| `/pelanggan` | 23 | 55 | 32,1 |
| `/pelanggan/on` | 23 | 54 | 31,1 |
| `/pelanggan/lunas` | 21 | 39 | 20,3 |
| `/pelanggan/belum-lunas` | 23 | 41 | 13,3 |
| `/data-pelanggan` | 7 | 17 | 8,1 |
| `/kelola-data/kartu-member` | 6 | 20 | 19,7 |
| `/kelola-data/template-isolir` | 5 | 8 | 14,7 |
| `/transaksi/penarikan-saldo` | **66** | 101 | 2,2 |
| `/transaksi/setor-kewajiban` | 11 | 19 | 5,3 |
| ↳ deferred `kewajiban` | **185** | 250 | 3,9 |
| `/laporan` (bulanan) | 18 | 39 | 10,6 |
| ↳ deferred `tren` | **98** | 143 | 0,7 |
| `/laporan?mode=tahunan` | **78** | 125 | 16,7 |
| ↳ deferred `tren` | **152** | 266 | 0,7 |
| `/laporan/shafa-net?mode=tahunan` | 19 | 41 | 9,2 |
| `/` (landing) | 4 | 7 | 0,9 |

Halaman lain di menu: 3–9 query, 6–25 ms.

**Beban saat website dibuka** (prefetch `mount` 34 link sidebar Super Admin, dijumlah dari tabel di atas): **394 query, ±723 ms waktu proses, ±224 KB JSON**, setiap kali halaman di-refresh atau dibuka di tab baru.

### 2.4 Yang tidak bisa diukur

- **Server production**: sesuai aturan audit, tidak diakses. Ukuran data asli tidak diketahui.
- **Kontensi kunci SQLite**: database lokal memakai MySQL, jadi efek antre kunci SQLite tidak bisa diukur di lokal.
- **Waktu muat di browser** (Lighthouse/LCP): tidak ada browser di lingkungan audit.
- **OPcache, PHP-FPM (`pm.max_children`), dan `LOG_LEVEL` production**: konfigurasinya tidak ada di repository.

## 3. Temuan (Tahap 2)

Prioritas: **Kritis** = terasa langsung oleh semua pengguna; **Tinggi** = besar dan/atau memburuk seiring data bertambah; **Sedang** = terukur tapi terbatas; **Rendah** = kecil atau perapian. Usaha: XS (<15 menit), S (<1 jam), M (setengah hari), L (≥1 hari).

| ID | Prioritas | File & baris | Masalah | Dampak | Solusi | Risiko | Usaha |
|---|---|---|---|---|---|---|---|
| P01 | **Kritis** | `resources/js/Layouts/Sidebar.jsx:8,51,69`; `resources/js/app.jsx:27` | `prefetch={['mount','hover']}` membuat 34 halaman diminta begitu sidebar tampil; `hoverDelay: 50` memicu permintaan hanya karena kursor melintas | 394 query / 224 KB JSON per buka halaman (lokal); di production halaman Pelanggan jauh lebih besar; server kecil penuh sehingga klik pengguna antre | Prefetch hanya `hover`, `hoverDelay` 150 ms | Rendah — navigasi tetap cepat karena data diambil saat kursor berhenti di menu | XS |
| P02 | **Tinggi** | `app/Http/Controllers/LaporanController.php:69,95,97` | Laporan dihitung langsung (bukan lazy) sehingga permintaan deferred `tren` juga menghitung ulang seluruh laporan | Deferred `tren`: 98 query (bulanan), 152 query (tahunan), padahal tren sendiri ±66 | Jadikan prop laporan closure + memo per request; tampilan perbandingan lokasi Shafa.net tidak mengirim daftar pelanggan | Rendah | S |
| P03 | **Tinggi** | `app/Support/RekapCabang.php:38-41,99-108,118`; `app/Http/Controllers/TransaksiController.php:64,233`; `app/Support/LaporanKeuangan.php:82` | Saldo/kewajiban dihitung lewat `RekapCabang::hitung()` per bulan (5 query/bulan), termasuk `loadCount` jumlah pelanggan yang tidak dipakai untuk saldo; `saldo` di Penarikan dihitung di permintaan utama | Deferred `kewajiban` 185 query/250 ms; Penarikan Saldo 66 query; Laporan tahunan mitra 56 query; semuanya naik linear dengan jumlah bulan | (a) Opsi lewati `loadCount` untuk saldo & laporan; (b) `saldo` Penarikan jadi deferred; (c) versi multi-periode: 1 query per tabel untuk semua bulan | (a)(b) Rendah; (c) **Sedang** — rumus keuangan inti, dijaga test Dashboard/Setoran/Penarikan/Laporan | (a)(b) S; (c) M |
| P04 | **Tinggi** | `app/Http/Controllers/DashboardController.php:29-30`; `app/Support/RekapCabang.php:44-47` | Semua baris pembayaran (12 bulan di Dashboard, 1 bulan per cabang di rekap) dimuat ke PHP lalu dijumlahkan | Memori & waktu naik linear dengan jumlah transaksi; ribuan objek model per request | `SUM`/`COUNT` dengan `GROUP BY` di database | Rendah–Sedang (format tanggal SQLite vs MySQL; dijaga test) | S |
| P05 | **Tinggi** | `config/database.php:41-43`; `.env.example:30,43` | SQLite tanpa `journal_mode`/`busy_timeout`/`synchronous`; session & cache juga di database yang sama | Setiap penulisan (session ditulis hampir tiap request) mengunci seluruh database; request bersamaan saling menunggu atau gagal "database is locked" | Default `journal_mode=wal`, `busy_timeout=5000`, `synchronous=normal` (bisa diubah lewat env); opsional di server: `SESSION_DRIVER=file`, `CACHE_STORE=file` | Rendah — backup SQLite harus memakai `sqlite3 .backup` (file `-wal` ikut) | XS |
| P06 | **Tinggi** | `app/Http/Controllers/PelangganController.php:45-85`; `app/Models/Pelanggan.php:229-237`; `app/Http/Controllers/KelolaDataController.php:23-26` | Semua pelanggan dikirim sekaligus beserta **seluruh** riwayat pembayaran; `toBaris()` mengirim model utuh termasuk relasi bersarang (`router`, `paket`, `lokasi`, `area`) dan `created_at`/`updated_at` yang tidak dipakai halaman | ±1 KB per pelanggan (32 KB untuk 30 pelanggan lokal), naik linear dengan jumlah pelanggan dan bulan berjalan; 7 halaman pelanggan juga ikut di-prefetch (P01) | (a) Buang field yang tidak dipakai; (b) batasi riwayat pembayaran ke tahun yang bisa dipilih di form; (c) pagination di server | (a) Rendah; (b) Sedang; (c) **Tinggi** — mengubah perilaku cari/urut/ekspor/bayar massal, **perlu persetujuan** | (a) S; (b) S; (c) L |
| P07 | **Sedang** | `database/migrations/2026_10_29_000006_create_pelanggan_table.php`, `2026_10_30_124712_add_koneksi_columns_to_pelanggan_table.php`, `2026_10_30_171205_create_pembayaran_table.php:35` | Tidak ada indeks `pelanggan(id_lokasi, status)`, `pelanggan(id_paket)`, `pembayaran(paid_at)`; SQLite tidak membuat indeks otomatis untuk foreign key | Pencarian pelanggan per cabang/status dan pembayaran per tanggal (tanpa filter cabang) memindai seluruh tabel | Migration indeks baru (**tidak dijalankan**) | Rendah — `CREATE INDEX` di SQLite mengunci tabel saat dibuat; untuk puluhan ribu baris hitungan detik | XS |
| P08 | **Sedang** | `resources/views/app.blade.php:10`; `deploy/nginx.conf:34-38` | CSS Cuba 1,69 MB (217 KB gzip) dimuat sebagai CSS pemblokir render di semua halaman, termasuk landing, login, dan Cek Tagihan; di-cache hanya 7 hari | Kunjungan pertama menunggu 217 KB CSS sebelum halaman tampil | Jangka pendek: cache `immutable` 1 tahun (URL sudah ber-versi `?v=filemtime`); jangka panjang: subset CSS yang dipakai | Cache: Rendah. Subset: **Tinggi** (tampilan bisa rusak; butuh alat tambahan) — **perlu persetujuan** | Cache XS; subset L |
| P09 | **Sedang** | `resources/js/app.jsx:11,22` | Setelah halaman pertama, semua 60 chunk halaman diunduh di latar, termasuk untuk pengunjung landing yang tidak login | Pengunjung umum ikut mengunduh JS halaman admin; boros kuota di HP | Preload hanya bila user sudah login | Rendah | XS |
| P10 | **Sedang** | `public/images/landing/klien/vaiotech-lintas-nusantara.png`; `public/images/landing/teknologi/*`; `public/images/landing/partner/*` | Logo jauh lebih besar dari ukuran tampil: Vaiotech 3030×2570 px / 462 KB (tampil ±64 px), Huawei 2560 px, Dell 1346 px; 5 file tidak dipakai (`acces-prima-nusantara.jpeg`, `smk-dewantara.jpg`, `diskominfo-purwakarta.jpg`, `lintas-arta.avif`, `lintas-arta.webp`) | Landing mengunduh ±2,3 MB logo saat discroll | Perkecil logo ke ±400 px; hapus file tidak terpakai | Rendah | S |
| P11 | **Rendah** | `resources/js/Pages/Landing/Index.jsx:221` | Gambar hero (227 KB) tanpa `fetchpriority` | Gambar terbesar di layar pertama dimuat tanpa prioritas | `fetchpriority="high"` | Rendah | XS |
| P12 | **Rendah** | `app/Http/Controllers/PelangganController.php:91-99` | 6 query `count()` terpisah untuk angka tab | 6 query per halaman pelanggan | Gabung 4 hitungan status sederhana menjadi 1 query agregasi | Rendah | S |
| P13 | **Rendah** | `app/Support/LaporanKeuangan.php:108`; `app/Models/PajakCabang.php:142` | Tarif pajak diambil 1 query per bulan, dan setiap query mengambil seluruh riwayat tarif | Laporan Shafa.net tahunan +10 query | Ambil tarif sekali untuk semua bulan | Rendah | S |
| P14 | **Rendah** | `app/Http/Controllers/HakAksesController.php:25` | Satu-satunya lazy load yang terdeteksi (`Role::permissions` role terpilih) | 1 query tambahan | Eager load | Sangat rendah | XS |
| P15 | **Rendah** | `deploy/nginx.conf:16-20,34-38` | Template Nginx: tanpa `gzip_vary`, font `ttf`/`eot` tidak dikompres, aset non-build di-cache 7 hari | Kecil | Lengkapi template | Rendah — template saja, server diterapkan manual | XS |
| P16 | **Rendah** | `resources/views/app.blade.php:9`; `resources/js/app.css:339` | Google Fonts memuat Rubik 4 bobot + Roboto 4 bobot; Poppins dipakai di landing tetapi tidak pernah dimuat (jatuh ke Rubik) | Kecil | Biarkan (mengubah font = mengubah tampilan) | — | — |

### 3.1 Sudah baik / tidak perlu tindakan

- `import.meta.glob` tanpa `eager` (`resources/js/app.jsx:7`): satu chunk per halaman.
- ApexCharts dimuat lazy lewat `resources/js/Components/Chart.jsx:4`; tidak ada impor langsung di tempat lain. Ikon `react-feather` ter-tree-shake (chunk per ikon).
- Tidak ada `dd()`, `dump()`, `var_dump`, Debugbar, Telescope, atau `console.log`.
- N+1: semua 30 halaman dijalankan dengan `Model::preventLazyLoading()` di skrip ukur; hanya P14 yang terdeteksi.
- Tidak ada email, PDF, ekspor, atau panggilan API yang perlu dipindah ke queue. API Mikrotik memakai timeout 5 detik (`app/Services/Mikrotik/RouterOsApi.php:15,23`) dan hanya dipanggil saat ada aksi pengguna.
- `deploy.sh` menjalankan `php artisan optimize` (cache config, route, view, event).
- Password router, tunnel, dan user disembunyikan (`#[Hidden]`). Catatan privasi (bukan performa): NIK pelanggan ikut terkirim di daftar pelanggan karena dipakai form edit.
- Middleware global ringan (`EnsureUserIsActive`, `HandleInertiaRequests`, `SecurityHeaders`); tidak ada query berat per request.

## 4. Urutan perbaikan yang diusulkan

Diurutkan dari dampak terbesar dengan risiko terkecil. Satu baris = satu commit.

| Urutan | Temuan | Alasan |
|---|---|---|
| 1 | P01 | Dampak terbesar, satu baris kode |
| 2 | P05 (kode) | Menghilangkan antre kunci SQLite; satu file config |
| 3 | P02 | Menghapus perhitungan ganda Laporan |
| 4 | P03 (a)+(b) | Memangkas query saldo/kewajiban tanpa mengubah rumus |
| 5 | P07 | File migration indeks (tidak dijalankan) |
| 6 | P09, P11 | Perubahan kecil frontend |
| 7 | P06 (a) | Memperkecil data pelanggan tanpa mengubah perilaku |
| 8 | P04 | Agregasi di database |
| 9 | P12, P13, P14 | Pengurangan query kecil |
| 10 | P10 | Optimasi gambar dan hapus file tidak terpakai |
| 11 | P15, P08 (cache) | Template Nginx |
| 12 | P03 (c) | Batch multi-periode — risiko sedang, dikerjakan terakhir di antara yang aman |
| — | P06 (b)(c), P08 (subset CSS) | **Perlu persetujuan terpisah** (mengubah perilaku atau berisiko tinggi) |

Catatan: implementasi awal untuk P01, P02, P03(a), P05, dan P07 sudah dibuat di sesi sebelumnya dan disimpan di `git stash` ("WIP sebelum audit…"), bersama perubahan tampilan mobile Laporan yang bukan bagian dari audit performa. Saat Tahap 4, bagian performanya akan diterapkan ulang per temuan dengan commit terpisah. Migration indeks di stash **sudah pernah dijalankan di database lokal** pada sesi sebelumnya; nama filenya akan dipertahankan agar status migration lokal tetap konsisten.
