import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, CheckCircle, CreditCard, FileText, MessageCircle, Printer, Search } from 'react-feather';
import InvoiceTagihan from '../../Components/InvoiceTagihan';
import { periode, rupiah, tanggal } from '../../lib/format';
import { api } from '../../lib/ui';

const WA_MARKETING = '6281384141950';
const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

const linkWa = (pesan) => `https://wa.me/${WA_MARKETING}?text=${encodeURIComponent(pesan)}`;

/**
 * Tombol "Bayar di sini" pada invoice. Bila pembayaran online aktif untuk pelanggan ini (Midtrans, cabang non Admin Full),
 * server membuat transaksi lalu browser diarahkan ke halaman pembayaran Midtrans; nominal dihitung ulang di server.
 * Selain itu menampilkan cara bayar manual.
 */
function TombolBayar({ invoice, pelanggan }) {
    const [info, setInfo] = useState(false);
    const [proses, setProses] = useState(false);
    const [galat, setGalat] = useState('');

    const bayar = async () => {
        if (!pelanggan.bayar_online) {
            setInfo(true);
            return;
        }
        setProses(true);
        setGalat('');
        try {
            const res = await api('POST', '/cek-tagihan/bayar', { id_pelanggan: pelanggan.id_pelanggan, periode: invoice.periode });
            if (!res.redirect_url) throw { message: 'Pembayaran online sedang tidak tersedia. Silakan coba lagi.' };
            window.location.assign(res.redirect_url);
        } catch (err) {
            setGalat(err.errors?.bayar?.[0] ?? err.message);
            setProses(false);
        }
    };

    return (
        <>
            <button type="button" className="lp-btn lp-btn-gold ct-bayar" onClick={bayar} disabled={proses}>
                <CreditCard size={18} /> {proses ? 'Menyiapkan pembayaran…' : pelanggan.bayar_online ? 'Bayar Online' : 'Bayar di sini'}
            </button>
            {pelanggan.bayar_online && !galat && <p className="ct-bayar-catatan">Transfer bank (VA), QRIS, atau e-wallet lewat Midtrans. Status lunas otomatis.</p>}
            {galat && <div className="ct-bayar-info ct-bayar-galat" role="alert">{galat}</div>}
            {info && (
                <div className="ct-bayar-info" role="status">
                    <strong>Bayar lewat transfer atau tunai.</strong> Silakan transfer ke rekening di bagian Info Pembayaran atau bayar tunai di kantor
                    {pelanggan.cabang?.nama_lokasi ? ` ${pelanggan.cabang.nama_lokasi}` : ' cabang'}, lalu{' '}
                    <a href={linkWa(`Halo MSS, saya sudah membayar invoice ${invoice.no_tagihan} (ID ${pelanggan.id_pelanggan ?? '-'}) sebesar ${rupiah(invoice.nominal)}. Berikut bukti transfernya.`)}
                        target="_blank" rel="noopener noreferrer">kirim bukti via WhatsApp</a>.
                </div>
            )}
        </>
    );
}

