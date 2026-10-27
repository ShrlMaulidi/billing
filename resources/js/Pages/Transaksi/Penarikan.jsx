import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Upload, X } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { ActionButton, ConfirmDialog, FormModal, StatusBadge } from '../../Components/ui';
import { periode, rupiah, waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

export default function Penarikan({ filters, rows, saldo }) {
    const { cabangOptions } = usePage().props;
    const [proses, setProses] = useState(null); // { row, aksi }
    const [bukti, setBukti] = useState(null);

    return (
        <>
            <CrudPage
                title="Penarikan Saldo Mitra" crumbs={['Transaksi']} entity="Request Penarikan" rows={rows} filters={filters} canEdit={false} canDelete={false}
                filterFields={['bulan', 'tahun', 'cabang', { name: 'status', label: 'Status', options: ['pending', 'disetujui', 'ditolak', 'dibayar'] }, { name: 'semua', label: 'Semua periode', type: 'switch' }]}
                tableProps={{ footer: sumFooter(['nominal'], rupiah) }}
                rowActions={(r) => (
                    <>
                        <ActionButton icon={Check} label="Setujui" color="success" disabled={r.status !== 'pending'} onClick={() => setProses({ row: r, aksi: 'setujui' })} />
                        <ActionButton icon={X} label="Tolak" color="danger" disabled={r.status !== 'pending'} onClick={() => setProses({ row: r, aksi: 'tolak' })} />
                        <ActionButton icon={Upload} label="Upload Bukti Transfer" color="info" disabled={r.status !== 'disetujui'} onClick={() => setBukti(r)} />
                    </>
                )}
                columns={[
                    { key: 'no', label: 'No', sortable: false, render: (r, i) => i + 1 },
                    { key: 'nama_request', label: 'Nama Request' },
                    { key: 'cabang', label: 'Mitra / Cabang', render: (r) => <>{r.nama_mitra}<br /><small className="f-light">{r.cabang}</small></> },
                    { key: 'periode_sumber', label: 'Periode Sumber', render: (r) => periode(r.periode_sumber) },
                    { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                    { key: 'created_at', label: 'Waktu Request', render: (r) => waktu(r.created_at) },
                    { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                    { key: 'diproses_oleh', label: 'Petugas', render: (r) => r.diproses_oleh ?? '-' },
                ]}
                fields={[
                    { name: 'id_lokasi', label: 'Mitra / Cabang', type: 'select', required: true, options: cabangOptions.filter((c) => c.value !== 1) },
                    { name: 'saldo', label: 'Saldo Tersedia', type: 'static', render: (v) => <h5 className="font-success mb-0">{v.id_lokasi ? rupiah(saldo[v.id_lokasi] ?? 0) : '-'}</h5> },
                    { name: 'nama_request', label: 'Nama Request', required: true },
                    { name: 'periode_sumber', label: 'Periode Sumber', type: 'month', required: true },
                    { name: 'nominal', label: 'Nominal', type: 'currency', required: true },
                    { name: 'keterangan', label: 'Keterangan', type: 'textarea', required: true },
                ]}
            />
            <ConfirmDialog
                isOpen={!!proses} toggle={() => setProses(null)}
                title={proses?.aksi === 'setujui' ? 'Setujui Penarikan' : 'Tolak Penarikan'}
                color={proses?.aksi === 'setujui' ? 'success' : 'danger'} confirmLabel={proses?.aksi === 'setujui' ? 'Setujui' : 'Tolak'}
                message={proses && <>{proses.aksi === 'setujui' ? 'Setujui' : 'Tolak'} penarikan <strong>{proses.row.nama_request}</strong> sebesar <strong>{rupiah(proses.row.nominal)}</strong>?</>}
                onConfirm={() => dummy(proses.aksi === 'setujui' ? 'Persetujuan penarikan' : 'Penolakan penarikan')}
            />
            <FormModal isOpen={!!bukti} toggle={() => setBukti(null)} title={`Upload Bukti Transfer — ${bukti?.nama_request ?? ''}`} size="md" submitLabel="Upload & Tandai Dibayar"
                fields={[{ name: 'bukti_transfer', label: 'Bukti Transfer', type: 'file', required: true }, { name: 'catatan', label: 'Catatan', type: 'textarea' }]}
                onSubmit={() => dummy('Upload bukti transfer')} />
        </>
    );
}
