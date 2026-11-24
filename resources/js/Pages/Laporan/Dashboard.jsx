import { useState } from 'react';
import { Briefcase, CheckCircle, ChevronRight, Home, Users, XCircle } from 'react-feather';
import { Offcanvas, OffcanvasBody, OffcanvasHeader, Progress } from 'reactstrap';
import DataTable, { sumFooter } from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import PeriodeLaporan, { judulPeriode } from '../../Components/PeriodeLaporan';
import { EmptyState, StatusBadge } from '../../Components/ui';
import { BULAN, angka, persen, rupiah } from '../../lib/format';

const TIPE = {
    reseller: { label: 'Mitra Reseller', ikon: Users, warna: 'primary', satuan: 'mitra' },
    pemerintah: { label: 'Instansi Pemerintah', ikon: Home, warna: 'success', satuan: 'instansi' },
    korporat: { label: 'Korporat', ikon: Briefcase, warna: 'info', satuan: 'korporat' },
};
const LABEL_STATUS = { lunas: 'Lunas', sebagian: 'Bayar Sebagian', belum_lunas: 'Belum Bayar' };
const bulanSingkat = (p) => `${BULAN[Number(p.slice(5, 7)) - 1].slice(0, 3)} ${p.slice(0, 4)}`;

/** Kotak Sudah Bayar / Belum Bayar yang bisa diklik untuk membuka daftar nama. */
function KotakStatus({ jenis, data, satuan, onClick }) {
    const sudah = jenis === 'sudah';
    const Ikon = sudah ? CheckCircle : XCircle;
    return (
        <button type="button" className={`dl-kotak ${sudah ? 'is-sudah' : 'is-belum'}`} onClick={onClick} disabled={!data.jumlah}>
            <span className="dl-kotak-label"><Ikon size={14} />{sudah ? 'Sudah Bayar' : 'Belum Bayar'}</span>
            <span className="dl-kotak-jumlah">{angka(data.jumlah)} <small>{satuan}</small></span>
            <span className="dl-kotak-nominal">{rupiah(data.nominal)}</span>
            <span className="dl-kotak-ket">
                {sudah ? 'tagihan lunas' : `sisa tagihan${data.sebagian ? ` · ${angka(data.sebagian)} bayar sebagian` : ''}`}
            </span>
            {data.jumlah > 0 && <ChevronRight size={16} className="dl-kotak-panah" />}
        </button>
    );
}

/** Persentase uang masuk dari seluruh tagihan (termasuk yang baru dibayar sebagian). */
function BilahMasuk({ data }) {
    return (
        <div className="dl-bilah">
            <div className="d-flex justify-content-between small mb-1">
                <span className="f-light">Tagihan {rupiah(data.omset)}</span>
                <span className="fw-semibold">{persen(data.persen_masuk)} masuk</span>
            </div>
            <Progress value={data.persen_masuk} color={data.persen_masuk >= 100 ? 'success' : data.persen_masuk > 0 ? 'warning' : 'danger'} style={{ height: 6 }} />
        </div>
    );
}

/**
 * Dashboard Laporan untuk direktur & manajemen: berapa Mitra, Instansi Pemerintah, dan Korporat yang sudah bayar (lunas)
 * dan yang belum bayar pada periode terpilih. Klik kotaknya untuk membuka daftar nama dari samping (slide), seperti Income Summary.
 */
