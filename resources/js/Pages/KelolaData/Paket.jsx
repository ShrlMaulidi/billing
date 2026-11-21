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
const KONEKSI = { pppoe: 'PPPoE', static: 'Static', hotspot: 'Hotspot', ip_binding: 'IP Binding' };
const KONEKSI_OPTIONS = Object.entries(KONEKSI).map(([value, label]) => ({ value, label }));
// Hanya PPPoE & Hotspot yang memakai profile MikroTik; Static & IP Binding tanpa profile
const pakaiProfile = (v) => !!JENIS_PROFILE[v.jns_koneksi];
const sinkronisasi = (v) => pakaiProfile(v) && !v.buat_profile;
const buatBaru = (v) => pakaiProfile(v) && !!v.buat_profile;
// Router milik cabang terpilih, plus router bersama yang tidak diberi cabang; kosong sebelum cabang dipilih
const routerCabang = (options, v) => (v.id_lokasi ? options.filter((o) => !o.id_lokasi || String(o.id_lokasi) === String(v.id_lokasi)) : []);

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
    const { cabangOptions, auth } = usePage().props;
    // User cabang hanya membuat paket untuk cabangnya sendiri
    const userPusat = !auth.user?.id_lokasi;
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
            filterFields={['cabang', { name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'select', options: KONEKSI_OPTIONS }]}
            defaults={{ harga: 0, jenis: 'bulanan', jns_koneksi: 'pppoe', tampil_registrasi: true, buat_profile: false, buat_pool: false, id_lokasi: filters.cabang ?? auth.user?.id_lokasi ?? '', semua_cabang: false }}
            onSave={saveTo('/paket')} onDelete={deleteFrom('/paket')}
            canAdd={bisa('kelola_data.paket.create')} canEdit={bisa('kelola_data.paket.update')} canDelete={bisa('kelola_data.paket.delete')}
            before={
                <div className="alert alert-light-primary d-flex gap-2">
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        Paket dibuat per cabang, router, dan <strong>jenis koneksi</strong>; pelanggan hanya bisa memilih paket dengan jenis koneksi yang sama.
                        <strong> Profile MikroTik</strong> (khusus PPPoE &amp; Hotspot) dipakai saat akun pelanggan dibuat, saat ganti paket, dan saat internet di-<strong>On</strong>-kan.
                        <em> Sinkronisasi</em>: pilih profile yang sudah ada di router. <em>Buat Baru</em>: profile (rate limit, local address, IP pool) dibuat otomatis
                        di router saat disimpan, tanpa perlu membuat manual di MikroTik. Nama profile tidak boleh sama dengan profile isolir router.
                    </span>
                </div>
            }
            rowActions={(r) => bisa('kelola_data.paket.update') && pakaiProfile(r) && (
                <ActionButton icon={RefreshCw} label="Sinkron ke MikroTik" color="info" loading={syncing === r.id} disabled={!!syncing} onClick={() => sinkron(r)} />
            )}
            columns={[
                { key: 'nama_paket', label: 'Nama Paket', render: (r) => <>{r.nama_paket}{r.keterangan && <><br /><small className="f-light">{r.keterangan}</small></>}</> },
                { key: 'harga', label: 'Harga', align: 'end', render: (r) => (r.harga ? rupiah(r.harga) : <StatusBadge value="free" text="Free" />) },
                { key: 'jenis', label: 'Jenis', render: (r) => <>{r.jenis === 'bulanan' ? 'Bulanan' : 'Non-bulanan'}<br /><small className="f-light">{KONEKSI[r.jns_koneksi] ?? r.jns_koneksi}</small></> },
                { key: 'profile_mikrotik', label: 'Profile MikroTik', render: (r) => (pakaiProfile(r) ? <>{r.profile_mikrotik}{r.rate_limit && <><br /><small className="f-light">{r.rate_limit}</small></>}</> : <small className="f-light">Tanpa profile</small>) },
                { key: 'router', label: 'Router', render: (r) => r.router?.nama_router ?? (pakaiProfile(r) ? 'Semua Router' : '-') },
                { key: 'cabang', label: 'Cabang', render: (r) => (r.semua_cabang ? <>{r.cabang}<br /><small className="f-light">Tampil di semua cabang</small></> : r.cabang) },
                {
                    key: 'synced_at', label: 'Sinkron', render: (r) => (!pakaiProfile(r) ? <small className="f-light">-</small> : !r.buat_profile && !r.synced_at && !r.sync_error ? <small className="f-light">Manual</small> : (
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
                { name: 'id_lokasi', label: 'Cabang / Lokasi', type: 'select', required: true, options: cabangOptions, resets: ['router_id', 'profile_mikrotik'], help: 'Pilih cabang dulu, lalu router yang ada di cabang tersebut' },
                {
                    name: 'semua_cabang', label: 'Tampilkan di semua cabang', type: 'checkbox', show: () => userPusat,
                    help: 'Dicentang: paket bisa dipilih pelanggan semua cabang · Tidak dicentang: hanya pelanggan cabang terpilih',
                },
                {
                    name: 'router_id', label: 'Router', type: 'select', required: true, options: (v) => routerCabang(routerOptions, v), resets: ['profile_mikrotik'],
                    help: 'Router di cabang terpilih; profile dipilih dari router ini', show: sinkronisasi,
                },
                {
                    name: 'router_id', label: 'Router', type: 'select', options: (v) => routerCabang(routerOptions, v), resets: ['profile_mikrotik'], show: (v) => !sinkronisasi(v),
                    help: (v) => (pakaiProfile(v)
                        ? 'Kosongkan = profile dibuat di semua router cabang terpilih (atau semua router bila tampil di semua cabang)'
                        : 'Router di cabang terpilih (opsional)'),
                },
                {
                    name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'select', required: true, options: KONEKSI_OPTIONS, resets: ['profile_mikrotik'],
                    help: 'Pelanggan hanya bisa memilih paket dengan jenis koneksi yang sama · Static & IP Binding tidak memakai profile MikroTik',
                },
                {
                    name: 'buat_profile', label: 'Profile di MikroTik', type: 'radio', required: true, resets: ['profile_mikrotik'], show: pakaiProfile,
                    options: [{ value: false, label: 'Sinkronisasi' }, { value: true, label: 'Buat Baru' }],
                    help: 'Sinkronisasi: profile sudah ada di router · Buat baru: profile dibuat/diperbarui otomatis di router saat disimpan',
                },
                { name: 'profile_mikrotik', label: 'Pilih Profile dari Router', type: 'custom', required: true, render: renderProfile, show: sinkronisasi },
                { name: 'profile_mikrotik', label: 'Nama Profile Baru', required: true, placeholder: 'UP-20M', help: 'Nama PPP Profile (atau Hotspot User Profile) yang dibuat di router', show: buatBaru },
                { name: 'rate_limit', label: 'Rate Limit (rx/tx)', placeholder: '20M/20M', help: 'Format MikroTik, mis. 20M/20M. Kosongkan = tanpa limit', show: buatBaru },
                { name: 'local_address', label: 'Local Address', placeholder: '10.10.1.1', help: 'IP gateway PPPoE (opsional)', show: (v) => buatBaru(v) && v.jns_koneksi !== 'hotspot' },
                { name: 'tampil_registrasi', label: 'Tampil di form registrasi', type: 'switch' },
                { name: 'buat_pool', label: 'Buat IP pool', type: 'switch', show: buatBaru },
                { name: 'nama_pool', label: 'Nama Pool', required: true, placeholder: 'pool-20m', show: (v) => buatBaru(v) && v.buat_pool },
                { name: 'range_pool', label: 'Range IP Pool', required: true, placeholder: '10.10.1.2-10.10.1.254', help: 'Dipakai sebagai remote address profile', show: (v) => buatBaru(v) && v.buat_pool },
            ]}
        />
    );
}
