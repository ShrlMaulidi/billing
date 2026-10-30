import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Info, Power, WifiOff } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import Select from '../../Components/Select';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { rupiah, tanggal, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo } from '../../lib/ui';

const RELOAD = ['rows', 'jumlah'];
const JUDUL = { on: 'Pelanggan On', off: 'Pelanggan Off' };
const KONEKSI = { pppoe: 'PPPoE', hotspot: 'Hotspot', static: 'Static', ip_binding: 'IP Binding' };
// Jenis koneksi yang punya akun di router (Sinkronisasi / Buat baru)
const AKUN = { pppoe: 'PPP Secret', hotspot: 'Hotspot User', ip_binding: 'IP Binding' };
const akunDipilih = (jenis, mode) => (v) => (Array.isArray(jenis) ? jenis : [jenis]).includes(v.jns_koneksi) && v.mode_akun === mode;
// Pilihan tanpa cabang (router/paket untuk semua cabang) tampil di setiap cabang
const sesuaiCabang = (options, v) => options.filter((o) => !o.id_lokasi || String(o.id_lokasi) === String(v.id_lokasi));

/** Mode Sinkronisasi: dropdown akun yang sudah ada di router terpilih, dimuat langsung dari Mikrotik. */
function AkunRouter({ routerId, jenis, value, onChange, invalid }) {
    const [state, setState] = useState({ loading: false, error: null, options: [] });
    const [muatUlang, setMuatUlang] = useState(0);
    useEffect(() => {
        if (!routerId) return undefined;
        let batal = false;
        setState({ loading: true, error: null, options: [] });
        api('GET', `/pelanggan/akun-router/${routerId}?jenis=${jenis}`)
            .then(({ data }) => !batal && setState({
                loading: false, error: null,
                options: data.map((a) => ({ value: a.name, label: [a.name, a.profile && `(${a.profile})`, a.address, a.comment && `· ${a.comment}`].filter(Boolean).join(' ') })),
            }))
            .catch((err) => !batal && setState({ loading: false, error: err.message, options: [] }));
        return () => { batal = true; };
    }, [routerId, jenis, muatUlang]);

    if (!routerId) return <div className="form-control-plaintext f-light">Pilih router terlebih dahulu</div>;
    if (state.loading) return <div className="form-control-plaintext f-light"><Spinner size="sm" className="me-1" />Memuat {AKUN[jenis]} dari router...</div>;
    if (state.error) {
        return (
            <div className="form-control-plaintext text-danger small">
                {state.error} <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setMuatUlang((n) => n + 1)}>Coba lagi</button>
            </div>
        );
    }
    // Akun yang tersimpan tetap tampil walau sudah tidak ada di router
    const options = value && !state.options.some((o) => o.value === value) ? [{ value, label: `${value} (tidak ditemukan di router)` }, ...state.options] : state.options;
    return <Select value={value} onChange={onChange} options={options} invalid={invalid} placeholder={`— Pilih dari ${state.options.length} ${AKUN[jenis]} —`} />;
}

/**
 * Data pelanggan (database). Halaman Pelanggan On punya tombol Off (isolir),
 * halaman Pelanggan Off punya tombol On (kembali ke profile asal).
 */
