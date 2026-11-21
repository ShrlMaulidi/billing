import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertCircle, CheckCircle, CreditCard, Eye, FileText, LifeBuoy, MessageCircle, RefreshCw } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import PageHeader from '../../Components/PageHeader';
import { bayarOnline, linkWa } from '../../Components/TagihanPelanggan';
import { Avatar, StatCard, StatusBadge } from '../../Components/ui';
import { periode, rupiah, tanggal } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

/**
 * Tombol Bayar Sekarang satu invoice. Cabang dengan pembayaran online: langsung ke halaman pembayaran Midtrans
 * (atau lanjutkan pembayaran yang menunggu dibayar, dengan opsi ganti metode). Selain itu tampilkan cara bayar manual.
 */
function TombolBayar({ invoice, langganan, onManual, block = false, size }) {
    const [proses, setProses] = useState(null);
    const [galat, setGalat] = useState('');
    const berjalan = langganan.bayar_online ? invoice.online_berjalan : null;

    const bayar = async (ganti = false) => {
        if (!langganan.bayar_online) {
            onManual(invoice, langganan);
            return;
        }
        if (ganti && !window.confirm(`Pembayaran ${berjalan?.metode ?? 'sebelumnya'} akan dibatalkan dan tidak bisa dipakai lagi. Lanjut memilih metode pembayaran lain?`)) return;
        setProses(ganti ? 'ganti' : 'bayar');
        setGalat('');
        try {
            await bayarOnline(langganan.id_pelanggan, invoice.periode, ganti);
        } catch (pesan) {
            setGalat(pesan);
            setProses(null);
        }
    };

    return (
        <div className={block ? 'd-grid gap-1' : 'd-inline-flex flex-column align-items-end gap-1'}>
            <Button color="primary" size={size} onClick={() => bayar()} disabled={!!proses}>
                {proses === 'bayar' ? <Spinner size="sm" className="me-1" /> : <CreditCard size={15} className="me-1" />}
                {proses === 'bayar' ? 'Menyiapkan…' : berjalan ? `Lanjutkan Pembayaran${berjalan.metode ? ` (${berjalan.metode})` : ''}` : 'Bayar Sekarang'}
            </Button>
            {berjalan && (
                <button type="button" className="btn btn-link btn-sm p-0" onClick={() => bayar(true)} disabled={!!proses}>
                    <RefreshCw size={12} className="me-1" />{proses === 'ganti' ? 'Membatalkan…' : 'Ganti metode pembayaran'}
                </button>
            )}
            {galat && <small className="text-danger" role="alert">{galat}</small>}
        </div>
    );
}

/** Selisih hari dari hari ini ke jatuh tempo (tanggal Y-m-d dari server), sebagai teks & warna. */
function sisaJatuhTempo(jatuhTempo, hariIni) {
    if (!jatuhTempo) return null;
    const hari = Math.round((Date.parse(jatuhTempo) - Date.parse(hariIni)) / 86400000);
    if (hari > 0) return { teks: `${hari} hari lagi`, warna: hari <= 3 ? 'text-warning' : 'f-light' };
    if (hari === 0) return { teks: 'jatuh tempo hari ini', warna: 'text-danger' };
    return { teks: `lewat ${-hari} hari`, warna: 'text-danger' };
}

/**
 * Kartu paling atas Home: tagihan bulan berjalan satu langganan dengan Bayar Sekarang & Lihat Detail.
 * Sudah lunas → tanda lunas + kuitansi; belum terbit → keterangan. Tunggakan bulan sebelumnya diingatkan di bawahnya.
 */
