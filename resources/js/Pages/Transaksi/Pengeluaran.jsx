import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Edit2, Plus } from 'react-feather';
import { Button, Card, CardBody } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import DataTable, { sumFooter } from '../../Components/DataTable';
import { ActionButton, ActionGroup, FormModal, StatusBadge } from '../../Components/ui';
import { rupiah, waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

function MasterWajib({ rows, cabangOptions }) {
    const [form, setForm] = useState(null);
    return (
        <Card>
            <CardBody>
                <DataTable
                    title="Master Pengeluaran Wajib Bulanan"
                    toolbar={<Button color="primary" onClick={() => setForm({})}><Plus size={14} className="me-1" />Tambah Template</Button>}
                    data={rows} exportable={false} pageSize={10}
                    actions={(r) => <ActionGroup><ActionButton icon={Edit2} label="Edit" color="warning" onClick={() => setForm(r)} /></ActionGroup>}
                    columns={[
                        { key: 'cabang', label: 'Cabang' }, { key: 'jenis_pengeluaran', label: 'Jenis Pengeluaran' },
                        { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                        { key: 'tgl_generate', label: 'Generate Otomatis', render: (r) => `Setiap tgl ${r.tgl_generate}` },
                        { key: 'aktif', label: 'Status', render: (r) => <StatusBadge value={r.aktif ? 'aktif' : 'tidak_aktif'} /> },
                    ]}
                />
            </CardBody>
            <FormModal isOpen={!!form} toggle={() => setForm(null)} title={`${form?.id ? 'Edit' : 'Tambah'} Template Wajib Bulanan`} initial={form ?? {}} size="md"
                onSubmit={() => dummy('Simpan template')}
                fields={[
                    { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                    { name: 'jenis_pengeluaran', label: 'Jenis Pengeluaran', required: true },
                    { name: 'nominal', label: 'Nominal', type: 'currency', required: true },
                    { name: 'tgl_generate', label: 'Tanggal Generate (1–28)', type: 'number', required: true },
                    { name: 'aktif', label: 'Aktif', type: 'switch' },
                ]} />
        </Card>
    );
}

export default function Pengeluaran({ filters, rows, ringkasan, master, rekeningOptions }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Pengeluaran" crumbs={['Transaksi']} rows={rows} filters={filters} filterFields={['bulan', 'tahun', 'cabang']}
            deleteLabel={(r) => `${r.jenis_pengeluaran} (${rupiah(r.nominal)})`} defaults={{ tipe: 'tidak_wajib' }}
            tableProps={{ footer: sumFooter(['nominal'], rupiah) }}
            columns={[
                { key: 'cabang', label: 'Cabang' }, { key: 'jenis_pengeluaran', label: 'Jenis Pengeluaran' },
                { key: 'tipe', label: 'Tipe', render: (r) => <StatusBadge value={r.tipe === 'wajib_bulanan' ? 'pending' : 'info'} text={r.tipe === 'wajib_bulanan' ? 'Wajib Bulanan' : 'Tidak Wajib'} /> },
                { key: 'waktu', label: 'Waktu', render: (r) => waktu(r.waktu) },
                { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                { key: 'metode', label: 'Metode' },
                { key: 'bukti', label: 'Bukti', render: (r) => (r.bukti ? <a href="#" onClick={(e) => { e.preventDefault(); dummy('Lihat bukti'); }}>Lihat</a> : '-') },
                { key: 'keterangan', label: 'Keterangan', hidden: true }, { key: 'created_by', label: 'Dicatat Oleh' },
            ]}
            fields={[
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                { name: 'tipe', label: 'Tipe', type: 'radio', required: true, options: [{ value: 'wajib_bulanan', label: 'Wajib Bulanan' }, { value: 'tidak_wajib', label: 'Tidak Wajib' }] },
                { name: 'jenis_pengeluaran', label: 'Jenis Pengeluaran', required: true },
                { name: 'waktu', label: 'Waktu', type: 'datetime-local', required: true },
                { name: 'nominal', label: 'Nominal', type: 'currency', required: true },
                { name: 'id_rekening', label: 'Metode (Cash / Rekening)', type: 'select', options: rekeningOptions },
                { name: 'bukti', label: 'Bukti', type: 'file' },
                { name: 'keterangan', label: 'Keterangan', type: 'textarea' },
            ]}
            after={
                <>
                    <div className="row">
                        {[['Pemasukan', ringkasan.pemasukan, 'success'], ['Pengeluaran', ringkasan.pengeluaran, 'danger'], ['Balance', ringkasan.balance, ringkasan.balance >= 0 ? 'primary' : 'danger']].map(([l, v, c]) => (
                            <div className="col-md-4" key={l}><Card><CardBody className="text-center"><span className="f-light">{l}</span><h4 className={`font-${c} mb-0`}>{rupiah(v)}</h4></CardBody></Card></div>
                        ))}
                    </div>
                    <MasterWajib rows={master} cabangOptions={cabangOptions} />
                </>
            }
        />
    );
}
