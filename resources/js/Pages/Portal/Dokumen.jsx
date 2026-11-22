import { Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer } from 'react-feather';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import KwitansiPembayaran from '../../Components/KwitansiPembayaran';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { portalLayout } from '../../Layouts/PortalLayout';

/**
 * Invoice atau kuitansi satu periode lunas dari Riwayat Tagihan. Tombol Cetak memakai dialog print browser
 * (bisa disimpan sebagai PDF); header, sidebar, dan navbar tidak ikut tercetak.
 */
export default function Dokumen({ jenis, pembayaranId, tagihan, kwitansi }) {
    const invoice = jenis === 'invoice';

    return (
        <>
            {/* Tombol di pita, kertas dokumen menumpuk di bawahnya; pita & tombol tidak ikut tercetak */}
            <PitaPortal judul={invoice ? 'Invoice' : 'Kuitansi'} keterangan={invoice ? 'Tagihan yang sudah lunas' : 'Bukti pembayaran'} judulTab={invoice ? 'Invoice' : 'Kuitansi Pembayaran'}>
                <Link href="/akun-saya/riwayat" className="pm-band-tombol is-transparan" aria-label="Kembali ke Riwayat"><ArrowLeft size={15} /><span className="d-none d-sm-inline">Riwayat</span></Link>
                <button type="button" className="pm-band-tombol" onClick={() => window.print()}><Printer size={15} />Cetak<span className="d-none d-sm-inline"> / PDF</span></button>
            </PitaPortal>
            <div className="container-fluid">
                <div className="pm-wrap pm-wrap-dokumen pm-isi">
                    <Link href={`/akun-saya/riwayat/${pembayaranId}/${invoice ? 'kuitansi' : 'invoice'}`} className="pm-info mt-0 mb-3 no-print">
                        <span className="pm-info-icon"><FileText size={18} /></span>
                        <span className="flex-grow-1"><strong>Lihat {invoice ? 'Kuitansi' : 'Invoice'}</strong><br /><small>{invoice ? 'Bukti pembayaran periode ini' : 'Tagihan periode ini'}</small></span>
                    </Link>
                    {invoice ? <InvoiceTagihan tagihan={tagihan} /> : <KwitansiPembayaran kwitansi={kwitansi} />}
                </div>
            </div>
        </>
    );
}

Dokumen.layout = portalLayout;
