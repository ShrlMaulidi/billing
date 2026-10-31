import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowUpRight, Menu, MessageCircle, X } from 'react-feather';
import { rupiah } from '../../lib/format';

/** Isi company profile PT Media Solusi Sukses (Company Profile 2026). Ubah di sini bila profil perusahaan berubah. */
const PERUSAHAAN = {
    nama: 'PT Media Solusi Sukses',
    singkat: 'MSS',
    website: 'https://mediasolusisukses.co.id',
    tentang: [
        'PT Media Solusi Sukses (MSS) adalah perusahaan penyedia layanan internet dan teknologi informasi yang didirikan pada tahun 2017 di Kabupaten Karawang, Jawa Barat. Kami hadir untuk memenuhi kebutuhan akan konektivitas internet yang andal dan inovasi teknologi yang mendukung pertumbuhan bisnis di era digital.',
        'Pelayanan serta kepuasan pelanggan adalah tujuan kami, dengan tenaga operator dan NOC (Network Operator Center) yang melayani dan memberikan solusi selama 24 jam sesuai kebutuhan pelanggan.',
    ],
    ceo: {
        nama: 'Sugeng Priyanto',
        jabatan: 'CEO PT Media Solusi Sukses',
        pesan: 'Di era digital ini, internet bukan lagi sekadar kemewahan, melainkan sebuah kebutuhan. Kami tidak hanya menjual koneksi, kami menawarkan stabilitas, kecepatan, dan kemudahan yang akan benar-benar mengubah cara Anda berinteraksi dengan dunia.',
    },
    visi: 'Memberikan pelanggan solusi layanan jasa dan produk terbaik dengan mengutamakan kepuasan pelanggan yang menjadikan PT Media Solusi Sukses sebagai pilihan utama dalam layanan jasa jaringan internet.',
    misi: [
        'Memberikan kualitas layanan terbaik yang didukung dengan berbagai solusi dan inovasi jaringan telekomunikasi dalam meningkatkan pelayanan bagi pelanggan.',
        'Membangun dan mengembangkan infrastruktur jaringan di seluruh kota-kota di Indonesia dengan teknologi terbaru.',
        'Selalu mengembangkan Sumber Daya Manusia secara konsisten untuk mencapai kesuksesan.',
    ],
    budaya: ['Aktif Kreatif', 'Semangat', 'Berjiwa Muda', 'Kerja Keras', 'Loyalitas', 'Dedikasi', 'Team Work'],
    kantor: [
        { judul: 'Admin Official · NOC', tlp: '+62 21 397 00 444', email: 'admin.office@mediasolusisukses.co.id', alamat: 'Perum. Bumi Karawang Residence, Blok G12 No. 7-9, Desa Cengkong, Kec. Purwasari, Kab. Karawang 41373' },
        { judul: 'Marketing Official', tlp: '+62 813 8414 1950', email: 'marketing.mss@mediasolusisukses.co.id', alamat: 'Block A1 No. 37, Jl. Graha, Purwadana, Telukjambe Timur, Karawang, Jawa Barat 41361' },
    ],
    whatsapp: '6281384141950',
};

const SOROTAN = [
    ['2017', 'Berdiri di Karawang'],
    ['24/7', 'NOC & dukungan teknis'],
    ['99,5%', 'SLA jaringan NOC'],
    ['100 Gbps', 'Kapasitas dedicated'],
];

const LAYANAN = [
    ['Internet Dedicated', 'Fiber optik & wireless hingga 100 Gbps, SLA 99,5%, purnajual 24x7, dan proteksi jalur jaringan. Jangkauan hampir seluruh Indonesia, fokus Karawang, Jabodetabek, dan kota besar.'],
    ['Broadband', 'Koneksi broadband sesuai kebutuhan pelanggan personal maupun perusahaan.'],
    ['Leased Line / Metro-E', 'Menghubungkan kantor pusat, cabang, dan antar lokasi dengan keamanan tinggi untuk transaksi data penting.'],
    ['Managed Network Service', 'Kami menangani switch, firewall, router, dan access point Anda dengan tim bersertifikasi, agar Anda fokus pada operasional.'],
    ['Data Center Co-location', 'Sewa tempat server dari 1U hingga full rack, dengan redundansi listrik, keamanan akses, dan NOC 24x7.'],
    ['VPS & Web Hosting', 'Server virtual eksklusif dengan kontrol penuh, serta paket hosting untuk website dan aplikasi.'],
    ['VPN Data', 'Jaringan privat untuk pertukaran data antar lokasi perusahaan.'],
    ['ERP & LAN Perusahaan', 'Enterprise Resources Planning dan koneksi internet perusahaan dengan Local Area Network.'],
];

