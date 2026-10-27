import { usePage } from '@inertiajs/react';
import { Input } from 'reactstrap';
import { BULAN, label } from '../lib/format';
import { applyFilters } from '../lib/ui';
import Select from './Select';

/**
 * Filter server-side (query string). fields: 'bulan' | 'tahun' | 'cabang' |
 * { name, label, type: 'select' | 'date' | 'switch', options }
 */
export default function FilterBar({ filters = {}, fields = ['bulan', 'tahun', 'cabang'] }) {
    const { cabangOptions } = usePage().props;
    // Basis tahun dari server (bukan jam browser)
    const thisYear = Math.max(Number(filters.tahun) || 0, new Date().getFullYear());
    const set = (name, value) => applyFilters({ ...filters, [name]: value });

    return (
        <div className="d-flex flex-wrap gap-2">
            {fields.map((f) => {
                if (f === 'bulan') return (
                    <Select key={f} ariaLabel="Bulan" style={{ width: 140 }} value={filters.bulan} onChange={(v) => set('bulan', v)}
                        options={BULAN.map((b, i) => ({ value: i + 1, label: b }))} />
                );
                if (f === 'tahun') return (
                    <Select key={f} ariaLabel="Tahun" style={{ width: 100 }} value={filters.tahun} onChange={(v) => set('tahun', v)}
                        options={[thisYear - 2, thisYear - 1, thisYear].map((y) => ({ value: y, label: String(y) }))} />
                );
                if (f === 'cabang') return (
                    <Select key={f} ariaLabel="Cabang" style={{ width: 200 }} value={filters.cabang} onChange={(v) => set('cabang', v)}
                        options={cabangOptions} emptyLabel="Semua Cabang" />
                );
                if (f.type === 'date') return (
                    <Input key={f.name} type="date" style={{ width: 160 }} title={f.label} value={filters[f.name] ?? ''} onChange={(e) => set(f.name, e.target.value)} />
                );
                if (f.type === 'switch') return (
                    <div key={f.name} className="form-check form-switch d-flex align-items-center gap-2 mb-0">
                        <Input type="switch" id={`flt-${f.name}`} checked={!!filters[f.name]} onChange={(e) => set(f.name, e.target.checked ? 1 : '')} />
                        <label htmlFor={`flt-${f.name}`} className="mb-0">{f.label}</label>
                    </div>
                );
                return (
                    <Select key={f.name} ariaLabel={f.label} style={{ width: f.width ?? 170 }} value={filters[f.name]} onChange={(v) => set(f.name, v)}
                        options={f.options.map((o) => (typeof o === 'object' ? o : { value: o, label: label(o) }))} emptyLabel={`${f.label}: Semua`} />
                );
            })}
        </div>
    );
}
