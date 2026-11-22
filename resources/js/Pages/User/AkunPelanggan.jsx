import { useState } from 'react';
import { Key, Slash, UserCheck } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify, reloadData, useBisa } from '../../lib/ui';

const STATUS_PELANGGAN = { aktif: '', berhenti: ' (berhenti)' };

/**
 * Akun login pelanggan, dibuat otomatis saat pelanggan ditambahkan di Data Pelanggan (username & password awal = nomor HP).
 * Terpisah dari Manajemen User; di sini hanya reset password dan aktif/nonaktif.
 */
export default function AkunPelanggan({ rows, filters }) {
    const bisa = useBisa();
    const [confirm, setConfirm] = useState(null); // { row, aksi: 'reset' | 'status' }

    const jalankan = async ({ row, aksi }) => {
        try {
            const url = aksi === 'reset' ? `/user/akun-pelanggan/${row.id}/reset-password` : `/user/akun-pelanggan/${row.id}/status`;
            notify((await api('PUT', url)).message);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            reloadData(['rows']);
        }
    };

    const pesan = (c) => {
        if (!c) return null;
        if (c.aksi === 'reset') {
            return (
                <>
                    Kembalikan password akun <strong>{c.row.nama}</strong> ke nomor HP <strong>{c.row.username}</strong>? Pelanggan akan keluar dari semua perangkat
                    dan wajib membuat password baru saat login.
                    <div className="alert alert-light-warning mt-3 mb-0 small">
                        Pastikan yang meminta benar pemilik akun: cocokkan nama, alamat pemasangan, dan ID pelanggan. Nomor HP saja tidak cukup sebagai bukti.
                    </div>
                </>
            );
        }
        return <>{c.row.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} akun <strong>{c.row.nama}</strong>?{c.row.status === 'aktif' && ' Pelanggan akan langsung keluar dan tidak bisa login.'}</>;
    };

    return (
        <>
            <CrudPage
                title="Akun Pelanggan" crumbs={['User']} entity="Akun Pelanggan" rows={rows} filters={filters} filterFields={['cabang']}
                canAdd={false} canEdit={false} canDelete={false}
                before={(
                    <div className="alert alert-light-primary">
                        Akun dibuat otomatis saat pelanggan ditambahkan di <strong>Pelanggan › Data Pelanggan</strong>. Username dan password awal = nomor HP pelanggan (format 08…).
                        Pelanggan bernomor HP sama memakai satu akun.
                    </div>
                )}
                rowActions={(r) => bisa('user.akun_pelanggan.update') && (
                    <>
                        <ActionButton icon={Key} label="Reset Password" color="warning" onClick={() => setConfirm({ row: r, aksi: 'reset' })} />
                        {r.status === 'aktif'
                            ? <ActionButton icon={Slash} label="Nonaktifkan" color="danger" onClick={() => setConfirm({ row: r, aksi: 'status' })} />
                            : <ActionButton icon={UserCheck} label="Aktifkan" color="success" onClick={() => setConfirm({ row: r, aksi: 'status' })} />}
                    </>
                )}
                columns={[
                    { key: 'nama', label: 'Nama' },
                    { key: 'username', label: 'Username (No. HP)' },
                    {
                        key: 'pelanggan', label: 'Pelanggan', sortable: false,
                        sortValue: (r) => r.pelanggan.map((p) => `${p.id_pelanggan} ${p.nama}`).join(' '),
                        render: (r) => (r.pelanggan.length
                            ? r.pelanggan.map((p) => <div key={p.id_pelanggan}><small>{p.id_pelanggan} · {p.nama}{STATUS_PELANGGAN[p.status] ?? ''}</small></div>)
                            : <small className="f-light">Tidak dipakai pelanggan</small>),
                    },
                    { key: 'cabang', label: 'Cabang' },
                    { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                    { key: 'last_login_at', label: 'Login Terakhir', render: (r) => <>{waktu(r.last_login_at)}{r.last_login_ip && <><br /><small className="f-light">{r.last_login_ip}</small></>}</> },
                ]}
            />
            <ConfirmDialog isOpen={!!confirm} toggle={() => setConfirm(null)}
                title={confirm?.aksi === 'reset' ? 'Reset Password' : 'Ubah Status Akun'}
                color={confirm?.aksi === 'reset' ? 'warning' : confirm?.row.status === 'aktif' ? 'danger' : 'success'}
                message={pesan(confirm)} onConfirm={() => jalankan(confirm)} />
        </>
    );
}
