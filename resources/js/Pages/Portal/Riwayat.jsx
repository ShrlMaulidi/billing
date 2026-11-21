import { FileText } from 'react-feather';
import { Card, CardBody } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import { StatusBadge } from '../../Components/ui';
import { periode, rupiah, tanggal } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Riwayat tagihan yang sudah lunas (cash, transfer, atau online) beserta kwitansinya. */
export default function Riwayat({ rows, jumlahLangganan }) {
    return (
        <>
            <PageHeader title="Riwayat Tagihan" home="/akun-saya" />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <DataTable
                            data={rows} exportable={false} emptyText="Belum ada pembayaran"
                            columns={[
                                { key: 'periode_pembayaran', label: 'Periode', render: (r) => <><strong>{periode(r.periode_pembayaran)}</strong><br /><small className="f-light">{r.nama_paket ?? '-'}</small></> },
                                ...(jumlahLangganan > 1 ? [{ key: 'pelanggan', label: 'Langganan', sortValue: (r) => r.pelanggan?.id_pelanggan, render: (r) => <>{r.pelanggan?.id_pelanggan}<br /><small className="f-light">{r.pelanggan?.nama}</small></> }] : []),
                                { key: 'no_invoice', label: 'No. Invoice', render: (r) => r.no_invoice ?? '-' },
                                { key: 'paid_at', label: 'Tanggal Bayar', render: (r) => tanggal(r.paid_at) },
                                { key: 'jml_bayar', label: 'Jumlah', align: 'end', render: (r) => <span className="fw-semibold">{rupiah(r.jml_bayar)}</span> },
                                { key: 'metode_label', label: 'Metode' },
                                { key: 'status', label: 'Status', sortable: false, render: () => <StatusBadge value="lunas" text="Lunas" /> },
                                {
                                    key: 'kwitansi', label: 'Kwitansi', sortable: false,
                                    render: (r) => <a href={r.link_kwitansi} target="_blank" rel="noopener noreferrer" className="btn btn-outline-primary btn-sm"><FileText size={13} className="me-1" />Lihat</a>,
                                },
                            ]}
                        />
                    </CardBody>
                </Card>
            </div>
        </>
    );
}

Riwayat.layout = portalLayout;
