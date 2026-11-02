# MSS Billing

Aplikasi billing ISP **PT Media Solusi Sukses (MSS)**: landing page company profile, cek tagihan online untuk pelanggan, manajemen pelanggan & pembayaran, saldo dan setoran mitra per cabang, serta integrasi langsung ke router **Mikrotik** (RouterOS API).

Spesifikasi awal: [docs/MSS_Billing_SuperAdmin_Spesifikasi.md](docs/MSS_Billing_SuperAdmin_Spesifikasi.md).

## Tech Stack

Versi yang terpasang saat ini (lihat `composer.lock` dan `package-lock.json`):

| Bagian | Teknologi | Versi |
|---|---|---|
| Backend | Laravel | 13.38 |
| Bahasa | PHP | **8.4.1 atau lebih baru** (dikembangkan di 8.5) |
| Penghubung | Inertia.js (`inertia-laravel` / `@inertiajs/react`) | 3.5 / 3.9 |
| Hak akses | spatie/laravel-permission | 8.3 |
| Frontend | React | 19 |
| Build | Vite (+ laravel-vite-plugin 3, @vitejs/plugin-react 6) | 8 |
| UI | Template Cuba (Bootstrap 5.3 + reactstrap 9), react-feather, ApexCharts 8 | |
| Test | PHPUnit | 12 |
| Format kode | Laravel Pint | 1.27 |

## Kebutuhan Sistem

Install semua ini sebelum menjalankan project:

| Software | Versi minimal | Keterangan |
|---|---|---|
| **PHP** | **8.4.1** | Paket Symfony 8 di `composer.lock` butuh PHP ≥ 8.4.1. PHP 8.3 tidak bisa, walaupun `composer.json` masih menulis `^8.3` |
| Ekstensi PHP | — | `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, plus `pdo_mysql` (MySQL) atau `pdo_sqlite` (SQLite) |
| **Composer** | 2.x | |
| **Node.js** | **20.19** atau **22.12+** | Syarat Vite 8. Node 18 dan 21 tidak bisa |
| npm | ikut Node.js | |
| **Database** | MySQL 8 / MariaDB 10.6+ **atau** SQLite 3 | Laptop developer memakai MySQL, server memakai SQLite |
| Git | — | |

Opsional, untuk fitur tertentu:

| Software | Untuk |
|---|---|
| Router **Mikrotik** RouterOS dengan service API aktif (port 8728, atau 8729 untuk SSL) | Sinkron PPP Secret / Hotspot / profile paket, On/Off pelanggan |
| **FreeRADIUS 3.2** + **xl2tpd** di VPS | Menu Tunnel (L2TP) untuk router mitra yang tidak punya IP publik |

> Ekstensi `fileinfo` wajib: dipakai untuk memeriksa isi file foto bukti transfer / bukti setor yang diunggah.

## Instalasi di Laptop (Development)

```bash
git clone <url-repo> mss-billing
cd mss-billing

composer install
cp .env.example .env
php artisan key:generate
```

Atur database di `.env`.

**MySQL:**

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=billingmssdb
DB_USERNAME=root
DB_PASSWORD=
```

**SQLite** (tanpa server database): biarkan `DB_CONNECTION=sqlite`, lalu buat filenya dengan `touch database/database.sqlite`.

Lanjutkan:

```bash
php artisan migrate
php artisan db:seed --class=HakAksesSeeder   # daftar permission & role bawaan
php artisan user:create admin                # buat akun Super Admin (password diketik tersembunyi)

npm install
npm run dev                                  # atau: composer run dev (server + Vite sekaligus)
```

Buka `http://localhost:8000` (atau domain Herd, mis. `http://mss-billing.test`). Halaman pertama adalah landing page; login di `/login`.

Data contoh untuk mencoba di laptop (cabang, area, rekening, router, paket, 6 pelanggan). Seeder ini **menolak jalan di production**:

```bash
php artisan db:seed --class=DataUjiSeeder
```

### Setelah `git pull` (update dari teman)

```bash
composer install
npm install
php artisan migrate
php artisan db:seed --class=HakAksesSeeder   # mendaftarkan permission baru; role yang sudah diatur tidak ditimpa
npm run dev                                  # atau npm run build
```

## Konfigurasi `.env`

| Variabel | Keterangan |
|---|---|
| `APP_URL` | Alamat lengkap situs. **Di server wajib `https://…`**. Bila diawali `https://`, semua link & redirect dibuat https (wajib di balik Cloudflare, kalau tidak login akan gagal) |
| `APP_ENV` / `APP_DEBUG` | Server: `production` dan `false`. `deploy.sh` menolak jalan bila `APP_DEBUG=true` |
| `SESSION_SECURE_COOKIE` | Server HTTPS: `true` (baris ini memang tidak ada di `.env` bawaan, tambahkan sendiri) |
| `DB_*` | Koneksi database |
| `MIKROTIK_TIMEOUT` | Batas waktu koneksi RouterOS API dalam detik (default 5) |
| `TUNNEL_SERVER`, `TUNNEL_SUBNET`, `TUNNEL_GATEWAY` | Server & jaringan L2TP untuk menu Tunnel (default: host `APP_URL`, `10.10.10.0/24`, `10.10.10.1`) |
| `ISOLIR_URL` | Halaman tujuan pelanggan terisolir (default `APP_URL/isolir`) |

## Menjalankan Test

```bash
php artisan test --compact          # semua test (PHPUnit)
vendor/bin/pint --dirty             # rapikan format kode PHP sebelum commit
```

