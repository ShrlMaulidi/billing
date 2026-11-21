import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Key, LogOut, MessageCircle } from 'react-feather';
import FormGantiPassword from '../../Components/FormGantiPassword';
import { HasilPelanggan, linkWa } from '../../Components/TagihanPelanggan';

/** Akun Saya: halaman pelanggan setelah login, berisi tagihan, bayar online, dan riwayat pembayaran tiap langganan. */
export default function Portal({ akun, hasil }) {
    const [gantiPassword, setGantiPassword] = useState(false);

    return (
        <div className="lp ct">
            <Head title="Akun Saya · PT Media Solusi Sukses">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&display=swap" rel="stylesheet" />
            </Head>

            <header className="ct-bar no-print">
                <div className="lp-wrap ct-bar-inner">
                    <Link href="/akun-saya" className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></Link>
                    <Link href="/logout" method="post" as="button" className="ct-back ak-keluar"><LogOut size={16} /> Keluar</Link>
                </div>
            </header>

            <section className="ct-head no-print">
                <div className="lp-wrap">
                    <h1 className="lp-title is-dark">Halo, <span>{akun.nama}</span></h1>
                    <p>Login sebagai {akun.username}. Berikut tagihan dan riwayat pembayaran langganan internet Anda.</p>
                    <button type="button" className="lp-btn lp-btn-ghost ak-toggle" onClick={() => setGantiPassword(!gantiPassword)}>
                        <Key size={16} /> {gantiPassword ? 'Tutup' : 'Ganti Password'}
                    </button>
                </div>
            </section>

            <main className="lp-wrap ct-body">
                {gantiPassword && (
                    <FormGantiPassword perluPasswordLama judul="Ganti Password" onSelesai={() => setGantiPassword(false)}
                        keterangan="Minimal 8 karakter, berisi huruf dan angka. Perangkat lain yang memakai akun ini akan keluar." />
                )}

                {hasil.length === 0 && (
                    <div className="ct-empty">
                        <h2>Belum ada langganan aktif</h2>
                        <p>Akun ini belum terhubung ke langganan internet yang aktif.</p>
                        <a href={linkWa(`Halo MSS, akun pelanggan saya (${akun.username}) belum menampilkan langganan.`)} target="_blank" rel="noopener noreferrer" className="lp-btn lp-btn-gold">
                            <MessageCircle size={18} /> Hubungi Kami
                        </a>
                    </div>
                )}

                {hasil.map((p) => <HasilPelanggan key={p.id_pelanggan ?? p.nama} pelanggan={p} />)}
            </main>

            <footer className="lp-footer no-print">
                <div className="lp-wrap lp-footer-inner"><span>© {new Date().getFullYear()} PT Media Solusi Sukses</span></div>
            </footer>
        </div>
    );
}

Portal.layout = null;