export default function PelangganIndex({ filters, status, rows, jumlah, areaOptions, routerOptions, paketOptions, salesOptions }) {
    const { cabangOptions } = usePage().props;
    // Saat edit, akun dianggap sudah ada di router (pilih "Buat baru" untuk membuat ulang)
    const formRows = useMemo(() => rows.map((r) => ({ ...r, mode_akun: 'sinkron' })), [rows]);
    const renderAkun = ({ value, onChange, values, invalid }) => <AkunRouter routerId={values.router_id} jenis={values.jns_koneksi} value={value} onChange={onChange} invalid={invalid} />;
    const [busy, setBusy] = useState(null);
    const [confirm, setConfirm] = useState(null); // { p, aksi }
    const [pilihProfile, setPilihProfile] = useState(null); // { p, options, value }

    const jalankan = async (p, aksi, profile) => {
        setBusy(p.id);
        try {
            notify((await api('POST', `/pelanggan/${p.id}/${aksi}`, profile ? { profile } : undefined)).message, 'success', 6000);
        } catch (err) {
            notify(err.message, 'danger', 9000);
        } finally {
            setBusy(null);
            reloadData(RELOAD);
        }
    };
    const klikOn = async (p) => {
        if (p.profile_asal || p.profile_paket) return setConfirm({ p, aksi: 'aktifkan' });
        // Profile asal & paket tidak diketahui (mis. diisolir langsung di router) → pilih profile dulu
        setBusy(p.id);
        try {
            const { data } = await api('GET', `/mikrotik/router/${p.router_id}/profiles`);
            setPilihProfile({ p, options: data.filter((x) => x !== p.router?.profile_isolir).map((x) => ({ value: x, label: x })), value: '' });
        } catch (err) {
            notify(err.message, 'danger', 9000);
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            <CrudPage
                title={JUDUL[status] ?? 'Data Pelanggan'} entity="Pelanggan" crumbs={['Pelanggan']} rows={formRows} filters={filters}
                deleteLabel={(r) => r.nama}
                defaults={{ jns_tagihan: 'prabayar', id_lokasi: filters.cabang ?? '', jns_koneksi: 'pppoe', mode_akun: 'sinkron', tgl_registrasi: new Date().toISOString().slice(0, 10), ppn: false }}
                onSave={saveTo('/pelanggan', RELOAD)} onDelete={deleteFrom('/pelanggan', RELOAD)}
                before={
                    <>
                        {[
                            [!cabangOptions.length, <>Belum ada cabang. Tambahkan dulu di <Link href="/kelola-data/cabang" className="fw-semibold">Kelola Data › Cabang</Link>.</>],
                            [!routerOptions.length, <>Belum ada router. Tambahkan dulu di <Link href="/kelola-data/router" className="fw-semibold">Kelola Data › Router</Link> agar pelanggan bisa di-On/Off.</>],
                            [!salesOptions.length, <>Belum ada user dengan role <strong>Sales</strong>. Buat lewat terminal: <code>php artisan user:create namasales --role=Sales</code></>],
                        ].filter(([tampil]) => tampil).map(([, pesan], i) => (
                            <div key={i} className="alert alert-light-warning d-flex gap-2 align-items-center">
                                <Info size={16} className="flex-shrink-0" />
                                <span>{pesan}</span>
                            </div>
                        ))}
                        <div className="btn-group mb-3">
                            {[['', 'Semua', jumlah.semua], ['on', 'On', jumlah.on], ['off', 'Off', jumlah.off]].map(([s, label, n]) => (
                                <Link key={label} href={`/pelanggan${s ? `/${s}` : ''}`} data={filters.cabang ? { cabang: filters.cabang } : {}} prefetch
                                    className={`btn btn-sm ${(status ?? '') === s ? 'btn-primary' : 'btn-outline-primary'}`}>
                                    {label} <span className="ms-1 opacity-75">{n}</span>
                                </Link>
                            ))}
                        </div>
                    </>
                }
                rowActions={status ? (p) => (status === 'on'
                    ? <ActionButton icon={WifiOff} label={p.jns_koneksi === 'pppoe' ? 'Off (Isolir)' : 'Off baru tersedia untuk PPPoE'} color="danger" loading={busy === p.id} disabled={!!busy || p.jns_koneksi !== 'pppoe'} onClick={() => setConfirm({ p, aksi: 'isolir' })} />
                    : <ActionButton icon={Power} label={p.jns_koneksi === 'pppoe' ? 'On (Aktifkan)' : 'On baru tersedia untuk PPPoE'} color="success" loading={busy === p.id} disabled={!!busy || p.jns_koneksi !== 'pppoe'} onClick={() => klikOn(p)} />) : undefined}
                columns={[
                    { key: 'id_pelanggan', label: 'ID Pelanggan' },
                    { key: 'nama', label: 'Nama', render: (p) => <>{p.nama}{p.tlp && <><br /><small className="f-light">{p.tlp}</small></>}</> },
                    { key: 'alamat', label: 'Alamat', hidden: true },
                    { key: 'cabang', label: 'Cabang' },
                    { key: 'paket', label: 'Paket', render: (p) => <>{p.paket}{p.harga != null && <><br /><small className="f-light">{rupiah(p.harga)}</small></>}</> },
                    {
                        key: 'username_pppoe', label: 'Router / Koneksi', sortValue: (p) => p.username_pppoe ?? p.mac_address ?? p.ip_address,
                        render: (p) => <>{p.router?.nama_router ?? <span className="text-danger">Router dihapus</span>}<br /><small className="f-light">{KONEKSI[p.jns_koneksi]} · {p.username_pppoe ?? p.mac_address ?? p.ip_address}</small></>,
                    },
                    { key: 'tgl_registrasi', label: 'Registrasi', hidden: true, render: (p) => tanggal(p.tgl_registrasi) },
                    {
                        key: 'status_langganan', label: 'Internet', render: (p) => (
                            <>
                                <StatusBadge value={p.status_langganan} text={p.status_langganan === 'off' ? 'Off' : 'On'} />
                                {p.status_langganan === 'off' && <><br /><small className="f-light">{p.isolir_at ? waktu(p.isolir_at) : ''}{p.profile_asal ? ` · asal ${p.profile_asal}` : ''}</small></>}
                            </>
                        ),
                    },
                    { key: 'status', label: 'Status', render: (p) => <StatusBadge value={p.status} /> },
                ]}
                fields={[
                    { name: 'id_pelanggan', label: 'ID Pelanggan', placeholder: 'Otomatis bila dikosongkan' },
                    { name: 'nama', label: 'Nama Pelanggan', required: true },
                    { name: 'tlp', label: 'No. Telepon / WA', type: 'tel', required: true },
                    { name: 'nik', label: 'NIK', placeholder: '16 digit angka', help: 'Opsional' },
                    { name: 'alamat', label: 'Alamat', type: 'textarea', required: true, col: 'col-12' },
                    { name: 'jns_tagihan', label: 'Jenis Tagihan', type: 'select', required: true, options: [{ value: 'prabayar', label: 'Prabayar' }, { value: 'pascabayar', label: 'Pascabayar' }] },
                    { name: 'id_lokasi', label: 'Cabang / Lokasi', type: 'select', required: true, options: cabangOptions, resets: ['id_area', 'router_id', 'id_paket', 'username_pppoe', 'mac_address'] },
                    { name: 'id_area', label: 'Area', type: 'select', options: (v) => areaOptions.filter((o) => String(o.id_lokasi) === String(v.id_lokasi)), help: 'Opsional · sesuai cabang terpilih' },
                    { name: 'router_id', label: 'Router', type: 'select', required: true, options: (v) => sesuaiCabang(routerOptions, v), resets: ['username_pppoe', 'mac_address'] },
                    { name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'select', required: true, options: Object.entries(KONEKSI).map(([value, label]) => ({ value, label })), resets: ['id_paket', 'username_pppoe', 'mac_address', 'ip_address'] },
                    {
                        name: 'mode_akun', label: 'Akun di MikroTik', type: 'radio', required: true, show: (v) => !!AKUN[v.jns_koneksi], resets: ['username_pppoe', 'mac_address'],
                        options: [{ value: 'sinkron', label: 'Sinkronisasi' }, { value: 'baru', label: 'Buat Baru' }],
                        help: 'Sinkronisasi: akun sudah ada di router · Buat baru: akun dibuat otomatis di router setelah data disimpan',
                    },
                    // PPPoE & Hotspot: Sinkronisasi pilih akun dari router, Buat baru isi username + password
                    { name: 'username_pppoe', label: 'Pilih Akun dari Router', type: 'custom', required: true, render: renderAkun, show: akunDipilih(['pppoe', 'hotspot'], 'sinkron') },
                    { name: 'username_pppoe', label: 'Username', required: true, show: akunDipilih(['pppoe', 'hotspot'], 'baru') },
                    { name: 'password_akun', label: 'Password', type: 'password', required: true, show: akunDipilih(['pppoe', 'hotspot'], 'baru') },
                    // IP Binding dikenali lewat MAC address; Static cukup IP address
                    { name: 'mac_address', label: 'Pilih IP Binding dari Router', type: 'custom', required: true, render: renderAkun, show: akunDipilih('ip_binding', 'sinkron') },
                    { name: 'mac_address', label: 'MAC Address', required: true, placeholder: 'AA:BB:CC:DD:EE:FF', show: akunDipilih('ip_binding', 'baru') },
                    { name: 'ip_address', label: 'IP Address', placeholder: '192.168.10.20', help: 'Opsional', show: (v) => v.jns_koneksi === 'ip_binding' },
                    { name: 'ip_address', label: 'IP Address', required: true, placeholder: '192.168.10.20', show: (v) => v.jns_koneksi === 'static' },
                    {
                        name: 'id_paket', label: 'Paket Langganan', type: 'select', required: true, help: 'Sesuai cabang; profile paket dipakai saat akun dibuat & saat On',
                        options: (v) => sesuaiCabang(paketOptions, v).filter((o) => !['pppoe', 'hotspot'].includes(v.jns_koneksi) || o.jns_koneksi === v.jns_koneksi),
                    },
                    { name: 'tgl_registrasi', label: 'Tanggal Registrasi', type: 'date', required: true },
                    { name: 'tgl_jatuh_tempo', label: 'Tanggal Jatuh Tempo', type: 'number', required: true, help: 'Tanggal 1–31 setiap bulan' },
                    { name: 'ppn', label: 'PPN', type: 'radio', required: true, options: [{ value: true, label: 'Iya' }, { value: false, label: 'Tidak' }] },
                    { name: 'sales_id', label: 'Sales', type: 'select', required: true, options: salesOptions },
                    { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'berhenti', label: 'Berhenti' }], show: (v) => !!v.id },
                ]}
            />

            <ConfirmDialog
                isOpen={!!confirm}
                toggle={() => setConfirm(null)}
                title={confirm?.aksi === 'isolir' ? 'Matikan Internet (Off)' : 'Nyalakan Internet (On)'}
                color={confirm?.aksi === 'isolir' ? 'danger' : 'success'}
                confirmLabel={confirm?.aksi === 'isolir' ? 'Ya, Off-kan' : 'Ya, On-kan'}
                message={confirm && (confirm.aksi === 'isolir'
                    ? <>Internet <strong>{confirm.p.nama}</strong> ({confirm.p.username_pppoe}) akan diisolir: profile diubah ke <strong>{confirm.p.router?.profile_isolir}</strong> dan sesi aktif diputus. Lanjutkan?</>
                    : <>Internet <strong>{confirm.p.nama}</strong> ({confirm.p.username_pppoe}) dikembalikan ke profile <strong>{confirm.p.profile_asal ?? confirm.p.profile_paket}</strong>{!confirm.p.profile_asal && ' (profile paket)'} dan sesinya diputus agar login ulang. Lanjutkan?</>)}
                onConfirm={() => jalankan(confirm.p, confirm.aksi)}
            />

            <Modal isOpen={!!pilihProfile} toggle={() => setPilihProfile(null)} centered>
                <ModalHeader toggle={() => setPilihProfile(null)}>Pilih Profile · {pilihProfile?.p.nama}</ModalHeader>
                <ModalBody>
                    <p className="f-light">Profile asal pelanggan ini tidak diketahui (kemungkinan diisolir langsung di router). Pilih profile yang dipakai saat internet dinyalakan:</p>
                    <Select ariaLabel="Profile" value={pilihProfile?.value} options={pilihProfile?.options ?? []} onChange={(v) => setPilihProfile((s) => ({ ...s, value: v }))} />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setPilihProfile(null)}>Batal</Button>
                    <Button color="success" disabled={!pilihProfile?.value} onClick={() => { jalankan(pilihProfile.p, 'aktifkan', pilihProfile.value); setPilihProfile(null); }}>Nyalakan</Button>
                </ModalFooter>
            </Modal>
        </>
    );
}
