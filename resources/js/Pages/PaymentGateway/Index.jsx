import { usePage } from '@inertiajs/react';
import { List } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { label, METODE, rupiah } from '../../lib/format';

export default function PaymentGateway({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Payment Gateway" crumbs={[]} rows={rows} filters={filters} deleteLabel={(r) => `${METODE[r.provider]} — ${r.cabang}`}
            defaults={{ mode: 'join_ebilling', beban_biaya: 'pelanggan', status: 'aktif' }}
            rowActions={(r) => <ActionButton icon={List} label="Channel / Merchant" color="info" href={`/payment-gateway/${r.id}/channel`} />}
            columns={[
                { key: 'provider', label: 'Provider', render: (r) => <strong>{METODE[r.provider]}</strong> },
                { key: 'mode', label: 'Mode', render: (r) => (r.mode === 'join_ebilling' ? 'Join e-Billing' : 'Private') },
                { key: 'cabang', label: 'Cabang' }, { key: 'merchant_code', label: 'Merchant Code' },
                { key: 'api_key', label: 'API Key', sortable: false, render: () => '••••' },
                { key: 'biaya_admin', label: 'Biaya Admin', align: 'end', render: (r) => rupiah(r.biaya_admin) },
                { key: 'beban_biaya', label: 'Beban Biaya', render: (r) => label(r.beban_biaya) },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
            ]}
            fields={[
                { name: 'provider', label: 'Provider', type: 'select', required: true, options: ['tripay', 'xendit', 'pakar_digital', 'moota'].map((v) => ({ value: v, label: METODE[v] })) },
                { name: 'mode', label: 'Mode', type: 'radio', required: true, options: [{ value: 'join_ebilling', label: 'Join e-Billing' }, { value: 'private', label: 'Private' }] },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                { name: 'merchant_code', label: 'Merchant Code', show: (v) => v.mode === 'private' },
                { name: 'api_key', label: 'API Key', type: 'password', show: (v) => v.mode === 'private' },
                { name: 'private_key', label: 'Private Key', type: 'password', show: (v) => v.mode === 'private' },
                { name: 'pin', label: 'PIN', type: 'password', show: (v) => v.mode === 'private' && v.provider === 'pakar_digital' },
                { name: 'email_api', label: 'Email API', type: 'email', show: (v) => v.mode === 'private' && v.provider === 'moota' },
                { name: 'beban_biaya', label: 'Beban Biaya', type: 'radio', required: true, options: [{ value: 'pelanggan', label: 'Pelanggan' }, { value: 'perusahaan', label: 'Perusahaan' }] },
                { name: 'biaya_admin', label: 'Biaya Admin', type: 'currency' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
            ]}
        />
    );
}
