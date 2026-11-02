# Dokumentasi Teknis — MSS Billing

Dokumen ini menjelaskan cara kerja seluruh fitur MSS Billing dari sisi kode: arsitektur, model data, aturan bisnis, integrasi Mikrotik/FreeRADIUS, hak akses, dan keamanan. Untuk instalasi, konfigurasi `.env`, dan deploy, lihat [README.md](../README.md). Untuk alur kerja Git tim, lihat [SOP_Git_Workflow.md](SOP_Git_Workflow.md).

> Semua penjelasan di sini mengikuti kode yang berjalan saat ini. Bila berbeda dengan [spesifikasi awal](MSS_Billing_SuperAdmin_Spesifikasi.md), yang berlaku adalah kode; perbedaan penting ditandai **Catatan**.

## Daftar Isi

1. [Arsitektur](#1-arsitektur)
2. [Model Data](#2-model-data)
3. [Hak Akses & Pembatasan Cabang](#3-hak-akses--pembatasan-cabang)
4. [Autentikasi & Akun User](#4-autentikasi--akun-user)
5. [Halaman Publik](#5-halaman-publik)
6. [Dashboard, Dashboard Cabang, Income Summary](#6-dashboard-dashboard-cabang-income-summary)
7. [Pelanggan & Tagihan](#7-pelanggan--tagihan)
8. [Pembayaran](#8-pembayaran)
9. [Kelola Data](#9-kelola-data)
10. [Rekap Keuangan Cabang & Saldo Mitra](#10-rekap-keuangan-cabang--saldo-mitra)
11. [Transaksi: Penarikan Saldo & Setor Kewajiban](#11-transaksi-penarikan-saldo--setor-kewajiban)
12. [Integrasi Mikrotik (RouterOS API)](#12-integrasi-mikrotik-routeros-api)
13. [Isolir](#13-isolir)
14. [Tunnel L2TP & FreeRADIUS](#14-tunnel-l2tp--freeradius)
15. [Keamanan](#15-keamanan)
16. [Frontend](#16-frontend)
17. [Testing](#17-testing)
18. [Fitur yang Belum Selesai](#18-fitur-yang-belum-selesai)

---

## 1. Arsitektur

| Lapisan | Isi | Lokasi |
|---|---|---|
| Routing | Semua route web (Inertia + endpoint JSON) | [routes/web.php](../routes/web.php) |
| Perintah konsol | `user:create` | [routes/console.php](../routes/console.php) |
| Controller | Satu controller per menu, extends `Controller` (filter, cek cabang, pesan validasi) | [app/Http/Controllers/](../app/Http/Controllers/) |
| Middleware | `EnsureUserIsActive`, `HandleInertiaRequests`, `SecurityHeaders`, `EnsureUserCoversAllBranches` (alias `semua_cabang`) | [app/Http/Middleware/](../app/Http/Middleware/) |
| Model | Eloquent, nama tabel & kolom berbahasa Indonesia | [app/Models/](../app/Models/) |
| Service | Mikrotik (RouterOS API, isolir, sinkron) dan Tunnel (FreeRADIUS) | [app/Services/](../app/Services/) |
| Support | Aturan bisnis lintas menu: hak akses, rekap cabang, script RouterOS, IP pool | [app/Support/](../app/Support/) |
| Frontend | React 19 + Inertia v3, satu file per halaman | [resources/js/](../resources/js/) |

### Pola request

- **Halaman** dirender dengan `Inertia::render('Folder/Halaman', [...props])`. Data tabel dikirim sebagai prop `rows`.
- **Aksi** (simpan, ubah, hapus, aksi router) memakai endpoint yang mengembalikan JSON `{ message, type? }`. Frontend memanggilnya lewat helper `api()` di [resources/js/lib/ui.js](../resources/js/lib/ui.js), lalu memuat ulang sebagian prop (`reloadData`). `type: 'warning'` dipakai bila data tersimpan tetapi langkah lanjutan (misalnya ke router) gagal.
- **Kesalahan router** (`RouterOsException`) dikembalikan sebagai HTTP 422 berisi pesan yang bisa ditampilkan.
- **Filter standar** dari `Controller::filters()`: `bulan`, `tahun` (default bulan berjalan), dan `cabang`. Untuk user cabang, nilai `cabang` selalu cabangnya sendiri, apa pun yang dikirim.
- **Periode** selalu disimpan sebagai teks `Y-m-01` (tanggal 1 bulan itu) tanpa cast `date`, supaya perbandingan sama di MySQL dan SQLite.

### Konfigurasi khusus

| Kunci config | Sumber `.env` | Dipakai |
|---|---|---|
| `app.csp` | `APP_CSP` (default `true`) | Content-Security-Policy di `SecurityHeaders` |
| `services.mikrotik.timeout` | `MIKROTIK_TIMEOUT` (default 5) | Timeout koneksi & balasan RouterOS API |
| `services.tunnel.server/subnet/gateway` | `TUNNEL_SERVER`, `TUNNEL_SUBNET`, `TUNNEL_GATEWAY` | Menu Tunnel |
| `services.isolir.url` | `ISOLIR_URL` | Tujuan redirect pelanggan terisolir (default `APP_URL/isolir`) |

---

## 2. Model Data

### Relasi utama

```
lokasi (cabang) ─┬─< area
                 ├─< users (id_lokasi null = semua cabang)
                 ├─< routers ──< pelanggan >── paket
                 ├─< paket          │
                 ├─< rekening       └─< pembayaran
                 ├─< pajak_cabang (tarif per bulan)
                 ├─< penarikan_saldo
                 └─< setoran_mitra

tunnels ─< routers           isolir_logs (riwayat isolir/aktifkan)
pengaturan (key-value)       radcheck / radreply / radacct (FreeRADIUS)
roles / permissions (spatie)
```

### Tabel

| Tabel | Model | Kegunaan & kolom penting |
|---|---|---|
| `lokasi` | `Lokasi` | Cabang. `kode_lokasi` unik, `nama_mitra` (teks), `terima_cash`, `admin_full` (mitra memakai payment gateway sendiri), `group_wa`, `status` (`aktif`/`tidak_aktif`) |
| `area` | `Area` | Area di dalam cabang. Ikut terhapus bila cabangnya dihapus |
| `users` | `User` | Akun login. `username` unik, `email` opsional, `id_lokasi` (null = semua cabang), `status` (`aktif`/`nonaktif`), `last_login_at`, `last_login_ip` |
| `pelanggan` | `Pelanggan` | Lihat [§7](#7-pelanggan--tagihan). Unik per router: `username_pppoe`, `ip_address`, `mac_address` |
| `paket` | `Paket` | Paket langganan + profile Mikrotik (`profile_mikrotik`, `rate_limit`, `local_address`, pool). `filter_lokasi = true` → khusus cabang `id_lokasi` |
| `routers` | `Router` | Router Mikrotik. `mode_koneksi` (`langsung`/`tunnel`), `password` terenkripsi, `profile_isolir`, `status_koneksi` (`belum_dicek`/`online`/`offline`) |
| `tunnels` | `Tunnel` | Akun L2TP router mitra. `password` terenkripsi, `ip_tunnel` unik |
| `rekening` | `Rekening` | Rekening tujuan transfer. `id_lokasi` null = berlaku untuk semua cabang (rekening pusat). `tampil_invoice` |
| `pajak_cabang` | `PajakCabang` | Tarif per cabang per bulan. Unik (`id_lokasi`, `periode`) |
| `pembayaran` | `Pembayaran` | Pembayaran satu periode pemakaian. Unik (`pelanggan_id`, `periode`). Menyimpan snapshot nama pelanggan, nama paket, harga, jenis tagihan |
| `penarikan_saldo` | `PenarikanSaldo` | Pengajuan WD mitra. Status `pending` → `disetujui`/`ditolak` |
| `setoran_mitra` | `SetoranMitra` | Setoran kewajiban mitra ke pusat |
| `isolir_logs` | `IsolirLog` | Log setiap aksi ke PPP Secret (isolir, aktifkan, ganti paket/IP), berhasil maupun gagal |
| `pengaturan` | `Pengaturan` | Key-value. Saat ini dipakai isi halaman isolir (`isolir_nama_isp`, `isolir_pesan`, `isolir_whatsapp`, `isolir_pembayaran`) |
| `radcheck`, `radreply`, `radacct`, … | — | Skema FreeRADIUS 3.2, dipakai menu Tunnel |
| `roles`, `permissions`, … | spatie | Role ditambah kolom `keterangan` |

### Aturan hapus (foreign key)

- `pembayaran.id_lokasi`, `rekening.id_lokasi`, `penarikan_saldo.id_lokasi`, `setoran_mitra.id_lokasi`, `users.id_lokasi`: **restrict**. Cabang yang masih dipakai tidak bisa dihapus.
- `pembayaran.rekening_id`, `setoran_mitra.rekening_id`: **restrict**. Rekening yang pernah dipakai tidak bisa dihapus.
- `routers.tunnel_id`: **restrict**.
- `pelanggan.router_id`, `pembayaran.pelanggan_id`, `pelanggan.sales_id`, `pelanggan.id_area`: **set null**. Riwayat pembayaran tetap ada walau pelanggan dihapus.

---

## 3. Hak Akses & Pembatasan Cabang

Akses dijaga dua lapis: **permission per menu** dan **cabang**.

### 3.1 Permission

Katalog ada di [app/Support/HakAkses.php](../app/Support/HakAkses.php). Format nama: `{menu}.{aksi}`, misalnya `pelanggan.data.create`. Aksi yang mungkin: `view`, `create`, `update`, `delete`, `export`, `approve`. Tiap menu hanya punya aksi yang berlaku untuknya (konstanta `MENU`).

- Setiap route diberi middleware `permission:...` di [routes/web.php](../routes/web.php).
- `Super Admin` lolos semua pemeriksaan lewat `Gate::before` di [AppServiceProvider](../app/Providers/AppServiceProvider.php).
- Penolakan permission dipetakan menjadi 403 "Anda tidak memiliki akses ke fitur ini" tanpa menyebut nama permission ([bootstrap/app.php](../bootstrap/app.php)).
- Frontend menerima daftar permission di prop bersama `auth.permissions` dan memakai hook `useBisa()` untuk menyembunyikan menu/tombol. Ini **hanya tampilan**; server tetap memeriksa.

**Arti khusus beberapa permission:**

| Permission | Arti |
|---|---|
| `pelanggan.on.update` | Mematikan (isolir) pelanggan |
| `pelanggan.off.update` | Menyalakan kembali pelanggan |
| `pelanggan.pembayaran.view/create/update/delete` | Riwayat & kwitansi / input / edit / batalkan pembayaran |
| `transaksi.penarikan.approve` | Menyetujui atau menolak penarikan saldo |
| `transaksi.setor.create/delete` | Input/batalkan setoran (hanya berlaku untuk user pusat) |

### 3.2 Role bawaan

Dibuat oleh `HakAksesSeeder`. Permission bawaan hanya diberikan saat role **pertama kali** dibuat; perubahan dari menu Hak Akses tidak ditimpa saat seeder dijalankan ulang.

| Role | Permission bawaan |
|---|---|
| Super Admin | Semua (lewat `Gate::before`) |
| Admin | Semua menu kecuali Router, Tunnel, Template Isolir, Manajemen User, Hak Akses |
| Mitra | `view`/`create`/`update` di Dashboard, Dashboard Cabang, Laporan, Penarikan, Setor, Pengeluaran |
| Sales | `dashboard.view`, `pelanggan.data.view`, `pelanggan.data.create` |
| Teknisi | `dashboard.view`, `pelanggan.data.view`, `pelanggan.on.view`, `pelanggan.off.view` |

Setelah login, user diarahkan ke menu pertama di `HakAkses::MENU` yang boleh dibukanya (`HakAkses::halamanAwal()`). User tanpa satu pun izin `view` tidak bisa login.

### 3.3 Pembatasan cabang

- `users.id_lokasi` **null** → user pusat, boleh melihat semua cabang. **Terisi** → user cabang, hanya cabang itu.
- `User::bolehAksesCabang($id)` dipakai oleh `Controller::pastikanCabang()`. Data cabang lain dijawab **404**, bukan 403, agar keberadaannya tidak terbaca.
- `Controller::aturanCabangSendiri()` menambah aturan validasi: user cabang hanya boleh mengisi `id_lokasi` dengan cabangnya sendiri.
- Prop bersama `cabangOptions` hanya berisi cabang yang boleh diakses.
- Data tanpa cabang (rekening pusat, router tanpa cabang, paket untuk semua cabang) ikut tampil sebagai pilihan untuk user cabang, tetapi hanya user pusat yang boleh mengubahnya.

### 3.4 Menu khusus user pusat (`semua_cabang`)

Middleware `EnsureUserCoversAllBranches` menolak (403) user cabang walaupun ia punya permission-nya. Route di grup ini:

- Input & batalkan setoran mitra
- Setujui/tolak penarikan saldo (mitra tidak boleh menyetujui penarikannya sendiri)
- Ubah tarif pajak & bagi hasil
- Template Isolir
- Tunnel
- Manajemen User & Hak Akses

---

## 4. Autentikasi & Akun User

### Login ([AuthController](../app/Http/Controllers/AuthController.php))

- Kolom login menerima **username atau email**.
- Pembatasan percobaan gagal:
  - 5 kali per kombinasi username + IP → dikunci 5 menit.
  - 20 kali per IP (username apa pun) → dikunci 15 menit.
- Login gagal dan login yang ditolak dicatat di log aplikasi (`Log::warning`).
- Ditolak bila akun `nonaktif` atau role-nya belum punya menu (`User::bolehMasuk()`).
- Berhasil: session diregenerasi, `last_login_at` & `last_login_ip` diperbarui, redirect ke halaman awal.

### Sesi aktif

`EnsureUserIsActive` berjalan di setiap request web. User yang dinonaktifkan saat masih login langsung dikeluarkan (redirect `/login`, atau 401 untuk request JSON).

### Manajemen User ([UserController](../app/Http/Controllers/UserController.php))

- Field: nama, username (`A-Z a-z 0-9 . _ -`), email, tlp, role, akses cabang (kosong = semua cabang), password, status.
- Password minimal 8 karakter dan harus berisi huruf dan angka. Saat edit, kosong = tidak diubah.
- Pengaman eskalasi hak akses:
  - Hanya Super Admin yang bisa memberi role Super Admin atau mengubah akun Super Admin.
  - User tidak bisa mengganti role, akses cabang, atau menonaktifkan akunnya sendiri.
- Ganti password atau nonaktifkan → semua sesi user itu diputus (baris di tabel `sessions` dihapus bila driver `database`, dan `remember_token` diganti).
- User tidak bisa dihapus; hanya diaktifkan/nonaktifkan.

### Hak Akses ([HakAksesController](../app/Http/Controllers/HakAksesController.php))

- Matriks menu × aksi per role. Tambah role baru, simpan permission, hapus role.
- Role Super Admin terkunci. User (selain Super Admin) tidak bisa mengubah role yang sedang dipakainya sendiri.
- Role yang masih dipakai user tidak bisa dihapus.

### Perintah konsol

```bash
php artisan user:create <username> [--name=] [--role="Super Admin"] [--cabang=<kode_lokasi>]
```

Password diketik tersembunyi. Bila username sudah ada, perintah menawarkan reset password.

### Profil

`/profil` bisa dibuka semua user yang login, tanpa permission.

---

## 5. Halaman Publik

Tidak butuh login.

### 5.1 Landing page `/` ([LandingController](../app/Http/Controllers/LandingController.php))

Company profile PT Media Solusi Sukses. Satu-satunya data dari database: maksimal 6 paket dengan `tampil_registrasi = true` dan harga > 0, diurutkan dari termurah, nama paket yang sama hanya tampil sekali. Kecepatan diambil dari bagian download `rate_limit` (`10M/20M` → `20 Mbps`).

### 5.2 Cek tagihan `/cek-tagihan` ([CekTagihanController](../app/Http/Controllers/CekTagihanController.php))

- Pencarian lewat **POST** agar nomor HP tidak tercatat di URL/log server.
- Input: 3–30 karakter, hanya huruf, angka, spasi, `+`, `-`, `.`.
- Dicocokkan dengan `id_pelanggan` secara persis. Bila tidak ketemu dan input berupa nomor HP (≥ 10 digit), dicocokkan dengan nomor HP terdaftar setelah dinormalkan (`0812…`, `62812…`, `+62 812-…` dianggap sama). Maksimal 5 hasil.
- Hanya pelanggan `aktif`. Nama disamarkan (`Budi Santoso` → `Bu** Sa****`); alamat dan telepon tidak dikirim.
- Hasil: invoice bulan-bulan yang belum dibayar (12 bulan terakhir, `Pelanggan::tagihanBelumDibayar()`), pembayaran terakhir, dan rekening cabang yang ditandai tampil di invoice.
- Rate limit `cek-tagihan`: 10 pencarian per menit per IP.

### 5.3 Halaman isolir `/isolir` ([IsolirController](../app/Http/Controllers/IsolirController.php))

Tujuan redirect web proxy router untuk pelanggan terisolir. Blade ringan tanpa aset eksternal ([resources/views/isolir.blade.php](../resources/views/isolir.blade.php)). Isinya (nama ISP, pesan, nomor WhatsApp, info pembayaran) diatur di menu Template Isolir. Nomor `08…` diubah ke `628…` untuk tautan `wa.me`.

---

## 6. Dashboard, Dashboard Cabang, Income Summary

Semua di [DashboardController](../app/Http/Controllers/DashboardController.php). Filter cabang mengikuti aturan §3.3.

### 6.1 Dashboard `/dashboard`

| KPI | Sumber |
|---|---|
| Data Pelanggan | Pelanggan `status = aktif` |
| Lunas / Belum Lunas | Scope `lunas()` / `belumLunas()` untuk bulan berjalan (§7.3) |
| On / Off | Pelanggan aktif dengan `status_langganan` `on` / `off` |
| Berhenti | `status = berhenti` |
| Pemasukan bulan ini / hari ini | Σ `pembayaran.jml_bayar` menurut `paid_at` |

Widget tambahan: 8 pelanggan baru bulan ini, grafik pemasukan 12 bulan terakhir (menurut tanggal bayar), dan **kartu Saldo** khusus user ber-role Mitra (baris rekap cabangnya dari `RekapCabang`, §10). Klik KPI membuka `/dashboard/pelanggan?kategori=…`. Widget WhatsApp Gateway masih kosong.

### 6.2 Dashboard Cabang `/dashboard-cabang`

Tabel rekap keuangan per cabang untuk bulan terpilih, dihitung `RekapCabang::hitung()` (§10). `/dashboard-cabang/{id}` menampilkan detail satu cabang: data cabang, rekap, dan daftar pelanggan. Daftar pembayaran & pengeluaran di halaman detail masih kosong.

### 6.3 Income Summary `/income-summary`

Pemasukan bulan terpilih menurut `paid_at`:

- Total keseluruhan (nominal + jumlah transaksi).
- Per metode: `cash`, `transfer`, dan metode payment gateway (`tripay`, `xendit`, `pakar_digital`, `moota`) bila ada transaksinya.
- Transfer dipecah per rekening.
- Daftar transaksi: no. invoice, pelanggan, cabang, periode, nominal, metode, rekening, waktu bayar, petugas.

---

## 7. Pelanggan & Tagihan

### 7.1 Data pelanggan ([Pelanggan](../app/Models/Pelanggan.php))

| Kolom | Isi |
|---|---|
| `id_pelanggan` | Kode pelanggan; otomatis `P00001` (dari id) bila tidak diisi |
| `jns_tagihan` | `prabayar` / `pascabayar` |
| `skema_tagihan` | `full` / `prorate` |
| `jns_koneksi` | `pppoe`, `hotspot`, `static`, `ip_binding` |
| `username_pppoe` | Username PPPoE/Hotspot |
| `ip_address` | IP Static, IP Binding, atau Remote Address PPP Secret |
| `mac_address` | IP Binding (disimpan huruf besar) |
| `tgl_registrasi`, `tgl_jatuh_tempo` | Jatuh tempo tanggal 1–27 agar ada di setiap bulan |
| `ppn` | Pelanggan dikenai PPN |
| `sales_id` | Sales yang membawa pelanggan (user ber-role Sales yang aktif) |
| `status` | `aktif` / `berhenti` |
| `status_langganan` | `on` / `off` (terisolir) |
| `profile_asal`, `isolir_at` | Profile sebelum diisolir, waktu isolir |

Hanya identitas akun yang sesuai jenis koneksinya yang disimpan; kolom lain dikosongkan saat simpan.

### 7.2 Halaman & tab

Semua ditangani `PelangganController::index()` dengan parameter `status`:

| URL | Isi |
|---|---|
| `/pelanggan` | Semua pelanggan kecuali yang berhenti |
| `/pelanggan/on`, `/pelanggan/off` | Pelanggan aktif menurut `status_langganan` |
| `/pelanggan/lunas`, `/pelanggan/belum-lunas` | Menurut pembayaran pada bulan tagihan filter (default bulan berjalan) |
| `/pelanggan/berhenti` | `status = berhenti` |
| `/pelanggan/prorate` | Tagihan prorate yang sudah muncul tetapi belum dibayar |

Setiap baris membawa: periode yang sudah dibayar, pembayaran terakhir, periode & nominal tagihan bulan filter, info prorate, dan pembayaran periode itu (untuk kwitansi/pembatalan di tab Lunas). Prop `jumlah` berisi hitungan setiap tab.

### 7.3 Aturan tagihan

**Bulan tagihan vs periode pemakaian.** Kolom `pembayaran.periode` adalah **periode pemakaian**.

- Prabayar: tagihan bulan X untuk pemakaian bulan X.
- Pascabayar: tagihan bulan X untuk pemakaian bulan X−1.

`Pelanggan::periodePemakaian($bulanTagihan)` dan `Pembayaran::periodePembayaran()` mengonversi keduanya.

**Lunas** (`scopeLunas`): pelanggan aktif yang punya pembayaran untuk periode pemakaian yang ditagih di bulan itu.

**Belum lunas** (`scopeBelumLunas`): pelanggan aktif, paket berbayar (harga > 0), tagihannya sudah muncul (registrasi sebelum akhir periode pemakaian), dan belum ada pembayaran untuk periode itu.

**Prorate** (`skema_tagihan = prorate`, registrasi bukan tanggal 1):

- Hari prorate = hari dari tanggal registrasi sampai akhir bulan, inklusif.
- Nominal = `ceil(harga × hari / jumlah_hari_bulan / 1000) × 1000`, dan tidak lebih dari harga paket.
- Hanya berlaku untuk periode pemakaian bulan registrasi; bulan berikutnya harga penuh.
- Muncul: prabayar saat registrasi, pascabayar tanggal 1 bulan berikutnya.

Contoh: paket Rp150.000, registrasi 21 Juni (30 hari) → 10 hari → 150.000 × 10/30 = 50.000 → Rp50.000.

**Invoice** (`Pelanggan::invoice()`): no. tagihan `TGH{Ym}-{id 5 digit}`, nama & harga paket, periode, nominal (penuh/prorate), dan jatuh tempo (tanggal jatuh tempo di bulan tagihan, dipotong ke akhir bulan bila perlu). Dipakai halaman `/pelanggan/{id}/tagihan` dan cek tagihan publik.

### 7.4 Tambah & edit pelanggan

Validasi penting di `PelangganController::validated()`:

- Router harus milik cabang pelanggan atau router tanpa cabang. Paket harus untuk semua cabang atau khusus cabang itu. Area harus di cabang itu.
- `username_pppoe`, `mac_address`, `ip_address` unik per router.
- Nilai yang dikirim ke router menolak karakter kontrol.
- Sales lama yang sudah nonaktif boleh dipertahankan saat edit.

**Akun di router** (`mode_akun`, tidak untuk Static):

- `sinkron`: pilih akun yang sudah ada di router. Daftar dimuat dari `GET /pelanggan/akun-router/{router}?jenis=pppoe|hotspot|ip_binding`.
- `baru`: setelah data tersimpan, buat PPP Secret / Hotspot User / IP Binding di router (`IsolirService::buatAkun()`), memakai profile paket dan comment `{id_pelanggan} {nama}`. Bila gagal, data pelanggan tetap tersimpan dan respons berupa peringatan; akun bisa dibuat ulang lewat Edit.

**IP PPPoE.** `GET /pelanggan/ip-router/{router}` mengembalikan IP Pool router beserta IP kosongnya (`IpPool::kosong()`) dan IP yang sudah terpakai (Remote Address PPP Secret + sesi aktif + IP pelanggan billing di router itu). Saat simpan, IP baru dicek ke router; ditolak bila dipakai akun PPPoE lain. Bila router tidak terjangkau, pengecekan ini dilewati.

**Edit PPPoE mode sinkron.** Bila paket atau IP berubah, PPP Secret ikut diperbarui (`IsolirService::perbaruiSecret()`) lalu sesi aktif diputus. Pelanggan yang sedang terisolir tetap di profile isolir; profile paket baru disimpan ke `profile_asal` dan dipakai saat diaktifkan kembali.

### 7.5 Hapus pelanggan (bertahap)

1. Hapus pertama → `status = berhenti` (pindah ke tab Berhenti).
2. Hapus permanen hanya dari tab Berhenti dengan `permanen=1`. Dicatat di log aplikasi. Riwayat pembayaran tetap ada (`pelanggan_id` jadi null).

Akun di router tidak diubah pada kedua langkah.

### 7.6 On / Off

`POST /pelanggan/{id}/isolir` dan `POST /pelanggan/{id}/aktifkan`. Lihat [§13.1](#131-isolir-pelanggan-pppoe).

---

## 8. Pembayaran

[PembayaranController](../app/Http/Controllers/PembayaranController.php).

### 8.1 Input satu pelanggan `POST /pelanggan/{id}/bayar`

- Input: `bulan`, `tahun` (tahun berjalan ±5), `metode`, `diskon_tipe` (`nominal`/`persentase`), `diskon_nilai`.
- `metode` = `cash` (hanya bila cabang `terima_cash`) atau id rekening milik cabang pelanggan / rekening semua cabang. Disimpan sebagai `metode = transfer` + `rekening_id`.
- **Harga diambil dari server**, bukan dari form: `Pelanggan::tagihanUntuk(periode)` (penuh atau prorate). Diskon dihitung ulang di server (`Pembayaran::hitungDiskon()`); diskon nominal tidak boleh melebihi harga, persentase maksimal 100%.
- `jml_bayar = harga − diskon`.
- Disimpan dalam transaksi bersama snapshot nama pelanggan, nama paket, jenis tagihan, `is_prorate`, petugas, dan `paid_at = now()`. No. invoice: `INV{Ym periode}-{id 5 digit}`.
- Periode yang sudah dibayar ditolak. Klik ganda bersamaan ditahan unique index (`pelanggan_id`, `periode`).

### 8.2 Bayar massal `POST /pembayaran/massal`

Dari tab Belum Lunas. Maksimal 200 pelanggan sekali input, satu metode untuk semua, tanpa diskon. Bila satu saja pelanggan bukan milik cabang user, seluruh permintaan ditolak (404). Pelanggan yang dilewati beserta alasannya dikembalikan dalam respons:

- sudah lunas / tidak ada tagihan
- cabang tidak menerima cash
- rekening bukan milik cabangnya

### 8.3 Riwayat, edit, batal, kwitansi

| Route | Fungsi |
|---|---|
| `GET /pelanggan/{id}/pembayaran` | Riwayat (JSON), terbaru dulu |
| `PUT /pembayaran/{id}` | Ubah periode, metode, atau diskon. Harga tetap harga saat dibayar; no. invoice tidak berubah. Nilai sebelum/sesudah dicatat di log |
| `DELETE /pembayaran/{id}` | Batalkan; pelanggan kembali belum lunas untuk periode itu. Dicatat di log |
| `GET /pembayaran/{id}/kwitansi` | Halaman kwitansi (siap cetak) |
| `GET /pelanggan/{id}/tagihan?bulan=&tahun=` | Halaman invoice; bila sudah dibayar tampil LUNAS dengan tautan kwitansi |

---

## 9. Kelola Data

### 9.1 Cabang `/kelola-data/cabang` ([CabangController](../app/Http/Controllers/CabangController.php))

- Field: kode (unik), nama, alamat, tlp, tlp owner, nama mitra, ID grup WA, terima cash, Admin Full, status.
- **Tambah** (hanya user pusat): tarif pajak & bagi hasil diisi sekaligus dan dibuat untuk Januari–Desember tahun berjalan.
- **Admin Full** hanya bisa diubah user pusat, karena menentukan ke mana uang payment gateway masuk (§10).
- **Hapus** ditolak bila cabang masih punya pelanggan, router, paket, akun user, pembayaran, atau rekening. Area ikut terhapus. Akun user dicek karena user tanpa cabang = semua cabang, sehingga menghapus cabangnya sama dengan menaikkan hak aksesnya.

### 9.2 Area `/kelola-data/area`

CRUD area per cabang (kode, nama, cakupan, status). Pelanggan di area yang dihapus tetap ada, hanya kehilangan area-nya.

### 9.3 Pengaturan Pajak `/kelola-data/pajak` ([PajakController](../app/Http/Controllers/PajakController.php), [PajakCabang](../app/Models/PajakCabang.php))

- Tabel Januari–Desember untuk satu cabang. Tahun yang bisa dipilih: tahun lalu sampai tahun depan.
- Tarif: PPN %, PPh 25 %, BHP %, USO %, admin fee (`persentase` atau `nominal` per transaksi), bagi hasil (`persentase` atau `nominal` per bulan). Persentase dibatasi 0–100.
- Bulan tanpa baris memakai tarif tersimpan terakhir sebelumnya (`PajakCabang::setahun()`, `PajakCabang::berlaku()`).
- `PUT /pajak/{lokasi}` mengubah **satu bulan saja**, hanya oleh user pusat.
- BHP dan USO disimpan tetapi belum dipakai dalam rumus rekap.

### 9.4 Paket `/kelola-data/paket` ([PaketController](../app/Http/Controllers/PaketController.php))

- Field: nama, keterangan, harga, jenis (`bulanan`/`non_bulanan`), jenis koneksi (`pppoe`/`hotspot`), cabang (`filter_lokasi`), router, profile Mikrotik, rate limit (format `rx/tx`, boleh dengan burst), local address, IP pool, tampil di registrasi/landing.
- User cabang hanya bisa membuat paket khusus cabangnya. Paket untuk semua cabang hanya bisa diubah user pusat.
- **Dua mode profile:**
  - **Buat baru** (`buat_profile = true`): saat simpan, profile (dan IP pool bila diisi) dibuat/diperbarui di router paket, atau di **semua router** bila paket tidak dibatasi router (`IsolirService::sinkronPaket()`).
  - **Sinkronisasi** (`buat_profile = false`): pilih profile yang sudah ada di router (`GET /paket/profile-router/{router}`). Router tidak diubah; rate limit disalin dari router.
- Hasil sinkron disimpan di `synced_at` / `sync_error`. Data paket tetap tersimpan walau sinkron gagal. Tombol **Sinkron ke MikroTik**: `POST /paket/{id}/sinkron`.
- Profile paket tidak boleh bernama sama dengan profile isolir router.

### 9.5 Router `/kelola-data/router` ([RouterController](../app/Http/Controllers/RouterController.php))

- Field: kode, nama, cabang (kosong = dipakai bersama semua cabang), mode koneksi, host (mode langsung) atau tunnel (mode tunnel), port API, SSL, username & password API, profile isolir.
- Password disimpan terenkripsi (`APP_KEY`) dan tidak pernah dikirim ke browser. Saat edit, kosong = tidak diubah.
- Setiap simpan me-reset `status_koneksi` ke `belum_dicek`.
- **Tes koneksi** `POST /mikrotik/router/{id}/test`: mengambil identity, versi RouterOS, board, uptime, lalu menyimpan identity & versi.
- `GET /mikrotik/router/{id}/profiles`: daftar PPP profile (untuk menyalakan pelanggan yang profile asalnya tidak diketahui).

### 9.6 Rekening `/kelola-data/rekening` ([RekeningController](../app/Http/Controllers/RekeningController.php))

Nama bank/e-wallet, atas nama, nomor rekening, cabang (kosong = semua cabang / rekening pusat), tampil di invoice. Rekening semua cabang hanya bisa diubah user pusat. Nomor rekening dimasking di tabel. Rekening pusat (tanpa cabang) juga menjadi pilihan tujuan di Setor Kewajiban.

### 9.7 Tunnel & Template Isolir

Lihat [§14](#14-tunnel-l2tp--freeradius) dan [§13.2](#132-template-script-isolir).

### 9.8 Kartu Member, Notifikasi, Upload Bukti PPh

Halaman sudah ada ([KelolaDataController](../app/Http/Controllers/KelolaDataController.php)) tetapi belum punya tabel; tampil kosong.

---

## 10. Rekap Keuangan Cabang & Saldo Mitra

Satu sumber rumus: [app/Support/RekapCabang.php](../app/Support/RekapCabang.php), dengan potongan dari `PajakCabang::rincian()`. Dipakai Dashboard (kartu Saldo Mitra), Dashboard Cabang, Penarikan Saldo, dan Setor Kewajiban, sehingga angka saldo di semua tempat selalu sama.

### 10.1 Input per cabang per bulan

- **Pemasukan**: Σ `jml_bayar` pembayaran dengan `paid_at` di bulan itu (menurut tanggal bayar, bukan periode tagihan).
- **Tarif**: tarif `pajak_cabang` yang berlaku untuk bulan itu. Tanpa tarif, semua potongan 0.
- **Sudah setor**: Σ `setoran_mitra.nominal` dengan `periode` = bulan itu.
- **Sudah WD**: Σ `penarikan_saldo.nominal` dengan `periode_sumber` = bulan itu dan status `disetujui`.

### 10.2 Rumus (skema withdrawal)

| Komponen | Rumus |
|---|---|
| DPP | Pemasukan ÷ (1 + PPN%) |
| PPN | Pemasukan − DPP |
| Admin fee | persentase: DPP × % · nominal: admin fee × jumlah transaksi |
| Bagi hasil | persentase: **Pemasukan** × % · nominal: nilai tetap per bulan |
| Omset reseller | DPP − admin fee − bagi hasil |
| PPh | max(0, omset reseller) × PPh% |
| **Harus setor ke MSS** | PPN + admin fee + bagi hasil + PPh |
| **Pendapatan reseller** | Omset reseller − PPh |
| Pemasukan via mitra | Pemasukan − (payment gateway, bila cabang **bukan** Admin Full) |
| **Saldo** | Pendapatan reseller − pemasukan via mitra + sudah setor − sudah WD |
| Sisa setor | Harus setor − sudah setor |
| Sisa WD | Pendapatan reseller − sudah WD |

Nilai dihitung tanpa pembulatan, lalu dibulatkan ke rupiah saat dikirim ke tampilan.

**Arti saldo.** Saldo **negatif** = uang perusahaan yang masih dipegang mitra (mitra harus setor). Saldo **positif** = hak mitra yang dipegang MSS (bisa ditarik).

**Admin Full.** Cash dan transfer selalu masuk ke mitra (setiap cabang memakai rekeningnya sendiri). Payment gateway masuk ke MSS, kecuali cabang Admin Full yang memakai gateway sendiri; untuk cabang itu uang gateway dihitung sebagai pemasukan via mitra.

> **Catatan:** Spesifikasi awal menghitung bagi hasil persentase dari (DPP − admin fee) dan memakai rumus saldo yang berbeda. Kode menghitung bagi hasil persentase dari pemasukan, dan memakai rumus saldo di atas.

### 10.3 Contoh

PPN 11%, admin fee 5% (persentase), bagi hasil nominal Rp4.500.000, PPh 2%, pemasukan Rp9.050.000 seluruhnya cash, belum ada setoran/WD:

| Komponen | Nilai |
|---|---|
| DPP | 8.153.153 |
| PPN | 896.847 |
| Admin fee | 407.658 |
| Bagi hasil | 4.500.000 |
| Omset reseller | 3.245.495 |
| PPh | 64.910 |
| Harus setor | 5.869.414 |
| Pendapatan reseller | 3.180.586 |
| Saldo | 3.180.586 − 9.050.000 = **−5.869.414** |

Bila semua pemasukan lewat mitra, saldo awal = −harus setor. Setiap setoran menaikkan saldo, setiap WD yang disetujui menurunkannya.

### 10.4 Turunan

- `RekapCabang::saldo($lokasi, $periode[])` → saldo per cabang per bulan.
- `RekapCabang::saldoTersedia()` → saldo dikurangi pengajuan WD yang masih `pending`. Dipakai form dan validasi pengajuan penarikan.
- **Kewajiban** di halaman Setor = `max(0, −saldo)`.

Kolom lunas, belum lunas, dan pengeluaran di rekap masih 0 sampai modulnya punya tabel.

---

## 11. Transaksi: Penarikan Saldo & Setor Kewajiban

[TransaksiController](../app/Http/Controllers/TransaksiController.php). Periode yang bisa dipilih: 12 bulan terakhir termasuk bulan berjalan.

### 11.1 Penarikan saldo mitra `/transaksi/penarikan-saldo`

```
pending ──(Super Admin transfer + unggah bukti)──> disetujui   (saldo berkurang)
   └──────(alasan wajib)─────────────────────────> ditolak
```

**Pengajuan** (`transaksi.penarikan.create`):

- Field: cabang (user cabang hanya cabangnya), nama request, periode sumber, nominal, keterangan, bank/e-wallet, nomor rekening, atas nama penerima.
- Ditolak bila saldo tersedia ≤ 0, atau nominal melebihi saldo tersedia.
- Baris cabang dikunci (`lockForUpdate`) agar dua pengajuan bersamaan tidak sama-sama lolos cek saldo.

**Setujui** (`transaksi.penarikan.approve`, hanya user pusat):

- Foto bukti transfer wajib: JPG/PNG/WEBP, maksimal 2 MB.
- Dalam transaksi: cabang dan pengajuan dikunci, status harus masih `pending`, dan nominal harus ≤ saldo saat itu.
- File disimpan di disk privat `local` folder `bukti-penarikan`. Bila simpan ke database gagal, file dihapus lagi.

**Tolak**: alasan wajib. Juga dikunci agar tidak diproses dua kali.

**Daftar**: filter bulan pengajuan (`created_at`), status, cabang, atau semua periode.

### 11.2 Setor kewajiban mitra `/transaksi/setor-kewajiban`

- Hanya **user pusat** yang menginput dan membatalkan setoran. Mitra hanya melihat setoran cabangnya.
- Field: cabang, periode kewajiban, nominal, tujuan (`cash` di kantor pusat atau rekening pusat), foto bukti (opsional, JPG/PNG/WEBP ≤ 2 MB), keterangan.
- Setoran langsung menambah saldo mitra pada periode itu. Membatalkan setoran menghapus file buktinya dan mengurangi saldo lagi.
- Halaman menampilkan kewajiban per cabang per periode (§10.4).

### 11.3 Bukti transfer/setor

`GET /transaksi/penarikan-saldo/{id}/bukti` dan `GET /transaksi/setor-kewajiban/{id}/bukti` mengalirkan file dari `storage/app/private` setelah memeriksa login, permission, dan cabang. Header `X-Content-Type-Options: nosniff` dan `Cache-Control: private, no-store`.

### 11.4 Pengeluaran

Halaman sudah ada, belum punya tabel; tampil kosong.

---

## 12. Integrasi Mikrotik (RouterOS API)

### 12.1 Lapisan

| Kelas | Peran |
|---|---|
| [RouterOsApi](../app/Services/Mikrotik/RouterOsApi.php) | Klien protokol RouterOS API murni PHP (tanpa ekstensi). TCP 8728 atau TLS 8729 (sertifikat self-signed diterima). Mendukung login baru dan challenge MD5 (RouterOS < 6.43) |
| [MikrotikConnector](../app/Services/Mikrotik/MikrotikConnector.php) | Membuka koneksi sesuai `mode_koneksi`: host langsung, atau `ip_tunnel` (tunnel harus ada dan aktif) |
| [MikrotikGateway](../app/Services/Mikrotik/MikrotikGateway.php) | Interface operasi yang dipakai billing; implementasinya [RouterOsGateway](../app/Services/Mikrotik/RouterOsGateway.php). Dipisah agar bisa diganti tiruan saat test (`tests/Fakes`) |
| [IsolirService](../app/Services/Mikrotik/IsolirService.php) | Logika bisnis: tes koneksi, isolir/aktifkan, perbarui secret, daftar akun, IP PPPoE, buat akun, sinkron paket |
| [RouterOsException](../app/Services/Mikrotik/RouterOsException.php) | Jenis `koneksi`, `login`, atau `perintah` |

### 12.2 Operasi gateway

`info`, `findSecret`, `profiles`, `profileList` (PPP / Hotspot User Profile), `setSecret`, `removeActive`, `saveProfile`, `pools`, `activeSessions`, `savePool`, `accounts` (PPP Secret / Hotspot User / IP Binding), `addAccount`.

### 12.3 Status router

Setiap aksi lewat `IsolirService::onRouter()` memperbarui router:

- Berhasil → `status_koneksi = online`, `last_error` dikosongkan.
- Gagal koneksi/login → `offline` + `last_error`.
- Perintah ditolak router (`perintah`) → tetap `online`, karena router terjangkau.

### 12.4 Rate limit

Semua route yang menghubungi router berada di grup `throttle:mikrotik`: 30 permintaan per menit per user. Bila lewat batas, respons 429 berisi pesan.

---

## 13. Isolir

### 13.1 Isolir pelanggan (PPPoE)

On/Off saat ini **hanya untuk PPPoE**. Mekanismenya mengganti profile PPP Secret.

**Isolir** (`IsolirService::isolir()`):

1. Cari PPP Secret `username_pppoe` di router.
2. Bila sudah di profile isolir → hanya samakan status di database.
3. Pastikan profile isolir router (`routers.profile_isolir`) ada.
4. Ubah profile secret ke profile isolir, putus sesi aktif.
5. Simpan `profile_asal`, `status_langganan = off`, `isolir_at`.

**Aktifkan** (`IsolirService::aktifkan()`). Profile tujuan, berurutan:

1. profile yang dipilih user (bila dikirim);
2. `profile_asal`;
3. profile paket pelanggan.

Bila tidak ada tujuan dan secret masih di profile isolir, user diminta memilih profile (daftar dari `/mikrotik/router/{id}/profiles`). Setelah profile diganti, sesi diputus, `status_langganan = on`, dan `profile_asal`/`isolir_at` dikosongkan.

Setiap aksi, termasuk yang gagal, dicatat di `isolir_logs`.

### 13.2 Template script isolir

Menu **Kelola Data › Template Isolir** (hanya user pusat). [IsolirScript](../app/Support/IsolirScript.php) menghasilkan script RouterOS v6/v7 untuk tiga metode: **Static**, **PPPoE**, **Hotspot**.

Cara kerja script:

- Pelanggan terisolir = IP di address-list `ISOLIR`. PPPoE/Hotspot masuk otomatis lewat profile isolir; Static ditambahkan manual.
- HTTP pelanggan terisolir diarahkan lewat web proxy router ke halaman `/isolir`.
- Lalu lintas lain diblokir, kecuali DNS dan alamat di `ISOLIR-ALLOW`.
- Semua aturan diberi comment `isolir-mss`, sehingga aman di-paste ulang.

Opsi form (`POST /isolir/script`): nama profile isolir, jaringan isolir (IPv4/prefix 16–29), port proxy (1024–65535), URL halaman isolir, alamat yang diizinkan (domain/IP/prefix). Isi halaman isolir disimpan lewat `PUT /isolir/pengaturan` ke tabel `pengaturan`.

---

## 14. Tunnel L2TP & FreeRADIUS

Untuk router mitra yang tidak punya IP publik. Hanya user pusat.

### Alur

```
Router mitra ──L2TP──> xl2tpd + pppd (VPS) ──auth──> FreeRADIUS ──> tabel radcheck/radreply (billing)
                                                                      │
Billing ──RouterOS API ke ip_tunnel (10.10.10.x)──> Router mitra  <───┘ Framed-IP-Address
```

### Penyimpanan akun ([TunnelRadius](../app/Services/Tunnel/TunnelRadius.php))

Setiap simpan menulis ulang akun di tabel FreeRADIUS dalam satu transaksi:

- `radcheck`: `Cleartext-Password`. Tunnel `tidak_aktif` ditambah `Auth-Type := Reject` (akun tetap ada, login ditolak).
- `radreply`: `Framed-IP-Address = ip_tunnel` dan `Acct-Interim-Interval = 60`.
- Ganti username → akun lama dihapus.

Status online dibaca dari `radacct` (sesi tanpa `acctstoptime`).

### Validasi ([TunnelController](../app/Http/Controllers/TunnelController.php))

- Username `A-Z a-z 0-9 . _ -`, unik, dan tidak dipakai akun RADIUS lain.
- `ip_tunnel` IPv4, unik, harus di dalam `TUNNEL_SUBNET`, dan bukan IP gateway. Form diisi otomatis dengan IP kosong pertama.
- Server hanya domain/IP. Protokol selalu L2TP.

### Script router mitra

`POST /mikrotik/tunnel/{id}/script` (butuh izin ubah, karena script memuat password tunnel). Script yang dihasilkan:

1. membuat L2TP client ke server billing;
2. mengaktifkan service API di port pilihan, hanya untuk IP gateway;
3. menambah aturan firewall input paling atas untuk server billing;
4. (opsional) membuat user group `billing-api` dan user API khusus.

Aman di-paste ulang. Semua nilai lewat `RouterOsScript::quote()`.

---

## 15. Keamanan

| Area | Penerapan |
|---|---|
| Otorisasi | Permission spatie di setiap route; pembatasan cabang di controller (404 untuk cabang lain); grup `semua_cabang` untuk menu global |
| Eskalasi hak akses | Lihat §4: Super Admin hanya oleh Super Admin, tidak bisa mengubah role/cabang/status akun sendiri, role Super Admin terkunci |
| Login | Rate limit per username+IP dan per IP; log login gagal; sesi diregenerasi |
| Sesi | User nonaktif dikeluarkan pada request berikutnya; ganti password/nonaktif memutus semua sesi |
| Data sensitif | Password router & tunnel di-cast `encrypted` dan `#[Hidden]`; `.env` di-`chmod 640` oleh `deploy.sh` |
| Injeksi script RouterOS | `RouterOsScript::quote()` meng-escape `"`, `\`, `$`, `?`, dan karakter kontrol (sebagai `\XX`), sehingga nilai tetap satu baris. Input yang dikirim ke router menolak karakter kontrol |
| Unggahan | Hanya gambar JPG/PNG/WEBP ≤ 2 MB, divalidasi isi file (`fileinfo`); disimpan di disk privat; dibuka lewat route berizin dengan `nosniff` & `no-store` |
| Halaman publik | Cek tagihan: POST, throttle 10/menit/IP, nama disamarkan, data minimum |
| Header HTTP | `SecurityHeaders`: `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS (bila https), CSP dengan nonce untuk script (dimatikan saat Vite dev atau `APP_CSP=false`) |
| Proxy | `trustProxies` hanya alamat lokal/private; `APP_URL` https memaksa skema https untuk semua URL |
| Jejak audit | Edit/batal pembayaran, hapus permanen pelanggan, setuju penarikan, catat/batal setoran → `storage/logs`. Aksi router → `isolir_logs` |
| Race condition | Unique index pembayaran per periode; `lockForUpdate` pada pengajuan & persetujuan penarikan |

---

## 16. Frontend

- **Entry**: [resources/js/app.jsx](../resources/js/app.jsx). Setiap halaman jadi chunk terpisah, dan semua chunk dimuat di latar belakang setelah halaman pertama tampil. Prefetch Inertia: segar 1 menit, cache hingga 10 menit.
- **Layout**: `AppLayout` (Sidebar, Header, MobileNav), default untuk semua halaman kecuali yang menetapkan layout sendiri (landing, cek tagihan, login).
- **Menu**: [resources/js/lib/menu.js](../resources/js/lib/menu.js), disaring dengan `useBisa()`.
- **Helper** ([resources/js/lib/ui.js](../resources/js/lib/ui.js)): `api()` (request JSON + CSRF), `notify()` (toast), `useBisa()`, `reloadData()`, `saveTo()`, `deleteFrom()`, `applyFilters()`, `useNavLoading()` (skeleton hanya bila muat > 400 ms).
- **Format** ([resources/js/lib/format.js](../resources/js/lib/format.js)): `rupiah`, `angka`, `persen`, `tanggal`, `waktu`, `periode`, `terbilang`, `maskRekening`, daftar `METODE` dan `BULAN`.
- **Komponen utama**:
  - `DataTable`: search, sort, paginasi, export, pilih kolom, kolom pertama sticky, footer total.
  - `CrudPage`: pola list + form modal + hapus.
  - `FilterBar`: filter bulan/tahun/cabang.
  - `Pembayaran`: `FormPembayaran`, `BayarMassal`, `RiwayatPembayaran`.
  - `InvoiceTagihan`, `RekapCabangTable`, `RingkasanCard`, `SaldoCard`, `Chart` (ApexCharts).
- **UI**: template Cuba (Bootstrap 5.3 + reactstrap), aset di `public/cuba`.

---

## 17. Testing

PHPUnit 12, `php artisan test --compact`.

- **Feature** ([tests/Feature/](../tests/Feature/)): satu file per menu (Pelanggan, Pembayaran, TagihanProrate, PenarikanSaldo, SetoranMitra, Isolir, Tunnel, Paket, Router via Kelola Data, User, HakAkses, AksesPeran, CekTagihan, SecurityHeaders, dll.).
- **Unit** ([tests/Unit/](../tests/Unit/)): `RouterOsApi`, `RouterOsScript`, `IpPool`.
- **Tiruan router** ([tests/Fakes/](../tests/Fakes/)): `FakeConnector` dan `FakeGateway` menggantikan `MikrotikConnector`, sehingga test tidak butuh router sungguhan.
- **Seeder**: `HakAksesSeeder` (wajib di setiap lingkungan) dan `DataUjiSeeder` (data contoh; menolak jalan di production).

---

## 18. Fitur yang Belum Selesai

| Fitur | Kondisi saat ini |
|---|---|
| Pengeluaran | Halaman ada, tanpa tabel; kolom pengeluaran di rekap = 0 |
| Laporan | Halaman ada, belum ada data/export |
| Payment Gateway | Halaman ada (aturan Admin Full sudah dipakai di rekap); belum ada tabel & integrasi Tripay/Xendit/Pakar Digital/Moota |
| Logs aktivitas | Halaman kosong; jejak sementara ada di `storage/logs` dan `isolir_logs` |
| Notifikasi WhatsApp / WAGW | Halaman Notifikasi & widget dashboard kosong |
| Kartu Member, Upload Bukti PPh | Halaman ada, belum ada tabel |
| Export Excel/PDF | Permission `export` sudah ada; export server belum dibuat |
| On/Off non-PPPoE | Isolir baru untuk PPPoE; Static perlu manual lewat address-list |
| BHP & USO | Tersimpan di tarif, belum masuk rumus rekap |
