import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Slash, UserCheck } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

/** Akun user: role (Hak Akses) + cabang yang boleh diakses. Buat cabangnya dulu di Kelola Data › Cabang. */
export default function Manajemen({ rows, roleOptions }) {
    const { cabangOptions, auth } = usePage().props;
    const bisa = useBisa();
    const [confirm, setConfirm] = useState(null); // row yang akan diubah statusnya

    const ubahStatus = async (r) => {
        try {
            notify((await api('PUT', `/user/${r.id}/status`)).message);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            reloadData(['rows']);
        }
    };
    // Role Super Admin hanya bisa diberikan / diubah oleh Super Admin
    const roles = auth.user?.super_admin ? roleOptions : roleOptions.filter((o) => o.value !== 'Super Admin');
    const bolehUbah = (r) => bisa('user.manajemen.update') && (auth.user?.super_admin || r.role !== 'Super Admin');

    return (
        <>
            <CrudPage
                title="Manajemen User" crumbs={['User']} entity="User" rows={rows} filterFields={[]} canDelete={false} defaults={{ status: 'aktif', id_lokasi: '' }}
                canAdd={bisa('user.manajemen.create')} canEdit={bisa('user.manajemen.update')}
                onSave={saveTo('/user')}
                rowActions={(r) => bolehUbah(r) && r.username !== auth.user?.username && (r.status === 'aktif'
                    ? <ActionButton icon={Slash} label="Nonaktifkan" color="danger" onClick={() => setConfirm(r)} />
                    : <ActionButton icon={UserCheck} label="Aktifkan" color="success" onClick={() => setConfirm(r)} />)}
                columns={[
                    { key: 'nama', label: 'Nama' }, { key: 'username', label: 'Username / Email', render: (r) => <>{r.username}<br /><small className="f-light">{r.email}</small></> },
                    { key: 'tlp', label: 'Tlp', render: (r) => r.tlp || '-' }, { key: 'role', label: 'Role', render: (r) => <span className="badge badge-light-primary">{r.role ?? '-'}</span> },
                    { key: 'cabang_akses', label: 'Cabang yang Diakses' }, { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                    { key: 'last_login_at', label: 'Login Terakhir', render: (r) => <>{waktu(r.last_login_at)}{r.last_login_ip && <><br /><small className="f-light">{r.last_login_ip}</small></>}</> },
                ]}
                fields={[
                    { name: 'nama', label: 'Nama', required: true },
                    { name: 'username', label: 'Username', required: true, help: 'Huruf, angka, titik, strip, atau garis bawah' },
                    { name: 'email', label: 'Email', type: 'email' },
                    { name: 'tlp', label: 'Tlp', type: 'tel' },
                    { name: 'role', label: 'Role', type: 'select', required: true, options: roles, help: 'Hak akses tiap role diatur di User › Hak Akses' },
                    { name: 'id_lokasi', label: 'Akses Cabang', type: 'select', options: [{ value: '', label: 'Semua Cabang' }, ...cabangOptions], help: 'Pilih cabang agar user hanya melihat data cabang tersebut' },
                    { name: 'password', label: 'Password', type: 'password', help: 'Minimal 8 karakter berisi huruf dan angka. Saat edit, kosongkan bila tidak diubah.' },
                    { name: 'status', label: 'Status', type: 'radio', options: [{ value: 'aktif', label: 'Aktif' }, { value: 'nonaktif', label: 'Nonaktif' }] },
                ]}
            />
            <ConfirmDialog isOpen={!!confirm} toggle={() => setConfirm(null)} title="Ubah Status User" color={confirm?.status === 'aktif' ? 'danger' : 'success'}
                message={confirm && <>{confirm.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} user <strong>{confirm.nama}</strong>?{confirm.status === 'aktif' && ' User akan langsung keluar dari aplikasi.'}</>}
                onConfirm={() => ubahStatus(confirm)} />
        </>
    );
}
