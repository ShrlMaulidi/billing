import { usePage } from '@inertiajs/react';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { angka } from '../../lib/format';
import { deleteFrom, saveTo } from '../../lib/ui';

// Area di dalam cabang: satu cabang bisa memiliki banyak area
export default function Area({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Area" rows={rows} filters={filters} deleteLabel={(r) => `${r.nama_area} (${r.cabang})`}
            defaults={{ id_lokasi: filters.cabang ?? '', status: 'aktif' }}
            onSave={saveTo('/area')} onDelete={deleteFrom('/area')}
            columns={[
                { key: 'kode_area', label: 'Kode' }, { key: 'nama_area', label: 'Nama Area' }, { key: 'cabang', label: 'Cabang' },
                { key: 'cakupan', label: 'Cakupan Wilayah' },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
            ]}
            fields={[
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions, col: 'col-12' },
                { name: 'kode_area', label: 'Kode Area', placeholder: 'C001-A05' },
                { name: 'nama_area', label: 'Nama Area', required: true },
                { name: 'cakupan', label: 'Cakupan Wilayah', type: 'textarea', col: 'col-12', placeholder: 'Contoh: Kec. Cibiru, Kec. Panyileukan' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }], col: 'col-12' },
            ]}
        />
    );
}