function TagihanBulanIni({ langganan: p, bulanIni, hariIni, tampilLangganan, onDetail, onManual }) {
    const t = p.tagihan.find((x) => x.periode_pembayaran === bulanIni);
    const lunas = p.lunas.find((x) => x.periode_pembayaran === bulanIni);
    const tunggakan = p.tagihan.filter((x) => x.periode_pembayaran < bulanIni);
    const sisa = t && sisaJatuhTempo(t.jatuh_tempo, hariIni);
    const status = t ? 'is-due' : lunas ? 'is-paid' : '';

    return (
        <Card className={`portal-hero ${status}`}>
            <CardBody>
                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <span className="portal-hero-label">Tagihan Bulan Ini · {periode(bulanIni)}</span>
                        {tampilLangganan && <div className="small f-light">{p.paket ?? 'Langganan'} · ID {p.id_pelanggan ?? '-'}</div>}
                    </div>
                    {t && <StatusBadge value="belum_lunas" text="Belum Lunas" />}
                    {!t && lunas && <StatusBadge value="lunas" text="Lunas" />}
                </div>

                {t && (
                    <div className="d-flex flex-wrap justify-content-between align-items-end gap-3">
                        <div>
                            <div className="portal-hero-nominal">{rupiah(t.nominal)}</div>
                            <div className="small">
                                {p.paket ?? '-'}{t.is_prorate ? ' · prorate' : ''}
                                {t.jatuh_tempo && <> · jatuh tempo {tanggal(t.jatuh_tempo)} <span className={`fw-semibold ${sisa.warna}`}>({sisa.teks})</span></>}
                            </div>
                        </div>
                        <div className="portal-hero-aksi">
                            <Button color="primary" outline size="lg" onClick={() => onDetail(t, p)}><Eye size={16} className="me-1" />Lihat Detail</Button>
                            <TombolBayar invoice={t} langganan={p} onManual={onManual} size="lg" />
                        </div>
                    </div>
                )}

                {!t && lunas && (
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div className="d-flex align-items-center gap-2 text-success">
                            <CheckCircle size={28} />
                            <div><div className="fw-bold fs-5">Sudah lunas</div><small>{rupiah(lunas.jml_bayar)} · dibayar {tanggal(lunas.paid_at)} via {lunas.metode_label}</small></div>
                        </div>
                        <Link href={`/akun-saya/riwayat/${lunas.id}/kuitansi`} className="btn btn-outline-success"><FileText size={15} className="me-1" />Lihat Kuitansi</Link>
                    </div>
                )}

                {!t && !lunas && <p className="mb-0 f-light">Belum ada tagihan untuk bulan ini.</p>}

                {tunggakan.length > 0 && (
                    <div className="alert alert-light-danger portal-alert mt-3 mb-0">
                        <AlertCircle size={18} />
                        <span className="flex-grow-1">Masih ada <strong>{tunggakan.length} tagihan bulan sebelumnya</strong> ({rupiah(tunggakan.reduce((s, x) => s + (x.nominal ?? 0), 0))}) yang belum dibayar.</span>
                        <a href="#semua-tagihan" className="fw-semibold text-nowrap">Lihat semua</a>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}

/** Satu langganan: ringkasan + daftar invoice belum dibayar, masing-masing dengan Bayar Sekarang & Lihat Detail. */
function KartuTagihan({ langganan: p, onDetail, onManual }) {
    const total = p.tagihan.reduce((s, t) => s + (t.nominal ?? 0), 0);

    return (
        <Card>
            <CardHeader className="pb-0 d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h5 className="mb-1">{p.paket ?? 'Langganan Internet'}</h5>
                    <span className="f-light">ID {p.id_pelanggan ?? '-'} · {p.nama} · {JENIS[p.jns_tagihan] ?? 'Prabayar'}{p.cabang?.nama_lokasi ? ` · ${p.cabang.nama_lokasi}` : ''}</span>
                </div>
                <StatusBadge value={p.tagihan.length ? 'belum_lunas' : 'lunas'} text={p.tagihan.length ? 'Belum Lunas' : 'Lunas'} />
            </CardHeader>
            <CardBody>
                {p.tagihan.length ? (
                    <>
                        <div className="portal-total mb-3">
                            <span>Total tagihan ({p.tagihan.length} bulan)</span>
                            <strong>{rupiah(total)}</strong>
                        </div>
                        <ul className="portal-invoice-list">
                            {p.tagihan.map((t) => (
                                <li key={t.no_tagihan}>
                                    <div className="portal-invoice-info">
                                        <strong>Tagihan {periode(t.periode_pembayaran)}</strong>
                                        <span className="f-light small">No. {t.no_tagihan}{t.jatuh_tempo ? ` · jatuh tempo ${tanggal(t.jatuh_tempo)}` : ''}{t.is_prorate ? ' · prorate' : ''}</span>
                                        <span className="fw-bold">{rupiah(t.nominal)}</span>
                                    </div>
                                    <div className="portal-invoice-aksi">
                                        <Button color="primary" outline onClick={() => onDetail(t, p)}><Eye size={15} className="me-1" />Lihat Detail</Button>
                                        <TombolBayar invoice={t} langganan={p} onManual={onManual} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                        {p.bayar_online && <p className="f-light small mb-0 mt-3">Bayar lewat transfer bank (VA), QRIS, atau e-wallet. Status lunas otomatis setelah pembayaran berhasil.</p>}
                    </>
                ) : (
                    <div className="d-flex align-items-center gap-2 text-success">
                        <CheckCircle size={20} /><span>Tidak ada tagihan yang belum dibayar. Terima kasih telah membayar tepat waktu.</span>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}

/** Home dashboard pelanggan: yang pertama tampil adalah tagihan bulan berjalan beserta Bayar Sekarang & Lihat Detail. */
export default function Home({ hasil, tiketAktif, bulanIni, hariIni }) {
    const { auth } = usePage().props;
    const [detail, setDetail] = useState(null); // { invoice, langganan }
    const [manual, setManual] = useState(null); // { invoice, langganan }
    const semuaTagihan = hasil.flatMap((p) => p.tagihan);
    const total = semuaTagihan.reduce((s, t) => s + (t.nominal ?? 0), 0);
    const bukaManual = (invoice, langganan) => { setDetail(null); setManual({ invoice, langganan }); };

    return (
        <>
            <PageHeader title="Home" home="/akun-saya" />
            <div className="container-fluid">
                {/* Sapaan kecil: memastikan pelanggan masuk dengan akun yang benar */}
                <div className="portal-sapa">
                    <Avatar name={auth.user.nama} size={26} />
                    <span>Halo, <strong>{auth.user.nama}</strong></span>
                    <span className="f-light">· masuk sebagai {auth.user.username}</span>
                </div>
                {hasil.map((p) => (
                    <TagihanBulanIni key={p.id_pelanggan ?? p.nama} langganan={p} bulanIni={bulanIni} hariIni={hariIni} tampilLangganan={hasil.length > 1}
                        onDetail={(invoice, langganan) => setDetail({ invoice, langganan })} onManual={bukaManual} />
                ))}

                <div className="row">
                    <div className="col-sm-6 col-xl-4">
                        <StatCard title="Total Tagihan" value={rupiah(total)} sub={`${semuaTagihan.length} invoice belum dibayar`} icon={semuaTagihan.length ? AlertCircle : CheckCircle} color={semuaTagihan.length ? 'danger' : 'success'} />
                    </div>
                    <div className="col-sm-6 col-xl-4">
                        <StatCard title="Tiket Aktif" value={tiketAktif} sub="Keluhan yang sedang ditangani" icon={LifeBuoy} color="warning" onClick={() => router.visit('/akun-saya/tiket')} />
                    </div>
                    <div className="col-sm-12 col-xl-4">
                        <StatCard title="Riwayat Tagihan" value="Lihat" sub="Pembayaran & kwitansi" icon={FileText} color="primary" onClick={() => router.visit('/akun-saya/riwayat')} />
                    </div>
                </div>

                {hasil.length === 0 && (
                    <Card>
                        <CardBody className="text-center py-5">
                            <h5>Belum ada langganan aktif</h5>
                            <p className="f-light">Akun ini belum terhubung ke langganan internet yang aktif.</p>
                            <a href={linkWa(`Halo MSS, akun pelanggan saya (${auth.user.username}) belum menampilkan langganan.`)} target="_blank" rel="noopener noreferrer" className="btn btn-primary">
                                <MessageCircle size={15} className="me-1" />Hubungi Kami
                            </a>
                        </CardBody>
                    </Card>
                )}

                {/* Daftar lengkap hanya bila ada tagihan selain bulan ini (tunggakan); tagihan bulan ini sudah di kartu atas */}
                <div id="semua-tagihan">
                    {hasil.filter((p) => p.tagihan.some((t) => t.periode_pembayaran !== bulanIni)).map((p) => (
                        <KartuTagihan key={p.id_pelanggan ?? p.nama} langganan={p} onDetail={(invoice, langganan) => setDetail({ invoice, langganan })} onManual={bukaManual} />
                    ))}
                </div>
            </div>

            {/* Detail tagihan: kertas invoice lengkap, dengan Bayar Sekarang di bawah total */}
            <Modal isOpen={!!detail} toggle={() => setDetail(null)} size="lg" centered scrollable>
                <ModalHeader toggle={() => setDetail(null)}>Detail Tagihan</ModalHeader>
                <ModalBody>
                    {detail && (
                        <InvoiceTagihan
                            tagihan={{ ...detail.invoice, nama: detail.langganan.nama, id_pelanggan: detail.langganan.id_pelanggan, jns_tagihan: detail.langganan.jns_tagihan, cabang: detail.langganan.cabang, rekening: detail.langganan.rekening, pembayaran_id: null }}
                            aksi={<TombolBayar invoice={detail.invoice} langganan={detail.langganan} onManual={bukaManual} block />} />
                    )}
                </ModalBody>
            </Modal>

            {/* Cabang tanpa pembayaran online: transfer / tunai lalu kirim bukti */}
            <Modal isOpen={!!manual} toggle={() => setManual(null)} centered>
                <ModalHeader toggle={() => setManual(null)}>Cara Pembayaran</ModalHeader>
                <ModalBody>
                    {manual && (
                        <>
                            <p>Tagihan <strong>{periode(manual.invoice.periode_pembayaran)}</strong> sebesar <strong>{rupiah(manual.invoice.nominal)}</strong> dapat dibayar lewat transfer ke rekening berikut, atau tunai di kantor {manual.langganan.cabang?.nama_lokasi ?? 'cabang'}.</p>
                            {manual.langganan.rekening?.length ? (
                                <ul className="list-group mb-3">
                                    {manual.langganan.rekening.map((r) => (
                                        <li key={`${r.nama_rekening}-${r.no_rekening}`} className="list-group-item">
                                            <strong>{r.nama_rekening}</strong><br /><span className="font-monospace">{r.no_rekening}</span><br /><small className="f-light">a.n. {r.atas_nama}</small>
                                        </li>
                                    ))}
                                </ul>
                            ) : <p className="f-light">Hubungi kami untuk informasi rekening pembayaran.</p>}
                            <p className="mb-0 small f-light">Setelah membayar, kirim bukti transfer agar tagihan segera ditandai lunas.</p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setManual(null)}>Tutup</Button>
                    {manual && (
                        <a className="btn btn-success" target="_blank" rel="noopener noreferrer"
                            href={linkWa(`Halo MSS, saya sudah membayar tagihan ${manual.invoice.no_tagihan} (ID ${manual.langganan.id_pelanggan ?? '-'}) sebesar ${rupiah(manual.invoice.nominal)}. Berikut bukti transfernya.`)}>
                            <MessageCircle size={15} className="me-1" />Kirim Bukti via WhatsApp
                        </a>
                    )}
                </ModalFooter>
            </Modal>
        </>
    );
}

Home.layout = portalLayout;
