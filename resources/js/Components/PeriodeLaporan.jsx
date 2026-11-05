import { Button } from 'reactstrap';
import { BULAN } from '../lib/format';
import { applyFilters } from '../lib/ui';
import FilterBar from './FilterBar';
import Select from './Select';

export const judulPeriode = (filters) => (filters.mode === 'tahunan' ? `Tahun ${filters.tahun}` : `${BULAN[filters.bulan - 1]} ${filters.tahun}`);

/** Pilihan mode Bulanan / Tahunan + periode, dipakai semua halaman Laporan. */
export default function PeriodeLaporan({ filters, tahunList }) {
    const tahunan = filters.mode === 'tahunan';
    return (
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
    );
}
