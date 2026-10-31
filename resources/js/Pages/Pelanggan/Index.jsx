import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { CheckSquare, Clipboard, Clock, DollarSign, FileText, Info, Power, WifiOff } from 'react-feather';
import { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { BayarMassal, FormPembayaran, JENIS_TAGIHAN, kunciPeriode, RiwayatPembayaran } from '../../Components/Pembayaran';
import Select from '../../Components/Select';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { periode as namaPeriode, rupiah, tanggal, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

const RELOAD = ['rows', 'jumlah'];
const JUDUL = { on: 'Pelanggan On', off: 'Pelanggan Off', lunas: 'Pelanggan Lunas', belum_lunas: 'Pelanggan Belum Lunas', berhenti: 'Pelanggan Berhenti', prorate: 'Tagihan Prorate' };
// Tombol filter cepat: [status, label, path]
const TAB = [
    ['', 'Semua', ''], ['on', 'On', '/on'], ['off', 'Off', '/off'], ['lunas', 'Lunas', '/lunas'], ['belum_lunas', 'Belum Lunas', '/belum-lunas'],
    ['berhenti', 'Berhenti', '/berhenti'], ['prorate', 'Prorate', '/prorate'],
];
const TAGIHAN = ['lunas', 'belum_lunas'];
// Tombol Input & Riwayat Pembayaran (Pelanggan Berhenti: riwayat saja)
const HALAMAN_BAYAR = [null, 'belum_lunas', 'prorate', 'berhenti'];
// Halaman daftar khusus: tanpa tombol Tambah Pelanggan
const TANPA_TAMBAH = ['lunas', 'berhenti', 'prorate'];
// Baris total Pelanggan Lunas: jumlah tagihan & yang dibayar dari pembayaran periode terpilih
const totalLunas = (col, rows) => {
    const field = { tagihan_lunas: 'harga', dibayar: 'jml_bayar' }[col.key];
    return field ? rupiah(rows.reduce((s, r) => s + (r.pembayaran_periode?.[field] ?? 0), 0)) : null;
};
const SKEMA = [{ value: 'full', label: 'Tagihan Full' }, { value: 'prorate', label: 'Prorate' }];
// Tanggal 1–27 (Pelanggan::JATUH_TEMPO_MAKS) selalu ada di setiap bulan
const JATUH_TEMPO = Array.from({ length: 27 }, (_, i) => ({ value: i + 1, label: `Tanggal ${i + 1}` }));
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
    // Router belum terkoneksi: akun diisi manual agar pelanggan tetap bisa disimpan
    if (state.error) {
        const mac = jenis === 'ip_binding';
        return (
            <>
                <Input value={value ?? ''} onChange={(e) => onChange(e.target.value.trim())} invalid={invalid} autoComplete="off"
                    placeholder={mac ? 'AA:BB:CC:DD:EE:FF' : 'Username akun di router'} />
                <div className="small mt-1 text-warning">
                    {state.error}. Isi {mac ? 'MAC address' : 'username'} manual, data tetap tersimpan.{' '}
                    <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setMuatUlang((n) => n + 1)}>Coba lagi</button>
                </div>
            </>
        );
    }
    // Akun yang tersimpan tetap tampil walau sudah tidak ada di router
    const options = value && !state.options.some((o) => o.value === value) ? [{ value, label: `${value} (tidak ditemukan di router)` }, ...state.options] : state.options;
    return <Select value={value} onChange={onChange} options={options} invalid={invalid} placeholder={`— Pilih dari ${state.options.length} ${AKUN[jenis]} —`} />;
}

