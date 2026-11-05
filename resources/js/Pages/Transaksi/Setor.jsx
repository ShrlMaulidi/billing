import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Image, Printer } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { ActionButton } from '../../Components/ui';
import { BULAN, periode, persen, rupiah, tanggal, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, useBisa } from '../../lib/ui';

const BASE = '/transaksi/setor-kewajiban';
const RELOAD = ['rows', 'kewajiban'];
/** Mitra Reseller & Shafa.net = setoran cabang (menambah saldo mitra); Korporat & Instansi Pemerintah = pembayaran langganan + PPN. */
const isCabang = (tipe) => tipe === 'reseller' || tipe === 'retail';
const isInstansi = (tipe) => tipe === 'korporat' || tipe === 'pemerintah';
const WARNA = { reseller: 'primary', korporat: 'info', pemerintah: 'success', retail: 'warning' };

// Periode dipilih lewat dropdown Bulan & Tahun terpisah, lalu digabung jadi Y-m-01 untuk dikirim ke server
const gabungPeriode = (tahun, bulan) => (tahun && bulan ? `${tahun}-${String(bulan).padStart(2, '0')}-01` : '');
const pecahPeriode = (p) => ({ periode: p ?? '', tahun_periode: p ? p.slice(0, 4) : '', bulan_periode: p ? String(Number(p.slice(5, 7))) : '' });

const hitungPpn = (v) => Math.round((Number(v.nominal) || 0) * (Number(v.ppn_persen) || 0) / 100);

/** Simpan setoran sebagai multipart karena bisa membawa foto bukti setor. */
const simpanSetoran = async (values) => {
    const data = new FormData();
    ['id_lokasi', 'periode', 'nominal', 'metode', 'keterangan'].forEach((k) => values[k] !== undefined && values[k] !== '' && data.append(k, values[k]));
    if (values.bukti_setor instanceof File) data.append('bukti_setor', values.bukti_setor);
    return kirim(() => api('POST', BASE, data));
};

/** Pembayaran korporat / instansi: PPN & total dihitung ulang di server. */
const simpanInstansi = (values) => kirim(() => api('POST', `${BASE}/instansi`, {
    tipe: values.tipe, pelanggan_instansi_id: values.pelanggan_instansi_id, nominal: values.nominal, ppn_persen: values.ppn_persen, periode: values.periode,
}));

const kirim = async (request) => {
    try {
        const res = await request();
        notify(res.message);
        reloadData(RELOAD);
    } catch (err) {
        if (!err.errors) notify(err.message, 'danger', 7000);
        // Error periode dari server ditampilkan di dropdown Bulan
        if (err.errors?.periode) err.errors.bulan_periode = err.errors.periode;
        throw err;
    }
};

/**
 * Pembayaran Masuk: pilih tipe pelanggan dulu, lalu nama pelanggan; isi formulir menyesuaikan tipe.
 * Setoran Mitra Reseller / Shafa.net langsung menambah saldo mitra pada periodenya; input & pembatalan hanya oleh user pusat.
 */
