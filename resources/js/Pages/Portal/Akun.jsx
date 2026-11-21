import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { LogOut, Save } from 'react-feather';
import { Button, Card, CardBody, CardHeader, FormFeedback, Input, Label, Spinner } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { Avatar, StatusBadge } from '../../Components/ui';
import { rupiah, tanggal, waktu } from '../../lib/format';
import { api, notify } from '../../lib/ui';
import { portalLayout, useNavMode } from '../../Layouts/PortalLayout';

const KOSONG = { password_lama: '', password: '', password_confirmation: '' };
const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

/** Ganti password (wajib konfirmasi password lama); perangkat lain yang memakai akun ini dikeluarkan. */
function GantiPassword() {
    const [data, setData] = useState(KOSONG);
    const [errors, setErrors] = useState({});
    const [proses, setProses] = useState(false);

    const simpan = async (e) => {
        e.preventDefault();
        setProses(true);
        setErrors({});
        try {
            notify((await api('PUT', '/akun-saya/password', data)).message);
            setData(KOSONG);
        } catch (err) {
            setErrors(err.errors ?? { password_lama: [err.message] });
        } finally {
            setProses(false);
        }
    };

    const kolom = (name, label, autoComplete) => (
        <div className="mb-3">
            <Label for={name}>{label}</Label>
            <Input id={name} type="password" value={data[name]} autoComplete={autoComplete} maxLength={100} invalid={!!errors[name]}
                onChange={(e) => setData({ ...data, [name]: e.target.value })} />
            {errors[name] && <FormFeedback>{errors[name][0]}</FormFeedback>}
        </div>
    );

    return (
        <Card>
            <CardHeader className="pb-0"><h5 className="mb-0">Ganti Password</h5></CardHeader>
            <CardBody>
                <form onSubmit={simpan} noValidate>
                    {kolom('password_lama', 'Password lama', 'current-password')}
                    {kolom('password', 'Password baru', 'new-password')}
                    {kolom('password_confirmation', 'Ulangi password baru', 'new-password')}
                    <p className="small f-light">Minimal 8 karakter, berisi huruf dan angka. Perangkat lain yang memakai akun ini akan keluar.</p>
                    <Button color="primary" type="submit" disabled={proses}>{proses ? <Spinner size="sm" className="me-1" /> : <Save size={14} className="me-1" />}Simpan Password</Button>
                </form>
            </CardBody>
        </Card>
    );
}

/** Pilihan navigasi di HP: navbar bawah atau sidebar (disimpan di perangkat ini). */
function PilihanNavigasi() {
    const { navMode, setNavMode } = useNavMode();
    const pilihan = [
        { value: 'navbar', label: 'Navbar bawah', desc: 'Menu Home, Riwayat, Tiket, dan Akun selalu terlihat di bawah layar' },
        { value: 'sidebar', label: 'Sidebar', desc: 'Menu dibuka lewat tombol di kiri atas, seperti di komputer' },
    ];

    return (
        <Card className="d-lg-none">
            <CardHeader className="pb-0"><h5 className="mb-0">Navigasi di HP</h5></CardHeader>
            <CardBody>
                {pilihan.map((p) => (
                    <div key={p.value} className="form-check mb-2">
                        <Input className="form-check-input" type="radio" name="nav-mode" id={`nav-${p.value}`} checked={navMode === p.value} onChange={() => setNavMode(p.value)} />
                        <Label className="form-check-label" for={`nav-${p.value}`}><strong>{p.label}</strong><br /><small className="f-light">{p.desc}</small></Label>
                    </div>
                ))}
            </CardBody>
        </Card>
    );
}

/** Akun pelanggan: profil, langganan, ganti password, pilihan navigasi HP, dan logout. */
export default function Akun({ akun, langganan }) {
    return (
        <>
            <PageHeader title="Akun" home="/akun-saya" />
            <div className="container-fluid">
                <div className="row">
                    <div className="col-xl-5">
                        <Card>
                            <CardBody className="d-flex align-items-center gap-3">
                                <Avatar name={akun.nama} size={56} />
                                <div className="min-w-0">
                                    <h5 className="mb-1">{akun.nama}</h5>
                                    <div className="f-light">Username: {akun.username}</div>
                                    {akun.last_login_at && <small className="f-light">Login terakhir {waktu(akun.last_login_at)}</small>}
                                </div>
                            </CardBody>
                        </Card>
                        <PilihanNavigasi />
                        <GantiPassword />
                        <Link href="/logout" method="post" as="button" className="btn btn-outline-danger w-100 mb-4"><LogOut size={15} className="me-1" />Logout</Link>
                    </div>
                    <div className="col-xl-7">
                        <Card>
                            <CardHeader className="pb-0"><h5 className="mb-0">Langganan Saya</h5></CardHeader>
                            <CardBody>
                                {langganan.length === 0 && <p className="f-light mb-0">Belum ada langganan yang terhubung ke akun ini.</p>}
                                {langganan.map((l) => (
                                    <div key={l.id_pelanggan ?? l.nama} className="portal-langganan">
                                        <div className="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <div><strong>{l.paket ?? '-'}</strong><br /><small className="f-light">ID {l.id_pelanggan ?? '-'} · {l.nama}</small></div>
                                            <StatusBadge value={l.status} />
                                        </div>
                                        <dl className="portal-dl">
                                            <dt>Harga</dt><dd>{l.harga != null ? `${rupiah(l.harga)} / bulan` : '-'}</dd>
                                            <dt>Jenis tagihan</dt><dd>{JENIS[l.jns_tagihan] ?? '-'}</dd>
                                            <dt>Jatuh tempo</dt><dd>{l.tgl_jatuh_tempo ? `Tanggal ${l.tgl_jatuh_tempo} setiap bulan` : '-'}</dd>
                                            <dt>Cabang</dt><dd>{l.cabang ?? '-'}</dd>
                                            <dt>Terdaftar</dt><dd>{tanggal(l.tgl_registrasi)}</dd>
                                            <dt>Alamat</dt><dd>{l.alamat ?? '-'}</dd>
                                        </dl>
                                    </div>
                                ))}
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

Akun.layout = portalLayout;
