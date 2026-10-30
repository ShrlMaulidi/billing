import { useState } from 'react';
import { Card, CardBody, Input } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import { METODE } from '../../lib/format';
import { notify } from '../../lib/ui';

export default function Channel({ pg, rows }) {
    const [aktif, setAktif] = useState(() => Object.fromEntries(rows.map((r) => [r.id, r.aktif])));
    const toggle = (r) => {
        setAktif((s) => ({ ...s, [r.id]: !s[r.id] }));
        notify(`${r.nama_channel} ${aktif[r.id] ? 'dinonaktifkan' : 'diaktifkan'} (belum tersimpan)`, 'info');
    };
    return (
        <>
            <PageHeader title={`Channel ${METODE[pg.provider]} — ${pg.cabang}`} crumbs={[{ label: 'Payment Gateway', href: '/payment-gateway' }]} />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <DataTable data={rows} exportable={false} pageSize={25} columns={[
                            { key: 'kode_channel', label: 'Kode Channel' }, { key: 'nama_channel', label: 'Nama Channel' },
                            { key: 'aktif', label: 'Aktif', sortable: false, render: (r) => <div className="form-check form-switch mb-0"><Input type="switch" checked={aktif[r.id]} onChange={() => toggle(r)} /></div> },
                        ]} />
                    </CardBody>
                </Card>
            </div>
        </>
    );
}