const PRODUK = [
    ['PROXIMA', 'Internet dedicated 1:1', 'Kestabilan koneksi terjamin, keamanan data lebih baik karena tidak berbagi bandwidth, dukungan teknis responsif, dan jaminan SLA.'],
    ['Shafa.net', 'FTTH · Fiber To The Home', 'Internet berkecepatan tinggi dan stabil untuk rumah tangga dan pribadi: streaming, gaming, dan browsing bersama keluarga.'],
    ['ServeGo', 'Aplikasi cafe & resto', 'Pemesanan, reservasi, dan pembayaran online untuk cafe/resto. Cepat, mudah, ramah, dan efisien.'],
];

const LISENSI = [
    ['Internet Service Provider (ISP)', 'JASA-0013/TEL.04.02/2022'],
    ['Jaringan Tetap Lokal (Jartaplok)', 'JAR-0164/TEL.04.02/2022'],
    ['Anggota APJII', 'No. Registrasi 0950'],
    ['Internet Exchange', 'AIX · IIX · ILIX · JKT-IX'],
    ['Upstream', 'Tata · Level 3 · NTT · Arelion · China Mobile · Telecom Italia'],
];

/** Logo dari halaman Our Client & Our Partner Company Profile 2026 (public/images/landing). */
const KLIEN = [
    ['mahkamah-agung', 'Mahkamah Agung RI · Pengadilan Tinggi TUN Jakarta', 'pttun-jakarta'],
    ['ma-biro-hukum', 'Mahkamah Agung RI · Biro Hukum dan Humas', 'ma-biro-hukum'],
    ['ma-sekretariat', 'Mahkamah Agung RI · Sekretariat Badan', 'ma-sekretariat'],
    ['bbopt', 'BBOPT Karawang · Kementerian Pertanian RI', 'bbopt-karawang'],
    ['transmigrasi', 'Kementerian Transmigrasi · Pusat Data & Informasi', 'kemen-transmigrasi'],
    ['cicendo', 'Kemenkes RS Mata Cicendo', 'rs-mata-cicendo'],
    ['unsika', 'Universitas Singaperbangsa Karawang', 'unsika'],
    ['kujang', 'Pupuk Kujang Cikampek', 'pupuk-kujang'],
    ['bri', 'BRI Kantor Cabang Cikampek', 'bri-cikampek'],
    ['kpu', 'Komisi Pemilihan Umum Kab. Karawang', 'kpu-karawang'],
    ['bapenda', 'Bapenda Kab. Karawang', 'bapenda-karawang'],
    ['dlh', 'Dinas Lingkungan Hidup Kab. Karawang', 'dlh-karawang'],
    ['disdik', 'Dinas Pendidikan Kab. Karawang', 'disdik-karawang'],
    ['dinkes', 'Dinas Kesehatan Kab. Karawang', 'dinkes-karawang'],
    ['polres', 'Polres Kab. Purwakarta', 'polres-purwakarta'],
    ['dishub', 'Dinas Perhubungan Kab. Purwakarta', 'dishub-purwakarta'],
    ['diskominfo', 'Diskominfo Purwakarta', 'diskominfo-purwakarta'],
    ['rsud', 'RSUD Rengasdengklok', 'rsud-rengasdengklok'],
    ['saraswati', 'RSU Saraswati Cikampek', 'rsu-saraswati'],
    ['smkn1', 'SMK Negeri 1 Karawang', 'smkn1-karawang'],
    ['sman1', 'SMA Negeri 1 Cikampek', 'sman1-cikampek'],
    ['smpn1', 'SMP Negeri 1 Klari', 'smpn1-klari'],
    ['dewantara', 'SMK Dewantara Bekasi', 'smk-dewantara'],
].map(([key, nama, logo]) => ({ key, nama, logo: `/images/landing/klien/${logo}.jpg` }));

