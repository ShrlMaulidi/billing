import { usePage } from '@inertiajs/react';
import { FileText } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { periode, waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

export default function Pph({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Upload Bukti PPh" entity="Bukti PPh" rows={rows} filters={filters} filterFields={['tahun', 'cabang']} deleteLabel={(r) => r.nama_pph}
            columns={[
                { key: 'nama_pph', label: 'Nama PPh' }, { key: 'cabang', label: 'Cabang' }, { key: 'periode', label: 'Periode', render: (r) => periode(r.periode) },
                { key: 'lampiran', label: 'Lampiran', render: (r) => <a href="#" onClick={(e) => { e.preventDefault(); dummy('Unduh lampiran'); }}><FileText size={14} /> {r.lampiran}</a> },
                { key: 'keterangan', label: 'Keterangan' }, { key: 'created_at', label: 'Waktu Upload', render: (r) => waktu(r.created_at) }, { key: 'uploaded_by', label: 'Petugas' },
            ]}
            fields={[
                { name: 'nama_pph', label: 'Nama PPh', required: true, placeholder: 'PPh 25 Oktober 2026' },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                { name: 'periode', label: 'Periode (Bulan)', type: 'month', required: true },
                { name: 'lampiran', label: 'Lampiran', type: 'file', required: true },
                { name: 'keterangan', label: 'Keterangan', type: 'textarea', col: 'col-12' },
            ]}
        />
    );
}
