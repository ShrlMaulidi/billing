import { useState } from 'react';
import { Edit2, Info, Lock, Star, Zap } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { angka, METODE, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

const PROVIDER = [{ value: 'midtrans', label: 'Midtrans' }, { value: 'xendit', label: 'Xendit' }, { value: 'tripay', label: 'Tripay' }];
const NAMA_PROVIDER = Object.fromEntries(PROVIDER.map((p) => [p.value, p.label]));

/** Petunjuk letak tiap kunci di dashboard provider. */
const BANTUAN_KUNCI = {
    server_key: 'Dashboard Midtrans › Settings › Access Keys. Sebaiknya buat Server Key khusus billing.',
    signature_key: 'Opsional, untuk dashboard Midtrans versi baru.',
    secret_key: 'Dashboard Xendit › Settings › API Keys. Buat Secret Key dengan izin Write untuk Invoice.',
    callback_token: 'Dashboard Xendit › Settings › Webhooks: Verification Token.',
    merchant_code: 'Dashboard Tripay › Merchant: kode merchant (mis. T12345).',
    api_key: 'Dashboard Tripay › Merchant › API & Integrasi.',
    private_key: 'Dashboard Tripay › Merchant › API & Integrasi.',
};
const BANTUAN_MODE = {
    midtrans: 'Production memakai kunci yang diawali "Mid-server-"; Sandbox memakai kunci yang diawali "SB-Mid-server-".',
    xendit: 'Mode Test memakai Secret Key "xnd_development_…", mode Live memakai "xnd_production_…".',
    tripay: 'Kunci Sandbox & Production Tripay berbeda; pilih mode sesuai kunci yang ditempel.',
};
const MODE = [{ value: false, label: 'Sandbox (uji coba)' }, { value: true, label: 'Production (uang sungguhan)' }];
const STATUS = [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }];

/**
 * Payment gateway diatur sendiri dari aplikasi. Tiap pemilik boleh punya beberapa gateway; yang ditandai Utama dipakai untuk
 * transaksi baru. Utama perusahaan (diatur Super Admin) dipakai semua cabang non Admin Full; cabang Admin Full menambah
 * gateway sendiri dari dashboard-nya (uang masuk ke mitra). Kunci tidak pernah dikirim ke browser: tabel hanya menampilkan
 * 4 karakter terakhir, dan kunci yang dikosongkan saat edit tetap memakai kunci lama.
 */
