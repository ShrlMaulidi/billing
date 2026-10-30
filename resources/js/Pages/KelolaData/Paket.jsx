import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Info, RefreshCw } from 'react-feather';
import { Spinner } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import Select from '../../Components/Select';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, rupiah, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

const JENIS_PROFILE = { pppoe: 'PPP Profile', hotspot: 'Hotspot User Profile' };
const sinkronisasi = (v) => !v.buat_profile;
const buatBaru = (v) => !!v.buat_profile;

/** Mode Sinkronisasi: dropdown profile yang sudah ada di router terpilih, dimuat langsung dari MikroTik. */
function ProfileRouter({ routerId, jenis, value, onChange, invalid }) {
    const [state, setState] = useState({ loading: false, error: null, options: [] });
    const [muatUlang, setMuatUlang] = useState(0);
    useEffect(() => {
        if (!routerId) return undefined;
        let batal = false;
        setState({ loading: true, error: null, options: [] });
        api('GET', `/paket/profile-router/${routerId}?jenis=${jenis}`)
            .then(({ data }) => !batal && setState({
                loading: false, error: null,
                options: data.map((p) => ({ value: p.name, label: p.rate_limit ? `${p.name} (${p.rate_limit})` : p.name })),
            }))
            .catch((err) => !batal && setState({ loading: false, error: err.message, options: [] }));
        return () => { batal = true; };
    }, [routerId, jenis, muatUlang]);

    if (!routerId) return <div className="form-control-plaintext f-light">Pilih router terlebih dahulu</div>;
    if (state.loading) return <div className="form-control-plaintext f-light"><Spinner size="sm" className="me-1" />Memuat {JENIS_PROFILE[jenis]} dari router...</div>;
    if (state.error) {
        return (
            <div className="form-control-plaintext text-danger small">
                {state.error} <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setMuatUlang((n) => n + 1)}>Coba lagi</button>
            </div>
        );
    }
    // Profile yang tersimpan tetap tampil walau sudah tidak ada di router
    const options = value && !state.options.some((o) => o.value === value) ? [{ value, label: `${value} (tidak ditemukan di router)` }, ...state.options] : state.options;
    return <Select value={value} onChange={onChange} options={options} invalid={invalid} placeholder={`— Pilih dari ${state.options.length} ${JENIS_PROFILE[jenis]} —`} />;
}

export default function Paket({ filters, rows, routerOptions }) {
    const bisa = useBisa();
    const { cabangOptions } = usePage().props;
    const [syncing, setSyncing] = useState(null);
    const renderProfile = ({ value, onChange, values, invalid }) => <ProfileRouter routerId={values.router_id} jenis={values.jns_koneksi} value={value} onChange={onChange} invalid={invalid} />;
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
            defaults={{ harga: 0, jenis: 'bulanan', jns_koneksi: 'pppoe', tampil_registrasi: true, buat_profile: false, buat_pool: false, filter_lokasi: false }}
            onSave={saveTo('/paket')} onDelete={deleteFrom('/paket')}
            canAdd={bisa('kelola_data.paket.create')} canEdit={bisa('kelola_data.paket.update')} canDelete={bisa('kelola_data.paket.delete')}
            before={
                <div className="alert alert-light-primary d-flex gap-2">
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        <strong>Profile MikroTik</strong> paket dipakai saat akun pelanggan dibuat, saat ganti paket, dan saat internet di-<strong>On</strong>-kan.
                        <em> Sinkronisasi</em>: pilih profile yang sudah ada di router. <em>Buat Baru</em>: profile (rate limit, local address, IP pool) dibuat otomatis
                        di router saat disimpan, tanpa perlu membuat manual di MikroTik. Nama profile tidak boleh sama dengan profile isolir router.
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
                { name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'radio', required: true, options: [{ value: 'pppoe', label: 'PPPoE' }, { value: 'hotspot', label: 'Hotspot' }], resets: ['profile_mikrotik'] },
                {
                    name: 'buat_profile', label: 'Profile di MikroTik', type: 'radio', required: true, resets: ['profile_mikrotik'],
                    options: [{ value: false, label: 'Sinkronisasi' }, { value: true, label: 'Buat Baru' }],
                    help: 'Sinkronisasi: profile sudah ada di router · Buat baru: profile dibuat/diperbarui otomatis di router saat disimpan',
                },
                { name: 'router_id', label: 'Router', type: 'select', required: true, options: routerOptions, resets: ['profile_mikrotik'], help: 'Profile dipilih dari router ini', show: sinkronisasi },
                { name: 'router_id', label: 'Router', type: 'select', options: routerOptions, help: 'Kosongkan = profile dibuat di semua router', show: buatBaru },
                { name: 'profile_mikrotik', label: 'Pilih Profile dari Router', type: 'custom', required: true, render: renderProfile, show: sinkronisasi },
                { name: 'profile_mikrotik', label: 'Nama Profile Baru', required: true, placeholder: 'UP-20M', help: 'Nama PPP Profile (atau Hotspot User Profile) yang dibuat di router', show: buatBaru },
                { name: 'rate_limit', label: 'Rate Limit (rx/tx)', placeholder: '20M/20M', help: 'Format MikroTik, mis. 20M/20M. Kosongkan = tanpa limit', show: buatBaru },
                { name: 'local_address', label: 'Local Address', placeholder: '10.10.1.1', help: 'IP gateway PPPoE (opsional)', show: (v) => buatBaru(v) && v.jns_koneksi !== 'hotspot' },
                { name: 'filter_lokasi', label: 'Hanya untuk cabang tertentu', type: 'switch' },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions, show: (v) => v.filter_lokasi },
                { name: 'tampil_registrasi', label: 'Tampil di form registrasi', type: 'switch' },
                { name: 'buat_pool', label: 'Buat IP pool', type: 'switch', show: buatBaru },
                { name: 'nama_pool', label: 'Nama Pool', required: true, placeholder: 'pool-20m', show: (v) => buatBaru(v) && v.buat_pool },
                { name: 'range_pool', label: 'Range IP Pool', required: true, placeholder: '10.10.1.2-10.10.1.254', help: 'Dipakai sebagai remote address profile', show: (v) => buatBaru(v) && v.buat_pool },
            ]}
        />
    );
}
