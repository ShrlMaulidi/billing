import { useState } from 'react';
import Chart from '../../Components/Chart';
import { CreditCard, DollarSign, Layers, Smartphone, Zap } from 'react-feather';
import { Card, CardBody, CardHeader, Offcanvas, OffcanvasBody, OffcanvasHeader } from 'reactstrap';
import DataTable, { sumFooter } from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import { StatCard } from '../../Components/ui';
import { angka, METODE, periode, rupiah, waktu } from '../../lib/format';

const ICON = { cash: DollarSign, transfer: CreditCard, tripay: Zap, xendit: Layers, pakar_digital: Smartphone };
const COLOR = { cash: 'success', transfer: 'primary', tripay: 'warning', xendit: 'info', pakar_digital: 'secondary' };

export default function IncomeSummary({ filters, total, metode, rekening, pembayaran }) {
    const [drawer, setDrawer] = useState(null); // { metode, rekening? , title }
    const list = drawer ? pembayaran.filter((p) => (drawer.metode === 'all' || p.metode === drawer.metode) && (!drawer.rekening || p.rekening === drawer.rekening)) : [];

    return (
        <>
            <PageHeader title="Income Summary" />
            <div className="container-fluid">
                <div className="d-flex justify-content-end mb-3"><FilterBar filters={filters} /></div>
                <div className="row">
                    <div className="col-xl-4">
                        <div className="card bg-primary text-white" style={{ cursor: 'pointer' }} onClick={() => setDrawer({ metode: 'all', title: 'Semua Metode' })}>
                            <div className="card-body">
                                <span>Total Income Summary · {periode(`${filters.tahun}-${String(filters.bulan).padStart(2, '0')}-01`)}</span>
                                <h2 className="text-white my-2">{rupiah(total.total)}</h2>
                                <span>{angka(total.jumlah)} transaksi</span>
                            </div>
                        </div>
                        <Card>
                            <CardHeader className="pb-0"><h5>Komposisi Metode</h5></CardHeader>
                            <CardBody>
                                <Chart type="donut" height={260} series={metode.map((m) => m.total)} options={{
                                    labels: metode.map((m) => METODE[m.metode]), legend: { position: 'bottom' },
                                    colors: ['#54ba4a', '#7366ff', '#ffaa05', '#16c7f9', '#f73164'], tooltip: { y: { formatter: rupiah } }, dataLabels: { enabled: false },
                                }} />
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-8">
                        <div className="row">
                            {metode.map((m) => (
                                <div className="col-md-6" key={m.metode}>
                                    <StatCard title={METODE[m.metode]} value={rupiah(m.total)} sub={`${angka(m.jumlah)} transaksi · klik untuk detail`} icon={ICON[m.metode]} color={COLOR[m.metode]}
                                        onClick={() => setDrawer({ metode: m.metode, title: METODE[m.metode] })} />
                                </div>
                            ))}
                        </div>
                        <Card>
                            <CardHeader className="pb-0"><h5>Transfer Bank per Rekening</h5></CardHeader>
                            <CardBody>
                                <DataTable data={rekening} rowKey="rekening" exportable={false} searchable={false} emptyText="Belum ada transfer bulan ini"
                                    onRowClick={(r) => setDrawer({ metode: 'transfer', rekening: r.rekening, title: r.rekening })}
                                    footer={sumFooter(['total', 'jumlah'], angka)}
                                    columns={[
                                        { key: 'rekening', label: 'Rekening' },
                                        { key: 'jumlah', label: 'Transaksi', align: 'end', render: (r) => angka(r.jumlah) },
                                        { key: 'total', label: 'Nominal', align: 'end', render: (r) => rupiah(r.total) },
                                    ]} />
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>

            <Offcanvas isOpen={!!drawer} toggle={() => setDrawer(null)} direction="end" style={{ width: 'min(1000px, 100vw)' }}>
                <OffcanvasHeader toggle={() => setDrawer(null)}>Pembayaran via {drawer?.title}</OffcanvasHeader>
                <OffcanvasBody>
                    {drawer && (
                        <DataTable key={drawer.title} data={list} rowKey="no_invoice" footer={sumFooter(['jml_bayar'], rupiah)} columns={[
                            { key: 'no_invoice', label: 'No Invoice' }, { key: 'id_pelanggan', label: 'ID Pelanggan' }, { key: 'nama', label: 'Nama' },
                            { key: 'cabang', label: 'Cabang' }, { key: 'periode', label: 'Periode', render: (r) => periode(r.periode) },
                            { key: 'jml_bayar', label: 'Nominal', align: 'end', render: (r) => rupiah(r.jml_bayar) },
                            { key: 'paid_at', label: 'Waktu Bayar', render: (r) => waktu(r.paid_at) },
                            { key: 'petugas', label: 'Petugas / Channel', render: (r) => r.reference_pg ? `${r.petugas} · ${r.reference_pg}` : r.petugas },
                        ]} />
                    )}
                </OffcanvasBody>
            </Offcanvas>
        </>
    );
}