export default function Setor({ filters, rows, kewajiban, periodeOptions, periodeSekarang, ppnBawaan, rekeningOptions, tipeOptions, namaOptions }) {
    const { auth } = usePage().props;
    const bisa = useBisa();
    // Input & pembatalan hanya oleh user pusat (server menolak user cabang)
    const pusat = !auth.user?.id_lokasi;
    const [kuitansi, setKuitansi] = useState(null);
    const labelTipe = Object.fromEntries(tipeOptions.map((o) => [o.value, o.label]));
    // Mitra / Shafa.net sampai bulan berjalan; Korporat & Instansi bisa bayar di muka sampai Desember tahun depan
    const periodeTipe = (v) => (isInstansi(v.tipe) ? periodeOptions.instansi : periodeOptions.cabang);
    const pilihanTahun = (v) => [...new Set(periodeTipe(v).map((p) => p.slice(0, 4)))].map((y) => ({ value: y, label: y }));
    // Bulan yang bisa dipilih mengikuti tahun terpilih
    const pilihanBulan = (v) => periodeTipe(v).filter((p) => p.slice(0, 4) === String(v.tahun_periode))
        .map((p) => ({ value: String(Number(p.slice(5, 7))), label: BULAN[Number(p.slice(5, 7)) - 1] }));
    const fieldPeriode = (col) => [
        {
            name: 'bulan_periode', label: 'Bulan', type: 'select', required: true, col, options: pilihanBulan,
            resets: (bulan, v) => ({ periode: gabungPeriode(v.tahun_periode, bulan) }),
        },
        {
            name: 'tahun_periode', label: 'Tahun', type: 'select', required: true, col, options: pilihanTahun,
            // Bulan yang tidak tersedia di tahun baru dikosongkan
            resets: (tahun, v) => {
                const p = gabungPeriode(tahun, v.bulan_periode);
                return periodeTipe(v).includes(p) ? { periode: p } : { periode: '', bulan_periode: '' };
            },
        },
    ];
    const pilihanNama = (v) => namaOptions[v.tipe] ?? [];

    // Ganti tipe → kosongkan nama & nominal, isi nilai bawaan tipe itu
    const bawaan = (tipe) => ({
        id_lokasi: '', pelanggan_instansi_id: '', nominal: '', ...pecahPeriode(periodeSekarang),
        ...(isInstansi(tipe) ? { ppn_persen: ppnBawaan } : { metode: 'cash' }),
    });

    const fieldsCabang = [
        { name: 'id_lokasi', label: 'Nama Mitra / Cabang', type: 'select', required: true, options: pilihanNama },
        ...fieldPeriode('col-md-3'),
        {
            name: 'kewajiban', label: 'Sisa Kewajiban Periode Terpilih', type: 'static', col: 'col-12', render: (v) => {
                // Deferred prop: masih dimuat setelah halaman tampil
                if (v.id_lokasi && v.periode && kewajiban === undefined) return <span className="skeleton d-inline-block" style={{ width: 160, height: 22 }} />;
                const sisa = kewajiban?.[v.id_lokasi]?.[v.periode];
                if (!v.id_lokasi || sisa === undefined) return '-';
                return (
                    <>
                        <h5 className={`mb-0 ${sisa > 0 ? 'font-danger' : 'font-success'}`}>{rupiah(sisa)}</h5>
                        <small className="f-light">{sisa > 0 ? 'Uang perusahaan yang masih dipegang mitra (saldo minus)' : 'Mitra tidak punya kewajiban setor di periode ini'}</small>
                    </>
                );
            },
        },
        { name: 'nominal', label: 'Nominal Disetor', type: 'currency', required: true },
        { name: 'metode', label: 'Diterima Lewat', type: 'select', required: true, options: rekeningOptions, help: 'Rekening pusat (tanpa cabang) atau cash' },
        { name: 'bukti_setor', label: 'Foto Bukti Setor', type: 'file', accept: '.jpg,.jpeg,.png,.webp', hint: 'JPG / PNG / WEBP, maks 2 MB (opsional)', col: 'col-12' },
        { name: 'keterangan', label: 'Keterangan', type: 'textarea', col: 'col-12' },
    ].map((f) => ({ ...f, show: (v) => isCabang(v.tipe) }));

    const fieldsInstansi = [
        {
            name: 'pelanggan_instansi_id', label: 'Nama Pelanggan', type: 'select', required: true, col: 'col-12', options: pilihanNama,
            // Nominal terisi otomatis dari harga langganan pelanggan, tetap bisa diubah
            resets: (id, v) => ({ nominal: pilihanNama(v).find((o) => String(o.value) === String(id))?.harga ?? '' }),
        },
        { name: 'nominal', label: 'Nominal Pembayaran', type: 'currency', required: true, help: 'Sebelum PPN' },
        { name: 'ppn_persen', label: 'PPN (%)', type: 'number', required: true },
        ...fieldPeriode('col-md-6'),
        {
            name: 'total', label: 'Total Setelah PPN', type: 'static', render: (v) => (
                <>
                    <h5 className="mb-0 font-primary">{rupiah((Number(v.nominal) || 0) + hitungPpn(v))}</h5>
                    <small className="f-light">PPN {rupiah(hitungPpn(v))}</small>
                </>
            ),
        },
    ].map((f) => ({ ...f, show: (v) => isInstansi(v.tipe) }));

    return (
        <>
            <CrudPage
                title="Pembayaran Masuk" crumbs={['Transaksi']} entity="Pembayaran" rows={rows} filters={filters}
                canAdd={pusat && bisa('transaksi.setor.create')} canEdit={false} canDelete={pusat && bisa('transaksi.setor.delete')}
                defaults={{}}
                onSave={(values) => (isInstansi(values.tipe) ? simpanInstansi(values) : simpanSetoran(values))}
                onDelete={(row) => deleteFrom(row.sumber === 'instansi' ? `${BASE}/instansi` : BASE, RELOAD)(row)}
                deleteMessage={(r) => (r.sumber === 'instansi'
                    ? <>Batalkan pembayaran <strong>{r.nama}</strong> sebesar <strong>{rupiah(r.total)}</strong> periode {periode(r.periode)}?</>
                    : <>Batalkan setoran <strong>{r.nama}</strong> sebesar <strong>{rupiah(r.nominal)}</strong> periode {periode(r.periode)}? Saldo mitra akan berkurang kembali.</>)}
                filterFields={['bulan', 'tahun', 'cabang', { name: 'tipe', label: 'Tipe', options: tipeOptions }, { name: 'semua', label: 'Semua periode', type: 'switch' }]}
                tableProps={{ rowKey: 'key', footer: sumFooter(['nominal', 'ppn', 'total'], rupiah) }}
                rowActions={(r) => (
                    <>
                        {r.ada_bukti && <ActionButton icon={Image} label="Lihat Bukti Setor" color="info" onClick={() => window.open(`${BASE}/${r.id}/bukti`, '_blank', 'noopener')} />}
                        <ActionButton icon={Printer} label="Cetak Kuitansi" color="dark" onClick={() => setKuitansi(r)} />
                    </>
                )}
                columns={[
                    { key: 'no', label: 'No', sortable: false, render: (r, i) => i + 1 },
                    { key: 'tipe', label: 'Tipe', sortValue: (r) => labelTipe[r.tipe], render: (r) => <span className={`badge badge-light-${WARNA[r.tipe] ?? 'secondary'}`}>{labelTipe[r.tipe] ?? '-'}</span> },
                    { key: 'nama', label: 'Nama Pelanggan', render: (r) => <>{r.nama}{r.nama_mitra && <><br /><small className="f-light">{r.nama_mitra}</small></>}</> },
                    { key: 'periode', label: 'Periode', render: (r) => periode(r.periode) },
                    { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                    { key: 'ppn', label: 'PPN', align: 'end', render: (r) => (r.ppn === null ? '-' : <>{rupiah(r.ppn)}<br /><small className="f-light">{persen(r.ppn_persen)}</small></>) },
                    { key: 'total', label: 'Total', align: 'end', render: (r) => rupiah(r.total) },
                    { key: 'metode', label: 'Metode / Rekening', render: (r) => (r.sumber === 'instansi' ? '-' : r.metode === 'cash' ? 'Cash' : r.rekening ?? 'Transfer') },
                    { key: 'keterangan', label: 'Keterangan', render: (r) => r.keterangan ?? '-' },
                    { key: 'petugas', label: 'Petugas' },
                    { key: 'created_at', label: 'Waktu Entry', render: (r) => waktu(r.created_at) },
                ]}
                fields={[
                    {
                        name: 'tipe', label: 'Tipe Pelanggan', type: 'select', required: true, col: 'col-12', options: tipeOptions,
                        resets: bawaan, help: 'Pilih tipe dulu, lalu nama pelanggannya',
                    },
                    ...fieldsCabang,
                    ...fieldsInstansi,
                ]}
            />
            <Modal isOpen={!!kuitansi} toggle={() => setKuitansi(null)} centered>
                <ModalHeader toggle={() => setKuitansi(null)}>Kuitansi Pembayaran</ModalHeader>
                <ModalBody>
                    {kuitansi && (
                        <div className="p-3 border rounded">
                            <div className="d-flex justify-content-between mb-3">
                                <h5>KUITANSI</h5>
                                <span>No. {kuitansi.sumber === 'instansi' ? 'PMB' : 'STR'}-{String(kuitansi.id).padStart(5, '0')}</span>
                            </div>
                            <p className="mb-1">Telah terima dari: <strong>{kuitansi.nama}{kuitansi.nama_mitra ? ` (${kuitansi.nama_mitra})` : ''}</strong></p>
                            {kuitansi.sumber === 'instansi' ? (
                                <>
                                    <p className="mb-1">Uang sejumlah: <strong>{rupiah(kuitansi.total)}</strong></p>
                                    <p className="mb-1">Rincian: {rupiah(kuitansi.nominal)} + PPN {persen(kuitansi.ppn_persen)} {rupiah(kuitansi.ppn)}</p>
                                    <p className="mb-4">Untuk pembayaran: Langganan internet periode {periode(kuitansi.periode)}</p>
                                </>
                            ) : (
                                <>
                                    <p className="mb-1">Uang sejumlah: <strong>{rupiah(kuitansi.nominal)}</strong></p>
                                    <p className="mb-1">Untuk pembayaran: Setoran kewajiban periode {periode(kuitansi.periode)}</p>
                                    <p className="mb-4">Via: {kuitansi.metode === 'cash' ? 'Cash' : kuitansi.rekening}</p>
                                </>
                            )}
                            <div className="text-end">{tanggal(kuitansi.created_at)}<br /><br /><br />( {kuitansi.petugas} )</div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter><Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak</Button></ModalFooter>
            </Modal>
        </>
    );
}
