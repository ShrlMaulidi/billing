import { ArrowLeft, Printer } from 'react-feather';
import { Button } from 'reactstrap';
import KwitansiPembayaran from '../../Components/KwitansiPembayaran';
import PageHeader from '../../Components/PageHeader';

/** Kwitansi satu pembayaran di dashboard. Tombol Cetak memakai dialog print browser (bisa disimpan sebagai PDF). */
export default function Kwitansi({ kwitansi }) {
    return (
        <>
            <PageHeader title="Kwitansi Pembayaran" crumbs={[{ label: 'Pelanggan Lunas', href: '/pelanggan/lunas' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3 no-print">
                    <Button color="light" onClick={() => window.history.back()}><ArrowLeft size={14} className="me-1" />Kembali</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                </div>
                <KwitansiPembayaran kwitansi={kwitansi} />
            </div>
        </>
    );
}
