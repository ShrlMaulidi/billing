import { Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer } from 'react-feather';
import { Button } from 'reactstrap';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import PageHeader from '../../Components/PageHeader';

/** Tagihan (invoice) satu pelanggan untuk satu bulan tagihan; tombol Cetak memakai dialog print browser (bisa disimpan sebagai PDF). */
export default function Tagihan({ tagihan: t }) {
    const lunas = !!t.pembayaran_id;

    return (
        <>
            <PageHeader title="Tagihan Pelanggan" crumbs={[{ label: 'Pelanggan Belum Lunas', href: '/pelanggan/belum-lunas' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3 no-print">
                    <Button color="light" onClick={() => window.history.back()}><ArrowLeft size={14} className="me-1" />Kembali</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                    {lunas && <Link href={`/pembayaran/${t.pembayaran_id}/kwitansi`} className="btn btn-outline-success"><FileText size={14} className="me-1" />Lihat Kwitansi</Link>}
                </div>

                <InvoiceTagihan tagihan={t} />
            </div>
        </>
    );
}