export default function PaymentGateway({ rows, cabangUser, superAdmin, pemilikOptions, perusahaanDariEnv, utamaPerusahaan, kredensialProvider, urlNotifikasi }) {
    const bisa = useBisa();
    const [menguji, setMenguji] = useState(null);
    const [mengutamakan, setMengutamakan] = useState(null);
    const mitra = !!cabangUser;
    const ikutPerusahaan = mitra && !cabangUser.admin_full;
    // Admin pusat selain Super Admin hanya melihat (tidak punya pilihan pemilik)
    const hanyaLihat = !mitra && !superAdmin;

    const jadikanUtama = async (r) => {
        setMengutamakan(r.id);
        try {
            notify((await api('POST', `/payment-gateway/${r.id}/utama`)).message, 'success', 6000);
        } catch (err) {
            notify(err.message, 'danger', 8000);
        } finally {
            setMengutamakan(null);
            reloadData(['rows']);
        }
    };

    const uji = async (r) => {
        setMenguji(r.id);
        try {
            notify((await api('POST', `/payment-gateway/${r.id}/uji`)).message, 'success', 6000);
            reloadData(['rows']);
        } catch (err) {
            notify(Object.values(err.errors ?? {})[0]?.[0] ?? err.message, 'danger', 8000);
        } finally {
            setMenguji(null);
        }
    };

    return (
        <CrudPage
            title="Payment Gateway" crumbs={[]} entity="Payment Gateway" rows={rows} filterFields={[]} modalSize="lg"
            deleteLabel={(r) => `${r.nama ?? METODE[r.provider] ?? r.provider} — ${r.pemilik}`}
            onSave={saveTo('/payment-gateway')} onDelete={deleteFrom('/payment-gateway')}
            canAdd={bisa('payment_gateway.create') && !ikutPerusahaan && !hanyaLihat} canEdit={false} canDelete={bisa('payment_gateway.delete') && !hanyaLihat}
            defaults={{ provider: 'midtrans', nama: 'Midtrans', id_lokasi: mitra ? cabangUser.id : '', produksi: true, status: 'aktif' }}
            before={(
                <div className={`alert ${ikutPerusahaan ? 'alert-light-primary' : 'alert-light-warning'} d-flex align-items-start gap-2`}>
                    <Info size={16} className="flex-shrink-0 mt-1" />
                    <span>
                        {ikutPerusahaan && (
                            <>
                                Cabang <strong>{cabangUser.nama_lokasi}</strong> bukan Admin Full, jadi memakai <strong>payment gateway Utama perusahaan</strong>
                                {utamaPerusahaan ? <> (<strong>{utamaPerusahaan.nama ?? METODE[utamaPerusahaan.provider]}</strong> · {utamaPerusahaan.produksi ? 'Production' : 'Sandbox'})</> : ''}.
                                Tidak perlu menambah payment gateway sendiri; pembayaran online pelanggan langsung diterima perusahaan.
                            </>
                        )}
                        {mitra && !ikutPerusahaan && <>Cabang <strong>{cabangUser.nama_lokasi}</strong> berstatus <strong>Admin Full</strong>: tambahkan akun Midtrans milik Anda sendiri. Gateway bertanda <Star size={12} className="align-baseline" /> <strong>Utama</strong> yang dipakai pelanggan cabang Anda; gateway lain menjadi cadangan. Pembayaran online masuk ke akun Anda, lalu setorkan kewajiban ke perusahaan sesuai <strong>Saldo / Balance</strong> di Dashboard.</>}
                        {!mitra && <>Gateway <strong>Perusahaan</strong> bertanda <Star size={12} className="align-baseline" /> <strong>Utama</strong> dipakai semua cabang yang <strong>Admin Full-nya Tidak</strong>; gateway perusahaan hanya bisa diatur Super Admin. Cabang Admin Full memakai gateway Utama miliknya sendiri. Gateway pertama tiap pemilik otomatis menjadi Utama. URL notifikasi tidak perlu diisi di dashboard Midtrans: billing mengirimnya otomatis di setiap transaksi.{hanyaLihat && <> <strong>Anda hanya bisa melihat:</strong> gateway cabang Admin Full diatur pemilik cabangnya atau Super Admin.</>}{perusahaanDariEnv && <> Saat ini gateway perusahaan masih memakai konfigurasi server (.env); setelah ditambahkan di sini, pengaturan di menu ini yang dipakai.</>}</>}
                    </span>
                </div>
            )}
            rowActions={(r, { edit }) => (
                <>
                    {r.bisa_kelola && bisa('payment_gateway.update') && !r.utama && r.status === 'aktif' && (
                        <ActionButton icon={Star} label={mengutamakan === r.id ? 'Memproses…' : 'Jadikan Utama'} color="info" onClick={() => mengutamakan === null && jadikanUtama(r)} />
                    )}
                    {r.bisa_kelola && <ActionButton icon={Zap} label={menguji === r.id ? 'Menguji…' : 'Uji koneksi'} color="success" onClick={() => menguji === null && uji(r)} />}
                    {r.bisa_kelola && bisa('payment_gateway.update') && <ActionButton icon={Edit2} label="Edit" color="warning" onClick={edit} />}
                </>
            )}
            columns={[
                {
                    key: 'nama', label: 'Nama', render: (r) => (
                        <>
                            <strong>{r.nama ?? METODE[r.provider] ?? r.provider}</strong>
                            {r.utama && <span className="badge badge-light-primary ms-2"><Star size={11} className="me-1 align-baseline" />Utama</span>}
                            <br /><small className="f-light">{METODE[r.provider] ?? r.provider}</small>
                        </>
                    ),
                },
                { key: 'pemilik', label: 'Pemilik' },
                { key: 'produksi', label: 'Mode', render: (r) => <span className={`badge badge-light-${r.produksi ? 'success' : 'warning'}`}>{r.produksi ? 'Production' : 'Sandbox'}</span> },
                {
                    key: 'kunci_tersamar', label: 'Kunci', sortable: false,
                    render: (r) => r.kunci_tersamar.filter((k) => k.nilai).map((k) => <div key={k.nama} className="small text-nowrap">{k.label}: <code>{k.nilai}</code></div>),
                },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                { key: 'diuji_at', label: 'Terakhir Diuji', render: (r) => waktu(r.diuji_at) },
                { key: 'jumlah_transaksi', label: 'Transaksi', align: 'end', render: (r) => angka(r.jumlah_transaksi) },
            ]}
            fields={[
                {
                    name: 'provider', label: 'Provider', type: 'select', required: true, options: PROVIDER, show: (v) => !v.id,
                    // Nama bawaan ikut provider selama belum diubah pengguna
                    resets: (baru, v) => (!v.nama || v.nama === NAMA_PROVIDER[v.provider] ? { nama: NAMA_PROVIDER[baru] ?? '' } : {}),
                },
                { name: 'nama', label: 'Nama Gateway', required: true, placeholder: 'Midtrans Utama', help: 'Pembeda bila pemilik punya lebih dari satu gateway, mis. "Midtrans Cadangan"' },
                mitra
                    ? { name: 'pemilik_cabang', label: 'Pemilik', type: 'static', show: (v) => !v.id, render: () => <strong>{cabangUser.nama_lokasi}</strong> }
                    : { name: 'id_lokasi', label: 'Pemilik Gateway', type: 'select', required: true, options: pemilikOptions, show: (v) => !v.id, help: 'Cabang yang bisa dipilih hanya cabang Admin Full' },
                { name: 'pemilik_edit', label: 'Pemilik', type: 'static', show: (v) => !!v.id, render: (v) => <strong>{v.pemilik}{v.utama && ' · Utama'}</strong> },
                { name: 'produksi', label: 'Mode', type: 'radio', required: true, options: MODE, col: 'col-12', help: (v) => BANTUAN_MODE[v.provider] ?? BANTUAN_MODE.midtrans },
                {
                    name: 'kunci_tersimpan', label: 'Kunci tersimpan', type: 'static', col: 'col-12', show: (v) => !!v.id,
                    render: (v) => <>{v.kunci_tersamar.filter((k) => k.nilai).map((k) => <span key={k.nama} className="me-3">{k.label} <code>{k.nilai}</code></span>)} Kosongkan isian kunci di bawah bila tidak diganti.</>,
                },
                // Isian kunci sesuai provider: wajib saat tambah, boleh dikosongkan saat edit (kunci lama dipakai)
                ...Object.entries(kredensialProvider).flatMap(([provider, isian]) => isian.flatMap((k) => [
                    { name: k.nama, label: k.label, type: k.nama === 'merchant_code' ? 'text' : 'password', required: k.wajib, show: (v) => !v.id && v.provider === provider, help: k.wajib ? BANTUAN_KUNCI[k.nama] : `Opsional. ${BANTUAN_KUNCI[k.nama] ?? ''}` },
                    { name: k.nama, label: `${k.label} baru`, type: k.nama === 'merchant_code' ? 'text' : 'password', show: (v) => !!v.id && v.provider === provider, help: 'Kosongkan bila tidak diganti' },
                ])),
                {
                    name: 'info_xendit', type: 'static', col: 'col-12', show: (v) => v.provider === 'xendit',
                    render: () => (
                        <div className="alert alert-light-primary mb-0 small">
                            Isi URL berikut di <strong>Dashboard Xendit › Settings › Webhooks › Invoices paid</strong> agar status lunas tercatat otomatis:<br />
                            <code className="user-select-all">{urlNotifikasi.xendit}</code>
                        </div>
                    ),
                },
                {
                    name: 'info_tripay', type: 'static', col: 'col-12', show: (v) => v.provider === 'tripay',
                    render: () => <div className="alert alert-light-primary mb-0 small">Pelanggan memilih metode bayar (QRIS, Virtual Account, gerai, e-wallet) di billing sebelum diarahkan ke Tripay. URL callback dikirim otomatis di setiap transaksi, tidak perlu diisi di dashboard Tripay.</div>,
                },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: STATUS, help: 'Tidak Aktif: tidak ada transaksi baru; transaksi yang sudah berjalan tetap diproses. Gateway Utama baru bisa dinonaktifkan setelah gateway lain dijadikan Utama (kecuali satu-satunya gateway).' },
                {
                    name: 'judul_konfirmasi', type: 'static', col: 'col-12',
                    render: () => <div className="alert alert-light-secondary d-flex align-items-center gap-2 mb-0"><Lock size={14} className="flex-shrink-0" />Kunci akan diuji ke payment gateway sebelum disimpan. Masukkan password Anda untuk konfirmasi.</div>,
                },
                { name: 'password', label: 'Password Anda', type: 'password', required: true },
            ]}
        />
    );
}
