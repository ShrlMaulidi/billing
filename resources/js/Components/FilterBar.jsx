import { usePage } from '@inertiajs/react';
import { Input } from 'reactstrap';
import { BULAN, label } from '../lib/format';
import { applyFilters } from '../lib/ui';

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
                    <Input key={f} type="select" style={{ width: 140 }} value={filters.bulan ?? ''} onChange={(e) => set('bulan', e.target.value)}>
                        {BULAN.map((b, i) => <option key={b} value={i + 1}>{b}</option>)}
                    </Input>
                );
                if (f === 'tahun') return (
                    <Input key={f} type="select" style={{ width: 100 }} value={filters.tahun ?? ''} onChange={(e) => set('tahun', e.target.value)}>
                        {[thisYear - 2, thisYear - 1, thisYear].map((y) => <option key={y}>{y}</option>)}
                    </Input>
                );
                if (f === 'cabang') return (
                    <Input key={f} type="select" style={{ width: 200 }} value={filters.cabang ?? ''} onChange={(e) => set('cabang', e.target.value)}>
                        <option value="">Semua Cabang</option>
                        {cabangOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Input>
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
                    <Input key={f.name} type="select" style={{ width: f.width ?? 170 }} value={filters[f.name] ?? ''} onChange={(e) => set(f.name, e.target.value)}>
                        <option value="">{f.label}: Semua</option>
                        {f.options.map((o) => (typeof o === 'object' ? <option key={o.value} value={o.value}>{o.label}</option> : <option key={o} value={o}>{label(o)}</option>))}
                    </Input>
                );
            })}
        </div>
    );
}
