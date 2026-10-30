import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Lock, Save, Trash2 } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Input, Spinner } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { ConfirmDialog, FormModal, SkeletonRows } from '../../Components/ui';
import { api, notify, useBisa, useNavLoading } from '../../lib/ui';

const ACTIONS = [['view', 'Lihat'], ['create', 'Tambah'], ['update', 'Ubah'], ['delete', 'Hapus'], ['export', 'Export'], ['approve', 'Approve']];

/** Matriks permission per role (spatie). Kolom yang tidak berlaku untuk suatu menu dinonaktifkan. */
export default function HakAkses({ roles, selected, menus, permissions }) {
    const bisa = useBisa();
    const [perm, setPerm] = useState(permissions);
    const [saving, setSaving] = useState(false);
    const [tambah, setTambah] = useState(false);
    const [hapus, setHapus] = useState(false);
    const loading = useNavLoading();
    useEffect(() => setPerm(permissions), [permissions]);
    const role = roles.find((r) => r.id === selected);
    const locked = !role || role.is_locked || !bisa('user.hak_akses.update');

    const berlaku = (m, act) => m.aksi.includes(act);
    const set = (key, act, val) => setPerm((p) => ({ ...p, [key]: { ...p[key], [act]: val } }));
    const setRow = (m, val) => setPerm((p) => ({ ...p, [m.key]: Object.fromEntries(m.aksi.map((a) => [a, val])) }));
    const setCol = (act, val) => setPerm((p) => ({ ...p, ...Object.fromEntries(menus.filter((m) => berlaku(m, act)).map((m) => [m.key, { ...p[m.key], [act]: val }])) }));
    const pilihRole = (id) => router.get('/user/hak-akses', { role: id }, { preserveState: true, preserveScroll: true });

    const simpan = async () => {
        setSaving(true);
        try {
            const daftar = menus.flatMap((m) => m.aksi.filter((a) => perm[m.key]?.[a]).map((a) => `${m.key}.${a}`));
            notify((await api('PUT', `/user/role/${role.id}`, { permissions: daftar })).message);
            router.reload({ only: ['permissions'] });
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            setSaving(false);
        }
    };
    const tambahRole = async (values) => {
        const res = await api('POST', '/user/role', values);
        notify(res.message);
        pilihRole(res.id);
    };
    const hapusRole = async () => {
        try {
            notify((await api('DELETE', `/user/role/${role.id}`)).message);
            router.get('/user/hak-akses');
        } catch (err) {
            notify(err.message, 'danger', 7000);
        }
    };

    return (
        <>
            <PageHeader title="Hak Akses" crumbs={['User']} />
            <div className="container-fluid">
                <div className="row">
                    <div className="col-xl-3">
                        <Card>
                            <CardHeader className="pb-0"><h5>Role</h5></CardHeader>
                            <CardBody>
                                <div className="list-group">
                                    {!roles.length && <p className="f-light text-center mb-0">Belum ada role</p>}
                                    {roles.map((r) => (
                                        <button key={r.id} type="button" className={`list-group-item list-group-item-action d-flex justify-content-between ${r.id === selected ? 'active' : ''}`}
                                            onClick={() => pilihRole(r.id)}>
                                            <span>{r.nama} {r.is_locked && <Lock size={12} />}<br /><small>{r.keterangan}</small></span>
                                            <span className="badge bg-light text-dark align-self-center">{r.jumlah_user}</span>
                                        </button>
                                    ))}
                                </div>
                                {bisa('user.hak_akses.create') && <Button color="primary" outline className="w-100 mt-3" onClick={() => setTambah(true)}>+ Tambah Role</Button>}
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-9">
                        <Card>
                            <CardHeader className="pb-0 d-flex justify-content-between align-items-center gap-2">
                                <h5 className="mb-0">Matriks Permission — {role?.nama}</h5>
                                <div className="d-flex gap-2">
                                    {role && !role.is_locked && bisa('user.hak_akses.delete') && (
                                        <Button color="danger" outline onClick={() => setHapus(true)}><Trash2 size={14} className="me-1" />Hapus Role</Button>
                                    )}
                                    <Button color="primary" disabled={locked || saving} onClick={simpan}>{saving ? <Spinner size="sm" className="me-1" /> : <Save size={14} className="me-1" />}Simpan</Button>
                                </div>
                            </CardHeader>
                            <CardBody>
                                {role?.is_locked && <div className="alert alert-light-warning">Role Super Admin terkunci: memiliki semua akses dan tidak dapat diubah.</div>}
                                <div className="table-responsive">
                                    <table className="table table-bordered perm-table">
                                        <thead>
                                            <tr>
                                                <th>Menu</th>
                                                {ACTIONS.map(([a, l]) => {
                                                    const kolom = menus.filter((m) => berlaku(m, a));
                                                    return (
                                                        <th key={a}>{l}<br /><Input type="checkbox" disabled={locked || !kolom.length}
                                                            checked={!!role?.is_locked || (kolom.length > 0 && kolom.every((m) => perm[m.key]?.[a]))} onChange={(e) => setCol(a, e.target.checked)} /></th>
                                                    );
                                                })}
                                                <th>Semua</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {loading ? <SkeletonRows cols={8} rows={10} /> : menus.map((m) => (
                                                <tr key={m.key}>
                                                    <td>{m.label}</td>
                                                    {ACTIONS.map(([a]) => (
                                                        <td key={a}>
                                                            {berlaku(m, a)
                                                                ? <Input type="checkbox" disabled={locked} checked={!!role?.is_locked || !!perm[m.key]?.[a]} onChange={(e) => set(m.key, a, e.target.checked)} />
                                                                : <span className="f-light">—</span>}
                                                        </td>
                                                    ))}
                                                    <td><Input type="checkbox" disabled={locked} checked={!!role?.is_locked || m.aksi.every((a) => perm[m.key]?.[a])} onChange={(e) => setRow(m, e.target.checked)} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>
            <FormModal isOpen={tambah} toggle={() => setTambah(false)} title="Tambah Role" size="md" onSubmit={tambahRole}
                fields={[{ name: 'nama', label: 'Nama Role', required: true }, { name: 'keterangan', label: 'Keterangan' }]} />
            <ConfirmDialog isOpen={hapus} toggle={() => setHapus(false)} title="Hapus Role" confirmLabel="Hapus"
                message={<>Hapus role <strong>{role?.nama}</strong>? Role yang masih dipakai user tidak bisa dihapus.</>} onConfirm={hapusRole} />
        </>
    );
}
