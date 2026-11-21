import { Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer } from 'react-feather';
import { Button } from 'reactstrap';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import KwitansiPembayaran from '../../Components/KwitansiPembayaran';
import PageHeader from '../../Components/PageHeader';
import { portalLayout } from '../../Layouts/PortalLayout';

/**
 * Invoice atau kuitansi satu periode lunas dari Riwayat Tagihan. Tombol Cetak memakai dialog print browser
 * (bisa disimpan sebagai PDF); header, sidebar, dan navbar tidak ikut tercetak.
 */
export default function Dokumen({ jenis, pembayaranId, tagihan, kwitansi }) {
    const invoice = jenis === 'invoice';

    return (
        <>
            <PageHeader title={invoice ? 'Invoice' : 'Kuitansi Pembayaran'} home="/akun-saya" crumbs={[{ label: 'Riwayat Tagihan', href: '/akun-saya/riwayat' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3 no-print">
                    <Link href="/akun-saya/riwayat" className="btn btn-light"><ArrowLeft size={14} className="me-1" />Kembali</Link>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                    <Link href={`/akun-saya/riwayat/${pembayaranId}/${invoice ? 'kuitansi' : 'invoice'}`} className="btn btn-outline-primary ms-sm-auto">
                        <FileText size={14} className="me-1" />Lihat {invoice ? 'Kuitansi' : 'Invoice'}
                    </Link>
                </div>
                {invoice ? <InvoiceTagihan tagihan={tagihan} /> : <KwitansiPembayaran kwitansi={kwitansi} />}
            </div>
        </>
    );
}

Dokumen.layout = portalLayout;
