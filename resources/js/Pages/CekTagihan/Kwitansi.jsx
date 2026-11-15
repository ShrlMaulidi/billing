import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'react-feather';
import KwitansiPembayaran from '../../Components/KwitansiPembayaran';

/** Kwitansi publik satu periode lunas, dibuka dari riwayat pembayaran di Cek Tagihan (link bertanda tangan). */
export default function KwitansiPublik({ kwitansi }) {
    return (
        <div className="lp ct">
            <Head title={`Kwitansi ${kwitansi.no_invoice} · PT Media Solusi Sukses`} />
            <header className="ct-bar no-print">
                <div className="lp-wrap ct-bar-inner">
                    <Link href="/" className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></Link>
                    <Link href="/cek-tagihan" className="ct-back"><ArrowLeft size={16} /> Cek Tagihan</Link>
                </div>
            </header>

            <main className="lp-wrap ct-body">
                <div className="ct-summary-bar no-print">
                    <p>Kwitansi pembayaran periode lunas. Simpan sebagai bukti pembayaran Anda.</p>
                    <button type="button" className="ct-print" onClick={() => window.print()}><Printer size={16} /> Cetak / Simpan PDF</button>
                </div>
                <KwitansiPembayaran kwitansi={kwitansi} />
            </main>

            <footer className="lp-footer no-print">
                <div className="lp-wrap lp-footer-inner"><span>© {new Date().getFullYear()} PT Media Solusi Sukses</span></div>
            </footer>
        </div>
    );
}

KwitansiPublik.layout = null;
