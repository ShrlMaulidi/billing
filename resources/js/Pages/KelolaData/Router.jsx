import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, Info } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

export default function Router({ filters, rows, tunnelOptions }) {
    const bisa = useBisa();
    const { cabangOptions } = usePage().props;
    const [testing, setTesting] = useState(null);
    const tes = async (r) => {
        setTesting(r.id);
        try {
            notify((await api('POST', `/mikrotik/router/${r.id}/test`)).message, 'success', 6000);
        } catch (err) {
            notify(err.message, 'danger', 8000);
        } finally {
            setTesting(null);
            reloadData(['rows']);
        }
    };

    return (
        <CrudPage
            title="Router" rows={rows} filters={filters} deleteLabel={(r) => r.nama_router}
            defaults={{ mode_koneksi: 'langsung', port_api: 8728, ssl: false, profile_isolir: 'ISOLIR' }}
            onSave={saveTo('/mikrotik/router')} onDelete={deleteFrom('/mikrotik/router')}
            canAdd={bisa('kelola_data.router.create')} canEdit={bisa('kelola_data.router.update')} canDelete={bisa('kelola_data.router.delete')}
            before={
                <div className="alert alert-light-primary d-flex gap-2">
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        <strong>Langsung</strong>: router utama ber-IP publik, billing terhubung ke IP publik + port API.{' '}
                        <strong>Tunnel</strong>: router turunan ber-IP private, billing terhubung ke IP router di dalam tunnel (atur di menu Tunnel).
                        Buat user API di Mikrotik (System › Users) dengan policy <code>api, read, write</code>.
                    </span>
                </div>
            }
            rowActions={(r) => (
                <>
                    <ActionButton icon={Activity} label="Tes Koneksi" color="info" loading={testing === r.id} onClick={() => tes(r)} />
                </>
            )}
            columns={[
                { key: 'kode_router', label: 'Kode', render: (r) => r.kode_router || '-' },
                { key: 'nama_router', label: 'Nama Router', render: (r) => <>{r.nama_router}{r.identity && <><br /><small className="f-light">{r.identity} · v{r.versi_routeros}</small></>}</> },
                {
                    key: 'alamat_api', label: 'Koneksi', render: (r) => (
                        <>
                            <span className={`badge badge-light-${r.mode_koneksi === 'tunnel' ? 'warning' : 'primary'} me-1`}>{r.mode_koneksi === 'tunnel' ? 'Tunnel' : 'Langsung'}</span>
                            {r.alamat_api ?? '-'}:{r.port_api}{r.ssl && ' (SSL)'}
                            {r.tunnel && <><br /><small className="f-light">via {r.tunnel.nama_tunnel}</small></>}
                        </>
                    ),
                },
                { key: 'cabang', label: 'Cabang' },
                { key: 'profile_isolir', label: 'Profile Isolir' },
                { key: 'pelanggan_count', label: 'Pelanggan', align: 'end', render: (r) => angka(r.pelanggan_count) },
                {
                    key: 'status_koneksi', label: 'Status Koneksi', render: (r) => (
                        <>
                            <StatusBadge value={r.status_koneksi} />
                            <br /><small className="f-light">{r.last_checked_at ? waktu(r.last_checked_at) : 'Belum dites'}</small>
                            {r.last_error && r.status_koneksi === 'offline' && <><br /><small className="text-danger d-inline-block text-truncate" style={{ maxWidth: 220 }} title={r.last_error}>{r.last_error}</small></>}
                        </>
                    ),
                },
            ]}
            fields={[
                { name: 'kode_router', label: 'Kode Router', placeholder: 'RTR-001' },
                { name: 'nama_router', label: 'Nama Router', required: true },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', options: cabangOptions },
                { name: 'mode_koneksi', label: 'Mode Koneksi', type: 'radio', required: true, options: [{ value: 'langsung', label: 'Langsung (IP Publik)' }, { value: 'tunnel', label: 'Tunnel' }] },
                { name: 'host', label: 'Host / IP Publik', required: true, placeholder: '203.0.113.10 atau router.domain.id', show: (v) => v.mode_koneksi !== 'tunnel' },
                { name: 'tunnel_id', label: 'Tunnel', type: 'select', required: true, options: tunnelOptions, help: 'Billing terhubung ke IP router di dalam tunnel ini', show: (v) => v.mode_koneksi === 'tunnel' },
                { name: 'port_api', label: 'Port API', type: 'number', required: true, help: '8728 (API) atau 8729 (API-SSL)' },
                { name: 'ssl', label: 'Gunakan API-SSL', type: 'switch' },
                { name: 'username', label: 'Username API', required: true },
                { name: 'password', label: 'Password API', type: 'password', help: 'Saat edit, kosongkan bila tidak diubah. Disimpan terenkripsi.' },
                { name: 'profile_isolir', label: 'Nama Profile Isolir', required: true, help: 'PPP Profile di router yang dipakai saat pelanggan diisolir' },
            ]}
        />
    );
}
