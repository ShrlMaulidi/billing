import { Link } from '@inertiajs/react';
import { CheckSquare, FileText } from 'react-feather';
import { Card, CardBody } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import { StatusBadge } from '../../Components/ui';
import { periode, rupiah, tanggal } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Riwayat tagihan yang sudah lunas (cash, transfer, atau online); tiap periode bisa dibuka invoice & kuitansinya. */
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
                                    key: 'dokumen', label: 'Invoice & Kuitansi', sortable: false,
                                    render: (r) => (
                                        <div className="d-flex flex-wrap gap-1">
                                            <Link href={`/akun-saya/riwayat/${r.id}/invoice`} className="btn btn-outline-primary btn-sm"><FileText size={13} className="me-1" />Invoice</Link>
                                            <Link href={`/akun-saya/riwayat/${r.id}/kuitansi`} className="btn btn-outline-success btn-sm"><CheckSquare size={13} className="me-1" />Kuitansi</Link>
                                        </div>
                                    ),
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
