import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Image, Printer } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { ActionButton } from '../../Components/ui';
import { periode, rupiah, tanggal, waktu } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, useBisa } from '../../lib/ui';

const BASE = '/transaksi/setor-kewajiban';
const RELOAD = ['rows', 'kewajiban'];

/** Simpan setoran sebagai multipart karena bisa membawa foto bukti setor. */
const simpan = async (values) => {
    const data = new FormData();
    ['id_lokasi', 'periode', 'nominal', 'metode', 'keterangan'].forEach((k) => values[k] !== undefined && values[k] !== '' && data.append(k, values[k]));
    if (values.bukti_setor instanceof File) data.append('bukti_setor', values.bukti_setor);
    try {
        const res = await api('POST', BASE, data);
        notify(res.message);
        reloadData(RELOAD);
    } catch (err) {
        if (!err.errors) notify(err.message, 'danger', 7000);
        throw err;
    }
};

/**
 * Setor kewajiban mitra: Super Admin mencatat setoran mitra ke perusahaan, langsung menambah saldo mitra pada periodenya.
 * Mitra hanya melihat setoran cabangnya.
 */
export default function Setor({ filters, rows, kewajiban, periodeOptions, rekeningOptions }) {
    const { auth, cabangOptions } = usePage().props;
    const bisa = useBisa();
    // Input & pembatalan hanya oleh user pusat (server menolak user cabang)
    const pusat = !auth.user?.id_lokasi;
    const [kuitansi, setKuitansi] = useState(null);

    return (
        <>
            <CrudPage
                title="Setor Kewajiban Mitra" crumbs={['Transaksi']} entity="Setoran" rows={rows} filters={filters}
                canAdd={pusat && bisa('transaksi.setor.create')} canEdit={false} canDelete={pusat && bisa('transaksi.setor.delete')}
                defaults={{ periode: periodeOptions[0], metode: 'cash' }}
                onSave={simpan} onDelete={deleteFrom(BASE, RELOAD)}
                deleteMessage={(r) => <>Batalkan setoran <strong>{r.cabang}</strong> sebesar <strong>{rupiah(r.nominal)}</strong> periode {periode(r.periode)}? Saldo mitra akan berkurang kembali.</>}
                filterFields={['bulan', 'tahun', 'cabang', { name: 'semua', label: 'Semua periode', type: 'switch' }]}
                tableProps={{ footer: sumFooter(['nominal'], rupiah) }}
                rowActions={(r) => (
                    <>
                        {r.ada_bukti && <ActionButton icon={Image} label="Lihat Bukti Setor" color="info" onClick={() => window.open(`${BASE}/${r.id}/bukti`, '_blank', 'noopener')} />}
                        <ActionButton icon={Printer} label="Cetak Kuitansi" color="dark" onClick={() => setKuitansi(r)} />
                    </>
                )}
                columns={[
                    { key: 'no', label: 'No', sortable: false, render: (r, i) => i + 1 },
                    { key: 'cabang', label: 'Cabang / Mitra', render: (r) => <>{r.cabang}{r.nama_mitra && <><br /><small className="f-light">{r.nama_mitra}</small></>}</> },
                    { key: 'periode', label: 'Periode', render: (r) => periode(r.periode) },
                    { key: 'nominal', label: 'Nominal Disetor', align: 'end', render: (r) => rupiah(r.nominal) },
                    { key: 'metode', label: 'Metode / Rekening', render: (r) => (r.metode === 'cash' ? 'Cash' : r.rekening ?? 'Transfer') },
                    { key: 'keterangan', label: 'Keterangan', render: (r) => r.keterangan ?? '-' },
                    { key: 'petugas', label: 'Petugas' },
                    { key: 'created_at', label: 'Waktu Entry', render: (r) => waktu(r.created_at) },
                ]}
                fields={[
                    { name: 'id_lokasi', label: 'Cabang / Mitra', type: 'select', required: true, options: cabangOptions },
                    { name: 'periode', label: 'Periode', type: 'select', required: true, options: periodeOptions.map((p) => ({ value: p, label: periode(p) })) },
                    {
                        name: 'kewajiban', label: 'Sisa Kewajiban Periode Terpilih', type: 'static', col: 'col-12', render: (v) => {
                            const sisa = kewajiban[v.id_lokasi]?.[v.periode];
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
                ]}
            />
            <Modal isOpen={!!kuitansi} toggle={() => setKuitansi(null)} centered>
                <ModalHeader toggle={() => setKuitansi(null)}>Kuitansi Setoran</ModalHeader>
                <ModalBody>
                    {kuitansi && (
                        <div className="p-3 border rounded">
                            <div className="d-flex justify-content-between mb-3"><h5>KUITANSI</h5><span>No. STR-{String(kuitansi.id).padStart(5, '0')}</span></div>
                            <p className="mb-1">Telah terima dari: <strong>{kuitansi.cabang}{kuitansi.nama_mitra ? ` (${kuitansi.nama_mitra})` : ''}</strong></p>
                            <p className="mb-1">Uang sejumlah: <strong>{rupiah(kuitansi.nominal)}</strong></p>
                            <p className="mb-1">Untuk pembayaran: Setoran kewajiban periode {periode(kuitansi.periode)}</p>
                            <p className="mb-4">Via: {kuitansi.metode === 'cash' ? 'Cash' : kuitansi.rekening}</p>
                            <div className="text-end">{tanggal(kuitansi.created_at)}<br /><br /><br />( {kuitansi.petugas} )</div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter><Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak</Button></ModalFooter>
            </Modal>
        </>
    );
}
