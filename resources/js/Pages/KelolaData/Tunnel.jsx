import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';

export default function Tunnel({ filters, rows, routerOptions }) {
    return (
        <CrudPage
            title="Tunnel" rows={rows} filters={filters} deleteLabel={(r) => r.nama_tunnel} defaults={{ status: 'aktif', protokol: 'L2TP' }}
            columns={[
                { key: 'nama_tunnel', label: 'Nama Tunnel' }, { key: 'username', label: 'User' }, { key: 'password', label: 'Password', sortable: false, render: () => '••••' },
                { key: 'protokol', label: 'Protokol' }, { key: 'router', label: 'Router / Cabang', render: (r) => <>{r.router}<br /><small className="f-light">{r.cabang}</small></> },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
            ]}
            fields={[
                { name: 'nama_tunnel', label: 'Nama Tunnel', required: true },
                { name: 'id_router', label: 'Router', type: 'select', required: true, options: routerOptions },
                { name: 'username', label: 'Username', required: true },
                { name: 'password', label: 'Password', type: 'password', required: true },
                { name: 'protokol', label: 'Protokol', type: 'radio', options: ['L2TP', 'SSTP', 'PPTP'].map((v) => ({ value: v, label: v })) },
                { name: 'status', label: 'Status', type: 'radio', options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
            ]}
        />
    );
}
