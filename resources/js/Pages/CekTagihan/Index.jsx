import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, MessageCircle, Search } from 'react-feather';
import { HasilPelanggan, linkWa } from '../../Components/TagihanPelanggan';

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
