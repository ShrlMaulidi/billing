import { useState } from 'react';
import { Card, CardBody, Modal, ModalBody, ModalHeader } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import { StatusBadge } from '../../Components/ui';
import { label, waktu } from '../../lib/format';

const AKSI_COLOR = { create: 'success', update: 'info', delete: 'danger', login: 'primary', logout: 'secondary', export: 'pending', approve: 'success', reject: 'danger' };
const AKSI = ['create', 'update', 'delete', 'login', 'logout', 'export', 'approve', 'reject'];

export default function Logs({ filters, rows, options }) {
    const [detail, setDetail] = useState(null);
    const keys = detail ? [...new Set([...Object.keys(detail.data_lama ?? {}), ...Object.keys(detail.data_baru ?? {})])] : [];

    return (
        <>
            <PageHeader title="Logs" crumbs={[]} />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <DataTable data={rows} pageSize={25} onRowClick={setDetail}
                            toolbar={<FilterBar filters={filters} fields={[
                                { name: 'dari', label: 'Dari tanggal', type: 'date' }, { name: 'sampai', label: 'Sampai tanggal', type: 'date' },
                                { name: 'user', label: 'User', options: options.user.map((u) => ({ value: u, label: u })) },
                                { name: 'modul', label: 'Modul', options: options.modul.map((u) => ({ value: u, label: u })) },
                                { name: 'aksi', label: 'Aksi', options: AKSI, width: 140 },
                            ]} />}
                            columns={[
                                { key: 'created_at', label: 'Waktu', render: (r) => waktu(r.created_at) }, { key: 'user', label: 'User' }, { key: 'role', label: 'Role' },
                                { key: 'modul', label: 'Modul' }, { key: 'aksi', label: 'Aksi', render: (r) => <StatusBadge value={AKSI_COLOR[r.aksi]} text={label(r.aksi)} /> },
                                { key: 'deskripsi', label: 'Deskripsi' }, { key: 'ip_address', label: 'IP Address' },
                                { key: 'user_agent', label: 'User Agent', hidden: true },
                            ]} />
                    </CardBody>
                </Card>
            </div>
            <Modal isOpen={!!detail} toggle={() => setDetail(null)} size="lg" centered>
                <ModalHeader toggle={() => setDetail(null)}>Detail Aktivitas #{detail?.id}</ModalHeader>
                <ModalBody>
                    {detail && (
                        <>
                            <div className="row mb-3 g-2">
                                {[['Waktu', waktu(detail.created_at)], ['User', `${detail.user} (${detail.role})`], ['Modul / Aksi', `${detail.modul} · ${label(detail.aksi)}`], ['IP', detail.ip_address], ['User Agent', detail.user_agent], ['Deskripsi', detail.deskripsi]].map(([k, v]) => (
                                    <div className="col-md-6" key={k}><small className="f-light d-block">{k}</small>{v}</div>
                                ))}
                            </div>
                            <h6>Perubahan Data</h6>
                            {keys.length ? (
                                <table className="table table-bordered table-sm">
                                    <thead><tr><th>Field</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                                    <tbody>
                                        {keys.map((k) => {
                                            const a = detail.data_lama?.[k]; const b = detail.data_baru?.[k];
                                            return <tr key={k} className={a !== b ? 'table-warning' : ''}><td>{k}</td><td>{a ?? <em className="f-light">—</em>}</td><td>{b ?? <em className="f-light">—</em>}</td></tr>;
                                        })}
                                    </tbody>
                                </table>
                            ) : <p className="f-light">Tidak ada perubahan data untuk aktivitas ini.</p>}
                        </>
                    )}
                </ModalBody>
            </Modal>
        </>
    );
}