/** Riwayat periode yang sudah lunas (cash, transfer, atau Midtrans): tidak bisa dibayar lagi, kwitansi bisa dibuka. */
function RiwayatLunas({ lunas }) {
    return (
        <div className="ct-riwayat no-print">
            <h3>Riwayat Pembayaran</h3>
            <ul>
                {lunas.map((b) => (
                    <li key={b.no_invoice ?? b.periode}>
                        <div className="ct-riwayat-info">
                            <strong>Periode {periode(b.periode_pembayaran)}</strong>
                            <span><span className="ct-badge-lunas"><CheckCircle size={13} /> Lunas</span> {rupiah(b.jml_bayar)} · via {b.metode_label}</span>
                            <small>Dibayar {tanggal(b.paid_at)}{b.no_invoice ? ` · No. ${b.no_invoice}` : ''}</small>
                        </div>
                        <Link href={b.link_kwitansi} className="ct-print"><FileText size={16} /> Lihat Kwitansi</Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Ringkasan satu pelanggan + invoice tiap bulan yang belum dibayar + riwayat lunas (data pribadi disamarkan oleh server). */
function HasilPelanggan({ pelanggan: p }) {
    const total = p.tagihan.reduce((s, t) => s + (t.nominal ?? 0), 0);

    return (
        <section className="ct-result">
            <header className="ct-summary">
                <div>
                    <h2>{p.nama}</h2>
                    <p>ID {p.id_pelanggan ?? '-'} · {p.paket ?? '-'} · {JENIS[p.jns_tagihan] ?? 'Prabayar'}{p.cabang?.nama_lokasi ? ` · ${p.cabang.nama_lokasi}` : ''}</p>
                </div>
                <div className={`ct-status ${p.tagihan.length ? 'is-due' : 'is-paid'}`}>{p.tagihan.length ? 'Belum lunas' : 'Lunas'}</div>
            </header>

            {p.tagihan.length ? (
                <>
                    <div className="ct-summary-bar no-print">
                        <p>{p.tagihan.length} invoice belum dibayar · total <strong>{rupiah(total)}</strong></p>
                        <button type="button" className="ct-print" onClick={() => window.print()}><Printer size={16} /> Cetak / Simpan PDF</button>
                    </div>
                    {p.tagihan.map((t) => (
                        <InvoiceTagihan key={t.no_tagihan}
                            tagihan={{ ...t, nama: p.nama, id_pelanggan: p.id_pelanggan, jns_tagihan: p.jns_tagihan, cabang: p.cabang, rekening: p.rekening, pembayaran_id: null }}
                            aksi={<TombolBayar invoice={t} pelanggan={p} />} />
                    ))}
                </>
            ) : (
                <p className="ct-paid">Tidak ada tagihan yang belum dibayar. Terima kasih telah membayar tepat waktu.</p>
            )}

            {p.lunas.length > 0 && <RiwayatLunas lunas={p.lunas} />}
        </section>
    );
}

/** Cek tagihan pelanggan tanpa login: cari dengan ID pelanggan atau nomor HP terdaftar, hasilnya invoice seperti di dashboard. */
export default function CekTagihan({ cari = '', hasil }) {
    const form = useForm({ cari });
    const submit = (e) => {
        e.preventDefault();
        form.post('/cek-tagihan', { preserveScroll: true });
    };

    return (
        <div className="lp ct">
            <Head title="Cek Tagihan · PT Media Solusi Sukses">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&display=swap" rel="stylesheet" />
            </Head>

            <header className="ct-bar no-print">
                <div className="lp-wrap ct-bar-inner">
                    <Link href="/" className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></Link>
                    <Link href="/" className="ct-back"><ArrowLeft size={16} /> Beranda</Link>
                </div>
            </header>

            <section className="ct-head no-print">
                <div className="lp-wrap">
                    <h1 className="lp-title is-dark">Cek <span>Tagihan</span></h1>
                    <p>Masukkan ID pelanggan atau nomor HP yang terdaftar saat berlangganan.</p>
                    <form onSubmit={submit} className="ct-form" noValidate>
                        <label htmlFor="cari" className="visually-hidden">ID pelanggan atau nomor HP</label>
                        <input id="cari" value={form.data.cari} onChange={(e) => form.setData('cari', e.target.value)} placeholder="Contoh: MSS00123 atau 0812xxxxxxxx"
                            autoComplete="off" inputMode="text" maxLength={30} aria-invalid={!!form.errors.cari} aria-describedby={form.errors.cari ? 'cari-error' : undefined} />
                        <button type="submit" className="lp-btn lp-btn-gold" disabled={form.processing}><Search size={18} /> {form.processing ? 'Mencari…' : 'Cek'}</button>
                    </form>
                    {form.errors.cari && <p id="cari-error" className="ct-error" role="alert">{form.errors.cari}</p>}
                </div>
            </section>

            <main className="lp-wrap ct-body">
                {hasil === null && (
                    <ul className="ct-help no-print">
                        <li><strong>ID pelanggan</strong> tertera di invoice atau kwitansi pembayaran Anda.</li>
                        <li><strong>Nomor HP</strong> boleh ditulis 0812…, 62812…, atau +62 812….</li>
                        <li>Lupa ID dan nomor yang didaftarkan? <a href={linkWa('Halo MSS, saya ingin menanyakan ID pelanggan saya.')} target="_blank" rel="noopener noreferrer">Hubungi kami</a>.</li>
                    </ul>
                )}

                {hasil && hasil.length === 0 && (
                    <div className="ct-empty">
                        <h2>Data tidak ditemukan</h2>
                        <p>Periksa kembali ID pelanggan atau nomor HP Anda. Nomor HP harus sama dengan yang didaftarkan saat berlangganan.</p>
                        <a href={linkWa(`Halo MSS, saya tidak menemukan tagihan untuk "${cari}".`)} target="_blank" rel="noopener noreferrer" className="lp-btn lp-btn-gold"><MessageCircle size={18} /> Tanya via WhatsApp</a>
                    </div>
                )}

                {hasil?.map((p) => <HasilPelanggan key={p.id_pelanggan ?? p.nama} pelanggan={p} />)}
            </main>

            <footer className="lp-footer no-print">
                <div className="lp-wrap lp-footer-inner"><span>© {new Date().getFullYear()} PT Media Solusi Sukses</span></div>
            </footer>
        </div>
    );
}

CekTagihan.layout = null;