const MITRA = [
    ['shafa-net', 'Shafa.net'], ['rynet', 'Rynet'], ['aira-net', 'Aira Net'], ['cipta-selaras-net', 'PT Cipta Selaras Net'], ['mitra-a', 'Mitra MSS'], ['bks-net', 'BKS Net'],
    ['m-network', 'M Network'], ['shevia-net', 'Shevia.net'], ['cakra-media-network', 'PT Cakra Media Network Solution'], ['dg-net', 'DG.Net'], ['adzriel-net', 'Adzriel.net'],
    ['add-medra', 'Add Medra'], ['kds-net', 'KDS Net'], ['odinet', 'Odinet'], ['dura-net', 'Dura Net'], ['fadli-net', 'Fadli Net'], ['smartwifi-net', 'SmartWifi.Net'],
    ['alt-net', 'ALT.Net'], ['ama-net', 'AMA Net'], ['as-net', 'AS.Net'], ['mila-net', 'Mila.Net'], ['nathan-freya', 'Nathan Freya'], ['awa-net', 'Awa.net'],
    ['satria-wifi', 'Satria WiFi'], ['aisha-net', 'Aisha.Net'], ['web-net', 'WEB.Net'], ['gagego', 'Gagego'], ['semeru-net', 'Semeru.Net'], ['ragil-print', 'Ragil Print'],
    ['libra-net', 'Libra Net'], ['top-wifi-kamulyan', 'Top WiFi Kamulyan'],
].map(([logo, nama]) => ({ nama, logo: `/images/landing/mitra/${logo}.jpg` }));

const MENU = [['#tentang', 'Tentang'], ['#layanan', 'Layanan'], ['#paket', 'Paket'], ['#klien', 'Klien'], ['#kontak', 'Kontak']];

const linkWa = (pesan = 'Halo MSS, saya ingin berkonsultasi tentang layanan internet.') => `https://wa.me/${PERUSAHAAN.whatsapp}?text=${encodeURIComponent(pesan)}`;

/** Judul bagian bergaya company profile: huruf kapital tebal, kata kedua berwarna emas. */
function Judul({ atas, bawah, gelap = false }) {
    return <h2 className={`lp-title ${gelap ? 'is-dark' : ''}`}>{atas} <span>{bawah}</span></h2>;
}

