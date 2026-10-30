import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Info, RefreshCw } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, rupiah, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

export default function Paket({ filters, rows, routerOptions }) {
    const bisa = useBisa();
    const { cabangOptions } = usePage().props;
    const [syncing, setSyncing] = useState(null);
    const sinkron = async (r) => {
        setSyncing(r.id);
        try {
            notify((await api('POST', `/paket/${r.id}/sinkron`)).message, 'success', 6000);
        } catch (err) {
            notify(err.message, 'danger', 9000);
        } finally {
            setSyncing(null);
            reloadData(['rows']);
        }
    };

    return (
        <CrudPage
            title="Paket Pelanggan" entity="Paket" rows={rows} filters={filters} deleteLabel={(r) => r.nama_paket}
            defaults={{ harga: 0, jenis: 'bulanan', jns_koneksi: 'pppoe', tampil_registrasi: true, buat_profile: true, buat_pool: false, filter_lokasi: false }}
            onSave={saveTo('/paket')} onDelete={deleteFrom('/paket')}
            canAdd={bisa('kelola_data.paket.create')} canEdit={bisa('kelola_data.paket.update')} canDelete={bisa('kelola_data.paket.delete')}
            before={
                <div className="alert alert-light-primary d-flex gap-2">
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        <strong>Profile MikroTik</strong> paket dipakai saat internet pelanggan di-<strong>On</strong>-kan bila profile asalnya tidak diketahui.
                        Aktifkan <em>Buat profile otomatis</em> agar profile (rate limit, local address, IP pool) dibuat/diperbarui di router saat disimpan,
                        atau klik tombol sinkron. Nama profile tidak boleh sama dengan profile isolir router.
                    </span>
                </div>
            }
            rowActions={(r) => bisa('kelola_data.paket.update') && (
                <ActionButton icon={RefreshCw} label="Sinkron ke MikroTik" color="info" loading={syncing === r.id} disabled={!!syncing} onClick={() => sinkron(r)} />
            )}
            columns={[
                { key: 'nama_paket', label: 'Nama Paket', render: (r) => <>{r.nama_paket}{r.keterangan && <><br /><small className="f-light">{r.keterangan}</small></>}</> },
                { key: 'harga', label: 'Harga', align: 'end', render: (r) => (r.harga ? rupiah(r.harga) : <StatusBadge value="free" text="Free" />) },
                { key: 'jenis', label: 'Jenis', render: (r) => <>{r.jenis === 'bulanan' ? 'Bulanan' : 'Non-bulanan'}<br /><small className="f-light">{r.jns_koneksi === 'hotspot' ? 'Hotspot' : 'PPPoE'}</small></> },
                { key: 'profile_mikrotik', label: 'Profile MikroTik', render: (r) => <>{r.profile_mikrotik}{r.rate_limit && <><br /><small className="f-light">{r.rate_limit}</small></>}</> },
                { key: 'router', label: 'Router', render: (r) => r.router?.nama_router ?? 'Semua Router' },
                { key: 'cabang', label: 'Cabang', render: (r) => (r.filter_lokasi ? r.cabang : 'Semua Cabang') },
                {
                    key: 'synced_at', label: 'Sinkron', render: (r) => (!r.buat_profile && !r.synced_at && !r.sync_error ? <small className="f-light">Manual</small> : (
                        <>
                            <StatusBadge value={r.sync_error ? 'offline' : r.synced_at ? 'online' : 'pending'} text={r.sync_error ? 'Gagal' : r.synced_at ? 'Tersinkron' : 'Belum'} />
                            {r.synced_at && <><br /><small className="f-light">{waktu(r.synced_at)}</small></>}
                            {r.sync_error && <><br /><small className="text-danger d-inline-block text-truncate" style={{ maxWidth: 220 }} title={r.sync_error}>{r.sync_error}</small></>}
                        </>
                    )),
                },
                { key: 'tampil_registrasi', label: 'Tampil di Registrasi', hidden: true, render: (r) => <StatusBadge value={r.tampil_registrasi} /> },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
            ]}
            fields={[
                { name: 'nama_paket', label: 'Nama Paket', required: true, placeholder: 'Ultra 20 Mbps' },
                { name: 'keterangan', label: 'Keterangan', placeholder: 'Up to 20 Mbps' },
                { name: 'harga', label: 'Harga', type: 'currency', required: true, help: 'Isi 0 untuk paket free' },
                { name: 'jenis', label: 'Jenis', type: 'radio', required: true, options: [{ value: 'bulanan', label: 'Bulanan' }, { value: 'non_bulanan', label: 'Non-bulanan' }] },
                { name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'radio', required: true, options: [{ value: 'pppoe', label: 'PPPoE' }, { value: 'hotspot', label: 'Hotspot' }] },
                { name: 'router_id', label: 'Router', type: 'select', options: routerOptions, help: 'Kosongkan = profile disinkronkan ke semua router' },
                { name: 'profile_mikrotik', label: 'Profile MikroTik', required: true, placeholder: 'UP-20M', help: 'Nama PPP Profile (atau Hotspot User Profile) di router' },
                { name: 'rate_limit', label: 'Rate Limit (rx/tx)', placeholder: '20M/20M', help: 'Format MikroTik, mis. 20M/20M. Kosongkan = tanpa limit' },
                { name: 'local_address', label: 'Local Address', placeholder: '10.10.1.1', help: 'IP gateway PPPoE (opsional)', show: (v) => v.jns_koneksi !== 'hotspot' },
                { name: 'filter_lokasi', label: 'Hanya untuk cabang tertentu', type: 'switch' },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions, show: (v) => v.filter_lokasi },
                { name: 'tampil_registrasi', label: 'Tampil di form registrasi', type: 'switch' },
                { name: 'buat_profile', label: 'Buat / perbarui profile otomatis di MikroTik saat disimpan', type: 'switch' },
                { name: 'buat_pool', label: 'Buat IP pool', type: 'switch' },
                { name: 'nama_pool', label: 'Nama Pool', required: true, placeholder: 'pool-20m', show: (v) => v.buat_pool },
                { name: 'range_pool', label: 'Range IP Pool', required: true, placeholder: '10.10.1.2-10.10.1.254', help: 'Dipakai sebagai remote address profile', show: (v) => v.buat_pool },
            ]}
        />
    );
}
