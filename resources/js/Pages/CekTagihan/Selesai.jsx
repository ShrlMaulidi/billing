import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle, Clock, FileText, MessageCircle, XCircle } from 'react-feather';
import { periode, rupiah } from '../../lib/format';

const WA_MARKETING = '6281384141950';
const linkWa = (pesan) => `https://wa.me/${WA_MARKETING}?text=${encodeURIComponent(pesan)}`;

/** Tampilan per status transaksi (status dari server, sudah disinkronkan dengan Midtrans). */
const TAMPILAN = {
    lunas: { ikon: CheckCircle, kelas: 'is-paid', judul: 'Pembayaran berhasil', teks: 'Terima kasih. Tagihan Anda sudah tercatat lunas.' },
    pending: { ikon: Clock, kelas: 'is-wait', judul: 'Menunggu pembayaran', teks: 'Selesaikan pembayaran sesuai instruksi Midtrans (mis. transfer ke nomor Virtual Account). Status akan berubah otomatis setelah pembayaran diterima.' },
    selisih: { ikon: Clock, kelas: 'is-wait', judul: 'Pembayaran sedang diperiksa', teks: 'Pembayaran Anda sudah kami terima dan sedang diperiksa petugas. Hubungi kami bila status belum berubah.' },
    ditarik: { ikon: XCircle, kelas: 'is-due', judul: 'Pembayaran dikembalikan', teks: 'Pembayaran ini sudah dikembalikan (refund) atau dibatalkan oleh bank penerbit kartu. Hubungi kami untuk informasi lebih lanjut.' },
    gagal: { ikon: XCircle, kelas: 'is-due', judul: 'Pembayaran tidak berhasil', teks: 'Pembayaran dibatalkan, ditolak, atau melewati batas waktu. Silakan buat pembayaran baru dari halaman Cek Tagihan.' },
};

/** Halaman tujuan setelah pelanggan kembali dari Midtrans. */
export default function Selesai({ transaksi }) {
    const t = transaksi ? (TAMPILAN[transaksi.status] ?? TAMPILAN.gagal) : null;
    const Ikon = t?.ikon;

    return (
        <div className="lp ct">
            <Head title="Status Pembayaran · PT Media Solusi Sukses" />
            <header className="ct-bar">
                <div className="lp-wrap ct-bar-inner">
                    <Link href="/" className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></Link>
                    <Link href="/cek-tagihan" className="ct-back"><ArrowLeft size={16} /> Cek Tagihan</Link>
                </div>
            </header>

            <main className="lp-wrap ct-body">
                {transaksi ? (
                    <section className={`ct-selesai ${t.kelas}`}>
                        <Ikon size={48} aria-hidden="true" />
                        <h1>{t.judul}</h1>
                        <p>{t.teks}</p>
                        <dl>
                            {transaksi.nama && <div><dt>Pelanggan</dt><dd>{transaksi.nama}{transaksi.id_pelanggan ? ` · ID ${transaksi.id_pelanggan}` : ''}</dd></div>}
                            <div><dt>Periode</dt><dd>{periode(transaksi.periode)}</dd></div>
                            <div><dt>Nominal</dt><dd>{rupiah(transaksi.nominal)}</dd></div>
                        </dl>
                        <div className="ct-selesai-aksi">
                            {transaksi.link_kwitansi && <Link href={transaksi.link_kwitansi} className="lp-btn lp-btn-gold"><FileText size={18} /> Lihat Kwitansi</Link>}
                            <Link href="/cek-tagihan" className={`lp-btn ${transaksi.link_kwitansi ? 'ct-wa' : 'lp-btn-gold'}`}>Kembali ke Cek Tagihan</Link>
                            {transaksi.status !== 'lunas' && (
                                <a href={linkWa(`Halo MSS, saya ingin menanyakan pembayaran online tagihan periode ${periode(transaksi.periode)} (ID ${transaksi.id_pelanggan ?? '-'}).`)}
                                    target="_blank" rel="noopener noreferrer" className="lp-btn ct-wa"><MessageCircle size={18} /> Hubungi kami</a>
                            )}
                        </div>
                    </section>
                ) : (
                    <section className="ct-empty">
                        <h2>Transaksi tidak ditemukan</h2>
                        <p>Silakan cek status tagihan Anda lewat halaman Cek Tagihan.</p>
                        <Link href="/cek-tagihan" className="lp-btn lp-btn-gold">Ke Cek Tagihan</Link>
                    </section>
                )}
            </main>

            <footer className="lp-footer">
                <div className="lp-wrap lp-footer-inner"><span>© {new Date().getFullYear()} PT Media Solusi Sukses</span></div>
            </footer>
        </div>
    );
}

Selesai.layout = null;
