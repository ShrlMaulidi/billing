import { useState } from 'react';
import { ArrowDownCircle, ArrowUpCircle, CheckCircle, DollarSign, Send, Users } from 'react-feather';
import { Card, CardBody, Nav, NavItem, NavLink } from 'reactstrap';
import DataTable, { sumFooter } from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import RingkasanCard from '../../Components/RingkasanCard';
import { StatCard, StatusBadge } from '../../Components/ui';
import { angka, BULAN, METODE, rupiah, tanggal, waktu } from '../../lib/format';

export default function CabangDetail({ filters, lokasi, rekap, pelanggan, pembayaran, pengeluaran }) {
    const [tab, setTab] = useState('pelanggan');
    const tabs = { pelanggan: `Pelanggan (${pelanggan.length})`, pembayaran: `Pembayaran (${pembayaran.length})`, pengeluaran: `Pengeluaran (${pengeluaran.length})` };

    return (
        <>
            <PageHeader title={lokasi.nama_lokasi} crumbs={[{ label: 'Dashboard Cabang', href: '/dashboard-cabang' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div><span className="badge badge-light-primary me-2">{lokasi.kode_lokasi}</span><span className="f-light">{lokasi.alamat_lokasi} · Mitra: {lokasi.nama_mitra}</span></div>
                    <FilterBar filters={filters} fields={['bulan', 'tahun']} />
                </div>
                <div className="row">
                    <div className="col-xxl-4 col-xl-5 order-xl-2">
                        <RingkasanCard row={rekap} subtitle={`${BULAN[filters.bulan - 1]} ${filters.tahun}`} moreHref={`/laporan?bulan=${filters.bulan}&tahun=${filters.tahun}`} />
                    </div>
                    <div className="col-xxl-8 col-xl-7 order-xl-1">
                <div className="row">
                    {[
                        ['Pelanggan Aktif', angka(rekap.jumlah_pelanggan), Users, 'primary', `${rekap.pelanggan_baru} baru bulan ini`],
                        ['Lunas / Belum', `${rekap.lunas} / ${rekap.belum_lunas}`, CheckCircle, 'success'],
                        ['Pemasukan', rupiah(rekap.pemasukan), ArrowDownCircle, 'success'],
                        ['Pengeluaran', rupiah(rekap.pengeluaran), ArrowUpCircle, 'danger'],
                        ['Sisa Harus Setor', rupiah(rekap.sisa_setor), Send, 'warning', `dari ${rupiah(rekap.harus_setor)}`],
                        ['Sisa WD', rupiah(rekap.sisa_wd), DollarSign, 'info', `Pendapatan ${rupiah(rekap.pendapatan_reseller)}`],
                    ].map(([t, v, i, c, s]) => <div className="col-xxl-4 col-sm-6" key={t}><StatCard title={t} value={v} icon={i} color={c} sub={s} /></div>)}
                </div>
                <Card>
                    <CardBody>
                        <Nav tabs className="border-tab mb-3">
                            {Object.entries(tabs).map(([k, l]) => (
                                <NavItem key={k}><NavLink href="#" className={tab === k ? 'active' : ''} onClick={(e) => { e.preventDefault(); setTab(k); }}>{l}</NavLink></NavItem>
                            ))}
                        </Nav>
                        {tab === 'pelanggan' && (
                            <DataTable key="p" data={pelanggan} columns={[
                                { key: 'id_pelanggan', label: 'ID' }, { key: 'nama', label: 'Nama' }, { key: 'paket', label: 'Paket' },
                                { key: 'harga', label: 'Harga', align: 'end', render: (r) => rupiah(r.harga) },
                                { key: 'tgl_registrasi', label: 'Registrasi', render: (r) => tanggal(r.tgl_registrasi) },
                                { key: 'status_langganan', label: 'Internet', render: (r) => <StatusBadge value={r.status_langganan} /> },
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                            ]} />
                        )}
                        {tab === 'pembayaran' && (
                            <DataTable key="b" data={pembayaran} rowKey="no_invoice" footer={sumFooter(['jml_bayar'], rupiah)} columns={[
                                { key: 'no_invoice', label: 'No Invoice' }, { key: 'id_pelanggan', label: 'ID Pelanggan' }, { key: 'nama', label: 'Nama' },
                                { key: 'metode', label: 'Metode', render: (r) => METODE[r.metode] },
                                { key: 'jml_bayar', label: 'Nominal', align: 'end', render: (r) => rupiah(r.jml_bayar) },
                                { key: 'paid_at', label: 'Waktu Bayar', render: (r) => waktu(r.paid_at) }, { key: 'petugas', label: 'Petugas' },
                            ]} />
                        )}
                        {tab === 'pengeluaran' && (
                            <DataTable key="k" data={pengeluaran} footer={sumFooter(['nominal'], rupiah)} columns={[
                                { key: 'jenis_pengeluaran', label: 'Jenis' }, { key: 'tipe', label: 'Tipe', render: (r) => <StatusBadge value={r.tipe === 'wajib_bulanan'} text={r.tipe === 'wajib_bulanan' ? 'Wajib Bulanan' : 'Tidak Wajib'} /> },
                                { key: 'waktu', label: 'Waktu', render: (r) => waktu(r.waktu) },
                                { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) }, { key: 'metode', label: 'Metode' }, { key: 'created_by', label: 'Dicatat Oleh' },
                            ]} />
                        )}
                    </CardBody>
                </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
