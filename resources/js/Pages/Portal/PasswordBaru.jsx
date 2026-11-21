import { Link, router, usePage } from '@inertiajs/react';
import { Lock, LogOut } from 'react-feather';
import { Card, CardBody, Modal, ModalBody, ModalHeader } from 'reactstrap';
import FormGantiPassword from '../../Components/FormGantiPassword';
import PageHeader from '../../Components/PageHeader';
import { Avatar } from '../../Components/ui';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Kerangka kartu dashboard di belakang modal; data tagihan baru dimuat setelah password baru dibuat. */
const Kerangka = ({ tinggi }) => (
    <Card aria-hidden="true">
        <CardBody className="placeholder-glow">
            <span className="placeholder col-4 mb-3 d-block" />
            <span className="placeholder col-7 d-block" style={{ height: tinggi }} />
        </CardBody>
    </Card>
);

/**
 * Login pertama / setelah password di-reset: tampilan dashboard pelanggan dengan modal wajib membuat password baru
 * (tidak bisa ditutup). Setelah tersimpan, pelanggan masuk ke Home.
 */
export default function PasswordBaru({ akun }) {
    const { auth } = usePage().props;

    return (
        <>
            <PageHeader title="Home" home="/akun-saya" />
            <div className="container-fluid">
                <div className="portal-sapa">
                    <Avatar name={auth.user.nama} size={26} />
                    <span>Halo, <strong>{akun.nama}</strong></span>
                    <span className="f-light">· masuk sebagai {akun.username}</span>
                </div>
                <Kerangka tinggi={44} />
                <div className="row">
                    {[1, 2, 3].map((i) => <div key={i} className="col-sm-6 col-xl-4"><Kerangka tinggi={24} /></div>)}
                </div>
            </div>

            <Modal isOpen centered backdrop="static" keyboard={false} aria-labelledby="judul-password-baru">
                <ModalHeader tag="div" className="d-flex align-items-center">
                    <h5 id="judul-password-baru" className="mb-0 d-flex align-items-center gap-2"><Lock size={18} />Buat Password Baru</h5>
                </ModalHeader>
                <ModalBody>
                    <p>
                        Selamat datang, <strong>{akun.nama}</strong>. Demi keamanan akun Anda, ganti password awal (nomor HP <strong>{akun.username}</strong>)
                        dengan password Anda sendiri sebelum melanjutkan.
                    </p>
                    <FormGantiPassword labelSimpan="Simpan & Lanjutkan"
                        keterangan="Minimal 8 karakter, berisi huruf dan angka. Jangan memakai nomor HP, tanggal lahir, atau password yang mudah ditebak."
                        aksiLain={<Link href="/logout" method="post" as="button" className="btn btn-light me-auto"><LogOut size={14} className="me-1" />Keluar</Link>}
                        onSelesai={() => router.visit('/akun-saya', { replace: true })} />
                </ModalBody>
            </Modal>
        </>
    );
}

PasswordBaru.layout = portalLayout;
