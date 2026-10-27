import { usePage } from '@inertiajs/react';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { maskRekening } from '../../lib/format';

export default function Rekening({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Rekening" rows={rows} filters={filters} deleteLabel={(r) => `${r.nama_rekening} ${r.atas_nama}`} defaults={{ tampil_invoice: true }}
            columns={[
                { key: 'nama_rekening', label: 'Bank / E-wallet', render: (r) => <>{r.nama_rekening} {r.is_cash && <StatusBadge value="info" text="Cash" />}</> },
                { key: 'atas_nama', label: 'Atas Nama' },
                { key: 'no_rekening', label: 'No Rekening', render: (r) => maskRekening(r.no_rekening) },
                { key: 'cabang', label: 'Cabang' },
                { key: 'tampil_invoice', label: 'Tampil di Invoice', render: (r) => <StatusBadge value={r.tampil_invoice} /> },
            ]}
            fields={[
                { name: 'nama_rekening', label: 'Nama Bank / E-wallet', required: true, placeholder: 'BCA, BRI, DANA, …' },
                { name: 'atas_nama', label: 'Atas Nama', required: true },
                { name: 'no_rekening', label: 'No Rekening', required: true },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                { name: 'tampil_invoice', label: 'Tampilkan di invoice', type: 'switch' },
                { name: 'is_cash', label: 'Record khusus Cash / Tunai', type: 'switch' },
            ]}
        />
    );
}