export default function DashboardLaporan({ filters, perTipe, total, rows, tahunList }) {
    const [daftar, setDaftar] = useState(null); // { jenis: 'sudah'|'belum', tipe: string|null }
    const subjudul = judulPeriode(filters);
    const isiDaftar = daftar
        ? rows.filter((r) => (!daftar.tipe || r.tipe === daftar.tipe) && (daftar.jenis === 'sudah' ? r.status === 'lunas' : r.status !== 'lunas'))
        : [];
    const buka = (jenis, tipe = null) => setDaftar({ jenis, tipe });
    const judulDaftar = daftar && `${daftar.jenis === 'sudah' ? 'Sudah Bayar' : 'Belum Bayar'} · ${daftar.tipe ? TIPE[daftar.tipe].label : 'Semua Klien'}`;

    return (
        <>
            <PageHeader title="Dashboard Laporan" crumbs={['Laporan']} />
            <div className="container-fluid laporan-hal">
                <div className="laporan-filter d-flex flex-wrap gap-2 mb-3">
                    <PeriodeLaporan filters={filters} tahunList={tahunList} />
                </div>

                {perTipe.length === 0 ? <div className="card"><div className="card-body"><EmptyState text="Anda tidak punya akses ke laporan Mitra, Instansi, atau Korporat" /></div></div> : (
                    <>
                        <div className="card dl-total">
                            <div className="card-body">
                                <div className="dl-judul">
                                    <h5 className="mb-0">Semua Klien</h5>
                                    <small className="f-light">{subjudul} · {angka(total.jumlah)} klien ditagih</small>
                                </div>
                                <div className="dl-kotak-baris">
                                    <KotakStatus jenis="sudah" data={total.sudah} satuan="klien" onClick={() => buka('sudah')} />
                                    <KotakStatus jenis="belum" data={total.belum} satuan="klien" onClick={() => buka('belum')} />
                                </div>
                                <BilahMasuk data={total} />
                            </div>
                        </div>

                        <div className="row">
                            {perTipe.map((t) => {
                                const { label, ikon: Ikon, warna, satuan } = TIPE[t.tipe];
                                return (
                                    <div className="col-xl-4 col-md-6" key={t.tipe}>
                                        <div className="card dl-tipe">
                                            <div className="card-body">
                                                <div className="dl-tipe-kepala">
                                                    <span className={`dl-tipe-ikon bg-light-${warna} font-${warna}`}><Ikon size={18} /></span>
                                                    <div className="min-w-0">
                                                        <h6 className="mb-0">{label}</h6>
                                                        <small className="f-light">{angka(t.jumlah)} {satuan} ditagih · {subjudul}</small>
                                                    </div>
                                                </div>
                                                {t.jumlah === 0 ? <p className="f-light small text-center my-4">Tidak ada tagihan pada periode ini</p> : (
                                                    <>
                                                        <div className="dl-kotak-baris">
                                                            <KotakStatus jenis="sudah" data={t.sudah} satuan={satuan} onClick={() => buka('sudah', t.tipe)} />
                                                            <KotakStatus jenis="belum" data={t.belum} satuan={satuan} onClick={() => buka('belum', t.tipe)} />
                                                        </div>
                                                        <BilahMasuk data={t} />
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>

            <Offcanvas isOpen={!!daftar} toggle={() => setDaftar(null)} direction="end" className="dl-panel" style={{ width: 'min(760px, 100vw)' }}>
                <OffcanvasHeader toggle={() => setDaftar(null)}>
                    {judulDaftar}
                    <small className="d-block f-light fw-normal">{subjudul} · {angka(isiDaftar.length)} klien</small>
                </OffcanvasHeader>
                <OffcanvasBody>
                    {daftar && (
                        <DataTable
                            key={judulDaftar}
                            data={isiDaftar}
                            rowKey="key"
                            exportable={false}
                            emptyText="Tidak ada klien"
                            footer={sumFooter(['omset', 'masuk', 'sisa'], rupiah)}
                            columns={[
                                {
                                    key: 'nama', label: 'Nama', kartu: 'judul',
                                    render: (r) => <>{r.nama}{r.keterangan && <><br /><small className="f-light">{r.keterangan}</small></>}</>,
                                },
                                ...(daftar.tipe ? [] : [{ key: 'tipe', label: 'Tipe', render: (r) => <span className={`badge badge-light-${TIPE[r.tipe].warna}`}>{TIPE[r.tipe].label}</span> }]),
                                { key: 'omset', label: 'Tagihan', align: 'end', render: (r) => rupiah(r.omset) },
                                { key: 'masuk', label: 'Sudah Masuk', align: 'end', render: (r) => <span className="font-success">{rupiah(r.masuk)}</span> },
                                ...(daftar.jenis === 'belum' ? [{ key: 'sisa', label: 'Sisa', align: 'end', render: (r) => <span className="font-danger">{rupiah(r.sisa)}</span> }] : []),
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} text={LABEL_STATUS[r.status]} /> },
                                ...(daftar.jenis === 'belum' && filters.mode === 'tahunan' ? [{
                                    key: 'periode_belum', label: 'Periode Belum Lunas', kartu: 'penuh', sortable: false, nowrap: false,
                                    render: (r) => <span className="d-inline-flex flex-wrap gap-1">{r.periode_belum.map((p) => <span key={p.periode} className={`badge badge-light-${p.status === 'sebagian' ? 'warning' : 'danger'}`}>{bulanSingkat(p.periode)}</span>)}</span>,
                                }] : []),
                            ]}
                        />
                    )}
                </OffcanvasBody>
            </Offcanvas>
        </>
    );
}
