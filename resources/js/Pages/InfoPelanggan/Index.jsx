import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, ChevronRight, CreditCard, Headphones, Info, Key, LogIn, MapPin, MessageCircle, Search, Shield, UserPlus, Wifi, X, Zap } from 'react-feather';
import { HasilPelanggan, linkWa } from '../../Components/TagihanPelanggan';
import { rupiah } from '../../lib/format';

/* WhatsApp marketing (pasang baru), sama dengan landing page; layanan pelanggan memakai nomor CS (linkWa) */
const WA_MARKETING = '628987515353';
const linkMarketing = (pesan) => `https://wa.me/${WA_MARKETING}?text=${encodeURIComponent(pesan)}`;
const GANTI_BANNER_MS = 5000;

function Banner({ paket }) {
    const termurah = paket[0]?.harga;
    const slide = [
        { kelas: 'is-biru', ikon: Wifi, judul: 'Internet Cepat & Stabil', isi: termurah ? `Fiber optic untuk rumah & usaha, mulai ${rupiah(termurah)}/bulan.` : 'Fiber optic untuk rumah & usaha di Karawang dan sekitarnya.', label: 'Lihat Paket', href: '#paket' },
        { kelas: 'is-hijau', ikon: CreditCard, judul: 'Bayar Tagihan Lebih Mudah', isi: 'QRIS, Virtual Account & e-wallet. Cek tagihan, bayar, langsung lunas.', label: 'Cek Tagihan', href: '#cek-tagihan' },
        { kelas: 'is-ungu', ikon: Headphones, judul: 'Bantuan 24 Jam', isi: 'Internet bermasalah? Laporkan lewat WhatsApp, teknisi kami siap membantu.', label: 'Lapor Gangguan', href: linkWa('Halo MSS, saya ingin melaporkan gangguan internet.'), luar: true },
    ];
    const jalur = useRef(null);
    const [aktif, setAktif] = useState(0);

    // Geser otomatis; berhenti saat pengguna menyentuh / mengarahkan kursor
    const jeda = useRef(false);
    useEffect(() => {
        const t = setInterval(() => {
            const el = jalur.current;
            if (!el || jeda.current) return;
            const anak = el.children[(aktif + 1) % slide.length];
            el.scrollTo({ left: anak.offsetLeft - (el.clientWidth - anak.clientWidth) / 2, behavior: 'smooth' });
        }, GANTI_BANNER_MS);
        return () => clearInterval(t);
    }, [aktif, slide.length]);

    const saatGeser = () => {
        const el = jalur.current;
        if (!el) return;
        // Slide yang titik tengahnya paling dekat dengan tengah jalur
        const tengah = el.scrollLeft + el.clientWidth / 2;
        const jarak = [...el.children].map((c) => Math.abs(c.offsetLeft + c.clientWidth / 2 - tengah));
        const i = jarak.indexOf(Math.min(...jarak));
        if (i !== aktif) setAktif(i);
    };

    return (
        <section className="ip-banner" aria-label="Info layanan" onMouseEnter={() => { jeda.current = true; }} onMouseLeave={() => { jeda.current = false; }}
            onTouchStart={() => { jeda.current = true; }} onTouchEnd={() => { jeda.current = false; }}>
            <div className="ip-banner-jalur" ref={jalur} onScroll={saatGeser}>
                {slide.map((s) => (
                    <a key={s.judul} href={s.href} className={`ip-slide ${s.kelas}`} {...(s.luar ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
                        <div className="ip-slide-teks">
                            <strong>{s.judul}</strong>
                            <span>{s.isi}</span>
                            <em>{s.label} <ChevronRight size={14} /></em>
                        </div>
                        <s.ikon className="ip-slide-ikon" aria-hidden="true" />
                    </a>
                ))}
            </div>
            <div className="ip-titik" aria-hidden="true">{slide.map((s, i) => <span key={s.judul} className={i === aktif ? 'is-aktif' : ''} />)}</div>
        </section>
    );
}

function CaraBayar({ onTutup }) {
    useEffect(() => {
        const esc = (e) => e.key === 'Escape' && onTutup();
        document.addEventListener('keydown', esc);
        return () => document.removeEventListener('keydown', esc);
    }, [onTutup]);

    return (
        <div className="ip-lapis" role="dialog" aria-modal="true" aria-labelledby="judul-cara-bayar" onClick={onTutup}>
            <div className="ip-lembar" onClick={(e) => e.stopPropagation()}>
                <div className="ip-lembar-kepala">
                    <h2 id="judul-cara-bayar">Cara Bayar Tagihan</h2>
                    <button type="button" onClick={onTutup} aria-label="Tutup"><X size={18} /></button>
                </div>
                <ol className="ip-langkah">
                    <li><strong>Bayar online (langsung lunas)</strong><span>Cek tagihan dengan nomor HP / ID pelanggan di halaman ini, lalu tekan <b>Bayar Sekarang</b>. Bisa QRIS, Virtual Account, atau e-wallet.</span></li>
                    <li><strong>Transfer bank</strong><span>Nomor rekening tampil di bawah tagihan setelah Anda cek tagihan. Kirim bukti transfer ke CS agar segera dicatat.</span></li>
                    <li><strong>Bayar di kantor / petugas</strong><span>Bayar tunai ke kantor cabang atau petugas resmi MSS, minta kuitansi pembayaran.</span></li>
                    <li><strong>Lewat akun pelanggan</strong><span>Masuk dengan nomor HP terdaftar untuk melihat tagihan, riwayat, invoice & kuitansi kapan saja.</span></li>
                </ol>
                <a href="#cek-tagihan" className="ip-tombol-utama" onClick={onTutup}><Search size={16} /> Cek Tagihan Sekarang</a>
            </div>
        </div>
    );
}

/**
 * Halaman informasi pelanggan (/pelanggan) yang dibagikan ke pelanggan: sapaan, cek tagihan (nomor HP / ID pelanggan),
 * menu cepat (Hubungi CS, Daftar Baru, Lapor Gangguan, Cara Bayar), tombol masuk ke akun pelanggan, paket, & layanan lain.
 * Gaya aplikasi mobile banking; di desktop dua kolom.
 */
export default function InfoPelanggan({ cari = '', hasil, paket = [], masuk }) {
    const form = useForm({ cari });
    const [caraBayar, setCaraBayar] = useState(false);
    const hasilRef = useRef(null);
    const submit = (e) => {
        e.preventDefault();
        form.post('/pelanggan/cek-tagihan', { preserveScroll: true, onSuccess: () => setTimeout(() => hasilRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50) });
    };

    const menu = [
        { label: 'Hubungi CS', ikon: Headphones, href: linkWa('Halo CS MSS, saya ingin bertanya.'), warna: 'is-biru' },
        { label: 'Daftar Baru', ikon: UserPlus, href: linkMarketing('Halo MSS, saya ingin berlangganan internet. Mohon info paket & jadwal pemasangan.'), warna: 'is-hijau' },
        { label: 'Lapor Gangguan', ikon: AlertTriangle, href: linkWa('Halo MSS, saya ingin melaporkan gangguan internet. ID pelanggan / nomor HP: '), warna: 'is-merah' },
        { label: 'Cara Bayar', ikon: CreditCard, onClick: () => setCaraBayar(true), warna: 'is-kuning' },
    ];
    const layanan = [
        { label: 'Paket Internet', ikon: Zap, href: '#paket' },
        { label: 'Lupa Password', ikon: Key, href: linkWa('Halo CS MSS, saya lupa password akun pelanggan. Nomor HP terdaftar: '), luar: true },
        { label: 'Kantor & Kontak', ikon: MapPin, href: '/#kontak' },
        { label: 'Tentang MSS', ikon: Info, href: '/' },
    ];

    return (
        <div className="ip">
            <Head title="Layanan Pelanggan · PT Media Solusi Sukses" />

            <header className="ip-atas">
                <div className="ip-wrap ip-atas-isi">
                    <Link href="/" className="ip-logo"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></Link>
                    <div className="ip-atas-aksi">
                        <a href={linkWa('Halo CS MSS, saya butuh bantuan.')} target="_blank" rel="noopener noreferrer" className="ip-pil"><Headphones size={16} /><span>Bantuan</span></a>
                        <a href={masuk ?? '/login'} className="ip-pil is-gelap"><LogIn size={15} /><span>{masuk ? 'Dashboard' : 'Masuk'}</span></a>
                    </div>
                </div>
            </header>

            <main className="ip-wrap ip-isi">
                <Banner paket={paket} />

                <div className="ip-akses">
                    <div className="ip-sapa">
                        <span>Halo,</span>
                        <strong>Pelanggan PT Media Solusi Sukses</strong>
                        <small>Semua informasi & layanan pelanggan MSS di satu tempat</small>
                    </div>

                    <form id="cek-tagihan" className="ip-kartu ip-cek" onSubmit={submit} noValidate>
                        <label htmlFor="cari">Cek Tagihan</label>
                        <p>Masukkan nomor HP atau ID pelanggan yang terdaftar.</p>
                        <div className="ip-cek-baris">
                            <input id="cari" value={form.data.cari} onChange={(e) => form.setData('cari', e.target.value)} placeholder="0812xxxxxxxx atau MSS00123"
                                autoComplete="off" inputMode="text" maxLength={30} aria-invalid={!!form.errors.cari} aria-describedby={form.errors.cari ? 'cari-error' : undefined} />
                            <button type="submit" disabled={form.processing} aria-label="Cek tagihan"><Search size={18} /><span>{form.processing ? 'Mencari…' : 'Cek'}</span></button>
                        </div>
                        {form.errors.cari && <p id="cari-error" className="ip-galat" role="alert">{form.errors.cari}</p>}
                    </form>

                    <a href={masuk ?? '/login'} className="ip-tombol-utama ip-masuk">{masuk ? 'Buka Dashboard Saya' : 'Masuk ke Akun Pelanggan'}</a>
                    <p className="ip-masuk-ket"><Shield size={13} /> {masuk ? 'Anda sudah masuk sebagai pelanggan' : 'Masuk dengan nomor HP terdaftar untuk melihat tagihan, riwayat & tiket'}</p>
                </div>

                {hasil && (
                    <section className="ip-hasil" ref={hasilRef} aria-live="polite">
                        <div className="ip-hasil-kepala">
                            <h2>Hasil cek tagihan</h2>
                            <span>“{cari}”</span>
                        </div>
                        <div className="lp ip-hasil-isi">
                            {hasil.length === 0 ? (
                                <div className="ip-kosong">
                                    <strong>Data tidak ditemukan</strong>
                                    <p>Periksa kembali nomor HP atau ID pelanggan. Nomor HP harus sama dengan yang didaftarkan saat berlangganan.</p>
                                    <a href={linkWa(`Halo MSS, saya tidak menemukan tagihan untuk "${cari}".`)} target="_blank" rel="noopener noreferrer" className="ip-pil is-gelap"><MessageCircle size={15} /><span>Tanya via WhatsApp</span></a>
                                </div>
                            ) : hasil.map((p) => <HasilPelanggan key={p.id_pelanggan ?? p.nama} pelanggan={p} />)}
                        </div>
                    </section>
                )}

                <section className="ip-kartu ip-menu" aria-label="Menu cepat">
                    {menu.map((m) => {
                        const isi = <><span className={`ip-menu-ikon ${m.warna}`}><m.ikon size={22} /></span><span className="ip-menu-label">{m.label}</span></>;
                        return m.href
                            ? <a key={m.label} href={m.href} target="_blank" rel="noopener noreferrer" className="ip-menu-item">{isi}</a>
                            : <button key={m.label} type="button" onClick={m.onClick} className="ip-menu-item">{isi}</button>;
                    })}
                </section>

                {paket.length > 0 && (
                    <section id="paket" className="ip-bagian ip-paket">
                        <h2>Paket Internet</h2>
                        <div className="ip-geser">
                            {paket.map((p) => (
                                <div key={p.nama} className="ip-paket-item">
                                    <span className="ip-paket-ikon"><Wifi size={18} /></span>
                                    <strong>{p.nama}</strong>
                                    {p.kecepatan && <small>Hingga {p.kecepatan}</small>}
                                    <span className="ip-paket-harga">{rupiah(p.harga)}<small>/bulan</small></span>
                                    <a href={linkMarketing(`Halo MSS, saya tertarik paket ${p.nama}. Mohon info pemasangan.`)} target="_blank" rel="noopener noreferrer">Daftar <ChevronRight size={14} /></a>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <section className="ip-bagian ip-lainnya">
                    <h2>Layanan Lainnya</h2>
                    <div className="ip-geser">
                        {layanan.map((l) => (
                            <a key={l.label} href={l.href} className="ip-lainnya-item" {...(l.luar ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
                                <l.ikon size={22} />
                                <span>{l.label}</span>
                            </a>
                        ))}
                    </div>
                    <Link href="/" className="ip-tentang"><Info size={15} /> Tentang PT Media Solusi Sukses</Link>
                </section>
            </main>

            <footer className="ip-kaki">© {new Date().getFullYear()} PT Media Solusi Sukses · Internet Service Provider</footer>

            {caraBayar && <CaraBayar onTutup={() => setCaraBayar(false)} />}
        </div>
    );
}

InfoPelanggan.layout = null;
