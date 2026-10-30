import { ArrowLeft, Printer } from 'react-feather';
import { Button, Card, CardBody } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { periode, rupiah, waktu } from '../../lib/format';

/** Kwitansi satu pembayaran; tombol Cetak memakai dialog print browser (bisa disimpan sebagai PDF). */
export default function Kwitansi({ kwitansi: k }) {
    const persenDiskon = k.diskon > 0 && k.diskon_tipe === 'persentase' ? ` (${String(k.diskon_nilai).replace('.', ',')}%)` : '';
    const baris = [
        ['No. Invoice', k.no_invoice],
        ['Tanggal Bayar', waktu(k.paid_at)],
        ['ID Pelanggan', k.id_pelanggan ?? '-'],
        ['Nama Pelanggan', k.nama_pelanggan],
        ['Alamat', k.alamat ?? '-'],
        ['Paket Langganan', k.nama_paket],
        ['Periode', periode(k.periode)],
        ['Metode Pembayaran', k.metode === 'cash' ? 'Cash' : `Transfer · ${k.rekening ?? '-'}`],
    ];

    return (
        <>
            <PageHeader title="Kwitansi Pembayaran" crumbs={[{ label: 'Pelanggan Lunas', href: '/pelanggan/lunas' }]} />
            <div className="container-fluid">
                <div className="d-flex gap-2 mb-3 no-print">
                    <Button color="light" onClick={() => window.history.back()}><ArrowLeft size={14} className="me-1" />Kembali</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                </div>
                <Card className="mx-auto" style={{ maxWidth: 640 }}>
                    <CardBody>
                        <div className="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                            <div>
                                <h4 className="mb-1">MSS Billing</h4>
                                {k.cabang && <div className="f-light small">{k.cabang.nama_lokasi}{k.cabang.alamat_lokasi && <><br />{k.cabang.alamat_lokasi}</>}{k.cabang.tlp_lokasi && <><br />Tlp {k.cabang.tlp_lokasi}</>}</div>}
                            </div>
                            <div className="text-end">
                                <h5 className="mb-1">KWITANSI</h5>
                                <span className="badge badge-light-success">LUNAS</span>
                            </div>
                        </div>
                        <table className="table table-borderless table-sm mb-3">
                            <tbody>
                                {baris.map(([label, nilai]) => (
                                    <tr key={label}><td className="f-light" style={{ width: '40%' }}>{label}</td><td className="fw-semibold">{nilai}</td></tr>
                                ))}
                            </tbody>
                        </table>
                        <table className="table table-sm mb-3">
                            <tbody>
                                <tr><td>Harga Paket</td><td className="text-end">{rupiah(k.harga)}</td></tr>
                                <tr><td>Diskon{persenDiskon}</td><td className="text-end">{k.diskon > 0 ? `- ${rupiah(k.diskon)}` : rupiah(0)}</td></tr>
                                <tr className="fw-bold fs-5"><td>Total Dibayar</td><td className="text-end text-success">{rupiah(k.jml_bayar)}</td></tr>
                            </tbody>
                        </table>
                        <div className="d-flex justify-content-between small f-light">
                            <span>Petugas: {k.petugas ?? '-'}</span>
                            <span>Terima kasih atas pembayaran Anda</span>
                        </div>
                    </CardBody>
                </Card>
            </div>
        </>
    );
}
