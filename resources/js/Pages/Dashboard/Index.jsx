import { Link, router } from '@inertiajs/react';
import Chart from '../../Components/Chart';
import { CheckCircle, DollarSign, MessageCircle, PauseCircle, PlayCircle, Sun, UserX, Users, XCircle } from 'react-feather';
import { Card, CardBody, CardHeader } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import SaldoCard, { RincianSaldo, useSaldoHidden } from '../../Components/SaldoCard';
import { StatCard, StatusBadge } from '../../Components/ui';
import { angka, periode, rupiah, tanggal, waktu } from '../../lib/format';

export default function Dashboard({ filters, kpi, pelangganBaru, wagw, grafik, saldoMitra }) {
    const [saldoHidden, toggleSaldo] = useSaldoHidden();
    // Data Pelanggan & On/Off membuka menu Pelanggan (data asli, ada tombol Tambah); kategori lain ke daftar dashboard
    const MENU_PELANGGAN = { total: '/pelanggan', on: '/pelanggan/on', off: '/pelanggan/off', lunas: '/pelanggan/lunas', belum_lunas: '/pelanggan/belum-lunas' };
    const target = (kategori) => (MENU_PELANGGAN[kategori]
        ? [MENU_PELANGGAN[kategori], { cabang: filters.cabang ?? undefined }]
        : ['/dashboard/pelanggan', { kategori, cabang: filters.cabang ?? undefined }]);
    const go = (kategori) => router.get(...target(kategori));
    const prefetch = (kategori) => { const [url, data] = target(kategori); router.prefetch(url, { data }); };
    const cards = [
        ['Data Pelanggan', angka(kpi.total), Users, 'primary', 'total'],
        ['Pelanggan Sudah Lunas', angka(kpi.lunas), CheckCircle, 'success', 'lunas'],
        ['Pelanggan Belum Lunas', angka(kpi.belum_lunas), XCircle, 'danger', 'belum_lunas'],
        ['Pelanggan On', angka(kpi.on), PlayCircle, 'info', 'on'],
        ['Pelanggan Off', angka(kpi.off), PauseCircle, 'warning', 'off'],
        ['Pelanggan Berhenti', angka(kpi.berhenti), UserX, 'secondary', 'berhenti'],
        ['Pemasukan Bulan Ini', rupiah(kpi.pemasukan_bulan), DollarSign, 'success'],
        ['Pemasukan Hari Ini', rupiah(kpi.pemasukan_hari), Sun, 'primary'],
    ];

    const chart = {
        options: {
            chart: { toolbar: { show: false }, fontFamily: 'Rubik, sans-serif' },
            colors: ['#7366ff'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 3 },
            xaxis: { categories: grafik.map((g) => periode(g.periode).replace(/ \d{4}$/, '').slice(0, 3)) },
            yaxis: { labels: { formatter: (v) => `${(v / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt` } },
            tooltip: { y: { formatter: rupiah } },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
        },
        series: [{ name: 'Pemasukan', data: grafik.map((g) => g.total) }],
    };

    return (
        <>
            <PageHeader title="Dashboard" />
            <div className="container-fluid">
                <div className="d-flex justify-content-end mb-3"><FilterBar filters={filters} fields={['cabang']} /></div>
                {saldoMitra && <SaldoCard saldo={saldoMitra} filters={filters} hidden={saldoHidden} onToggle={toggleSaldo} />}
                <div className="row stat-compact">
                    {cards.map(([title, value, icon, color, kategori]) => (
                        <div className="col-6 col-xl-3" key={title}>
                            <StatCard title={title} value={value} icon={icon} color={color} onClick={kategori ? () => go(kategori) : undefined} onMouseEnter={kategori ? () => prefetch(kategori) : undefined} sub={kategori ? 'Klik untuk lihat daftar' : 'Periode berjalan'} />
                        </div>
                    ))}
                </div>
                {saldoMitra && <RincianSaldo saldo={saldoMitra} hidden={saldoHidden} />}
                <div className="row">
                    <div className="col-xl-8">
                        <Card>
                            <CardHeader className="pb-0"><h5>Pemasukan 12 Bulan Terakhir</h5></CardHeader>
                            <CardBody><Chart type="area" height={300} options={chart.options} series={chart.series} /></CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-4">
                        <Card>
                            <CardHeader className="pb-0 d-flex justify-content-between align-items-center">
                                <h5 className="mb-0"><MessageCircle size={18} className="me-1" />Data WAGW</h5>
                                <Link href="/kelola-data/notifikasi" prefetch className="btn btn-outline-primary btn-sm">Kelola</Link>
                            </CardHeader>
                            <CardBody>
                                {!wagw.devices.length && <p className="f-light text-center mb-0">Belum ada device WhatsApp</p>}
                                {wagw.devices.map((d) => (
                                    <div key={d.id} className="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                                        <div>
                                            <h6 className="mb-0">{d.nama_device}</h6>
                                            <small className="f-light">+{d.nomor} · {d.server_url}</small><br />
                                            <small className="f-light">Terakhir aktif {waktu(d.last_seen)}</small>
                                        </div>
                                        <StatusBadge value={d.status} />
                                    </div>
                                ))}
                                <div className="row text-center mt-3">
                                    {[['Terkirim', wagw.sent, 'success'], ['Outbox', wagw.outbox, 'warning'], ['Gagal', wagw.failed, 'danger']].map(([l, v, c]) => (
                                        <div className="col-4" key={l}><h4 className={`font-${c} mb-0`}>{angka(v)}</h4><small className="f-light">{l} hari ini</small></div>
                                    ))}
                                </div>
                            </CardBody>
                        </Card>
                    </div>
                </div>
                <Card>
                    <CardBody>
                        <DataTable
                            title="Pelanggan Baru Bulan Ini"
                            toolbar={<Link href={`/dashboard/pelanggan?kategori=baru${filters.cabang ? `&cabang=${filters.cabang}` : ''}`} prefetch className="btn btn-outline-primary btn-sm">Lihat Semua</Link>}
                            exportable={false}
                            searchable={false}
                            pageSize={10}
                            data={pelangganBaru}
                            emptyText="Belum ada pelanggan baru bulan ini"
                            columns={[
                                { key: 'id_pelanggan', label: 'ID Pelanggan' },
                                { key: 'nama', label: 'Nama' },
                                { key: 'cabang', label: 'Cabang' },
                                { key: 'paket', label: 'Paket' },
                                { key: 'tgl_registrasi', label: 'Tgl Registrasi', render: (r) => tanggal(r.tgl_registrasi) },
                            ]}
                        />
                    </CardBody>
                </Card>
            </div>
        </>
    );
}
