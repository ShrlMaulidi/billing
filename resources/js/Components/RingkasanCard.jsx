import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRightCircle, Eye, EyeOff } from 'react-feather';
import { rupiah } from '../lib/format';

const STORAGE_KEY = 'ringkasan_hidden';

/** Kartu "Ringkasan Bulan Ini" (mengikuti aplikasi lama). Nominal bisa disembunyikan. */
export default function RingkasanCard({ row, title = 'Ringkasan Bulan Ini', subtitle, moreHref }) {
    const [hidden, setHidden] = useState(() => { try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) { return false; } });
    const toggle = () => {
        setHidden(!hidden);
        try { localStorage.setItem(STORAGE_KEY, hidden ? '0' : '1'); } catch (e) { /* abaikan */ }
    };
    const pct = (v) => (v === null || v === undefined ? '' : ` ${String(v).replace('.', ',')}${String(v).match(/%|trx/) ? '' : '%'}`);
    const items = [
        ['Pemasukan Bulan Ini', row.pemasukan],
        ['DPP', row.dpp],
        [`PPN${pct(row.ppn_persen)}`, row.ppn],
        [`Admin Fee${pct(row.admin_fee_label)}`, row.admin_fee],
        [`Bagi Hasil${row.metode_bagi_hasil ? ` (${row.metode_bagi_hasil})` : ''}`, row.nominal_bagi_hasil],
        ['Omset Reseller', row.omset_reseller],
        [`PPh${pct(row.pph_persen)}`, row.pph],
        ['Total Setor ke MSS', row.harus_setor],
        ['Pendapatan Reseller', row.pendapatan_reseller],
        ['Pemasukan Via Mitra', row.pemasukan_via_mitra],
        ['Sudah Setor ke MSS', row.sudah_setor],
        ['Sudah WD ke Reseller', row.total_wd],
        ['Pengeluaran', row.pengeluaran],
    ];

    return (
        <div className="card ringkasan-card">
            <div className="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h5 className="mb-0">{title}</h5>
                    {subtitle && <small>{subtitle}</small>}
                </div>
                <button type="button" className="ringkasan-toggle" onClick={toggle} title={hidden ? 'Tampilkan nominal' : 'Sembunyikan nominal'}>
                    {hidden ? <Eye size={18} /> : <EyeOff size={18} />}
                </button>
            </div>
            <div className="card-body">
                {items.map(([label, value]) => (
                    <div className="ringkasan-row" key={label}><span>{label}</span><strong>{hidden ? 'Rp ••••••' : rupiah(value)}</strong></div>
                ))}
                <div className="ringkasan-row total"><span>Saldo / Balance</span><strong>{hidden ? 'Rp ••••••' : rupiah(row.saldo)}</strong></div>
            </div>
            {moreHref && <Link href={moreHref} className="ringkasan-more">Selengkapnya <ArrowRightCircle size={16} /></Link>}
        </div>
    );
}
