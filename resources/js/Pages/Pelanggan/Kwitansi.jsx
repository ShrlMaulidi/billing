import { ArrowLeft, Printer } from 'react-feather';
import { Button } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { JENIS_TAGIHAN } from '../../Components/Pembayaran';
import { periode, rupiah, tanggal, terbilang, waktu } from '../../lib/format';

// Penanda tangan kwitansi (gambar tanda tangan + stempel di public/finance.png)
const PENANDA_TANGAN = { nama: 'Sukirman', jabatan: 'Finance Manager', ttd: '/finance.png' };

/**
 * Kwitansi satu pembayaran: konsep sama dengan invoice (Tagihan.jsx), dibedakan aksen hijau, cap & tanda air LUNAS,
 * terbilang, dan tanda tangan Finance Manager. Tombol Cetak memakai dialog print browser (bisa disimpan sebagai PDF).
 */
export default function Kwitansi({ kwitansi: k }) {
    const jenis = JENIS_TAGIHAN[k.jns_tagihan] ?? 'Prabayar';
    const persenDiskon = k.diskon > 0 && k.diskon_tipe === 'persentase' ? ` (${String(k.diskon_nilai).replace('.', ',')}%)` : '';
    const metode = k.metode === 'cash' ? 'Cash' : `Transfer · ${k.rekening ?? '-'}`;

    return (
        <>
            <PageHeader title="Kwitansi Pembayaran" crumbs={[{ label: 'Pelanggan Lunas', href: '/pelanggan/lunas' }]} />
            <div className="container-fluid">
                <div className="d-flex flex-wrap gap-2 mb-3 no-print">
                    <Button color="light" onClick={() => window.history.back()}><ArrowLeft size={14} className="me-1" />Kembali</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                </div>

                <div className="invoice-paper is-kwitansi mb-4">
                    <div className="inv-watermark" aria-hidden="true">LUNAS</div>

                    {/* Kop: logo & nama usaha | judul, tanggal bayar, status */}
                    <div className="d-flex flex-wrap justify-content-between gap-4 mb-4">
                        <div>
                            <img src="/cuba/images/logo/logo.png" alt="MSS" style={{ height: 64 }} />
                            <h4 className="mt-2 mb-0 fw-bold">{k.cabang?.nama_lokasi ?? 'MSS Billing'}</h4>
                            <div className="inv-accent fw-semibold">Internet Service Provider</div>
                        </div>
                        <div className="text-sm-end">
                            <div className="inv-title">KWITANSI</div>
                            <div className="fw-semibold small">Bukti Pembayaran</div>
                            <div className="fw-bold mt-2">{waktu(k.paid_at)}</div>
                            <div className="small">No. {k.no_invoice}</div>
                            <div className="inv-stamp mt-2 text-success">✓ LUNAS</div>
                        </div>
                    </div>

                    {/* Alamat kantor (cabang) | pembayar */}
                    <div className="row g-4 mb-4">
                        <div className="col-sm-6">
                            <div className="inv-label">Alamat Kantor</div>
                            <div>{k.cabang?.alamat_lokasi ?? '-'}</div>
                            {k.cabang?.tlp_lokasi && <div className="fw-semibold mt-2">{k.cabang.tlp_lokasi}</div>}
                        </div>
                        <div className="col-sm-6 text-sm-end">
                            <div className="inv-label">Telah diterima dari :</div>
                            <div className="fw-bold">{k.nama_pelanggan}</div>
                            <div className="small">ID Pelanggan: {k.id_pelanggan ?? '-'}</div>
                            <div>{k.alamat ?? '-'}</div>
                            {k.tlp && <div>{k.tlp}</div>}
                        </div>
                    </div>

                    <div className="table-responsive">
                        <table className="inv-table">
                            <thead>
                                <tr><th>Untuk Pembayaran</th><th className="text-end">Harga</th><th className="text-center">Qty</th><th className="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div className="fw-bold">{k.nama_paket}</div>
                                        <div className="small f-light">
                                            Langganan internet {jenis} · periode {periode(k.periode_pembayaran)} untuk pemakaian {periode(k.periode)}
                                            {k.is_prorate && <><br />Tagihan prorate (bulan registrasi)</>}
                                        </div>
                                    </td>
                                    <td className="text-end text-nowrap">{rupiah(k.harga)}</td>
                                    <td className="text-center">1</td>
                                    <td className="text-end text-nowrap fw-semibold">{rupiah(k.harga)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {/* Terbilang & detail pembayaran | ringkasan & total */}
                    <div className="row g-4 mt-1 align-items-end">
                        <div className="col-md-6 order-2 order-md-1">
                            <div className="inv-label small">Terbilang:</div>
                            <div className="inv-terbilang mb-3">{terbilang(k.jml_bayar)}</div>
                            <table className="small">
                                <tbody>
                                    <tr><td className="pe-3 f-light">Metode</td><td className="fw-semibold">{metode}</td></tr>
                                    <tr><td className="pe-3 f-light">Tanggal Bayar</td><td className="fw-semibold">{tanggal(k.paid_at)}</td></tr>
                                    <tr><td className="pe-3 f-light">Petugas</td><td className="fw-semibold">{k.petugas ?? '-'}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div className="col-md-6 order-1 order-md-2">
                            <table className="inv-summary ms-auto mb-3">
                                <tbody>
                                    <tr><td className="inv-accent fw-bold text-end pe-4">SUBTOTAL :</td><td className="inv-accent fw-bold text-end">{rupiah(k.harga)}</td></tr>
                                    <tr><td className="fw-semibold text-end pe-4">DISKON{persenDiskon} :</td><td className="text-end">{k.diskon > 0 ? `- ${rupiah(k.diskon)}` : rupiah(0)}</td></tr>
                                </tbody>
                            </table>
                            <div className="inv-total"><span>TOTAL DIBAYAR :</span><span className="text-nowrap">{rupiah(k.jml_bayar)}</span></div>
                        </div>
                    </div>

                    {/* Tanda tangan & stempel penanggung jawab keuangan */}
                    <div className="d-flex flex-wrap justify-content-between align-items-end gap-4 mt-4">
                        <h6 className="inv-accent fw-bold mb-0">Terima kasih atas pembayaran Anda</h6>
                        <div className="inv-ttd small">
                            <div>Hormat kami,</div>
                            <img src={PENANDA_TANGAN.ttd} alt={`Tanda tangan ${PENANDA_TANGAN.nama}`} className="ttd-img" />
                            <div className="fw-bold text-decoration-underline">{PENANDA_TANGAN.nama}</div>
                            <div>{PENANDA_TANGAN.jabatan}</div>
                        </div>
                    </div>

                    <div className="inv-footer row g-3">
                        <div className="col-sm-4">
                            <h6>Pertanyaan?</h6>
                            <div>Telepon : {k.cabang?.tlp_lokasi ?? '-'}</div>
                            <div>Kantor : {k.cabang?.nama_lokasi ?? '-'}</div>
                        </div>
                        <div className="col-sm-4">
                            <h6>Info Pembayaran :</h6>
                            <div>Metode : {k.metode === 'cash' ? 'Cash' : 'Transfer'}</div>
                            {k.metode !== 'cash' && <div>Ke : {k.rekening ?? '-'}</div>}
                            <div>No. Invoice : {k.no_invoice}</div>
                        </div>
                        <div className="col-sm-4">
                            <h6>Catatan :</h6>
                            <div>Kwitansi ini adalah bukti pembayaran yang sah. Simpan sebagai arsip pembayaran Anda.</div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
