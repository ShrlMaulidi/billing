import { Eye } from 'react-feather';
import { angka, rupiah } from '../lib/format';
import DataTable, { sumFooter } from './DataTable';
import { ActionButton } from './ui';

const r = (key, label) => ({ key, label, align: 'end', render: (row) => rupiah(row[key]) });
const n = (key, label) => ({ key, label, align: 'end', render: (row) => angka(row[key]) });

// 23 kolom Dashboard Cabang (§3.2) — dipakai juga oleh Laporan
export const REKAP_COLUMNS = [
    { key: 'nama_cabang', label: 'Nama Cabang' },
    n('jumlah_pelanggan', 'Jumlah Pelanggan'), n('pelanggan_baru', 'Pelanggan Baru'), n('pelanggan_free', 'Pelanggan Free'),
    n('lunas', 'Pelanggan Lunas'), n('belum_lunas', 'Pelanggan Belum Lunas'),
    r('pemasukan', 'Total Pemasukan'), r('pemasukan_via_mitra', 'Pemasukan Via Mitra'), r('total_cash', 'Total Cash'), r('total_pg', 'Total Payment Gateway'),
    r('dpp', 'DPP'), r('ppn', 'PPN'), r('admin_fee', 'Admin Fee'),
    { key: 'metode_bagi_hasil', label: 'Metode Bagi Hasil' },
    r('nominal_bagi_hasil', 'Nominal Bagi Hasil'), r('omset_reseller', 'Omset Reseller'), r('pph', 'PPh'),
    r('harus_setor', 'Total Setor ke MSS'), r('sudah_setor', 'Sudah Setor ke MSS'), r('sisa_setor', 'Sisa Harus Setor'),
    r('pendapatan_reseller', 'Pendapatan Reseller'), r('total_wd', 'Sudah WD ke Reseller'), r('sisa_wd', 'Sisa WD'),
    r('pengeluaran', 'Pengeluaran'),
    { key: 'saldo', label: 'Saldo / Balance', align: 'end', render: (row) => <span className={row.saldo < 0 ? 'font-danger' : 'font-success'}>{rupiah(row.saldo)}</span> },
];

const NUMERIC = REKAP_COLUMNS.filter((c) => c.align === 'end');

// Jumlahkan semua baris rekap (untuk ringkasan "Semua Cabang")
export const sumRekap = (rows) => {
    const out = { nama_cabang: 'Semua Cabang' };
    NUMERIC.forEach((c) => { out[c.key] = rows.reduce((s, x) => s + (Number(x[c.key]) || 0), 0); });
    ['ppn_persen', 'pph_persen', 'admin_fee_label', 'metode_bagi_hasil'].forEach((k) => { out[k] = rows.every((x) => x[k] === rows[0]?.[k]) ? rows[0]?.[k] : null; });
    return out;
};
const footer = (col, rows) => {
    if (!NUMERIC.includes(col)) return null;
    const total = rows.reduce((s, x) => s + (Number(x[col.key]) || 0), 0);
    return col.render({ [col.key]: total });
};

export default function RekapCabangTable({ rows, toolbar, detailQuery = '', withDetail = true }) {
    return (
        <DataTable
            columns={REKAP_COLUMNS}
            data={rows}
            rowKey="id_lokasi"
            stickyFirst
            footer={footer}
            toolbar={toolbar}
            pageSize={25}
            actions={withDetail ? (row) => (
                <ActionButton icon={Eye} label="Detail Cabang" href={`/dashboard-cabang/${row.id_lokasi}${detailQuery}`} />
            ) : undefined}
        />
    );
}

export { sumFooter };
