import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowUpCircle, CreditCard, Eye, EyeOff } from 'react-feather';
import { BULAN, rupiah } from '../lib/format';
import { useBisa } from '../lib/ui';

const STORAGE_KEY = 'saldo_hidden';
const persen = (v) => (v === null || v === undefined ? '' : ` ${String(v).replace('.', ',')}%`);

/** Status sembunyikan nominal saldo, diingat di browser ini; dipakai bersama SaldoCard & RincianSaldo. */
export function useSaldoHidden() {
    const [hidden, setHidden] = useState(() => { try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) { return false; } });
    const toggle = () => {
        setHidden(!hidden);
        try { localStorage.setItem(STORAGE_KEY, hidden ? '0' : '1'); } catch (e) { /* abaikan */ }
    };

    return [hidden, toggle];
}

/** Kartu saldo utama Mitra di Dashboard; tombol mata menyembunyikan semua nominal (termasuk RincianSaldo). */
export default function SaldoCard({ saldo: s, filters, hidden, onToggle }) {
    const bisa = useBisa();

    return (
        <div className="card saldo-card">
            <div className="card-body">
                <div className="d-flex justify-content-between align-items-start gap-3">
                    <div className="d-flex align-items-center gap-2">
                        <span className="saldo-icon"><CreditCard size={20} /></span>
                        <div>
                            <div className="saldo-label">Saldo / Balance</div>
                            <small>{s.nama_cabang}{s.nama_mitra ? ` · ${s.nama_mitra}` : ''} · {BULAN[filters.bulan - 1]} {filters.tahun}</small>
                        </div>
                    </div>
                    <button type="button" className="ringkasan-toggle" onClick={onToggle} title={hidden ? 'Tampilkan saldo' : 'Sembunyikan saldo'} aria-label={hidden ? 'Tampilkan saldo' : 'Sembunyikan saldo'}>
                        {hidden ? <Eye size={18} /> : <EyeOff size={18} />}
                    </button>
                </div>
                <div className="saldo-nominal">{hidden ? 'Rp ••••••••' : rupiah(s.saldo)}</div>
                {!hidden && s.saldo < 0 && <div className="saldo-hint">Saldo minus: masih ada {rupiah(-s.saldo)} yang harus disetor ke MSS.</div>}
                {bisa('transaksi.penarikan.view') && (
                    <Link href="/transaksi/penarikan-saldo" prefetch className="btn saldo-btn"><ArrowUpCircle size={16} className="me-1" />Tarik Saldo</Link>
                )}
            </div>
        </div>
    );
}

/** Rincian skema withdrawal (Omset − PPN − Admin Fee − Bagi Hasil − PPh) dan posisi setoran & saldo Mitra. */
export function RincianSaldo({ saldo: s, hidden }) {
    const nominal = (v) => (hidden ? 'Rp ••••••' : rupiah(v));
    const adminFee = typeof s.admin_fee_label === 'number' ? persen(s.admin_fee_label) : s.admin_fee_label ? ` ${s.admin_fee_label}` : '';
    const minus = s.saldo < 0;

    const pendapatan = [
        { label: 'Pemasukan Bulan Ini', note: 'Omset, sudah termasuk PPN', value: s.pemasukan, type: 'start' },
        { label: `PPN${persen(s.ppn_persen)}`, value: s.ppn, type: 'minus' },
        { label: 'DPP', note: 'Dasar Pengenaan Pajak', value: s.dpp, type: 'subtotal' },
        { label: `Admin Fee${adminFee}`, value: s.admin_fee, type: 'minus' },
        { label: 'Bagi Hasil (Biaya Bandwidth)', note: s.metode_bagi_hasil, value: s.nominal_bagi_hasil, type: 'minus' },
        { label: 'Omset Reseller', value: s.omset_reseller, type: 'subtotal' },
        { label: `PPh${persen(s.pph_persen)}`, value: s.pph, type: 'minus' },
        { label: 'Pendapatan Reseller', note: 'Hak Reseller ISP MSS', value: s.pendapatan_reseller, type: 'total' },
    ];
    const setoran = [
        { label: 'Total Setor ke MSS', note: 'PPN + Admin Fee + Bagi Hasil + PPh', value: s.harus_setor },
        { label: 'Sudah Setor ke MSS', value: s.sudah_setor },
        { label: 'Sisa Harus Setor', value: s.sisa_setor, accent: 'warning' },
        { label: 'Pemasukan Via Mitra', note: 'Cash & transfer ke rekening cabang', value: s.pemasukan_via_mitra },
        { label: 'Total Payment Gateway', note: 'Pembayaran online, diterima MSS', value: s.total_pg },
        { label: 'Sudah WD ke Reseller', value: s.total_wd },
        { label: 'Pengeluaran', value: s.pengeluaran },
    ];

    return (
        <div className="row">
            <div className="col-lg-7">
                <div className="card">
                    <div className="card-header pb-0">
                        <h5 className="mb-1">Rincian Pendapatan</h5>
                        <small className="f-light">Omset − PPN − Admin Fee − Bagi Hasil − PPh</small>
                    </div>
                    <div className="card-body">
                        {pendapatan.map((r) => (
                            <div key={r.label} className={`rincian-row is-${r.type}`}>
                                <div>
                                    <div className="rincian-label">{r.type === 'minus' && <span className="rincian-op">−</span>}{r.label}</div>
                                    {r.note && <small className="f-light">{r.note}</small>}
                                </div>
                                <strong>{r.type === 'minus' && !hidden ? `− ${rupiah(r.value)}` : nominal(r.value)}</strong>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <div className="col-lg-5">
                <div className="card">
                    <div className="card-header pb-0">
                        <h5 className="mb-1">Setoran & Saldo</h5>
                        <small className="f-light">Posisi uang antara Mitra dan MSS</small>
                    </div>
                    <div className="card-body">
                        {setoran.map((r) => (
                            <div key={r.label} className="rincian-row">
                                <div>
                                    <div className="rincian-label">{r.label}</div>
                                    {r.note && <small className="f-light">{r.note}</small>}
                                </div>
                                <strong className={r.accent ? `font-${r.accent}` : ''}>{nominal(r.value)}</strong>
                            </div>
                        ))}
                        <div className={`rincian-saldo ${minus ? 'is-minus' : ''}`}>
                            <div>
                                <div className="rincian-label">Saldo / Balance</div>
                                <small>Pendapatan Reseller − Pemasukan Via Mitra + Sudah Setor − Sudah WD</small>
                            </div>
                            <strong>{nominal(s.saldo)}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