/** Remote Address PPPoE: saran IP kosong dari IP Pool router & peringatan bila IP sudah dipakai akun lain di router. */
function RemoteAddress({ routerId, username, value, onChange, invalid }) {
    const [state, setState] = useState({ loading: false, error: null, pools: [], terpakai: {} });
    const [muatUlang, setMuatUlang] = useState(0);
    useEffect(() => {
        if (!routerId) return undefined;
        let batal = false;
        setState((s) => ({ ...s, loading: true, error: null }));
        api('GET', `/pelanggan/ip-router/${routerId}`)
            .then((data) => !batal && setState({ loading: false, error: null, pools: data.pools, terpakai: data.terpakai }))
            .catch((err) => !batal && setState({ loading: false, error: err.message, pools: [], terpakai: {} }));
        return () => { batal = true; };
    }, [routerId, muatUlang]);

    // Default: pool yang namanya mengandung "ppp", selain itu pool pertama
    const [poolDipilih, setPoolDipilih] = useState('');
    const pool = state.pools.find((p) => p.name === poolDipilih) ?? state.pools.find((p) => /ppp/i.test(p.name)) ?? state.pools[0];
    const pemakai = value && state.terpakai[value];
    const bentrok = !!pemakai && pemakai !== username;

    return (
        <>
            <div className="input-group">
                {state.pools.length > 0 && (
                    <Input type="select" className="form-select" style={{ maxWidth: '40%' }} value={pool?.name ?? ''} onChange={(e) => setPoolDipilih(e.target.value)} title="IP Pool di router">
                        {state.pools.map((p) => <option key={p.name} value={p.name}>{p.name}</option>)}
                    </Input>
                )}
                <Input value={value ?? ''} onChange={(e) => onChange(e.target.value.trim())} invalid={invalid || bentrok} placeholder="10.12.10.5" list="ip-kosong-router" autoComplete="off" />
                {pool && <Button type="button" color="primary" outline disabled={!pool.kosong[0]} onClick={() => onChange(pool.kosong[0])}>IP kosong</Button>}
            </div>
            <datalist id="ip-kosong-router">
                {pool?.kosong.map((ip) => <option key={ip} value={ip} />)}
            </datalist>
            <div className={`small mt-1 ${bentrok ? 'text-danger' : 'f-light'}`}>
                {bentrok && `IP sudah dipakai akun PPPoE '${pemakai}' di router`}
                {!bentrok && !routerId && 'Pilih router untuk melihat IP kosong di pool'}
                {!bentrok && state.loading && <><Spinner size="sm" className="me-1" />Membaca IP pool router...</>}
                {!bentrok && state.error && <span className="text-warning">{state.error}. Isi IP manual, data tetap tersimpan. <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setMuatUlang((n) => n + 1)}>Coba lagi</button></span>}
                {!bentrok && routerId && !state.loading && !state.error && (pool ? `${pool.jumlah_kosong} IP kosong · ${pool.ranges}` : 'Router belum punya IP pool, isi IP manual')}
            </div>
        </>
    );
}

/**
 * Data pelanggan (database). Halaman Pelanggan On punya tombol Off (isolir),
 * halaman Pelanggan Off punya tombol On (kembali ke profile asal).
 */
