import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Lock, Save } from 'react-feather';
import { Button, Card, CardBody, CardHeader, Input } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { SkeletonRows } from '../../Components/ui';
import { dummy, useNavLoading } from '../../lib/ui';

const ACTIONS = [['view', 'Lihat'], ['create', 'Tambah'], ['update', 'Ubah'], ['delete', 'Hapus'], ['export', 'Export'], ['approve', 'Approve']];

export default function HakAkses({ roles, selected, menus, permissions }) {
    const [perm, setPerm] = useState(permissions);
    const loading = useNavLoading();
    useEffect(() => setPerm(permissions), [permissions]);
    const role = roles.find((r) => r.id === selected);
    const locked = role?.is_locked;

    const set = (key, act, val) => setPerm((p) => ({ ...p, [key]: { ...p[key], [act]: val } }));
    const setRow = (key, val) => setPerm((p) => ({ ...p, [key]: Object.fromEntries(ACTIONS.map(([a]) => [a, val])) }));
    const setCol = (act, val) => setPerm((p) => Object.fromEntries(Object.entries(p).map(([k, v]) => [k, { ...v, [act]: val }])));

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
                                            onClick={() => router.get('/user/hak-akses', { role: r.id }, { preserveState: true, preserveScroll: true })}>
                                            <span>{r.nama} {r.is_locked && <Lock size={12} />}<br /><small>{r.keterangan}</small></span>
                                            <span className="badge bg-light text-dark align-self-center">{r.jumlah_user}</span>
                                        </button>
                                    ))}
                                </div>
                                <Button color="primary" outline className="w-100 mt-3" onClick={() => dummy('Tambah role')}>+ Tambah Role</Button>
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-9">
                        <Card>
                            <CardHeader className="pb-0 d-flex justify-content-between align-items-center">
                                <h5 className="mb-0">Matriks Permission — {role?.nama}</h5>
                                <Button color="primary" disabled={locked} onClick={() => dummy('Simpan hak akses')}><Save size={14} className="me-1" />Simpan</Button>
                            </CardHeader>
                            <CardBody>
                                {locked && <div className="alert alert-light-warning">Role Super Admin terkunci: memiliki semua akses dan tidak dapat diubah.</div>}
                                <div className="table-responsive">
                                    <table className="table table-bordered perm-table">
                                        <thead>
                                            <tr>
                                                <th>Menu</th>
                                                {ACTIONS.map(([a, l]) => (
                                                    <th key={a}>{l}<br /><Input type="checkbox" disabled={locked} checked={menus.every((m) => perm[m.key]?.[a])} onChange={(e) => setCol(a, e.target.checked)} /></th>
                                                ))}
                                                <th>Semua</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {loading ? <SkeletonRows cols={8} rows={10} /> : menus.map((m) => (
                                                <tr key={m.key}>
                                                    <td>{m.label}</td>
                                                    {ACTIONS.map(([a]) => <td key={a}><Input type="checkbox" disabled={locked} checked={!!perm[m.key]?.[a]} onChange={(e) => set(m.key, a, e.target.checked)} /></td>)}
                                                    <td><Input type="checkbox" disabled={locked} checked={ACTIONS.every(([a]) => perm[m.key]?.[a])} onChange={(e) => setRow(m.key, e.target.checked)} /></td>
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
        </>
    );
}
