import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertCircle, AlertTriangle, Award, CheckCircle, ChevronRight, CreditCard, Eye, FileText, Key, LifeBuoy, MessageCircle, Wifi } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import { KartuBulanIni, MenuItem } from '../../Components/Portal/Beranda';
import TombolBayar from '../../Components/Portal/TombolBayar';
import { linkWa } from '../../Components/TagihanPelanggan';
import { StatusBadge } from '../../Components/ui';
import { periode, rupiah, tanggal } from '../../lib/format';
import { notify } from '../../lib/ui';
import { portalLayout } from '../../Layouts/PortalLayout';

const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

/** Satu langganan yang punya tunggakan: total + daftar invoice belum dibayar, masing-masing dengan Bayar Sekarang & Lihat Detail. */
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

/**
 * Home dashboard pelanggan (gaya yang sama di HP & desktop): pita biru bersapaan, kartu tagihan bulan ini dengan
 * Bayar Sekarang & Lihat Detail yang menumpuk di pita, menu utama berbentuk ikon, pengingat tunggakan & tiket.
 * Desktop: dua kolom (tagihan | menu); HP: satu kolom, kartu langganan bisa digeser.
 */
export default function Home({ hasil, tiketAktif, bulanIni, hariIni }) {
    const { auth } = usePage().props;
    const [detail, setDetail] = useState(null); // { invoice, langganan }
    const [manual, setManual] = useState(null); // { invoice, langganan }
    const tunggakan = hasil.flatMap((p) => p.tagihan.filter((t) => t.periode_pembayaran < bulanIni));
    const bukaManual = (invoice, langganan) => { setDetail(null); setManual({ invoice, langganan }); };
    const bukaDetail = (invoice, langganan) => setDetail({ invoice, langganan });
    // Menu "Bayar Tagihan": buka detail tagihan bulan ini (atau tagihan tertua bila bulan ini sudah lunas)
    const bayarTagihan = () => {
        const p = hasil.find((x) => x.tagihan.some((t) => t.periode_pembayaran === bulanIni)) ?? hasil.find((x) => x.tagihan.length);
        if (!p) {
            notify('Tidak ada tagihan yang perlu dibayar. Terima kasih!');
            return;
        }
        bukaDetail(p.tagihan.find((t) => t.periode_pembayaran === bulanIni) ?? p.tagihan[0], p);
    };

    return (
        <>
            <Head title="Home" />
            {/* Sapaan: memastikan pelanggan masuk dengan akun yang benar */}
            <section className="pm-band no-print">
                <div className="pm-band-dalam">
                    <p className="pm-halo">HALO, <strong>{auth.user.nama.toUpperCase()}</strong></p>
                    <p className="pm-user">Masuk sebagai {auth.user.username}</p>
                </div>
            </section>

            <div className="container-fluid">
                <div className="pm-wrap pm-isi pm-beranda">
                    <div className="pm-kolom">
                        <div className={`pm-cards ${hasil.length > 1 ? 'is-geser' : ''}`}>
                            {hasil.map((p) => <KartuBulanIni key={p.id_pelanggan ?? p.nama} langganan={p} bulanIni={bulanIni} hariIni={hariIni} onDetail={bukaDetail} onManual={bukaManual} />)}
                            {hasil.length === 0 && (
                                <div className="card pm-card">
                                    <div className="pm-card-body">
                                        <span className="pm-card-label">Belum ada langganan aktif</span>
                                        <div className="pm-due mb-3">Akun ini belum terhubung ke langganan internet yang aktif.</div>
                                        <a href={linkWa(`Halo MSS, akun pelanggan saya (${auth.user.username}) belum menampilkan langganan.`)} target="_blank" rel="noopener noreferrer" className="btn btn-primary">
                                            <MessageCircle size={15} className="me-1" />Hubungi Kami
                                        </a>
                                    </div>
                                </div>
                            )}
                        </div>
                        {hasil.length > 1 && <p className="pm-geser">Geser untuk melihat langganan lainnya</p>}

                        {/* Tunggakan bulan sebelumnya (tagihan bulan ini sudah di kartu atas) */}
                        {tunggakan.length > 0 && (
                            <div id="semua-tagihan" className="pm-anchor pm-tunggakan">
                                <h6 className="pm-title">Tagihan Belum Dibayar</h6>
                                {hasil.filter((p) => p.tagihan.some((t) => t.periode_pembayaran < bulanIni)).map((p) => (
                                    <KartuTagihan key={p.id_pelanggan ?? p.nama} langganan={p} onDetail={bukaDetail} onManual={bukaManual} />
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="pm-kolom">
                        <section className="card pm-panel">
                            <h6 className="pm-title">Menu Utama</h6>
                            <div className="pm-menu">
                                <MenuItem icon={CreditCard} label="Bayar Tagihan" onClick={bayarTagihan} />
                                <MenuItem icon={FileText} label="Riwayat" href="/akun-saya/riwayat" />
                                <MenuItem icon={LifeBuoy} label="Tiket" href="/akun-saya/tiket" badge={tiketAktif} />
                                <MenuItem icon={AlertTriangle} label="Lapor Gangguan" href="/akun-saya/tiket?buat=1" />
                                <MenuItem icon={Wifi} label="Paket Saya" href="/akun-saya/akun" />
                                <MenuItem icon={Key} label="Ganti Password" href="/akun-saya/akun#password" />
                                <MenuItem icon={MessageCircle} label="Hubungi Kami" url={linkWa(`Halo MSS, saya pelanggan dengan nomor ${auth.user.username}.`)} />
                                <MenuItem icon={Award} label="Kartu Member" href="/akun-saya/akun#kartu" />
                            </div>
                        </section>

                        {tunggakan.length > 0 && (
                            <a href="#semua-tagihan" className="pm-info is-bahaya">
                                <span className="pm-info-icon"><AlertCircle size={20} /></span>
                                <span className="flex-grow-1"><strong>{tunggakan.length} tagihan bulan sebelumnya</strong><br /><small>{rupiah(tunggakan.reduce((s, t) => s + (t.nominal ?? 0), 0))} belum dibayar</small></span>
                                <ChevronRight size={18} />
                            </a>
                        )}
                        <Link href="/akun-saya/tiket" className="pm-info">
                            <span className="pm-info-icon"><LifeBuoy size={20} /></span>
                            <span className="flex-grow-1"><strong>{tiketAktif ? `${tiketAktif} tiket sedang ditangani` : 'Ada kendala internet?'}</strong><br /><small>{tiketAktif ? 'Lihat perkembangan tiket Anda' : 'Buat tiket, petugas kami siap membantu'}</small></span>
                            <ChevronRight size={18} />
                        </Link>
                        <Link href="/akun-saya/riwayat" className="pm-info">
                            <span className="pm-info-icon is-sukses"><CheckCircle size={20} /></span>
                            <span className="flex-grow-1"><strong>Riwayat & kuitansi</strong><br /><small>Lihat pembayaran yang sudah lunas</small></span>
                            <ChevronRight size={18} />
                        </Link>
                    </div>
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
