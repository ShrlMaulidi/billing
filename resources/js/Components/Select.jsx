import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search } from 'react-feather';

/**
 * Dropdown dengan kotak pencarian, pengganti <select>.
 * options: [{ value, label }] · emptyLabel: opsi kosong di paling atas (mis. "Semua Cabang")
 * onChange menerima value berupa string, sama seperti <select> biasa.
 * Daftar dirender ke <body> agar tidak terpotong di dalam modal / tabel.
 */
export default function Select({ value, onChange, options = [], emptyLabel, placeholder = '— Pilih —', id, invalid, disabled, style, className = '', ariaLabel }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const [pos, setPos] = useState(null);
    const btnRef = useRef(null);
    const popRef = useRef(null);
    const listRef = useRef(null);
    const escClosed = useRef(false);

    const all = useMemo(() => (emptyLabel !== undefined ? [{ value: '', label: emptyLabel }, ...options] : options), [options, emptyLabel]);
    const items = useMemo(() => {
        const term = q.trim().toLowerCase();
        return term ? all.filter((o) => String(o.label).toLowerCase().includes(term)) : all;
    }, [all, q]);
    const selected = all.find((o) => String(o.value) === String(value ?? ''));

    const place = () => {
        const r = btnRef.current.getBoundingClientRect();
        const below = window.innerHeight - r.bottom;
        const up = below < 300 && r.top > below;
        setPos({ left: r.left, width: Math.max(r.width, 220), ...(up ? { bottom: window.innerHeight - r.top + 4 } : { top: r.bottom + 4 }) });
    };

    const show = () => {
        if (disabled) return;
        place();
        setQ('');
        setActive(Math.max(0, all.findIndex((o) => o === selected)));
        setOpen(true);
    };
    const close = (refocus = true) => { setOpen(false); if (refocus) btnRef.current?.focus(); };
    const pick = (o) => { onChange?.(String(o.value)); close(); };

    useEffect(() => {
        if (!open) return undefined;
        const outside = (e) => !btnRef.current?.contains(e.target) && !popRef.current?.contains(e.target) && close(false);
        // Ikuti posisi tombol saat halaman / modal di-scroll atau ukuran layar berubah
        const reposition = (e) => !popRef.current?.contains(e.target) && place();
        document.addEventListener('mousedown', outside);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);
        return () => {
            document.removeEventListener('mousedown', outside);
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useLayoutEffect(() => {
        if (open) listRef.current?.children[active]?.scrollIntoView({ block: 'nearest' });
    }, [active, open]);

    const onKeyDown = (e) => {
        // Esc hanya menutup dropdown, bukan modal di belakangnya
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); escClosed.current = true; close(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(i + 1, items.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) pick(items[active]); }
        else if (e.key === 'Tab') close(false);
    };

    return (
        <>
            <button
                ref={btnRef} id={id} type="button" disabled={disabled} aria-label={ariaLabel} aria-haspopup="listbox" aria-expanded={open}
                className={`form-select search-select-toggle text-start ${invalid ? 'is-invalid' : ''} ${className}`} style={style}
                onClick={() => (open ? close() : show())}
                onKeyDown={(e) => { if (!open && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(); } }}
                // keyup Esc yang menutup dropdown jangan sampai ikut menutup modal
                onKeyUp={(e) => { if (e.key === 'Escape' && escClosed.current) { e.stopPropagation(); escClosed.current = false; } }}
            >
                <span className={selected ? '' : 'text-muted'}>{selected ? selected.label : placeholder}</span>
            </button>
            {open && pos && createPortal(
                <div ref={popRef} className="search-select-pop" style={pos} onKeyDown={onKeyDown}>
                    <div className="search-select-search">
                        <Search size={14} />
                        <input autoFocus value={q} placeholder="Cari..." aria-label="Cari pilihan" onChange={(e) => { setQ(e.target.value); setActive(0); }} />
                    </div>
                    <ul ref={listRef} role="listbox" className="search-select-list">
                        {items.map((o, i) => {
                            const isSel = o === selected;
                            return (
                                <li key={`${o.value}`} role="option" aria-selected={isSel} className={`${i === active ? 'active' : ''} ${isSel ? 'selected' : ''}`}
                                    onMouseEnter={() => setActive(i)} onMouseDown={(e) => e.preventDefault()} onClick={() => pick(o)}>
                                    <span>{o.label}</span>
                                    {isSel && <Check size={14} />}
                                </li>
                            );
                        })}
                        {!items.length && <li className="empty">Tidak ditemukan</li>}
                    </ul>
                </div>,
                document.body,
            )}
        </>
    );
}
