import { Head, Link, router } from '@inertiajs/react';
import { Lock, LogOut } from 'react-feather';
import { Modal, ModalBody, ModalHeader } from 'reactstrap';
import FormGantiPassword from '../../Components/FormGantiPassword';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Kerangka kartu dashboard di belakang modal; data tagihan baru dimuat setelah password baru dibuat. */
const Kerangka = ({ tinggi }) => (
    <div className="card pm-card">
        <div className="pm-card-body placeholder-glow">
            <span className="placeholder col-5 mb-3 d-block" />
            <span className="placeholder col-12 d-block rounded" style={{ height: tinggi }} />
        </div>
    </div>
);

/**
 * Login pertama / setelah password di-reset: tampilan dashboard pelanggan dengan modal wajib membuat password baru
 * (tidak bisa ditutup). Setelah tersimpan, pelanggan masuk ke Home.
 */
export default function PasswordBaru({ akun }) {
    return (
        <>
            <Head title="Buat Password Baru" />
            <section className="pm-band no-print">
                <div className="pm-band-dalam">
                    <p className="pm-halo">HALO, <strong>{akun.nama.toUpperCase()}</strong></p>
                    <p className="pm-user">Masuk sebagai {akun.username}</p>
                </div>
            </section>
            <div className="container-fluid">
                <div className="pm-wrap pm-isi pm-beranda" aria-hidden="true">
                    <div className="pm-kolom"><Kerangka tinggi={120} /></div>
                    <div className="pm-kolom"><Kerangka tinggi={150} /></div>
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
