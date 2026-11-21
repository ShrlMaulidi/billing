import { useState } from 'react';
import { Edit2, Info, Lock, Zap } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, METODE, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

const PROVIDER = [{ value: 'midtrans', label: 'Midtrans' }];
const MODE = [{ value: false, label: 'Sandbox (uji coba)' }, { value: true, label: 'Production (uang sungguhan)' }];
const STATUS = [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }];

/**
 * Payment gateway diatur sendiri dari aplikasi. Cabang non Admin Full memakai gateway perusahaan (diatur Super Admin);
 * cabang Admin Full menambah gateway sendiri (uang masuk ke mitra). Kunci tidak pernah dikirim ke browser: tabel hanya
 * menampilkan 4 karakter terakhir, dan kunci yang dikosongkan saat edit tetap memakai kunci lama.
 */
export default function PaymentGateway({ rows, cabangUser, superAdmin, pemilikOptions, perusahaanDariEnv }) {
    const bisa = useBisa();
    const [menguji, setMenguji] = useState(null);
    const mitra = !!cabangUser;
    const ikutPerusahaan = mitra && !cabangUser.admin_full;
    const sudahPunya = mitra && rows.length > 0;

    const uji = async (r) => {
        setMenguji(r.id);
        try {
            notify((await api('POST', `/payment-gateway/${r.id}/uji`)).message, 'success', 6000);
            reloadData(['rows']);
        } catch (err) {
            notify(err.errors?.server_key?.[0] ?? err.message, 'danger', 8000);
        } finally {
            setMenguji(null);
        }
    };

    return (
        <CrudPage
            title="Payment Gateway" crumbs={[]} entity="Payment Gateway" rows={rows} filterFields={[]} modalSize="lg"
            deleteLabel={(r) => `${METODE[r.provider] ?? r.provider} — ${r.pemilik}`}
            onSave={saveTo('/payment-gateway')} onDelete={deleteFrom('/payment-gateway')}
            canAdd={bisa('payment_gateway.create') && !ikutPerusahaan && !sudahPunya} canEdit={false} canDelete={bisa('payment_gateway.delete')}
            defaults={{ provider: 'midtrans', id_lokasi: mitra ? cabangUser.id : '', produksi: true, status: 'aktif' }}
            before={(
                <div className={`alert ${ikutPerusahaan ? 'alert-light-primary' : 'alert-light-warning'} d-flex align-items-start gap-2`}>
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        {ikutPerusahaan && <>Cabang <strong>{cabangUser.nama_lokasi}</strong> memakai payment gateway perusahaan, jadi tidak perlu menambah payment gateway sendiri. Pembayaran online pelanggan langsung diterima perusahaan.</>}
                        {mitra && !ikutPerusahaan && <>Cabang <strong>{cabangUser.nama_lokasi}</strong> berstatus <strong>Admin Full</strong>: tambahkan akun Midtrans milik Anda sendiri. Pembayaran online pelanggan masuk ke akun Anda, lalu setorkan kewajiban ke perusahaan sesuai <strong>Saldo / Balance</strong> di Dashboard.</>}
                        {!mitra && <>Gateway <strong>Perusahaan</strong> dipakai pelanggan cabang non Admin Full dan hanya bisa diatur Super Admin. Cabang Admin Full bisa punya gateway sendiri. URL notifikasi tidak perlu diisi di dashboard Midtrans: billing mengirimnya otomatis di setiap transaksi.{perusahaanDariEnv && <> Saat ini gateway perusahaan masih memakai konfigurasi server (.env); setelah ditambahkan di sini, pengaturan di menu ini yang dipakai.</>}</>}
                    </span>
                </div>
            )}
            rowActions={(r, { edit }) => (
                <>
                    {r.bisa_kelola && <ActionButton icon={Zap} label={menguji === r.id ? 'Menguji…' : 'Uji koneksi'} color="success" onClick={() => menguji === null && uji(r)} />}
                    {r.bisa_kelola && bisa('payment_gateway.update') && <ActionButton icon={Edit2} label="Edit" color="warning" onClick={edit} />}
                </>
            )}
            columns={[
                { key: 'provider', label: 'Provider', render: (r) => <strong>{METODE[r.provider] ?? r.provider}</strong> },
                { key: 'pemilik', label: 'Pemilik' },
                { key: 'produksi', label: 'Mode', render: (r) => <span className={`badge badge-light-${r.produksi ? 'success' : 'warning'}`}>{r.produksi ? 'Production' : 'Sandbox'}</span> },
                { key: 'server_key_tersamar', label: 'Server Key', sortable: false, render: (r) => <code>{r.server_key_tersamar}</code> },
                { key: 'signature_key_tersamar', label: 'Signature Key', sortable: false, render: (r) => (r.signature_key_tersamar ? <code>{r.signature_key_tersamar}</code> : '-') },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                { key: 'diuji_at', label: 'Terakhir Diuji', render: (r) => waktu(r.diuji_at) },
                { key: 'jumlah_transaksi', label: 'Transaksi', align: 'end', render: (r) => angka(r.jumlah_transaksi) },
            ]}
            fields={[
                { name: 'provider', label: 'Provider', type: 'select', required: true, options: PROVIDER, show: (v) => !v.id },
                mitra
                    ? { name: 'pemilik_cabang', label: 'Pemilik', type: 'static', show: (v) => !v.id, render: () => <strong>{cabangUser.nama_lokasi}</strong> }
                    : { name: 'id_lokasi', label: 'Pemilik Gateway', type: 'select', required: true, options: pemilikOptions, show: (v) => !v.id, help: superAdmin ? 'Cabang yang bisa dipilih hanya cabang Admin Full' : 'Gateway perusahaan hanya bisa diatur Super Admin' },
                { name: 'pemilik_edit', label: 'Gateway', type: 'static', show: (v) => !!v.id, render: (v) => <strong>{METODE[v.provider] ?? v.provider} — {v.pemilik}</strong> },
                { name: 'produksi', label: 'Mode', type: 'radio', required: true, options: MODE, col: 'col-12', help: 'Production memakai kunci yang diawali "Mid-server-"; Sandbox memakai kunci yang diawali "SB-Mid-server-".' },
                { name: 'server_key', label: 'Server Key', type: 'password', required: true, show: (v) => !v.id, help: 'Dashboard Midtrans › Settings › Access Keys. Sebaiknya buat Server Key khusus billing.' },
                {
                    name: 'kunci_tersimpan', label: 'Kunci tersimpan', type: 'static', col: 'col-12', show: (v) => !!v.id,
                    render: (v) => <>Server Key <code>{v.server_key_tersamar}</code>{v.signature_key_tersamar && <> · Signature Key <code>{v.signature_key_tersamar}</code></>}. Kosongkan isian kunci di bawah bila tidak diganti.</>,
                },
                { name: 'server_key', label: 'Server Key baru', type: 'password', show: (v) => !!v.id, help: 'Kosongkan bila tidak diganti' },
                { name: 'signature_key', label: 'Signature Secret Key', type: 'password', help: 'Opsional, untuk dashboard Midtrans versi baru' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: STATUS, help: 'Tidak Aktif: tidak ada transaksi baru; transaksi yang sudah berjalan tetap diproses.' },
                {
                    name: 'judul_konfirmasi', type: 'static', col: 'col-12',
                    render: () => <div className="alert alert-light-secondary d-flex align-items-center gap-2 mb-0"><Lock size={14} className="flex-shrink-0" />Kunci akan diuji ke Midtrans sebelum disimpan. Masukkan password Anda untuk konfirmasi.</div>,
                },
                { name: 'password', label: 'Password Anda', type: 'password', required: true },
            ]}
        />
    );
}
