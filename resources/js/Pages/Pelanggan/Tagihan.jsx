import { Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer } from 'react-feather';
import { Button } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { JENIS_TAGIHAN } from '../../Components/Pembayaran';
import { periode, rupiah, tanggal } from '../../lib/format';

/** Tagihan (invoice) satu pelanggan untuk satu bulan tagihan; tombol Cetak memakai dialog print browser (bisa disimpan sebagai PDF). */
export default function Tagihan({ tagihan: t }) {
    const lunas = !!t.pembayaran_id;
    const jenis = JENIS_TAGIHAN[t.jns_tagihan] ?? 'Prabayar';

    return (
        <>
            <PageHeader title="Tagihan Pelanggan" crumbs={[{ label: 'Pelanggan Belum Lunas', href: '/pelanggan/belum-lunas' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3 no-print">
                    <Button color="light" onClick={() => window.history.back()}><ArrowLeft size={14} className="me-1" />Kembali</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                    {lunas && <Link href={`/pembayaran/${t.pembayaran_id}/kwitansi`} className="btn btn-outline-success"><FileText size={14} className="me-1" />Lihat Kwitansi</Link>}
                </div>

                <div className="invoice-paper mb-4">
                    {/* Kop: logo & nama usaha | judul, tanggal, status */}
                    <div className="d-flex flex-wrap justify-content-between gap-4 mb-4">
                        <div>
                            <img src="/cuba/images/logo/logo.png" alt="MSS" style={{ height: 64 }} />
                            <h4 className="mt-2 mb-0 fw-bold">{t.cabang?.nama_lokasi ?? 'MSS Billing'}</h4>
                            <div className="inv-accent fw-semibold">Internet Service Provider</div>
                        </div>
                        <div className="text-sm-end">
                            <div className="inv-title">INVOICE</div>
                            <div className="fw-bold mt-2">{tanggal(t.periode_pembayaran)}</div>
                            <div className="small">No. {t.no_tagihan}</div>
                            <div className={`inv-stamp mt-2 ${lunas ? 'text-success' : 'text-danger'}`}>{lunas ? 'LUNAS' : 'BELUM LUNAS'}</div>
                        </div>
                    </div>

                    {/* Alamat kantor (cabang) | pelanggan */}
                    <div className="row g-4 mb-4">
                        <div className="col-sm-6">
                            <div className="inv-label">Alamat Kantor</div>
                            <div>{t.cabang?.alamat_lokasi ?? '-'}</div>
                            {t.cabang?.tlp_lokasi && <div className="fw-semibold mt-2">{t.cabang.tlp_lokasi}</div>}
                        </div>
                        <div className="col-sm-6 text-sm-end">
                            <div className="inv-label">Kepada :</div>
                            <div className="fw-bold">{t.nama}</div>
                            <div className="small">ID Pelanggan: {t.id_pelanggan ?? '-'}</div>
                            <div>{t.alamat ?? '-'}</div>
                            {t.tlp && <div>{t.tlp}</div>}
                        </div>
                    </div>

                    <div className="table-responsive">
                        <table className="inv-table">
                            <thead>
                                <tr><th>Deskripsi</th><th className="text-end">Harga</th><th className="text-center">Qty</th><th className="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div className="fw-bold">{t.nama_paket ?? '-'}</div>
                                        <div className="small f-light">
                                            Langganan internet {jenis} · pemakaian {periode(t.periode)}
                                            {t.is_prorate && <><br />Prorate {t.hari_prorate} hari dari {rupiah(t.harga_paket)}</>}
                                        </div>
                                    </td>
                                    <td className="text-end text-nowrap">{rupiah(t.nominal)}</td>
                                    <td className="text-center">1</td>
                                    <td className="text-end text-nowrap fw-semibold">{rupiah(t.nominal)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {/* Catatan | ringkasan & total */}
                    <div className="row g-4 mt-1 align-items-end">
                        <div className="col-md-6 order-2 order-md-1">
                            <div className="inv-label small">Catatan:</div>
                            <div className="small">
                                Periode pembayaran {periode(t.periode_pembayaran)} untuk pemakaian {periode(t.periode)} ({jenis}).
                                {t.jatuh_tempo && <> Mohon lakukan pembayaran sebelum <strong>{tanggal(t.jatuh_tempo)}</strong>.</>}
                            </div>
                        </div>
                        <div className="col-md-6 order-1 order-md-2">
                            <table className="inv-summary ms-auto mb-3">
                                <tbody>
                                    <tr><td className="inv-accent fw-bold text-end pe-4">SUBTOTAL :</td><td className="inv-accent fw-bold text-end">{rupiah(t.nominal)}</td></tr>
                                    <tr><td className="fw-semibold text-end pe-4">DISKON :</td><td className="text-end">{rupiah(0)}</td></tr>
                                </tbody>
                            </table>
                            <div className="inv-total"><span>TOTAL TAGIHAN :</span><span className="text-nowrap">{rupiah(t.nominal)}</span></div>
                        </div>
                    </div>

                    <h6 className="inv-accent fw-bold mt-4 mb-0">Terima kasih atas kepercayaan Anda</h6>

                    <div className="inv-footer row g-3">
                        <div className="col-sm-4">
                            <h6>Pertanyaan?</h6>
                            <div>Telepon : {t.cabang?.tlp_lokasi ?? '-'}</div>
                            <div>Kantor : {t.cabang?.nama_lokasi ?? '-'}</div>
                        </div>
                        <div className="col-sm-4">
                            <h6>Info Pembayaran :</h6>
                            {t.rekening.length ? t.rekening.map((r) => (
                                <div key={`${r.nama_rekening}-${r.no_rekening}`} className="mb-1">
                                    <div><span className="fw-semibold">{r.nama_rekening}</span> : {r.no_rekening}</div>
                                    <div>a.n. {r.atas_nama}</div>
                                </div>
                            )) : <div>Tunai di kantor cabang</div>}
                        </div>
                        <div className="col-sm-4">
                            <h6>Syarat & Catatan :</h6>
                            <div>
                                {lunas ? 'Tagihan ini sudah dibayar; kwitansi tersedia sebagai bukti pembayaran.' : 'Abaikan invoice ini bila sudah membayar. Kwitansi diberikan setelah pembayaran diterima.'}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
