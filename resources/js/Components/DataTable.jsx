import { useMemo, useState } from 'react';
import { ChevronDown, ChevronUp, Columns, Download, Search } from 'react-feather';
import { Button, DropdownItem, DropdownMenu, DropdownToggle, Input, UncontrolledDropdown } from 'reactstrap';
import { dummy, useNavLoading } from '../lib/ui';
import { EmptyState, ErrorState, SkeletonRows } from './ui';

const cellValue = (row, col) => (col.sortValue ? col.sortValue(row) : row[col.key]);

/**
 * Tabel reusable: search, sort, paginasi, export, column toggle, sticky kolom pertama,
 * baris total, seleksi, serta empty / loading / error state.
 */
export default function DataTable({
    columns, data = [], rowKey = 'id', toolbar, actions, exportable = true, stickyFirst = false, footer,
    pageSize = 10, selectable = false, selected, onSelect, onRowClick, error, onRetry, emptyText, searchable = true, title,
}) {
    const loading = useNavLoading();
    const [q, setQ] = useState('');
    const [sort, setSort] = useState({ key: null, dir: 'asc' });
    const [page, setPage] = useState(1);
    const [per, setPer] = useState(pageSize);
    const [hidden, setHidden] = useState(() => new Set(columns.filter((c) => c.hidden).map((c) => c.key)));

    const cols = columns.filter((c) => !hidden.has(c.key));
    const filtered = useMemo(() => {
        const term = q.trim().toLowerCase();
        let rows = term ? data.filter((r) => columns.some((c) => String(cellValue(r, c) ?? '').toLowerCase().includes(term))) : data;
        if (sort.key) {
            const col = columns.find((c) => c.key === sort.key);
            rows = [...rows].sort((a, b) => {
                const x = cellValue(a, col); const y = cellValue(b, col);
                const r = typeof x === 'number' && typeof y === 'number' ? x - y : String(x ?? '').localeCompare(String(y ?? ''), 'id');
                return sort.dir === 'asc' ? r : -r;
            });
        }
        return rows;
    }, [data, q, sort, columns]);

    const pages = Math.max(1, Math.ceil(filtered.length / per));
    const current = Math.min(page, pages);
    const rows = filtered.slice((current - 1) * per, current * per);
    const totalCols = cols.length + (actions ? 1 : 0) + (selectable ? 1 : 0);
    const allChecked = selectable && rows.length > 0 && rows.every((r) => selected?.has(r[rowKey]));

    const toggleSort = (key) => setSort((s) => ({ key, dir: s.key === key && s.dir === 'asc' ? 'desc' : 'asc' }));
    const toggleRow = (id) => { const next = new Set(selected); next.has(id) ? next.delete(id) : next.add(id); onSelect(next); };
    const toggleAll = () => { const next = new Set(selected); rows.forEach((r) => (allChecked ? next.delete(r[rowKey]) : next.add(r[rowKey]))); onSelect(next); };

    return (
        <div>
            <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                {title && <h5 className="mb-0 me-2">{title}</h5>}
                {toolbar}
                <div className="ms-auto d-flex flex-wrap gap-2">
                    {searchable && (
                        <div className="input-group" style={{ width: 220 }}>
                            <span className="input-group-text"><Search size={14} /></span>
                            <Input placeholder="Cari..." value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                        </div>
                    )}
                    <UncontrolledDropdown>
                        <DropdownToggle color="light" title="Tampilkan kolom"><Columns size={16} /></DropdownToggle>
                        <DropdownMenu end style={{ maxHeight: 320, overflowY: 'auto' }}>
                            {columns.map((c) => (
                                <DropdownItem key={c.key} toggle={false} onClick={() => setHidden((h) => { const n = new Set(h); n.has(c.key) ? n.delete(c.key) : n.add(c.key); return n; })}>
                                    <Input type="checkbox" readOnly checked={!hidden.has(c.key)} className="me-2" />{c.label}
                                </DropdownItem>
                            ))}
                        </DropdownMenu>
                    </UncontrolledDropdown>
                    {exportable && (
                        <UncontrolledDropdown>
                            <DropdownToggle color="success" caret><Download size={14} className="me-1" />Export</DropdownToggle>
                            <DropdownMenu end>
                                <DropdownItem onClick={() => dummy('Export Excel')}>Excel (.xlsx)</DropdownItem>
                                <DropdownItem onClick={() => dummy('Export PDF')}>PDF</DropdownItem>
                            </DropdownMenu>
                        </UncontrolledDropdown>
                    )}
                </div>
            </div>

            <div className="table-responsive">
                <table className={`table table-hover table-bordernone align-middle ${stickyFirst ? 'table-sticky' : ''}`}>
                    <thead>
                        <tr>
                            {selectable && <th style={{ width: 36 }}><Input type="checkbox" checked={allChecked} onChange={toggleAll} /></th>}
                            {cols.map((c) => (
                                <th key={c.key} className={`${c.sortable === false ? '' : 'th-sort'} ${c.align === 'end' ? 'text-end' : ''}`} onClick={() => c.sortable !== false && toggleSort(c.key)}>
                                    {c.label}
                                    {sort.key === c.key && (sort.dir === 'asc' ? <ChevronUp size={12} /> : <ChevronDown size={12} />)}
                                </th>
                            ))}
                            {actions && <th className="text-end">Aksi</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? <SkeletonRows cols={totalCols} /> : error ? (
                            <tr><td colSpan={totalCols}><ErrorState text={error} onRetry={onRetry} /></td></tr>
                        ) : rows.length === 0 ? (
                            <tr><td colSpan={totalCols}><EmptyState text={q ? `Tidak ada hasil untuk "${q}"` : emptyText} /></td></tr>
                        ) : rows.map((r, i) => (
                            <tr key={r[rowKey] ?? i} onClick={onRowClick ? () => onRowClick(r) : undefined} style={onRowClick ? { cursor: 'pointer' } : undefined}>
                                {selectable && <td onClick={(e) => e.stopPropagation()}><Input type="checkbox" checked={selected?.has(r[rowKey]) ?? false} onChange={() => toggleRow(r[rowKey])} /></td>}
                                {cols.map((c) => (
                                    <td key={c.key} className={`${c.align === 'end' ? 'text-end' : ''} ${c.nowrap !== false ? 'text-nowrap' : ''}`}>
                                        {c.render ? c.render(r, (current - 1) * per + i) : r[c.key] ?? '-'}
                                    </td>
                                ))}
                                {actions && <td className="text-end text-nowrap" onClick={(e) => e.stopPropagation()}>{actions(r)}</td>}
                            </tr>
                        ))}
                    </tbody>
                    {footer && !loading && filtered.length > 0 && (
                        <tfoot>
                            <tr className="table-light">
                                {selectable && <td />}
                                {cols.map((c, i) => <td key={c.key} className={c.align === 'end' ? 'text-end text-nowrap' : ''}>{i === 0 ? 'Total' : footer(c, filtered) ?? ''}</td>)}
                                {actions && <td />}
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            <div className="d-flex flex-wrap align-items-center gap-2 mt-3">
                <small className="f-light">
                    Menampilkan {filtered.length ? (current - 1) * per + 1 : 0}–{Math.min(current * per, filtered.length)} dari {filtered.length} data
                </small>
                <Input type="select" bsSize="sm" style={{ width: 80 }} value={per} onChange={(e) => { setPer(Number(e.target.value)); setPage(1); }}>
                    {[10, 25, 50, 100].map((n) => <option key={n}>{n}</option>)}
                </Input>
                <ul className="pagination pagination-primary pagination-sm mb-0 ms-auto">
                    <li className={`page-item ${current === 1 ? 'disabled' : ''}`}><Button color="link" className="page-link" onClick={() => setPage(current - 1)}>‹</Button></li>
                    {Array.from({ length: pages }, (_, i) => i + 1)
                        .filter((p) => p === 1 || p === pages || Math.abs(p - current) <= 1)
                        .map((p, i, arr) => (
                            <li key={p} className={`page-item ${p === current ? 'active' : ''}`}>
                                <Button color="link" className="page-link" onClick={() => setPage(p)}>{arr[i - 1] && p - arr[i - 1] > 1 ? `… ${p}` : p}</Button>
                            </li>
                        ))}
                    <li className={`page-item ${current === pages ? 'disabled' : ''}`}><Button color="link" className="page-link" onClick={() => setPage(current + 1)}>›</Button></li>
                </ul>
            </div>
        </div>
    );
}

// Helper footer: jumlahkan kolom numerik tertentu
export const sumFooter = (keys, format) => (col, rows) => (keys.includes(col.key) ? format(rows.reduce((s, r) => s + (Number(r[col.key]) || 0), 0)) : null);
