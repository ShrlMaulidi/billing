import { useState } from 'react';
import { Copy, ExternalLink, Info } from 'react-feather';
import { Button, Card, CardBody, Nav, NavItem, NavLink } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { Field } from '../../Components/ui';
import { api, notify } from '../../lib/ui';

const METODE = [
    {
        key: 'pppoe', label: 'PPPoE', langkah: [
            'Paste script di New Terminal router.',
            'Pastikan nama profile sama dengan "Profile Isolir" router di menu Router billing.',
            'Isolir/aktifkan pelanggan dari menu Pelanggan (tombol Off/On), billing yang mengganti profile PPP-nya.',
        ],
    },
    {
        key: 'hotspot', label: 'Hotspot', langkah: [
            'Paste script di New Terminal router.',
            'Untuk mengisolir, pindahkan user hotspot ke user profile isolir (IP › Hotspot › Users). On/Off otomatis dari billing baru tersedia untuk PPPoE.',
        ],
    },
    {
        key: 'static', label: 'Static', langkah: [
            'Paste script di New Terminal router.',
            'Untuk mengisolir, tambahkan IP pelanggan ke address-list ISOLIR (contoh perintah ada di bagian akhir script). Aktifkan kembali = hapus dari list.',
        ],
    },
];

const FIELD_HALAMAN = [
    { name: 'isolir_nama_isp', label: 'Nama ISP', required: true },
    { name: 'isolir_whatsapp', label: 'WhatsApp CS', type: 'tel', placeholder: '0812xxxxxxx', help: 'Tampil sebagai tombol "Hubungi CS via WhatsApp"' },
    { name: 'isolir_pesan', label: 'Pesan', type: 'textarea', required: true, col: 'col-12' },
    { name: 'isolir_pembayaran', label: 'Info Pembayaran', type: 'textarea', col: 'col-12', placeholder: 'BCA 1234567890 a.n. PT MSS\nDANA 0812xxxxxxx', help: 'Rekening / cara bayar, satu per baris' },
];

