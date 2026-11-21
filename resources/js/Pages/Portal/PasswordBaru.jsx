import { Head, Link, router } from '@inertiajs/react';
import { LogOut } from 'react-feather';
import FormGantiPassword from '../../Components/FormGantiPassword';

/** Wajib dibuka pelanggan setelah login pertama / password di-reset: ganti password awal (nomor HP) sebelum masuk Akun Saya. */
export default function PasswordBaru({ akun }) {
    return (
        <div className="lp ct">
            <Head title="Buat Password Baru · PT Media Solusi Sukses">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&display=swap" rel="stylesheet" />
            </Head>

            <header className="ct-bar">
                <div className="lp-wrap ct-bar-inner">
                    <span className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></span>
                    <Link href="/logout" method="post" as="button" className="ct-back ak-keluar"><LogOut size={16} /> Keluar</Link>
                </div>
            </header>

            <section className="ct-head">
                <div className="lp-wrap">
                    <h1 className="lp-title is-dark">Buat <span>Password Baru</span></h1>
                    <p>Halo {akun.nama}, demi keamanan akun Anda, ganti password awal (nomor HP {akun.username}) dengan password Anda sendiri sebelum melanjutkan.</p>
                </div>
            </section>

            <main className="lp-wrap ct-body">
                <FormGantiPassword labelSimpan="Simpan & Lanjutkan" onSelesai={() => router.visit('/akun-saya', { replace: true })}
                    keterangan="Minimal 8 karakter, berisi huruf dan angka. Jangan memakai nomor HP, tanggal lahir, atau password yang mudah ditebak." />
            </main>

            <footer className="lp-footer">
                <div className="lp-wrap lp-footer-inner"><span>© {new Date().getFullYear()} PT Media Solusi Sukses</span></div>
            </footer>
        </div>
    );
}

PasswordBaru.layout = null;
