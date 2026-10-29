import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Info, Power, WifiOff } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import Select from '../../Components/Select';
import { ActionButton, ConfirmDialog, StatusBadge } from '../../Components/ui';
import { rupiah, tanggal, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo } from '../../lib/ui';

const RELOAD = ['rows', 'jumlah'];
const JUDUL = { on: 'Pelanggan On', off: 'Pelanggan Off' };

/**
 * Data pelanggan (database). Halaman Pelanggan On punya tombol Off (isolir),
 * halaman Pelanggan Off punya tombol On (kembali ke profile asal).
 */
export default function PelangganIndex({ filters, status, rows, jumlah, routerOptions, paketOptions }) {
    const { cabangOptions } = usePage().props;
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
        if (p.profile_asal) return setConfirm({ p, aksi: 'aktifkan' });
        // Profile asal tidak diketahui (mis. diisolir langsung di router) → pilih profile dulu
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
                title={JUDUL[status] ?? 'Data Pelanggan'} entity="Pelanggan" crumbs={['Pelanggan']} rows={rows} filters={filters}
                deleteLabel={(r) => r.nama}
                defaults={{ status: 'aktif', tgl_registrasi: new Date().toISOString().slice(0, 10), id_lokasi: filters.cabang ?? '' }}
                onSave={saveTo('/pelanggan', RELOAD)} onDelete={deleteFrom('/pelanggan', RELOAD)}
                before={
                    <>
                        {!routerOptions.length && (
                            <div className="alert alert-light-warning d-flex gap-2 align-items-center">
                                <Info size={16} className="flex-shrink-0" />
                                <span>Belum ada router. Tambahkan dulu di <Link href="/kelola-data/router" className="fw-semibold">Kelola Data › Router</Link> agar pelanggan bisa di-On/Off.</span>
                            </div>
                        )}
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
                    ? <ActionButton icon={WifiOff} label="Off (Isolir)" color="danger" loading={busy === p.id} disabled={!!busy} onClick={() => setConfirm({ p, aksi: 'isolir' })} />
                    : <ActionButton icon={Power} label="On (Aktifkan)" color="success" loading={busy === p.id} disabled={!!busy} onClick={() => klikOn(p)} />) : undefined}
                columns={[
                    { key: 'id_pelanggan', label: 'ID Pelanggan' },
                    { key: 'nama', label: 'Nama', render: (p) => <>{p.nama}{p.tlp && <><br /><small className="f-light">{p.tlp}</small></>}</> },
                    { key: 'alamat', label: 'Alamat', hidden: true },
                    { key: 'cabang', label: 'Cabang' },
                    { key: 'paket', label: 'Paket', render: (p) => <>{p.paket}{p.harga != null && <><br /><small className="f-light">{rupiah(p.harga)}</small></>}</> },
                    { key: 'username_pppoe', label: 'Router / PPPoE', render: (p) => <>{p.router?.nama_router ?? <span className="text-danger">Router dihapus</span>}<br /><small className="f-light">{p.username_pppoe}</small></> },
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
                    { name: 'tlp', label: 'No. Telepon / WA', type: 'tel' },
                    { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                    { name: 'alamat', label: 'Alamat', type: 'textarea', col: 'col-12' },
                    { name: 'id_paket', label: 'Paket', type: 'select', options: paketOptions },
                    { name: 'tgl_jatuh_tempo', label: 'Tgl Jatuh Tempo', type: 'number', help: 'Tanggal 1–31 setiap bulan' },
                    { name: 'router_id', label: 'Router', type: 'select', required: true, options: routerOptions },
                    { name: 'username_pppoe', label: 'Username PPPoE', required: true, help: 'Harus sama persis dengan nama PPP Secret di Mikrotik' },
                    { name: 'tgl_registrasi', label: 'Tgl Registrasi', type: 'date' },
                    { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'berhenti', label: 'Berhenti' }] },
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
                    : <>Internet <strong>{confirm.p.nama}</strong> ({confirm.p.username_pppoe}) dikembalikan ke profile <strong>{confirm.p.profile_asal}</strong> dan sesinya diputus agar login ulang. Lanjutkan?</>)}
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