export default function TemplateIsolir({ pengaturan, opsi: opsiAwal, script: scriptAwal, routerOptions }) {
    const [halaman, setHalaman] = useState(pengaturan);
    const [errHalaman, setErrHalaman] = useState({});
    const [opsi, setOpsi] = useState({ ...opsiAwal, alamat_izin: opsiAwal.alamat_izin.join('\n') });
    const [errOpsi, setErrOpsi] = useState({});
    const [script, setScript] = useState(scriptAwal);
    const [tab, setTab] = useState('pppoe');
    const [busy, setBusy] = useState(null);

    const kirim = async (jenis, method, url, data, setErr, onOk) => {
        setBusy(jenis);
        setErr({});
        try {
            onOk(await api(method, url, data));
        } catch (err) {
            if (err.errors) setErr(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, [].concat(v)[0]])));
            else notify(err.message, 'danger', 7000);
        } finally {
            setBusy(null);
        }
    };
    const simpanHalaman = (e) => {
        e.preventDefault();
        kirim('halaman', 'PUT', '/isolir/pengaturan', halaman, setErrHalaman, (res) => notify(res.message));
    };
    const buatScript = (e) => {
        e.preventDefault();
        kirim('script', 'POST', '/isolir/script', opsi, setErrOpsi, (res) => { setScript(res.script); notify('Script diperbarui'); });
    };
    const salin = () => navigator.clipboard?.writeText(script[tab]).then(() => notify('Script disalin'), () => notify('Gagal menyalin, salin manual', 'danger'));
    const metode = METODE.find((m) => m.key === tab);
    const fieldOpsi = [
        { name: 'router', label: 'Ambil dari Router', type: 'select', options: routerOptions, help: 'Isi nama profile sesuai Profile Isolir router' },
        { name: 'profile', label: 'Nama Profile Isolir', required: true },
        { name: 'jaringan', label: 'Jaringan Isolir', required: true, placeholder: '192.168.200.0/24', help: 'Gateway = IP pertama, pool = sisanya' },
        { name: 'port_proxy', label: 'Port Web Proxy', type: 'number', required: true, help: 'Port proxy di router, hanya bisa diakses pelanggan isolir' },
        { name: 'url_isolir', label: 'URL Halaman Isolir', required: true, col: 'col-12' },
        { name: 'alamat_izin', label: 'Alamat Tambahan yang Diizinkan', type: 'textarea', col: 'col-12', placeholder: 'checkout.xendit.co\ntripay.co.id', help: 'Opsional, satu per baris: domain/IP yang tetap bisa dibuka pelanggan isolir (mis. payment gateway). Server billing otomatis diizinkan.' },
    ];
    const ubahOpsi = (name) => (v) => setOpsi((s) => (name === 'router'
        ? { ...s, router: v, profile: routerOptions.find((r) => String(r.value) === String(v))?.profile_isolir ?? s.profile }
        : { ...s, [name]: v }));

    return (
        <>
            <PageHeader title="Template Isolir" crumbs={['Kelola Data']} />
            <div className="container-fluid">
                <div className="alert alert-light-primary d-flex gap-2">
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        Pelanggan yang diisolir diarahkan ke <strong>halaman isolir billing</strong> saat membuka situs HTTP, sedangkan akses lain (HTTPS, aplikasi, game) diblokir.
                        Situs HTTPS tidak bisa dialihkan oleh router, jadi hanya gagal dimuat. Atur isi halaman di kiri, lalu salin script sesuai metode koneksi.
                    </span>
                </div>
                <div className="row">
                    <div className="col-xl-4">
                        <Card>
                            <CardBody>
                                <div className="d-flex justify-content-between align-items-center mb-3">
                                    <h5 className="mb-0">Halaman Isolir</h5>
                                    <a href={opsiAwal.url_isolir} target="_blank" rel="noreferrer" className="btn btn-sm btn-outline-primary"><ExternalLink size={13} className="me-1" />Lihat</a>
                                </div>
                                <form onSubmit={simpanHalaman}>
                                    {FIELD_HALAMAN.map((f) => (
                                        <Field key={f.name} field={f} value={halaman[f.name] ?? ''} values={halaman} error={errHalaman[f.name]} onChange={(v) => setHalaman((s) => ({ ...s, [f.name]: v }))} />
                                    ))}
                                    <Button color="primary" className="w-100" disabled={busy === 'halaman'}>Simpan Halaman Isolir</Button>
                                </form>
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-8">
                        <Card>
                            <CardBody>
                                <h5 className="mb-3">Script MikroTik</h5>
                                <form onSubmit={buatScript} className="row">
                                    {fieldOpsi.map((f) => (
                                        <div key={f.name} className={f.col ?? 'col-md-6'}>
                                            <Field field={f} value={opsi[f.name] ?? ''} values={opsi} error={errOpsi[f.name]} onChange={ubahOpsi(f.name)} />
                                        </div>
                                    ))}
                                    <div className="col-12 mb-3">
                                        <Button color="info" disabled={busy === 'script'}>Perbarui Script</Button>
                                    </div>
                                </form>

                                <Nav tabs className="border-tab mb-3">
                                    {METODE.map((m) => (
                                        <NavItem key={m.key}><NavLink href="#" className={tab === m.key ? 'active' : ''} onClick={(e) => { e.preventDefault(); setTab(m.key); }}>{m.label}</NavLink></NavItem>
                                    ))}
                                </Nav>
                                <ol className="ps-3 mb-3">
                                    {metode.langkah.map((l) => <li key={l}>{l}</li>)}
                                </ol>
                                {/* Gaya terminal: warna eksplisit agar terbaca di tema terang maupun gelap */}
                                <pre className="p-3 rounded mb-3" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all', fontSize: 12, background: '#1e1e2d', color: '#e4e6ef', maxHeight: 480, overflow: 'auto' }}>{script[tab]}</pre>
                                <Button color="primary" onClick={salin}><Copy size={14} className="me-1" />Salin Script {metode.label}</Button>
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
