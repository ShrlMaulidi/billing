import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Camera, Download, LogOut, Trash2 } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Input, Label, Spinner } from 'reactstrap';
import FormGantiPassword from '../../Components/FormGantiPassword';
import { GambarKartuMember, unduhKartuMember } from '../../Components/KartuMember';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { Avatar, StatusBadge } from '../../Components/ui';
import { rupiah, tanggal, waktu } from '../../lib/format';
import { api, notify } from '../../lib/ui';
import { portalLayout, useNavMode } from '../../Layouts/PortalLayout';

const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

/* Foto dipotong persegi di tengah lalu diperkecil di browser: unggahan ringan dan metadata (mis. lokasi GPS) ikut terbuang */
const SISI_FOTO = 400;
const MAKS_FILE_ASLI = 15 * 1024 * 1024;

async function kecilkanFoto(file) {
    let gambar;
    try {
        gambar = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (e) {
        gambar = await new Promise((selesai, gagal) => {
            const img = new Image();
            img.onload = () => selesai(img);
            img.onerror = gagal;
            img.src = URL.createObjectURL(file);
        });
    }
    const lebar = gambar.width;
    const tinggi = gambar.height;
    const sisi = Math.min(lebar, tinggi);
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = Math.min(SISI_FOTO, sisi);
    canvas.getContext('2d').drawImage(gambar, (lebar - sisi) / 2, (tinggi - sisi) / 2, sisi, sisi, 0, 0, canvas.width, canvas.height);
    return new Promise((selesai) => canvas.toBlob(selesai, 'image/jpeg', 0.85));
}

/** Ganti / hapus foto profil; foto tampil di header dan menu Akun (hanya terlihat oleh pemilik akun). */
function FotoProfil({ nama }) {
    const { auth } = usePage().props;
    const masukan = useRef(null);
    const [proses, setProses] = useState(null);

    const pilih = async (e) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        if (!file.type.startsWith('image/') || file.type === 'image/svg+xml') {
            notify('Pilih file gambar (JPG, PNG, atau WebP)', 'danger');
            return;
        }
        if (file.size > MAKS_FILE_ASLI) {
            notify('Ukuran foto terlalu besar (maksimal 15 MB)', 'danger');
            return;
        }
        setProses('unggah');
        try {
            const kecil = await kecilkanFoto(file).catch(() => null);
            if (!kecil) throw { message: 'Gambar tidak bisa dibaca. Coba foto lain berformat JPG atau PNG.' };
            const data = new FormData();
            data.append('foto', kecil, 'foto.jpg');
            notify((await api('POST', '/akun-saya/foto', data)).message);
            router.reload({ only: ['auth'] });
        } catch (err) {
            notify(err.errors?.foto?.[0] ?? err.message, 'danger');
        } finally {
            setProses(null);
        }
    };

    const hapus = async () => {
        setProses('hapus');
        try {
            notify((await api('DELETE', '/akun-saya/foto')).message);
            router.reload({ only: ['auth'] });
        } catch (err) {
            notify(err.message, 'danger');
        } finally {
            setProses(null);
        }
    };

    return (
        <Card>
            <CardHeader className="pb-0"><h5 className="mb-0">Foto Profil</h5></CardHeader>
            <CardBody>
                <div className="d-flex align-items-center gap-3">
                    <Avatar name={nama} src={auth.user.foto} size={72} />
                    <div className="flex-grow-1">
                        <div className="d-flex flex-wrap gap-2">
                            <Button color="primary" size="sm" onClick={() => masukan.current?.click()} disabled={!!proses}>
                                {proses === 'unggah' ? <Spinner size="sm" className="me-1" /> : <Camera size={14} className="me-1" />}{auth.user.foto ? 'Ganti Foto' : 'Unggah Foto'}
                            </Button>
                            {auth.user.foto && (
                                <Button color="light" size="sm" onClick={hapus} disabled={!!proses}>
                                    {proses === 'hapus' ? <Spinner size="sm" className="me-1" /> : <Trash2 size={14} className="me-1" />}Hapus
                                </Button>
                            )}
                        </div>
                        <small className="f-light d-block mt-2">JPG, PNG, atau WebP. Foto dipotong persegi otomatis.</small>
                    </div>
                </div>
                <input ref={masukan} type="file" accept="image/jpeg,image/png,image/webp" className="d-none" onChange={pilih} />
            </CardBody>
        </Card>
    );
}

/** Kartu member tiap langganan aktif: pratinjau + unduh PNG (QR untuk cek tagihan). */
function KartuMemberSaya({ langganan }) {
    const kartu = langganan.filter((l) => l.link_kartu);
    const [mengunduh, setMengunduh] = useState(null);
    if (kartu.length === 0) return null;

    const unduh = async (l) => {
        setMengunduh(l.id);
        try {
            await unduhKartuMember(l);
        } catch (e) {
            notify('Kartu gagal diunduh, coba lagi', 'danger');
        } finally {
            setMengunduh(null);
        }
    };

    return (
        <Card id="kartu" className="portal-anchor">
            <CardHeader className="pb-0"><h5 className="mb-0">Kartu Member</h5></CardHeader>
            <CardBody>
                <div className="kartu-member-daftar">
                    {kartu.map((l) => (
                        <div key={l.id} className="kartu-member-item">
                            <GambarKartuMember p={l} />
                            <Button color="primary" outline size="sm" className="mt-2" onClick={() => unduh(l)} disabled={mengunduh !== null}>
                                {mengunduh === l.id ? <Spinner size="sm" className="me-1" /> : <Download size={14} className="me-1" />}Unduh Kartu
                            </Button>
                        </div>
                    ))}
                </div>
                <small className="f-light d-block mt-3">Simpan di HP atau cetak. QR di kartu bisa dipindai untuk melihat tagihan tanpa login.</small>
            </CardBody>
        </Card>
    );
}

/** Ganti password (wajib konfirmasi password lama); perangkat lain yang memakai akun ini dikeluarkan. */
function GantiPassword() {
    return (
        <Card id="password" className="portal-anchor">
            <CardHeader className="pb-0"><h5 className="mb-0">Ganti Password</h5></CardHeader>
            <CardBody>
                <FormGantiPassword perluPasswordLama keterangan="Minimal 8 karakter, berisi huruf dan angka. Perangkat lain yang memakai akun ini akan keluar." />
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
    const { auth } = usePage().props;

    return (
        <>
            <PitaPortal judul={akun.nama} judulTab="Akun" keterangan={`Username: ${akun.username}${akun.last_login_at ? ` · login terakhir ${waktu(akun.last_login_at)}` : ''}`} ikon={<Avatar name={akun.nama} src={auth.user.foto} size={48} />} />
            <div className="container-fluid">
                <div className="pm-wrap">
                <div className="row pm-isi">
                    {/* HP: kartu Langganan Saya tampil pertama (menumpuk di pita), lalu pengaturan akun */}
                    <div className="col-xl-5 order-2 order-xl-1">
                        <FotoProfil nama={akun.nama} />
                        <PilihanNavigasi />
                        <GantiPassword />
                        <Link href="/logout" method="post" as="button" className="btn btn-outline-danger w-100 mb-4"><LogOut size={15} className="me-1" />Logout</Link>
                    </div>
                    <div className="col-xl-7 order-1 order-xl-2">
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
                        <KartuMemberSaya langganan={langganan} />
                    </div>
                </div>
                </div>
            </div>
        </>
    );
}

Akun.layout = portalLayout;
