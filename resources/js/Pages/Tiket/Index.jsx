import { router } from '@inertiajs/react';
import { Eye } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';

/** Tiket dari dashboard pelanggan, diterima cabang masing-masing; yang belum selesai tampil paling atas. */
export default function TiketIndex({ filters, rows, statusOptions }) {
    return (
        <CrudPage
            title="Tiket" crumbs={[]} entity="Tiket" rows={rows} filters={filters}
            filterFields={['cabang', { name: 'status', label: 'Status', type: 'select', options: statusOptions }]}
            canAdd={false} canEdit={false} canDelete={false}
            tableProps={{ onRowClick: (r) => router.visit(`/tiket/${r.id}`), emptyText: 'Belum ada tiket' }}
            rowActions={(r) => <ActionButton icon={Eye} label="Buka" href={`/tiket/${r.id}`} />}
            columns={[
                { key: 'no_tiket', label: 'No. Tiket', render: (r) => <span className="fw-semibold">{r.no_tiket}</span> },
                { key: 'pelanggan', label: 'Pelanggan', sortValue: (r) => `${r.pelanggan?.id_pelanggan ?? ''} ${r.pelanggan?.nama ?? ''}`, render: (r) => <>{r.pelanggan?.nama ?? '-'}<br /><small className="f-light">{r.pelanggan?.id_pelanggan}</small></> },
                { key: 'judul', label: 'Judul', kartu: 'judul', render: (r) => <>{r.judul}<br /><small className="f-light">{r.kategori_label}</small></> },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                { key: 'cabang', label: 'Cabang', render: (r) => r.cabang ?? '-' },
                { key: 'created_at', label: 'Dibuat', render: (r) => waktu(r.created_at) },
                { key: 'updated_at', label: 'Update Terakhir', render: (r) => waktu(r.updated_at) },
            ]}
        />
    );
}