/** Landing page company profile PT Media Solusi Sukses; halaman pertama website, tanpa layout aplikasi. */
export default function Landing({ paket }) {
    const { auth } = usePage().props;
    const [menuTerbuka, setMenuTerbuka] = useState(false);
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 40);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const masuk = <Link href={auth.user ? '/dashboard' : '/login'} className="lp-login">{auth.user ? 'Dashboard' : 'Masuk'}</Link>;

    return (
        <div className="lp">
            <Head title="PT Media Solusi Sukses · Internet Service Provider">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&display=swap" rel="stylesheet" />
            </Head>

            <header className={`lp-nav ${scrolled ? 'is-solid' : ''}`}>
                <div className="lp-wrap lp-nav-inner">
                    <a href="#beranda" className="lp-brand"><img src="/cuba/images/logo/logo.png" alt="MSS Internet Service Provider" /></a>
                    <nav className={`lp-menu ${menuTerbuka ? 'is-open' : ''}`}>
                        {MENU.map(([href, label]) => <a key={href} href={href} onClick={() => setMenuTerbuka(false)}>{label}</a>)}
                        <Link href="/cek-tagihan" className="lp-cek">Cek Tagihan</Link>
                        {masuk}
                    </nav>
                    <button type="button" className="lp-burger" onClick={() => setMenuTerbuka(!menuTerbuka)} aria-label={menuTerbuka ? 'Tutup menu' : 'Buka menu'}>
                        {menuTerbuka ? <X size={22} /> : <Menu size={22} />}
                    </button>
                </div>
            </header>

            <section id="beranda" className="lp-hero">
                <img className="lp-hero-img" src="/images/landing/gedung.jpg" alt="" />
                <div className="lp-wrap lp-hero-body">
                    <p className="lp-kicker">PT Media Solusi Sukses · Internet Service Provider</p>
                    <h1>Your internet solutions <span>start here.</span></h1>
                    <p className="lp-hero-text">Penyedia layanan internet dan teknologi informasi di Karawang sejak 2017, berizin KOMINFO dan anggota APJII. Melayani rumah, perusahaan, dan instansi dengan NOC 24 jam.</p>
                    <div className="lp-hero-actions">
                        <Link href="/cek-tagihan" className="lp-btn lp-btn-gold">Cek Tagihan</Link>
                        <a href={linkWa()} target="_blank" rel="noopener noreferrer" className="lp-btn lp-btn-ghost">Hubungi Marketing</a>
                    </div>
                </div>
                <div className="lp-wrap">
                    <dl className="lp-facts">
                        {SOROTAN.map(([angka, label]) => <div key={label}><dt>{angka}</dt><dd>{label}</dd></div>)}
                    </dl>
                </div>
            </section>

            <section id="tentang" className="lp-sec">
                <div className="lp-wrap lp-about">
                    <div>
                        <Judul atas="About" bawah="Company" />
                        {PERUSAHAAN.tentang.map((t) => <p key={t}>{t}</p>)}
                        <p className="lp-culture"><strong>Budaya kerja:</strong> {PERUSAHAAN.budaya.join(' · ')}</p>
                    </div>
                    <blockquote className="lp-quote">
                        <p>“{PERUSAHAAN.ceo.pesan}”</p>
                        <footer><strong>{PERUSAHAAN.ceo.nama}</strong>, {PERUSAHAAN.ceo.jabatan}</footer>
                    </blockquote>
                </div>
            </section>

            <section className="lp-sec lp-dark">
                <div className="lp-wrap lp-vm">
                    <div>
                        <Judul atas="Vision" bawah="& Mission" gelap />
                    </div>
                    <div>
                        <h3>Visi</h3>
                        <p>{PERUSAHAAN.visi}</p>
                        <h3>Misi</h3>
                        <ol>{PERUSAHAAN.misi.map((m) => <li key={m}>{m}</li>)}</ol>
                    </div>
                </div>
            </section>

            <section id="layanan" className="lp-sec">
                <div className="lp-wrap">
                    <div className="lp-sec-head">
                        <Judul atas="Our" bawah="Service" />
                        <p>Untuk pelanggan individual, perusahaan, dan institusi. Didukung teknisi berpengalaman dan NOC yang beroperasi 24 jam, 7 hari.</p>
                    </div>
                    <div className="lp-service-wrap">
                        <ol className="lp-services">
                            {LAYANAN.map(([judul, teks], i) => (
                                <li key={judul}>
                                    <span className="lp-num">{String(i + 1).padStart(2, '0')}</span>
                                    <div><h3>{judul}</h3><p>{teks}</p></div>
                                </li>
                            ))}
                        </ol>
                        <figure className="lp-photo">
                            <img src="/images/landing/noc.jpg" alt="Operator NOC MSS melayani pelanggan" loading="lazy" />
                            <figcaption>Network Operator Center: pemantauan jaringan & layanan pelanggan 24 jam.</figcaption>
                        </figure>
                    </div>
                </div>
            </section>

            <section id="produk" className="lp-sec lp-soft">
                <div className="lp-wrap">
                    <Judul atas="Our Special" bawah="Product" />
                    <div className="lp-products">
                        {PRODUK.map(([nama, jenis, teks]) => (
                            <article key={nama}>
                                <h3>{nama}</h3>
                                <p className="lp-product-type">{jenis}</p>
                                <p>{teks}</p>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            {paket.length > 0 && (
                <section id="paket" className="lp-sec">
                    <div className="lp-wrap">
                        <div className="lp-sec-head">
                            <Judul atas="Paket" bawah="Internet Rumah" />
                            <p>Harga per bulan. Ketersediaan tergantung jangkauan jaringan di lokasi Anda.</p>
                        </div>
                        <table className="lp-price">
                            <thead><tr><th>Paket</th><th>Kecepatan</th><th className="text-end">Harga / bulan</th><th aria-label="Aksi" /></tr></thead>
                            <tbody>
                                {paket.map((p) => (
                                    <tr key={p.nama}>
                                        <td><strong>{p.nama}</strong>{p.keterangan && <small>{p.keterangan}</small>}</td>
                                        <td>{p.kecepatan ? `Hingga ${p.kecepatan}` : '-'}</td>
                                        <td className="text-end lp-price-num">{rupiah(p.harga)}</td>
                                        <td className="text-end">
                                            <a href={linkWa(`Halo MSS, saya ingin menanyakan ketersediaan paket ${p.nama}.`)} target="_blank" rel="noopener noreferrer">Tanya ketersediaan <ArrowUpRight size={14} /></a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <section className="lp-sec lp-dark lp-network">
                <div className="lp-wrap lp-network-grid">
                    <div>
                        <Judul atas="Jaringan" bawah="& Lisensi" gelap />
                        <p>Terhubung ke internet exchange nasional dan upstream internasional melalui Cyber Building Jakarta dan Singapura.</p>
                        <dl className="lp-spec">
                            {LISENSI.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}
                        </dl>
                    </div>
                    <img src="/images/landing/jaringan.jpg" alt="" loading="lazy" className="lp-network-img" />
                </div>
            </section>

            <section id="klien" className="lp-sec">
                <div className="lp-wrap">
                    <div className="lp-sec-head">
                        <Judul atas="Our" bawah="Client" />
                        <p>Instansi pemerintah, BUMN, rumah sakit, kampus, dan sekolah yang mempercayakan koneksinya kepada MSS.</p>
                    </div>
                    <ul className="lp-logos lp-logos-client">
                        {KLIEN.map((k) => (
                            <li key={k.key}>
                                <img src={k.logo} alt="" loading="lazy" decoding="async" />
                                <span>{k.nama}</span>
                            </li>
                        ))}
                    </ul>

                    <div className="lp-sec-head lp-partner-head">
                        <Judul atas="Our" bawah="Partner" />
                    </div>
                    <ul className="lp-logos lp-logos-partner">
                        {MITRA.map((m) => <li key={m.logo}><img src={m.logo} alt={m.nama} title={m.nama} loading="lazy" decoding="async" /></li>)}
                    </ul>
                </div>
            </section>

            <section id="kontak" className="lp-contact">
                <div className="lp-wrap lp-contact-grid">
                    <div>
                        <Judul atas="Let’s connect" bawah="with us" gelap />
                        <a href={linkWa()} target="_blank" rel="noopener noreferrer" className="lp-btn lp-btn-gold"><MessageCircle size={18} /> WhatsApp Marketing</a>
                    </div>
                    {PERUSAHAAN.kantor.map((k) => (
                        <address key={k.judul}>
                            <h3>{k.judul}</h3>
                            <p>{k.alamat}</p>
                            <a href={`tel:${k.tlp.replace(/[^\d+]/g, '')}`}>{k.tlp}</a>
                            <a href={`mailto:${k.email}`}>{k.email}</a>
                        </address>
                    ))}
                </div>
            </section>

            <footer className="lp-footer">
                <div className="lp-wrap lp-footer-inner">
                    <span>© {new Date().getFullYear()} {PERUSAHAAN.nama}</span>
                    <a href={PERUSAHAAN.website} target="_blank" rel="noopener noreferrer">mediasolusisukses.co.id</a>
                    {masuk}
                </div>
            </footer>

            <a href={linkWa()} target="_blank" rel="noopener noreferrer" className="lp-wa" aria-label="Chat WhatsApp"><MessageCircle size={24} /></a>
        </div>
    );
}

Landing.layout = null;