export default function PelangganIndex({ filters, status, rows, jumlah, periode, tahunBayar, areaOptions, routerOptions, paketOptions, salesOptions, rekeningOptions }) {
    const { cabangOptions } = usePage().props;
    const bisa = useBisa();
    // Saat edit, akun dianggap sudah ada di router (pilih "Buat baru" untuk membuat ulang)
    const formRows = useMemo(() => rows.map((r) => ({ ...r, mode_akun: 'sinkron' })), [rows]);
    const renderAkun = ({ value, onChange, values, invalid }) => <AkunRouter routerId={values.router_id} jenis={values.jns_koneksi} value={value} onChange={onChange} invalid={invalid} />;
    const renderRemoteAddress = ({ value, onChange, values, invalid }) => <RemoteAddress routerId={values.router_id} username={values.username_pppoe} value={value} onChange={onChange} invalid={invalid} />;
    const [busy, setBusy] = useState(null);
    const [confirm, setConfirm] = useState(null); // { p, aksi }
    const [pilihProfile, setPilihProfile] = useState(null); // { p, options, value }
    const [bayar, setBayar] = useState(null); // target FormPembayaran
    const [riwayat, setRiwayat] = useState(null); // pelanggan yang riwayat pembayarannya dibuka
    // Pelanggan Belum Lunas: centang beberapa pelanggan lalu bayar sekaligus
    const bayarMassal = status === 'belum_lunas' && bisa('pelanggan.pembayaran.create');
    const [dicentang, setDicentang] = useState(() => new Set());
    const [massal, setMassal] = useState(false);
    // Hanya baris yang masih ada (yang sudah lunas hilang setelah reload)
    const barisDicentang = useMemo(() => rows.filter((r) => dicentang.has(r.id)), [rows, dicentang]);
    const halamanBayar = HALAMAN_BAYAR.includes(status ?? null);
    // Halaman Pelanggan Lunas hanya untuk kwitansi & pembatalan pembayaran periode terpilih
    const lunas = status === 'lunas';

    // Periode bawaan: tagihan prorate (halaman Prorate) atau periode yang ditagih bulan filter, maju ke yang belum dibayar
    const bukaBayar = (p) => {
        const lunas = new Set(p.periode_lunas);
        const awal = status === 'prorate' ? p.prorate.periode : p.periode_tagihan;
        let [tahun, bulan] = [Number(awal.slice(0, 4)), Number(awal.slice(5, 7))];
        for (let i = 0; i < 120 && lunas.has(kunciPeriode(tahun, bulan)); i++) {
            [tahun, bulan] = bulan === 12 ? [tahun + 1, 1] : [tahun, bulan + 1];
        }
        setBayar({
            judul: `Input Pembayaran · ${p.id_pelanggan ?? p.nama}`, nama: p.nama, paket: p.paket, harga: p.harga, jns_tagihan: p.jns_tagihan, prorate: p.prorate, id_lokasi: p.id_lokasi, terima_cash: p.terima_cash,
            lunas, initial: { bulan, tahun, metode: p.terima_cash ? 'cash' : '', diskon_tipe: '', diskon_nilai: '' }, method: 'POST', url: `/pelanggan/${p.id}/bayar`,
        });
    };

    // Hapus bertahap: selain di menu Pelanggan Berhenti, hapus = pindah ke Pelanggan Berhenti
    const hapusPelanggan = async (r) => {
        try {
            notify((await api('DELETE', `/pelanggan/${r.id}`, status === 'berhenti' ? { permanen: true } : undefined)).message);
            reloadData(RELOAD);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        }
    };

    // Invoice bulan tagihan: bulan filter, atau bulan tagihan prorate muncul (halaman Tagihan Prorate)
    const linkTagihan = (p) => {
        const bulanTagihan = status === 'prorate' ? p.prorate.muncul : periode;
        return `/pelanggan/${p.id}/tagihan?bulan=${Number(bulanTagihan.slice(5, 7))}&tahun=${Number(bulanTagihan.slice(0, 4))}`;
    };

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
                title={JUDUL[status] ?? 'Data Pelanggan'} entity={lunas ? 'Pembayaran' : 'Pelanggan'} crumbs={['Pelanggan']} rows={formRows} filters={filters}
                deleteLabel={(r) => (lunas ? `pembayaran ${r.pembayaran_periode?.no_invoice} atas nama ${r.nama}` : r.nama)}
                defaults={{ jns_tagihan: 'prabayar', skema_tagihan: 'full', id_lokasi: filters.cabang ?? '', jns_koneksi: 'pppoe', mode_akun: 'sinkron', tgl_registrasi: new Date().toISOString().slice(0, 10), ppn: false }}
                onSave={saveTo('/pelanggan', RELOAD)}
                onDelete={lunas ? (r) => deleteFrom('/pembayaran', RELOAD)(r.pembayaran_periode) : hapusPelanggan}
                deleteMessage={lunas ? undefined : (r) => (status === 'berhenti'
                    ? <>Hapus permanen <strong>{r.nama}</strong>? Data pelanggan tidak bisa dikembalikan; riwayat pembayaran tetap tersimpan. Tindakan ini tercatat di log.</>
                    : <>Pindahkan <strong>{r.nama}</strong> ke Pelanggan Berhenti? Pelanggan tidak lagi ditagih dan bisa diaktifkan kembali lewat Edit. Akun di router tidak diubah. Hapus permanen dilakukan dari menu Pelanggan Berhenti.</>)}
                canAdd={!TANPA_TAMBAH.includes(status) && bisa('pelanggan.data.create')} canEdit={!lunas && bisa('pelanggan.data.update')}
                canDelete={lunas ? bisa('pelanggan.pembayaran.delete') : bisa('pelanggan.data.delete')}
                tableProps={{
                    ...(bayarMassal && { selectable: true, selected: dicentang, onSelect: setDicentang }),
                    // Total tagihan belum lunas (mengikuti pencarian)
                    ...(status === 'belum_lunas' && { footer: sumFooter(['tagihan'], rupiah) }),
                    ...(lunas && { footer: totalLunas }),
                }}
                headerActions={bayarMassal && (
                    <Button color="success" disabled={!barisDicentang.length} onClick={() => setMassal(true)}>
                        <CheckSquare size={14} className="me-1" />Bayar Terpilih{barisDicentang.length > 0 && ` (${barisDicentang.length})`}
                    </Button>
                )}
                before={
                    <>
                        {[
                            [!cabangOptions.length, <>Belum ada cabang. Tambahkan dulu di <Link href="/kelola-data/cabang" className="fw-semibold">Kelola Data › Cabang</Link>.</>],
                            [!routerOptions.length, <>Belum ada router. Tambahkan dulu di <Link href="/kelola-data/router" className="fw-semibold">Kelola Data › Router</Link> agar pelanggan bisa di-On/Off.</>],
                        ].filter(([tampil]) => tampil).map(([, pesan], i) => (
                            <div key={i} className="alert alert-light-warning d-flex gap-2 align-items-center">
                                <Info size={16} className="flex-shrink-0" />
                                <span>{pesan}</span>
                            </div>
                        ))}
                        <div className="btn-group flex-wrap mb-3">
                            {TAB.map(([s, label, path]) => (
                                <Link key={label} href={`/pelanggan${path}`} data={filters.cabang ? { cabang: filters.cabang } : {}} prefetch
                                    className={`btn btn-sm ${(status ?? '') === s ? 'btn-primary' : 'btn-outline-primary'}`}>
                                    {label} <span className="ms-1 opacity-75">{jumlah[s || 'semua']}</span>
                                </Link>
                            ))}
                        </div>
                    </>
                }
                filterFields={TAGIHAN.includes(status) ? ['bulan', 'tahun', 'cabang'] : ['cabang']}
                rowActions={lunas ? (p) => bisa('pelanggan.pembayaran.view') && (
                    <ActionButton icon={FileText} label={`Kwitansi ${p.pembayaran_periode?.no_invoice ?? ''}`} href={`/pembayaran/${p.pembayaran_periode?.id}/kwitansi`} />
                ) : halamanBayar ? (p) => (
                    <>
                        {status !== 'berhenti' && bisa('pelanggan.pembayaran.create') && (
                            <ActionButton icon={DollarSign} color="success"
                                label={p.status !== 'aktif' ? 'Pelanggan berhenti' : !p.harga ? 'Paket gratis / belum ada paket' : 'Input Pembayaran'}
                                disabled={p.status !== 'aktif' || !p.harga} onClick={() => bukaBayar(p)} />
                        )}
                        {['belum_lunas', 'prorate'].includes(status) && (
                            <ActionButton icon={Clipboard} color="primary" label={status === 'prorate' ? 'Lihat Invoice Prorate' : 'Lihat Tagihan / Invoice'} href={linkTagihan(p)} />
                        )}
                        {bisa('pelanggan.pembayaran.view') && <ActionButton icon={Clock} color="info" label="Riwayat Pembayaran" onClick={() => setRiwayat(p)} />}
                    </>
                ) : ['on', 'off'].includes(status) && bisa(`pelanggan.${status}.update`) ? (p) => (status === 'on'
                    ? <ActionButton icon={WifiOff} label={p.jns_koneksi === 'pppoe' ? 'Off (Isolir)' : 'Off baru tersedia untuk PPPoE'} color="danger" loading={busy === p.id} disabled={!!busy || p.jns_koneksi !== 'pppoe'} onClick={() => setConfirm({ p, aksi: 'isolir' })} />
                    : <ActionButton icon={Power} label={p.jns_koneksi === 'pppoe' ? 'On (Aktifkan)' : 'On baru tersedia untuk PPPoE'} color="success" loading={busy === p.id} disabled={!!busy || p.jns_koneksi !== 'pppoe'} onClick={() => klikOn(p)} />) : undefined}
                columns={[
                    { key: 'id_pelanggan', label: 'ID Pelanggan' },
                    { key: 'nama', label: 'Nama', render: (p) => <>{p.nama}{p.tlp && <><br /><small className="f-light">{p.tlp}</small></>}</> },
                    { key: 'alamat', label: 'Alamat', hidden: true },
                    { key: 'cabang', label: 'Cabang' },
                    { key: 'area', label: 'Area', render: (p) => p.area ?? <span className="f-light">-</span> },
                    { key: 'paket', label: 'Paket', render: (p) => <>{p.paket}{p.harga != null && <><br /><small className="f-light">{rupiah(p.harga)}</small></>}</> },
                    // Bulan tagihan (filter) vs bulan pemakaian yang ditagih: pascabayar = bulan sebelumnya
                    ...(!status || [...TAGIHAN, 'on', 'off', 'prorate', 'berhenti'].includes(status) ? [{ key: 'jns_tagihan', label: 'Tipe Pembayaran', render: (p) => JENIS_TAGIHAN[p.jns_tagihan] ?? 'Prabayar' }] : []),
                    ...(TAGIHAN.includes(status) ? [
                        ...(status === 'belum_lunas' ? [{
                            key: 'tagihan', label: 'Tagihan', align: 'end', render: (p) => (
                                <>{rupiah(p.tagihan)}<br />{p.tagihan_prorate
                                    ? <span className="badge badge-light-info">Prorate {p.prorate.hari}/{p.prorate.hari_bulan} hari</span>
                                    : <small className="f-light">Full</small>}</>
                            ),
                        }] : []),
                        // Pelanggan Lunas: nominal yang tercatat di pembayaran (harga saat dibayar), bukan harga paket terbaru
                        ...(lunas ? [
                            {
                                key: 'tagihan_lunas', label: 'Tagihan', align: 'end', sortValue: (p) => p.pembayaran_periode?.harga ?? 0, render: (p) => (
                                    <>{rupiah(p.pembayaran_periode?.harga)}<br />{p.pembayaran_periode?.is_prorate
                                        ? <span className="badge badge-light-info">Prorate</span>
                                        : <small className="f-light">Full</small>}</>
                                ),
                            },
                            {
                                key: 'dibayar', label: 'Dibayar', align: 'end', sortValue: (p) => p.pembayaran_periode?.jml_bayar ?? 0, render: (p) => (
                                    <><span className="fw-semibold text-success">{rupiah(p.pembayaran_periode?.jml_bayar)}</span>
                                        {p.pembayaran_periode?.diskon > 0 && <><br /><small className="f-light">diskon {rupiah(p.pembayaran_periode.diskon)}</small></>}</>
                                ),
                            },
                        ] : []),
                        { key: 'periode_tagihan', label: 'Periode', render: (p) => <>{namaPeriode(periode)}<br /><small className="f-light">untuk pemakaian {namaPeriode(p.periode_tagihan)}</small></> },
                    ] : []),
                    ...(status === 'prorate' ? [
                        { key: 'prorate', label: 'Tagihan Prorate', align: 'end', sortValue: (p) => p.prorate.nominal, render: (p) => <>{rupiah(p.prorate.nominal)}<br /><small className="f-light">{p.prorate.hari}/{p.prorate.hari_bulan} hari</small></> },
                        // Bulan tagihan = bulan tagihan muncul (pascabayar: bulan setelah registrasi)
                        { key: 'prorate_periode', label: 'Periode', sortValue: (p) => p.prorate.periode, render: (p) => <>{namaPeriode(p.prorate.muncul)}<br /><small className="f-light">untuk pemakaian {namaPeriode(p.prorate.periode)}</small></> },
                        { key: 'prorate_muncul', label: 'Tagihan Muncul', sortValue: (p) => p.prorate.muncul, render: (p) => tanggal(p.prorate.muncul) },
                    ] : []),
                    // Pelanggan Berhenti: periode pembayaran terakhir sebelum berhenti
                    ...(status === 'berhenti' ? [{
                        key: 'periode_pembayaran_terakhir', label: 'Periode', sortValue: (p) => p.pembayaran_terakhir ?? '', render: (p) => (p.pembayaran_terakhir
                            ? <>{namaPeriode(p.periode_pembayaran_terakhir)}<br /><small className="f-light">untuk pemakaian {namaPeriode(p.pembayaran_terakhir)} · terakhir dibayar</small></>
                            : <span className="f-light">Belum ada pembayaran</span>),
                    }] : []),
                    ...(status === 'berhenti' ? [] : [{ key: 'pembayaran_terakhir', label: 'Pembayaran Terakhir', render: (p) => (p.pembayaran_terakhir ? namaPeriode(p.pembayaran_terakhir) : <span className="f-light">Belum ada</span>) }]),
                    {
                        key: 'username_pppoe', label: 'Router / Koneksi', sortValue: (p) => p.username_pppoe ?? p.mac_address ?? p.ip_address,
                        render: (p) => <>{p.router?.nama_router ?? <span className="text-danger">Router dihapus</span>}<br /><small className="f-light">{KONEKSI[p.jns_koneksi]} · {p.username_pppoe ?? p.mac_address ?? p.ip_address}</small></>,
                    },
                    { key: 'tgl_registrasi', label: 'Tgl Registrasi', render: (p) => tanggal(p.tgl_registrasi) },
                    { key: 'tgl_jatuh_tempo', label: 'Jatuh Tempo', render: (p) => (p.tgl_jatuh_tempo ? `Tanggal ${p.tgl_jatuh_tempo}` : '-') },
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
                    { name: 'jns_tagihan', label: 'Jenis Tagihan', type: 'select', required: true, options: [{ value: 'prabayar', label: 'Prabayar' }, { value: 'pascabayar', label: 'Pascabayar' }], help: 'Prabayar: tagihan muncul di bulan pemakaian · Pascabayar: tanggal 1 bulan berikutnya' },
                    { name: 'skema_tagihan', label: 'Skema Tagihan', type: 'select', required: true, options: SKEMA, help: 'Prorate: bulan registrasi ditagih per hari (s/d akhir bulan), bulan berikutnya penuh' },
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
                        name: 'ip_address', label: 'Remote Address (IP Pelanggan)', type: 'custom', required: true, render: renderRemoteAddress, show: (v) => v.jns_koneksi === 'pppoe',
                        help: 'IP yang sudah dipakai di router atau billing ditolak',
                    },
                    {
                        name: 'id_paket', label: 'Paket Langganan', type: 'select', required: true, help: 'Sesuai cabang; profile paket dipakai saat akun dibuat & saat On',
                        options: (v) => sesuaiCabang(paketOptions, v).filter((o) => !['pppoe', 'hotspot'].includes(v.jns_koneksi) || o.jns_koneksi === v.jns_koneksi),
                    },
                    { name: 'tgl_registrasi', label: 'Tanggal Registrasi', type: 'date', required: true },
                    { name: 'tgl_jatuh_tempo', label: 'Tanggal Jatuh Tempo', type: 'select', required: true, options: JATUH_TEMPO, help: 'Setiap bulan pada tanggal ini' },
                    { name: 'ppn', label: 'PPN', type: 'radio', required: true, options: [{ value: true, label: 'Iya' }, { value: false, label: 'Tidak' }] },
                    { name: 'sales_id', label: 'Sales', type: 'select', options: (v) => sesuaiCabang(salesOptions, v), help: 'Opsional · sales yang membawa pelanggan ini' },
                    { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'berhenti', label: 'Berhenti' }], show: (v) => !!v.id },
                ]}
            />

            <FormPembayaran target={bayar} onClose={() => setBayar(null)} onSaved={() => reloadData(RELOAD)} rekeningOptions={rekeningOptions} tahunBayar={tahunBayar} />
            <BayarMassal rows={barisDicentang} periode={periode} isOpen={massal} onClose={() => setMassal(false)} rekeningOptions={rekeningOptions}
                onSaved={() => { setDicentang(new Set()); reloadData(RELOAD); }} />
            <RiwayatPembayaran pelanggan={riwayat} onClose={() => setRiwayat(null)} onChanged={() => reloadData(RELOAD)} rekeningOptions={rekeningOptions} tahunBayar={tahunBayar} />

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
