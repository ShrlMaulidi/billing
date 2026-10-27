import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Activity } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, waktu } from '../../lib/format';
import { notify } from '../../lib/ui';

export default function Router({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    const [testing, setTesting] = useState(null);
    const tes = (r) => {
        setTesting(r.id);
        setTimeout(() => {
            setTesting(null);
            r.status_koneksi === 'online' ? notify(`${r.nama_router}: koneksi API berhasil (dummy)`) : notify(`${r.nama_router}: gagal terhubung ke ${r.host}:${r.port_api} (dummy)`, 'danger');
        }, 900);
    };
    return (
        <CrudPage
            title="Router" rows={rows} filters={filters} deleteLabel={(r) => r.nama_router} defaults={{ port_api: 8728 }}
            rowActions={(r) => (
                <ActionButton icon={Activity} label="Tes Koneksi" color="info" loading={testing === r.id} onClick={() => tes(r)} />
            )}
            columns={[
                { key: 'kode_router', label: 'ID' }, { key: 'nama_router', label: 'Nama Router' },
                { key: 'host', label: 'IP:Port API', render: (r) => `${r.host}:${r.port_api}` },
                { key: 'username', label: 'User API', hidden: true }, { key: 'password', label: 'Password', sortable: false, render: () => '••••', hidden: true },
                { key: 'cabang', label: 'Cabang' },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
                { key: 'status_koneksi', label: 'Status Koneksi', render: (r) => <><StatusBadge value={r.status_koneksi} /><br /><small className="f-light">{waktu(r.last_checked_at)}</small></> },
            ]}
            fields={[
                { name: 'kode_router', label: 'Kode Router', placeholder: 'RTR-015' },
                { name: 'nama_router', label: 'Nama Router', required: true },
                { name: 'host', label: 'Host (IP / Domain)', required: true },
                { name: 'port_api', label: 'Port API', type: 'number', required: true },
                { name: 'username', label: 'Username API', required: true },
                { name: 'password', label: 'Password API', type: 'password', required: true, help: 'Disimpan terenkripsi, tidak pernah ditampilkan kembali' },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions, col: 'col-12' },
            ]}
        />
    );
}
