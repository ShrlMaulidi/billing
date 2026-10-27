import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Key, Slash, UserCheck } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

export default function Manajemen({ rows, roleOptions }) {
    const { cabangOptions } = usePage().props;
    const [confirm, setConfirm] = useState(null); // { row, aksi }

    return (
        <>
            <CrudPage
                title="Manajemen User" crumbs={['User']} entity="User" rows={rows} filterFields={[]} canDelete={false} defaults={{ status: 'aktif' }}
                rowActions={(r) => (
                    <>
                        <ActionButton icon={Key} label="Reset Password" color="info" onClick={() => setConfirm({ row: r, aksi: 'reset' })} />
                        {r.status === 'aktif'
                            ? <ActionButton icon={Slash} label="Nonaktifkan" color="danger" onClick={() => setConfirm({ row: r, aksi: 'status' })} />
                            : <ActionButton icon={UserCheck} label="Aktifkan" color="success" onClick={() => setConfirm({ row: r, aksi: 'status' })} />}
                    </>
                )}
                columns={[
                    { key: 'nama', label: 'Nama' }, { key: 'username', label: 'Username / Email', render: (r) => <>{r.username}<br /><small className="f-light">{r.email}</small></> },
                    { key: 'tlp', label: 'Tlp' }, { key: 'role', label: 'Role', render: (r) => <span className="badge badge-light-primary">{r.role}</span> },
                    { key: 'cabang_akses', label: 'Cabang yang Diakses' }, { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                    { key: 'last_login_at', label: 'Login Terakhir', render: (r) => <>{waktu(r.last_login_at)}<br /><small className="f-light">{r.last_login_ip}</small></> },
                ]}
                fields={[
                    { name: 'nama', label: 'Nama', required: true },
                    { name: 'username', label: 'Username', required: true },
                    { name: 'email', label: 'Email', type: 'email' },
                    { name: 'tlp', label: 'Tlp', type: 'tel' },
                    { name: 'id_role', label: 'Role', type: 'select', required: true, options: roleOptions },
                    { name: 'lokasi', label: 'Akses Cabang', type: 'select', options: [{ value: 'all', label: 'Semua Cabang' }, ...cabangOptions], help: 'Multi-cabang menyusul di tahap backend' },
                    { name: 'password', label: 'Password', type: 'password', help: 'Kosongkan saat edit jika tidak diubah' },
                    { name: 'status', label: 'Status', type: 'radio', options: [{ value: 'aktif', label: 'Aktif' }, { value: 'nonaktif', label: 'Nonaktif' }] },
                ]}
            />
            <ConfirmDialog isOpen={!!confirm} toggle={() => setConfirm(null)}
                title={confirm?.aksi === 'reset' ? 'Reset Password' : 'Ubah Status User'} color={confirm?.aksi === 'reset' ? 'warning' : 'danger'}
                message={confirm && (confirm.aksi === 'reset'
                    ? <>Reset password <strong>{confirm.row.nama}</strong>? Password baru akan dikirim via WhatsApp.</>
                    : <>{confirm.row.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} user <strong>{confirm.row.nama}</strong>?</>)}
                onConfirm={() => dummy(confirm.aksi === 'reset' ? 'Reset password' : 'Ubah status user')} />
        </>
    );
}
