# MSS e-Billing ISP — Super Admin

Rebuild aplikasi billing ISP MSS untuk role **Super Admin**. Spesifikasi lengkap: [docs/MSS_Billing_SuperAdmin_Spesifikasi.md](docs/MSS_Billing_SuperAdmin_Spesifikasi.md).

> **Status: Tahap 1 (UI saja).** Semua halaman memakai data dummy. Tombol simpan, hapus, setujui, export, dan sejenisnya hanya menampilkan notifikasi *"data dummy"*. Backend dikerjakan di tahap berikutnya.

## Tech Stack

| Bagian | Teknologi |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Penghubung | Inertia.js v3 |
| Frontend | React 19 (JavaScript / JSX), Vite |
| UI | Template **Cuba** (Bootstrap 5 + reactstrap), react-feather, ApexCharts |
| Server lokal | Laravel Herd → http://mss-billing.test |

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

Di Herd, folder `~/Herd/mss-billing` otomatis tersedia di **http://mss-billing.test**. Tahap UI belum memakai database, jadi tidak perlu `migrate`.

Saat mengembangkan, pakai hot reload:

```bash
npm run dev
```

Kalau hanya menjalankan `npm run build`, build ulang setiap kali file React berubah.

## Struktur Folder

```
app/
├── Http/Controllers/
│   ├── Controller.php            # helper filter bulan/tahun/cabang
│   ├── DashboardController.php   # Dashboard, Dashboard Cabang, Income Summary
│   ├── KelolaDataController.php  # 9 submenu Kelola Data
│   ├── TransaksiController.php   # Penarikan Saldo, Setor Kewajiban, Pengeluaran
│   └── SistemController.php      # Laporan, Payment Gateway, Logs, User
├── Http/Middleware/HandleInertiaRequests.php  # shared props: auth (dummy), cabangOptions
└── Support/MockData.php          # sumber data dummy + rumus rekap cabang

resources/js/
├── app.jsx                       # entry Inertia (layout default = AppLayout)
├── app.css                       # penyesuaian di atas CSS Cuba
├── Layouts/                      # AppLayout, Sidebar, Header
├── Components/                   # komponen reusable (lihat di bawah)
├── Pages/                        # satu file per halaman (sesuai nama Inertia::render)
└── lib/
    ├── format.js                 # rupiah(), tanggal(), periode(), label, dll. (locale id-ID)
    ├── menu.js                   # struktur menu sidebar
    └── ui.js                     # notify(), dummy(), applyFilters(), useNavLoading()

public/cuba/                      # CSS, font, logo, dan sprite ikon dari template Cuba
```

## Komponen Reusable

| Komponen | Fungsi |
|---|---|
| `DataTable` | Tabel dengan search, sort, paginasi, export, pilih kolom, kolom pertama sticky, baris total, seleksi, serta empty, loading, dan error state |
| `CrudPage` | Pola Kelola Data: daftar → form tambah/edit (modal) → hapus (konfirmasi), dibangun dari skema `columns` + `fields` |
| `FilterBar` | Filter server-side lewat query string (bulan, tahun, cabang, select, tanggal, switch) |
| `FormModal`, `Field` | Form berbasis skema: text, currency, select, radio, switch, file, textarea, date/month/time, password, static |
| `ActionButton`, `ActionGroup` | Tombol aksi baris tabel (ikon + tooltip) yang seragam |
| `StatCard`, `StatusBadge`, `ConfirmDialog`, `FileUpload`, `CurrencyInput`, `Avatar` | Di `Components/ui.jsx` |
| `RekapCabangTable`, `RingkasanCard` | Tabel 25 kolom rekap cabang dan kartu "Ringkasan Bulan Ini" |

## Halaman

| Menu | URL |
|---|---|
| Dashboard | `/dashboard`, `/dashboard/pelanggan?kategori=…` |
| Dashboard Cabang | `/dashboard-cabang`, `/dashboard-cabang/{id}` |
| Income Summary | `/income-summary` |
| Kelola Data | `/kelola-data/{kartu-member, cabang, pajak, paket, router, rekening, notifikasi, pph, tunnel}` |
| Transaksi | `/transaksi/{penarikan-saldo, setor-kewajiban, pengeluaran}` |
| Laporan | `/laporan?mode=bulanan|tahunan` |
| Payment Gateway | `/payment-gateway`, `/payment-gateway/{id}/channel` |
| Logs | `/logs` |
| User | `/user/manajemen`, `/user/hak-akses`, `/profil` |
| Login | `/login` (UI saja) |

## Data Dummy

`App\Support\MockData` menghasilkan data yang konsisten antar halaman dan tidak berubah saat reload (Faker dengan seed tetap), yaitu 10 cabang, 300 pelanggan, 12 bulan tagihan dan pembayaran, pengeluaran, setoran, penarikan, user, dan log. Bentuk datanya mengikuti struktur §4 di spesifikasi.

Rumus rekap cabang (DPP, PPN, Admin Fee, Bagi Hasil, Omset, PPh, Total Setor ke MSS, Saldo) ada di `rekapBase()` dan `rekapCabang()`, sesuai §3.2 spesifikasi.

## Tahap Berikutnya (Backend)

1. Buat migration, model, dan seeder sesuai §4 spesifikasi.
2. Ganti pemanggilan `MockData::…` di controller dengan query Eloquent. Props yang dikirim ke React tetap sama, jadi halaman tidak perlu diubah.
3. Ganti `dummy()` / `notify()` di halaman dengan `router.post/put/delete` beserta validasi Form Request.
4. Ganti user dummy di `HandleInertiaRequests` dengan autentikasi sungguhan dan hak akses berbasis role.
