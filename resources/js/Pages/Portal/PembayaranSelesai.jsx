import { Link } from '@inertiajs/react';
import { CheckCircle, Clock, CreditCard, FileText, Home, MessageCircle, XCircle } from 'react-feather';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { linkWa } from '../../Components/TagihanPelanggan';
import { periode, rupiah } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Tampilan per status transaksi (status sudah disinkronkan dengan Midtrans sebelum halaman ini dibuka). */
const TAMPILAN = {
    lunas: { ikon: CheckCircle, kelas: 'is-lunas', judul: 'Pembayaran berhasil', teks: 'Terima kasih. Tagihan Anda sudah tercatat lunas.' },
    pending: { ikon: Clock, kelas: 'is-tunggu', judul: 'Menunggu pembayaran', teks: 'Selesaikan pembayaran sesuai instruksi (mis. transfer ke nomor Virtual Account). Status berubah otomatis setelah pembayaran diterima.' },
    selisih: { ikon: Clock, kelas: 'is-tunggu', judul: 'Pembayaran sedang diperiksa', teks: 'Pembayaran Anda sudah kami terima dan sedang diperiksa petugas. Hubungi kami bila status belum berubah.' },
    ditarik: { ikon: XCircle, kelas: 'is-gagal', judul: 'Pembayaran dikembalikan', teks: 'Pembayaran ini sudah dikembalikan (refund) atau dibatalkan oleh bank penerbit kartu. Hubungi kami untuk informasi lebih lanjut.' },
    gagal: { ikon: XCircle, kelas: 'is-gagal', judul: 'Pembayaran tidak berhasil', teks: 'Pembayaran dibatalkan, ditolak, atau melewati batas waktu. Silakan bayar kembali dari Dashboard.' },
};

/** Hasil pembayaran online di dashboard pelanggan, dengan tombol kembali ke Dashboard & kuitansi. */
export default function PembayaranSelesai({ transaksi: tr }) {
    const t = TAMPILAN[tr.status] ?? TAMPILAN.gagal;
    const Ikon = t.ikon;

    return (
        <>
            <PitaPortal judul="Status Pembayaran" keterangan={`Tagihan ${periode(tr.periode)}`} />
            <div className="container-fluid">
                <div className="pm-wrap pm-wrap-sempit pm-isi">
                    <div className={`card pm-card pm-hasil ${t.kelas}`}>
                        <div className="pm-card-body text-center">
                            <span className="pm-hasil-ikon"><Ikon size={34} /></span>
                            <h2 className="pm-hasil-judul">{t.judul}</h2>
                            <p className="pm-due mb-0">{t.teks}</p>
                            <dl className="pm-hasil-rincian">
                                <div><dt>Pelanggan</dt><dd>{tr.nama}{tr.id_pelanggan ? ` · ID ${tr.id_pelanggan}` : ''}</dd></div>
                                <div><dt>Periode</dt><dd>{periode(tr.periode)}</dd></div>
                                {tr.metode && <div><dt>Metode</dt><dd>{tr.metode}</dd></div>}
                                <div><dt>Nominal</dt><dd className="fw-bold">{rupiah(tr.nominal)}</dd></div>
                            </dl>
                            <div className="pm-hasil-aksi">
                                {tr.pembayaran_id && <Link href={`/akun-saya/riwayat/${tr.pembayaran_id}/kuitansi`} className="btn btn-primary btn-lg"><FileText size={16} className="me-1" />Lihat Kuitansi</Link>}
                                {tr.lanjut_url && <a href={tr.lanjut_url} className="btn btn-primary btn-lg"><CreditCard size={16} className="me-1" />Lanjutkan Pembayaran</a>}
                                <Link href="/akun-saya" className="btn btn-outline-primary btn-lg"><Home size={16} className="me-1" />Kembali ke Dashboard</Link>
                                {tr.status !== 'lunas' && (
                                    <a href={linkWa(`Halo MSS, saya ingin menanyakan pembayaran online tagihan periode ${periode(tr.periode)} (ID ${tr.id_pelanggan ?? '-'}).`)}
                                        target="_blank" rel="noopener noreferrer" className="btn btn-light btn-lg"><MessageCircle size={16} className="me-1" />Hubungi Kami</a>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

PembayaranSelesai.layout = portalLayout;
