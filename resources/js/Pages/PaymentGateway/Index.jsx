import { Info, List } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { label, METODE, rupiah } from '../../lib/format';

/**
 * Payment gateway mengikuti Admin Full cabang (Kelola Data › Cabang): cabang non Admin Full memakai gateway perusahaan
 * dan tidak bisa menambah; cabang Admin Full menambah gateway sendiri (mode Private) dan menyetor kewajiban ke perusahaan.
 */
export default function PaymentGateway({ filters, rows, cabangUser, pemilikOptions }) {
    const mitra = !!cabangUser;
    const ikutPerusahaan = mitra && !cabangUser.admin_full;

    return (
        <CrudPage
            title="Payment Gateway" crumbs={[]} rows={rows} filters={filters} deleteLabel={(r) => `${METODE[r.provider]} — ${r.cabang}`}
            canAdd={!ikutPerusahaan} filterFields={mitra ? [] : ['cabang']}
            defaults={mitra ? { mode: 'private', id_lokasi: cabangUser.id, beban_biaya: 'pelanggan', status: 'aktif' } : { mode: 'join_ebilling', id_lokasi: 'perusahaan', beban_biaya: 'pelanggan', status: 'aktif' }}
            before={mitra && (
                <div className={`alert ${ikutPerusahaan ? 'alert-light-primary' : 'alert-light-warning'} d-flex align-items-start gap-2`}>
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        {ikutPerusahaan
                            ? <>Cabang <strong>{cabangUser.nama_lokasi}</strong> memakai payment gateway perusahaan, jadi tidak perlu menambah payment gateway sendiri. Pembayaran online pelanggan langsung diterima perusahaan.</>
                            : <>Cabang <strong>{cabangUser.nama_lokasi}</strong> berstatus <strong>Admin Full</strong>: tambahkan payment gateway milik Anda sendiri. Pembayaran online pelanggan masuk ke akun Anda, lalu setorkan kewajiban ke perusahaan sesuai <strong>Saldo / Balance</strong> di Dashboard.</>}
                    </span>
                </div>
            )}
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
                // Mitra Admin Full selalu Private (gateway miliknya sendiri); user pusat memilih pemilik gateway
                { name: 'mode', label: 'Mode', type: 'radio', required: true, disabled: mitra, options: [{ value: 'join_ebilling', label: 'Join e-Billing' }, { value: 'private', label: 'Private' }] },
                mitra
                    ? { name: 'pemilik', label: 'Pemilik', type: 'static', render: () => <strong>{cabangUser.nama_lokasi}</strong> }
                    : { name: 'id_lokasi', label: 'Pemilik Gateway', type: 'select', required: true, options: pemilikOptions, help: 'Cabang yang bisa dipilih hanya cabang Admin Full; cabang lain memakai gateway perusahaan' },
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
