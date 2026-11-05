import { Deferred } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, CheckCircle, DollarSign, Eye, FileText, MapPin, Percent, TrendingDown, TrendingUp, Users } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Modal, ModalBody, ModalFooter, ModalHeader, Progress } from 'reactstrap';
import Chart from '../../Components/Chart';
import DataTable, { sumFooter } from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import PeriodeLaporan, { judulPeriode } from '../../Components/PeriodeLaporan';
import Select from '../../Components/Select';
import { ActionButton, EmptyState, StatCard, StatusBadge } from '../../Components/ui';
import { BULAN, angka, persen, rupiah } from '../../lib/format';
import { applyFilters } from '../../lib/ui';

const LABEL_TIPE = { reseller: 'Mitra Reseller', pemerintah: 'Instansi Pemerintah', korporat: 'Korporat', retail: 'Shafa.net' };
const WARNA = { reseller: 'primary', korporat: 'info', pemerintah: 'success', retail: 'warning' };
/** Warna grafik per tipe (palet kategori tervalidasi buta warna; urutan tetap, warna mengikuti tipe, bukan peringkat). */
const WARNA_GRAFIK = { reseller: '#2a78d6', pemerintah: '#eb6834', korporat: '#1baf7a', retail: '#eda100' };
const WARNA_MASUK = '#2a78d6';
const WARNA_OMSET = '#eb6834';
const juta = (v) => `${(v / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
const STATUS = [{ value: 'lunas', label: 'Lunas' }, { value: 'sebagian', label: 'Bayar Sebagian' }, { value: 'belum_lunas', label: 'Belum Bayar' }];
const LABEL_STATUS = Object.fromEntries(STATUS.map((s) => [s.value, s.label]));
const UANG = ['omset', 'sebelum_pajak', 'pajak', 'masuk', 'sisa'];
const JUMLAH = ['jumlah', 'lunas', 'sebagian', 'belum_lunas'];

const bulanSingkat = (p) => `${BULAN[Number(p.slice(5, 7)) - 1].slice(0, 3)} ${p.slice(0, 4)}`;
const tipeBadge = (tipe) => <span className={`badge badge-light-${WARNA[tipe] ?? 'secondary'}`}>{LABEL_TIPE[tipe] ?? tipe}</span>;
const statusBadge = (status) => <StatusBadge value={status} text={LABEL_STATUS[status]} />;

function PersenMasuk({ value }) {
    return (
        <div style={{ minWidth: 110 }}>
            <div className="small text-end">{persen(value)}</div>
            <Progress value={value} color={value >= 100 ? 'success' : value > 0 ? 'warning' : 'danger'} style={{ height: 6 }} />
        </div>
    );
}

/** Tren pembayaran masuk & total omset per bulan, dengan perubahan dibanding bulan sebelumnya. */
function GrafikTren({ tren }) {
    if (!tren.length) return <EmptyState text="Belum ada bulan yang berjalan pada periode ini" />;
    const bulan = tren.map((t) => bulanSingkat(t.periode));
    const [sebelum, terakhir] = tren.slice(-2).map((t) => t.masuk);
    const selisih = tren.length > 1 && sebelum > 0 ? ((terakhir - sebelum) / sebelum) * 100 : null;
    const naik = selisih !== null && selisih >= 0;
    return (
        <>
            {selisih !== null && (
                <div className={`d-flex align-items-center gap-1 mb-2 ${naik ? 'font-success' : 'font-danger'}`}>
                    {naik ? <TrendingUp size={16} /> : <TrendingDown size={16} />}
                    <span className="fw-semibold">{naik ? 'Naik' : 'Turun'} {persen(Math.abs(selisih))}</span>
                    <span className="f-light">pembayaran masuk {bulan[bulan.length - 1]} dibanding {bulan[bulan.length - 2]}</span>
                </div>
            )}
            <Chart type="bar" height={300} series={[
                { name: 'Pembayaran Masuk', data: tren.map((t) => t.masuk) },
                { name: 'Total Omset', data: tren.map((t) => t.omset) },
            ]} options={{
                chart: { toolbar: { show: false }, fontFamily: 'Rubik, sans-serif', zoom: { enabled: false } },
                colors: [WARNA_MASUK, WARNA_OMSET],
                // Batang ramping, ujung atas membulat 4px, jarak 2px antar batang berdampingan
                plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
                stroke: { show: true, width: 2, colors: ['transparent'] },
                dataLabels: { enabled: false },
                legend: { position: 'top', horizontalAlign: 'left' },
                grid: { borderColor: 'rgba(0,0,0,.06)', strokeDashArray: 3 },
                xaxis: { categories: bulan, tooltip: { enabled: false } },
                yaxis: { labels: { formatter: juta } },
                tooltip: { shared: true, intersect: false, y: { formatter: rupiah } },
            }} />
        </>
    );
}

/** Komposisi pembayaran masuk per tipe pelanggan pada periode terpilih. */
function GrafikTipe({ perTipe }) {
    const total = perTipe.reduce((t, r) => t + r.masuk, 0);
    if (total === 0) return <EmptyState text="Belum ada pembayaran masuk pada periode ini" />;
    return (
        <Chart type="donut" height={300} series={perTipe.map((r) => r.masuk)} options={{
            chart: { fontFamily: 'Rubik, sans-serif' },
            labels: perTipe.map((r) => LABEL_TIPE[r.tipe]),
            colors: perTipe.map((r) => WARNA_GRAFIK[r.tipe]),
            stroke: { width: 2, colors: ['#fff'] },
            legend: { position: 'bottom' },
            dataLabels: { enabled: true, formatter: (v) => `${Math.round(v)}%`, dropShadow: { enabled: false } },
            tooltip: { y: { formatter: rupiah } },
            plotOptions: { pie: { donut: { size: '62%', labels: { show: true, total: { show: true, label: 'Total Masuk', formatter: () => rupiah(total) }, value: { formatter: (v) => rupiah(Number(v)) } } } } },
        }} />
    );
}

const uang = (key, label) => ({ key, label, align: 'end', render: (r) => rupiah(r[key]) });

/** Saldo mitra: plus = hak mitra yang masih dipegang MSS (bisa ditarik), minus = mitra masih harus setor ke MSS. */
const saldo = (nilai) => (
    <span className={nilai < 0 ? 'font-danger' : 'font-success'} title={nilai < 0 ? 'Mitra masih harus setor ke MSS' : 'Hak mitra yang masih di MSS'}>{rupiah(nilai)}</span>
);

/**
 * Laporan keuangan perusahaan untuk direktur & manajer: omset, pajak, pembayaran masuk, sisa, persentase,
 * dan status bayar tiap pelanggan (periode yang belum lunas / baru sebagian), per bulan atau per tahun.
 */
export default function Laporan({ filters, judul, periode, ringkasan, perTipe, rows, tipeOptions, tahunList, perLokasi, lokasi, tren }) {
    const [detail, setDetail] = useState(null);
    const semuaTipe = perTipe.length > 1;
    const subjudul = judulPeriode(filters);
    // Shafa.net: perbandingan lokasi dulu; pelanggan tampil setelah satu lokasi dipilih
    const pilihLokasi = perLokasi && !lokasi;
    // Laporan Mitra (hanya tipe Mitra Reseller): tampilkan saldo / balance tiap mitra
    const tampilSaldo = perTipe.length === 1 && perTipe[0].tipe === 'reseller';
    const bukaLokasi = (id) => applyFilters({ ...filters, lokasi: id, status: '' });

    return (
        <>
            <PageHeader title={lokasi ? `${judul} — ${lokasi.nama_lokasi}` : judul} crumbs={['Laporan']} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3">
                    {lokasi && (
                        <Button color="light" onClick={() => applyFilters({ ...filters, lokasi: '', status: '' })}><ArrowLeft size={14} className="me-1" />Semua Lokasi</Button>
                    )}
                    <PeriodeLaporan filters={filters} tahunList={tahunList} />
                    {tipeOptions.length > 1 && (
                        <Select ariaLabel="Tipe pelanggan" style={{ width: 200 }} value={filters.tipe} emptyLabel="Semua Tipe" options={tipeOptions}
                            onChange={(v) => applyFilters({ ...filters, tipe: v })} />
                    )}
                    {!pilihLokasi && (
                        <Select ariaLabel="Status pembayaran" style={{ width: 180 }} value={filters.status} emptyLabel="Semua Status" options={STATUS}
                            onChange={(v) => applyFilters({ ...filters, status: v })} />
                    )}
                </div>

                <div className="row">
                    {[
                        ['Total Omset Perusahaan', ringkasan.omset, TrendingUp, 'primary', 'Seluruh tagihan, termasuk pajak'],
                        ['Omset Sebelum Pajak', ringkasan.sebelum_pajak, FileText, 'info', 'Total omset dikurangi pajak'],
                        ['Total Pajak', ringkasan.pajak, Percent, 'secondary', 'PPN & PPh'],
                        ['Pembayaran Masuk', ringkasan.masuk, CheckCircle, 'success', `${persen(ringkasan.persen_masuk)} dari total omset`],
                        ['Sisa Pembayaran', ringkasan.sisa, DollarSign, 'danger', `${persen(ringkasan.persen_belum)} belum masuk`],
                    ].map(([t, v, i, c, s]) => <div className="col-xxl-4 col-md-6" key={t}><StatCard title={t} value={rupiah(v)} icon={i} color={c} sub={s} /></div>)}
                    <div className="col-xxl-4 col-md-6">
                        <div className="card mb-4">
                            <div className="card-body">
                                <div className="d-flex justify-content-between mb-2">
                                    <span className="f-light">Sudah Masuk</span>
                                    <span className="f-light">Belum Masuk</span>
                                </div>
                                <div className="d-flex justify-content-between align-items-end mb-2">
                                    <h4 className="mb-0 font-success">{persen(ringkasan.persen_masuk)}</h4>
                                    <h4 className="mb-0 font-danger">{persen(ringkasan.persen_belum)}</h4>
                                </div>
                                <Progress multi style={{ height: 10 }}>
                                    <Progress bar color="success" value={ringkasan.persen_masuk} />
                                    <Progress bar color="danger" value={ringkasan.persen_belum} />
                                </Progress>
                                <small className="f-light d-block mt-2">
                                    {angka(ringkasan.lunas)} lunas · {angka(ringkasan.sebagian)} bayar sebagian · {angka(ringkasan.belum_lunas)} belum bayar
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                {tren !== null && (
                    <div className="row">
                        <div className={semuaTipe ? 'col-xl-8' : 'col-12'}>
                            <Card>
                                <CardHeader className="pb-0"><h5 className="mb-0">Tren Pemasukan per Bulan</h5></CardHeader>
                                <CardBody>
                                    <Deferred data="tren" fallback={<div className="skeleton" style={{ height: 320 }} />}>
                                        <GrafikTren tren={tren ?? []} />
                                    </Deferred>
                                </CardBody>
                            </Card>
                        </div>
                        {semuaTipe && (
                            <div className="col-xl-4">
                                <Card>
                                    <CardHeader className="pb-0"><h5 className="mb-0">Pemasukan per Tipe — {subjudul}</h5></CardHeader>
                                    <CardBody><GrafikTipe perTipe={perTipe} /></CardBody>
                                </Card>
                            </div>
                        )}
                    </div>
                )}

                {semuaTipe && (
                    <Card>
                        <CardBody>
                            <h5 className="mb-3">Ringkasan per Tipe Pelanggan — {subjudul}</h5>
                            <DataTable
                                data={perTipe}
                                rowKey="tipe"
                                searchable={false}
                                exportable={false}
                                footer={sumFooter(UANG, rupiah)}
                                columns={[
                                    { key: 'tipe', label: 'Tipe Pelanggan', render: (r) => tipeBadge(r.tipe) },
                                    { key: 'jumlah', label: 'Pelanggan', align: 'end', render: (r) => angka(r.jumlah) },
                                    uang('omset', 'Total Omset'), uang('sebelum_pajak', 'Sebelum Pajak'), uang('pajak', 'Pajak'),
                                    uang('masuk', 'Pembayaran Masuk'), uang('sisa', 'Sisa Pembayaran'),
                                    { key: 'persen_masuk', label: '% Masuk', render: (r) => <PersenMasuk value={r.persen_masuk} /> },
                                ]}
                            />
                        </CardBody>
                    </Card>
                )}

                {pilihLokasi && (
                    <Card>
                        <CardBody>
                            <h5 className="mb-1">Perbandingan Lokasi Shafa.net — {subjudul}</h5>
                            <p className="f-light mb-3">Klik salah satu lokasi untuk melihat status pembayaran pelanggannya.</p>
                            <DataTable
                                data={perLokasi}
                                pageSize={25}
                                stickyFirst
                                footer={(col, data) => (UANG.includes(col.key) ? sumFooter(UANG, rupiah)(col, data) : sumFooter(JUMLAH, angka)(col, data))}
                                onRowClick={(r) => bukaLokasi(r.id)}
                                emptyText="Belum ada lokasi Shafa.net"
                                actions={(r) => <ActionButton icon={Users} label={`Lihat Pelanggan ${r.nama_lokasi}`} onClick={(e) => { e.stopPropagation(); bukaLokasi(r.id); }} />}
                                columns={[
                                    {
                                        key: 'nama_lokasi', label: 'Lokasi',
                                        render: (r) => <span className="d-inline-flex align-items-center gap-1"><MapPin size={14} className="font-primary" /><span className="fw-semibold">{r.nama_lokasi}</span><small className="f-light">{r.kode_lokasi}</small></span>,
                                    },
                                    { key: 'jumlah', label: 'Pelanggan', align: 'end', render: (r) => angka(r.jumlah) },
                                    uang('omset', 'Total Omset'), uang('sebelum_pajak', 'Sebelum Pajak'), uang('pajak', 'Pajak'),
                                    uang('masuk', 'Pembayaran Masuk'), uang('sisa', 'Sisa Pembayaran'),
                                    { key: 'persen_masuk', label: '% Masuk', render: (r) => <PersenMasuk value={r.persen_masuk} /> },
                                    { key: 'lunas', label: 'Lunas', align: 'end', render: (r) => <span className="font-success">{angka(r.lunas)}</span> },
                                    { key: 'sebagian', label: 'Sebagian', align: 'end', render: (r) => <span className="font-warning">{angka(r.sebagian)}</span> },
                                    { key: 'belum_lunas', label: 'Belum Bayar', align: 'end', render: (r) => <span className="font-danger">{angka(r.belum_lunas)}</span> },
                                ]}
                            />
                        </CardBody>
                    </Card>
                )}

                {!pilihLokasi && (
                <Card>
                    <CardBody>
                        <h5 className="mb-3">Status Pembayaran Pelanggan{lokasi ? ` ${lokasi.nama_lokasi}` : ''} — {subjudul}</h5>
                        <DataTable
                            data={rows}
                            rowKey="key"
                            pageSize={25}
                            stickyFirst
                            footer={(col, data) => (col.key === 'saldo' ? saldo(data.reduce((t, r) => t + r.saldo, 0)) : sumFooter(UANG, rupiah)(col, data))}
                            emptyText="Tidak ada tagihan pada periode ini"
                            actions={(r) => <ActionButton icon={Eye} label="Rincian per Periode" color="info" onClick={() => setDetail(r)} />}
                            columns={[
                                { key: 'nama', label: 'Nama Pelanggan', render: (r) => <>{r.nama}{r.keterangan && <><br /><small className="f-light">{r.keterangan}</small></>}</> },
                                ...(semuaTipe ? [{ key: 'tipe', label: 'Tipe', sortValue: (r) => LABEL_TIPE[r.tipe], render: (r) => tipeBadge(r.tipe) }] : []),
                                uang('omset', 'Total Tagihan'), uang('pajak', 'Pajak'), uang('masuk', 'Sudah Masuk'), uang('sisa', 'Sisa'),
                                ...(tampilSaldo ? [{ key: 'saldo', label: 'Saldo Mitra', align: 'end', render: (r) => saldo(r.saldo) }] : []),
                                { key: 'persen_masuk', label: '% Masuk', render: (r) => <PersenMasuk value={r.persen_masuk} /> },
                                { key: 'status', label: 'Status', sortValue: (r) => STATUS.findIndex((s) => s.value === r.status), render: (r) => statusBadge(r.status) },
                                {
                                    key: 'periode_belum', label: 'Periode Belum Lunas', sortValue: (r) => r.periode_belum.length,
                                    render: (r) => (r.periode_belum.length === 0 ? '-' : (
                                        <div className="d-flex flex-wrap gap-1" style={{ maxWidth: 260 }}>
                                            {r.periode_belum.map((p) => (
                                                <span key={p.periode} className={`badge badge-light-${p.status === 'sebagian' ? 'warning' : 'danger'}`} title={`Sisa ${rupiah(p.sisa)}`}>
                                                    {bulanSingkat(p.periode)}{p.status === 'sebagian' ? ' (sebagian)' : ''}
                                                </span>
                                            ))}
                                        </div>
                                    )),
                                },
                            ]}
                        />
                        {tampilSaldo && (
                            <small className="f-light d-block mt-2">
                                Saldo Mitra = pendapatan reseller − pemasukan via mitra + sudah setor − sudah WD (sama dengan Dashboard Cabang).
                                <span className="font-success"> Plus</span>: hak mitra yang masih di MSS dan bisa ditarik. <span className="font-danger">Minus</span>: mitra masih harus setor ke MSS.
                            </small>
                        )}
                        <small className="f-light d-block mt-2">
                            Mitra Reseller: kewajiban setor ke MSS (PPN, admin fee, bagi hasil, PPh) dari pembayaran pelanggan mitra. Shafa.net: tagihan paket pelanggan end user.
                            Korporat &amp; Instansi Pemerintah: harga langganan per bulan + PPN. {filters.mode === 'tahunan' ? `Mode tahunan: Januari sampai ${periode.length ? BULAN[Number(periode[periode.length - 1].slice(5, 7)) - 1] : '-'} ${filters.tahun}.` : ''}
                        </small>
                    </CardBody>
                </Card>
                )}
            </div>

            <Modal isOpen={!!detail} toggle={() => setDetail(null)} centered scrollable size="lg">
                <ModalHeader toggle={() => setDetail(null)}>Rincian Pembayaran — {detail?.nama}</ModalHeader>
                <ModalBody>
                    {detail && (
                        <>
                            <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                                {tipeBadge(detail.tipe)} {statusBadge(detail.status)}
                                {detail.keterangan && <small className="f-light">{detail.keterangan}</small>}
                            </div>
                            <div className="table-responsive">
                                <table className="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Periode</th><th className="text-end">Tagihan</th><th className="text-end">Pajak</th><th className="text-end">Sudah Masuk</th><th className="text-end">Sisa</th>
                                            {detail.saldo !== null && <th className="text-end">Saldo Mitra</th>}<th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detail.periode.map((p) => (
                                            <tr key={p.periode}>
                                                <td>{BULAN[Number(p.periode.slice(5, 7)) - 1]} {p.periode.slice(0, 4)}</td>
                                                <td className="text-end">{rupiah(p.tagihan)}</td>
                                                <td className="text-end">{rupiah(p.pajak)}</td>
                                                <td className="text-end">{rupiah(p.masuk)}</td>
                                                <td className={`text-end ${p.sisa > 0 ? 'font-danger' : ''}`}>{rupiah(p.sisa)}</td>
                                                {detail.saldo !== null && <td className="text-end">{saldo(p.saldo)}</td>}
                                                <td>{statusBadge(p.status)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="fw-semibold">
                                            <td>Total</td>
                                            <td className="text-end">{rupiah(detail.omset)}</td>
                                            <td className="text-end">{rupiah(detail.pajak)}</td>
                                            <td className="text-end">{rupiah(detail.masuk)}</td>
                                            <td className="text-end">{rupiah(detail.sisa)}</td>
                                            {detail.saldo !== null && <td className="text-end">{saldo(detail.saldo)}</td>}
                                            <td>{persen(detail.persen_masuk)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </>
                    )}
                </ModalBody>
                <ModalFooter><Button color="light" onClick={() => setDetail(null)}>Tutup</Button></ModalFooter>
            </Modal>
        </>
    );
}
