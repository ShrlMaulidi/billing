import { usePage } from '@inertiajs/react';
import CrudPage from '../../Components/CrudPage';
import { persen, rupiah, tanggal } from '../../lib/format';

export default function Pajak({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    const pct = (key, label) => ({ key, label, align: 'end', render: (r) => persen(r[key]) });
    return (
        <CrudPage
            title="Pengaturan Pajak" entity="Pengaturan Pajak" rows={rows} filters={filters}
            defaults={{ admin_fee_tipe: 'nominal', ppn_persen: 11 }} deleteLabel={(r) => r.cabang}
            columns={[
                { key: 'cabang', label: 'Cabang', render: (r) => (r.id_lokasi ? r.cabang : <strong>{r.cabang}</strong>) },
                pct('ppn_persen', 'PPN'), pct('pph_persen', 'PPh'), pct('bhp_persen', 'BHP'), pct('uso_persen', 'USO'),
                { key: 'admin_fee', label: 'Admin Fee', align: 'end', render: (r) => (r.admin_fee_tipe === 'persentase' ? persen(r.admin_fee) : rupiah(r.admin_fee)) },
                { key: 'berlaku_sejak', label: 'Berlaku Sejak', render: (r) => tanggal(r.berlaku_sejak) },
            ]}
            fields={[
                { name: 'id_lokasi', label: 'Cabang', type: 'select', options: cabangOptions, help: 'Kosongkan untuk default global', col: 'col-12' },
                { name: 'ppn_persen', label: 'PPN (%)', type: 'number', required: true },
                { name: 'pph_persen', label: 'PPh 25 (%)', type: 'number', required: true },
                { name: 'bhp_persen', label: 'BHP (%)', type: 'number' },
                { name: 'uso_persen', label: 'USO (%)', type: 'number' },
                { name: 'admin_fee_tipe', label: 'Tipe Admin Fee', type: 'radio', options: [{ value: 'persentase', label: 'Persentase' }, { value: 'nominal', label: 'Nominal' }] },
                { name: 'admin_fee', label: 'Admin Fee', type: 'number', required: true, help: 'Persen atau Rupiah sesuai tipe' },
                { name: 'berlaku_sejak', label: 'Berlaku Sejak', type: 'date', required: true },
            ]}
        />
    );
}
