import { usePage } from '@inertiajs/react';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { maskRekening } from '../../lib/format';
import { deleteFrom, saveTo, useBisa } from '../../lib/ui';

/** Rekening tujuan transfer; muncul sebagai pilihan metode saat input pembayaran pelanggan. */
export default function Rekening({ filters, rows }) {
    const { cabangOptions, auth } = usePage().props;
    const bisa = useBisa();
    return (
        <CrudPage
            title="Rekening" entity="Rekening" rows={rows} filters={filters} deleteLabel={(r) => `${r.nama_rekening} ${r.atas_nama}`}
            defaults={{ tampil_invoice: true, id_lokasi: auth.user?.id_lokasi ?? '' }}
            onSave={saveTo('/rekening')} onDelete={deleteFrom('/rekening')}
            canAdd={bisa('kelola_data.rekening.create')} canEdit={bisa('kelola_data.rekening.update')} canDelete={bisa('kelola_data.rekening.delete')}
            columns={[
                { key: 'nama_rekening', label: 'Bank / E-wallet' },
                { key: 'atas_nama', label: 'Atas Nama' },
                { key: 'no_rekening', label: 'No Rekening', render: (r) => maskRekening(r.no_rekening) },
                { key: 'cabang', label: 'Cabang' },
                { key: 'tampil_invoice', label: 'Tampil di Invoice', render: (r) => <StatusBadge value={r.tampil_invoice} /> },
            ]}
            fields={[
                { name: 'nama_rekening', label: 'Nama Bank / E-wallet', required: true, placeholder: 'BCA, BRI, DANA, …' },
                { name: 'atas_nama', label: 'Atas Nama', required: true },
                { name: 'no_rekening', label: 'No Rekening', required: true, placeholder: 'Angka saja' },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', options: cabangOptions, required: !!auth.user?.id_lokasi, help: auth.user?.id_lokasi ? undefined : 'Kosongkan bila rekening berlaku untuk semua cabang' },
                { name: 'tampil_invoice', label: 'Tampilkan di invoice', type: 'switch' },
            ]}
        />
    );
}