## Deploy ke Server

### Ubuntu 24.04 + Nginx + PHP-FPM (tanpa panel)

Contoh konfigurasi Nginx ada di [`deploy/nginx.conf`](deploy/nginx.conf). Untuk update aplikasi cukup:

```bash
cd /var/www/mss-billing
sudo bash deploy.sh
```

`deploy.sh` menjalankan: cek `.env` aman → `git pull` → `composer install --no-dev` → `npm ci && npm run build` → `migrate --force` → `HakAksesSeeder` → bersihkan & buat ulang cache → atur hak akses file (`www-data`) → reload PHP-FPM.

### aaPanel

Web server berjalan sebagai user `www`, service PHP `php-fpm-84`:

```bash
cd /var/www/mss-billing
git pull origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
rm -f public/hot
php artisan migrate --force
php artisan db:seed --class=HakAksesSeeder --force
php artisan optimize:clear && php artisan optimize
chown -R www:www /var/www/mss-billing
chmod -R 775 storage bootstrap/cache
systemctl reload php-fpm-84
```

> Pemilik file di aaPanel harus `www`, bukan `www-data`. Kalau salah, aplikasi error 500 tanpa catatan di log.

### Jangan dijalankan di server

- `php artisan migrate:fresh` / `migrate:refresh` → **menghapus semua data**.
- `php artisan db:seed` **tanpa** `--class=HakAksesSeeder` → ikut membuat akun uji dengan password bawaan. Buat akun dengan `php artisan user:create <username>`.

### Cloudflare

- SSL/TLS mode: **Full (strict)**.
- `APP_URL` harus `https://…` (lihat tabel `.env` di atas).

## Fitur Utama

| Menu | URL |
|---|---|
| Landing page company profile | `/` |
| Cek tagihan pelanggan (tanpa login, ID / nomor HP) | `/cek-tagihan` |
| Dashboard (+ kartu Saldo untuk role Mitra) | `/dashboard` |
| Dashboard Cabang & Income Summary | `/dashboard-cabang`, `/income-summary` |
| Pelanggan: Data, On, Off, Lunas, Belum Lunas, Berhenti, Tagihan Prorate | `/pelanggan`, `/pelanggan/{on,off,lunas,belum-lunas,berhenti,prorate}` |
| Invoice & kwitansi | `/pelanggan/{id}/tagihan`, `/pembayaran/{id}/kwitansi` |
| Kelola Data: kartu member, cabang (Admin Full), area, pajak, paket, router, rekening, tunnel, template isolir | `/kelola-data/…` |
| Transaksi: penarikan saldo mitra, setor kewajiban mitra | `/transaksi/penarikan-saldo`, `/transaksi/setor-kewajiban` |
| User & Hak Akses | `/user/manajemen`, `/user/hak-akses` |

Belum selesai: pengeluaran, laporan, payment gateway (integrasi), notifikasi WhatsApp, logs aktivitas, export Excel/PDF.

## Struktur Folder

```
app/
├── Http/Controllers/        # satu controller per menu (Pelanggan, Pembayaran, Transaksi, CekTagihan, Landing, …)
├── Http/Middleware/         # SecurityHeaders (CSP, HSTS), EnsureUserCoversAllBranches, HandleInertiaRequests
├── Models/                  # Pelanggan, Pembayaran, Lokasi (cabang), PenarikanSaldo, SetoranMitra, Router, Tunnel, …
├── Services/Mikrotik/       # RouterOS API, sinkron secret/profile, isolir
├── Services/Tunnel/         # akun tunnel L2TP di tabel FreeRADIUS
└── Support/
    ├── HakAkses.php         # katalog menu, permission & role bawaan
    ├── RekapCabang.php      # rumus rekap & saldo mitra (DPP, PPN, admin fee, bagi hasil, PPh, setoran, WD)
    └── RouterOsScript.php   # escape nilai untuk script Mikrotik (anti injeksi)

database/
├── migrations/
└── seeders/                 # HakAksesSeeder (wajib), DataUjiSeeder (data contoh, lokal saja)

resources/js/
├── Pages/                   # satu file per halaman Inertia (Landing, CekTagihan, Pelanggan, Transaksi, …)
├── Components/              # DataTable, CrudPage, InvoiceTagihan, SaldoCard, FormModal, …
├── Layouts/                 # AppLayout, Sidebar, Header, MobileNav
└── lib/                     # format.js (rupiah, tanggal, terbilang), menu.js, ui.js (api, notify)

public/
├── cuba/                    # aset template Cuba
└── images/landing/          # foto & logo klien/mitra landing page

deploy/nginx.conf            # contoh konfigurasi Nginx
deploy.sh                    # script update aplikasi di server
tests/Feature/               # test otomatis (PHPUnit)
```

## Keamanan

- Setiap route admin diperiksa permission-nya di server (spatie/laravel-permission). Menyembunyikan tombol di tampilan hanya untuk kenyamanan.
- User cabang (mis. Mitra) hanya bisa mengakses data cabangnya sendiri; data cabang lain dijawab 404.
- Password router & tunnel disimpan terenkripsi dan tidak pernah dikirim ke browser.
- Nilai yang masuk ke script Mikrotik wajib lewat `RouterOsScript::quote()`.
- File unggahan (bukti transfer/setor) disimpan di `storage/app/private` dan hanya bisa dibuka lewat route yang memeriksa login & cabang.
- Halaman publik (cek tagihan) dibatasi 10 pencarian/menit per IP dan menyamarkan nama pelanggan.
