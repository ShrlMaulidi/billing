# Audit Wajib Sebelum Commit & Push

Aturan ini berlaku untuk SETIAP perubahan kode, sekecil apa pun, dan tidak boleh dilewati. Jangan pernah commit atau push sebelum semua langkah di bawah selesai dan lulus.

Hook `.claude/hooks/audit-sebelum-commit.sh` menjalankan pemeriksaan otomatis (tes, Pint, file rahasia, sisa debug, build, `composer audit`, `npm audit`) setiap kali `git commit` / `git push` dijalankan, dan memblokir perintah bila ada yang gagal. Hook hanya menangkap hal yang bisa dicek mesin; audit keamanan, performa, dan tampilan HP di bawah tetap wajib dilakukan sendiri.

## 1. Testing

- Tulis atau perbarui tes untuk setiap perubahan perilaku/logika (ikuti skill `testing-best-practices`).
- Jalankan tes yang terkait dulu, lalu seluruh suite: `php artisan test --compact`. Semua harus lulus.
- Dilarang menghapus, men-skip, atau melemahkan assertion supaya tes lulus. Perbaiki kodenya.
- Rapikan format: `vendor/bin/pint --dirty --format agent`.
- Bila menyentuh `resources/`, `package.json`, atau `vite.config.*`: jalankan `npm run build` dan pastikan tanpa error.

## 2. Audit Keamanan

Periksa diff terhadap setiap poin berikut:

- **Otorisasi**: setiap route baru memakai middleware `permission:` (spatie) dengan nama dari `app/Support/HakAkses.php`. Data yang dibatasi cabang tetap dicek cabangnya, termasuk lewat route model binding (cegah IDOR: user cabang A tidak boleh membuka data cabang B dengan mengganti ID di URL).
- **Validasi input**: pakai Form Request atau `$request->validate()`. Jangan teruskan `$request->all()` ke `create()`/`update()`; gunakan `$request->validated()` dan `$fillable`.
- **SQL injection**: jangan menyambung input ke query. `whereRaw`/`DB::raw`/`selectRaw` wajib memakai binding `?`.
- **Command injection MikroTik**: setiap nilai yang masuk ke script RouterOS wajib lewat `RouterOsScript::quote()`. Endpoint yang memanggil router memakai `throttle:mikrotik`. Dilarang `exec`/`shell_exec`/`system` dengan input pengguna.
- **XSS**: dilarang `dangerouslySetInnerHTML` dan `{!! !!}` untuk data dari pengguna/database.
- **Upload file**: validasi `mimes` dan `max`, simpan dengan nama acak, jangan izinkan file yang bisa dieksekusi.
- **Rahasia & data pribadi**: jangan commit `.env`, kunci, password, token, atau data pelanggan asli. Jangan log password/token/OTP. Gunakan data rekaan untuk tes dan contoh.
- **CSRF & auth**: jangan mengecualikan route dari CSRF kecuali webhook yang memverifikasi signature. Endpoint login/publik diberi rate limit.
- **Sisa debug**: tidak boleh ada `dd()`, `dump()`, `var_dump()`, `ray()`, `console.log()` yang ikut ter-commit.
- **Dependency**: tidak menambah/mengubah dependency tanpa persetujuan. Bila disetujui, jalankan `composer audit` / `npm audit --omit=dev` dan pastikan tidak menambah kerentanan.
- Untuk route atau aksi sensitif baru, tambahkan tes yang membuktikan user tanpa permission mendapat 403 dan user cabang lain ditolak.

## 3. Audit Performa (Website Tetap Cepat)

- **N+1 query**: eager load relasi (`with()`), pakai `withCount()`/`withSum()` alih-alih query di dalam loop.
- **Data besar**: halaman daftar wajib `paginate()`; jangan `->get()` tanpa batas pada tabel yang terus bertambah (pelanggan, tagihan, pembayaran, log). Pilih kolom yang perlu saja.
- **Index database**: kolom yang dipakai di `where`/`orderBy`/`join` (foreign key, status, tanggal jatuh tempo) diberi index di migration.
- **Pekerjaan berat** (kirim WhatsApp, panggil API MikroTik, export, auto isolir massal) dijalankan lewat queue/job atau command terjadwal, bukan di dalam request pengguna. Loop data besar pakai `chunkById()`/`lazy()`.
- **Inertia props**: kirim hanya field yang dipakai halaman (bukan seluruh model). Data berat pakai deferred props dengan skeleton loading.
- **Frontend**: jangan menambah library berat tanpa persetujuan; hindari re-render dan request berulang yang tidak perlu.

## 4. Tampilan HP (Mobile)

- Cek setiap halaman yang diubah pada lebar layar sekitar 360px: tidak ada scroll horizontal, teks terbaca, tombol mudah disentuh, form bisa diisi.
- Ikuti pola yang sudah ada: tabel otomatis menjadi kartu di HP, kartu statistik ringkas. Jangan membuat tabel yang melebar keluar layar.

## 5. Laporan Sebelum Commit

Sebelum `git commit`/`git push`, tulis ringkasan audit singkat kepada pengguna:

- Tes: jumlah tes lulus dan tes baru yang ditambahkan.
- Keamanan: poin yang dicek dan temuan (atau "tidak ada temuan").
- Performa: query/halaman yang terdampak dan hasilnya.
- Mobile: halaman yang dicek.

Bila ada yang gagal atau belum bisa dicek, perbaiki dulu atau sampaikan terus terang. Jangan pernah melewati pemeriksaan dengan `--no-verify`, menonaktifkan hook, atau mengubah `.claude/settings.json` agar commit lolos.
