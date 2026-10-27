import { useState } from 'react';
import { FileText, Lock } from 'react-feather';
import { Button, Card, CardBody } from 'reactstrap';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import RekapCabangTable from '../../Components/RekapCabangTable';
import Select from '../../Components/Select';
import { ConfirmDialog } from '../../Components/ui';
import { BULAN } from '../../lib/format';
import { applyFilters, dummy } from '../../lib/ui';

export default function Laporan({ filters, rows, tahunList }) {
    const [confirm, setConfirm] = useState(false);
    const tahunan = filters.mode === 'tahunan';
    const judul = tahunan ? `Tahun ${filters.tahun}` : `${BULAN[filters.bulan - 1]} ${filters.tahun}`;

    return (
        <>
            <PageHeader title="Laporan" actions={
                <>
                    <Button color="danger" outline onClick={() => dummy('Export PDF')}><FileText size={14} className="me-1" />Export PDF</Button>
                    {!tahunan && <Button color="primary" onClick={() => setConfirm(true)}><Lock size={14} className="me-1" />Simpan Rekap</Button>}
                </>
            } />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <h5 className="mb-3">Rekap Cabang — {judul}</h5>
                        <RekapCabangTable rows={rows} withDetail={!tahunan} detailQuery={`?bulan=${filters.bulan}&tahun=${filters.tahun}`} toolbar={
                            <>
                                <div className="btn-group">
                                    {['bulanan', 'tahunan'].map((m) => (
                                        <Button key={m} color="primary" outline={filters.mode !== m} onClick={() => applyFilters({ ...filters, mode: m })}>{m === 'bulanan' ? 'Bulanan' : 'Tahunan'}</Button>
                                    ))}
                                </div>
                                {tahunan ? (
                                    <Select ariaLabel="Tahun" style={{ width: 100 }} value={filters.tahun} onChange={(v) => applyFilters({ ...filters, tahun: v })}
                                        options={tahunList.map((y) => ({ value: y, label: String(y) }))} />
                                ) : <FilterBar filters={filters} fields={['bulan', 'tahun']} />}
                            </>
                        } />
                        {tahunan && <small className="f-light">Mode tahunan: nilai diakumulasi dari periode yang tersedia di tahun {filters.tahun}; jumlah pelanggan memakai posisi bulan terakhir.</small>}
                    </CardBody>
                </Card>
            </div>
            <ConfirmDialog isOpen={confirm} toggle={() => setConfirm(false)} title="Simpan Rekap (Tutup Buku)" color="primary" confirmLabel="Simpan Snapshot"
                message={<>Simpan snapshot rekap <strong>{judul}</strong> untuk {rows.length} cabang? Data periode ini akan dikunci sebagai rekap.</>}
                onConfirm={() => dummy('Simpan rekap')} />
        </>
    );
}
