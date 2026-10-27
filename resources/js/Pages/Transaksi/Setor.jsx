import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CheckSquare, Printer } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { periode, rupiah, tanggal, waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

export default function Setor({ filters, rows, kewajiban, rekeningOptions }) {
    const { cabangOptions } = usePage().props;
    const [verif, setVerif] = useState(null);
    const [kuitansi, setKuitansi] = useState(null);

    return (
        <>
            <CrudPage
                title="Setor Kewajiban Mitra" crumbs={['Transaksi']} entity="Setoran" rows={rows} filters={filters} canEdit={false} canDelete={false}
                filterFields={['bulan', 'tahun', 'cabang', { name: 'semua', label: 'Semua periode', type: 'switch' }]}
                tableProps={{ footer: sumFooter(['nominal_harus_setor', 'nominal_setor'], rupiah) }}
                rowActions={(r) => (
                    <>
                        <ActionButton icon={CheckSquare} label="Verifikasi" color="success" disabled={r.status !== 'menunggu_verifikasi'} onClick={() => setVerif(r)} />
                        <ActionButton icon={Printer} label="Cetak Kuitansi" color="dark" onClick={() => setKuitansi(r)} />
                    </>
                )}
                columns={[
                    { key: 'no', label: 'No', sortable: false, render: (r, i) => i + 1 },
                    { key: 'cabang', label: 'Cabang / Mitra' },
                    { key: 'periode', label: 'Periode', render: (r) => periode(r.periode) },
                    { key: 'nominal_harus_setor', label: 'Harus Setor', align: 'end', render: (r) => rupiah(r.nominal_harus_setor) },
                    { key: 'nominal_setor', label: 'Disetor', align: 'end', render: (r) => rupiah(r.nominal_setor) },
                    { key: 'metode', label: 'Metode / Rekening' },
                    { key: 'bukti', label: 'Bukti', render: (r) => <a href="#" onClick={(e) => { e.preventDefault(); dummy('Lihat bukti'); }}>{r.bukti}</a> },
                    { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                    { key: 'created_at', label: 'Waktu Entry', render: (r) => waktu(r.created_at) },
                ]}
                fields={[
                    { name: 'id_lokasi', label: 'Cabang / Mitra', type: 'select', required: true, options: cabangOptions.filter((c) => c.value !== 1) },
                    { name: 'kewajiban', label: 'Sisa Kewajiban Periode Terpilih', type: 'static', render: (v) => <h5 className="font-danger mb-0">{v.id_lokasi ? rupiah(kewajiban[v.id_lokasi] ?? 0) : '-'}</h5> },
                    { name: 'periode', label: 'Periode', type: 'month', required: true },
                    { name: 'nominal_setor', label: 'Nominal Disetor', type: 'currency', required: true },
                    { name: 'id_rekening', label: 'Rekening Pusat / Cash', type: 'select', required: true, options: rekeningOptions },
                    { name: 'bukti', label: 'Bukti Setor', type: 'file', required: true },
                    { name: 'keterangan', label: 'Keterangan', type: 'textarea', col: 'col-12' },
                ]}
            />
            <Modal isOpen={!!verif} toggle={() => setVerif(null)} centered>
                <ModalHeader toggle={() => setVerif(null)}>Verifikasi Setoran</ModalHeader>
                <ModalBody>
                    {verif && (
                        <table className="table table-sm mb-0">
                            <tbody>
                                {[['Cabang', verif.cabang], ['Periode', periode(verif.periode)], ['Harus Setor', rupiah(verif.nominal_harus_setor)], ['Disetor', rupiah(verif.nominal_setor)], ['Rekening', verif.metode], ['Bukti', verif.bukti]].map(([k, v]) => <tr key={k}><th className="f-light">{k}</th><td>{v}</td></tr>)}
                            </tbody>
                        </table>
                    )}
                    <div className="file-drop mt-3 f-light">Preview bukti setor (placeholder)</div>
                </ModalBody>
                <ModalFooter>
                    <Button color="danger" outline onClick={() => { dummy('Penolakan setoran'); setVerif(null); }}>Tolak</Button>
                    <Button color="success" onClick={() => { dummy('Verifikasi setoran'); setVerif(null); }}>Terima</Button>
                </ModalFooter>
            </Modal>
            <Modal isOpen={!!kuitansi} toggle={() => setKuitansi(null)} centered>
                <ModalHeader toggle={() => setKuitansi(null)}>Kuitansi Setoran</ModalHeader>
                <ModalBody>
                    {kuitansi && (
                        <div className="p-3 border rounded">
                            <div className="d-flex justify-content-between mb-3"><h5>KUITANSI</h5><span>No. KWT-{String(kuitansi.id).padStart(5, '0')}</span></div>
                            <p className="mb-1">Telah terima dari: <strong>{kuitansi.cabang}</strong></p>
                            <p className="mb-1">Uang sejumlah: <strong>{rupiah(kuitansi.nominal_setor)}</strong></p>
                            <p className="mb-1">Untuk pembayaran: Setoran kewajiban periode {periode(kuitansi.periode)}</p>
                            <p className="mb-4">Via: {kuitansi.metode}</p>
                            <div className="text-end">Bandung, {tanggal(kuitansi.created_at)}<br /><br /><br />( Admin Pusat )</div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter><Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak</Button></ModalFooter>
            </Modal>
        </>
    );
}
