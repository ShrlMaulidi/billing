import { Link, usePage } from '@inertiajs/react';
import { Info } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { CabangFormInfo, cabangFields } from '../../lib/cabang';
import { angka, periode, persen, rupiah } from '../../lib/format';
import { deleteFrom, saveTo, useBisa } from '../../lib/ui';

// Cabang lama yang belum punya tarif di Pengaturan Pajak tampil '-'
const kosong = (v) => v === null || v === undefined;
const TIPE = { reseller: ['Mitra Reseller', 'primary'], retail: ['Retail / Shafa.net', 'warning'] };

/** Cabang baru ditambahkan lewat menu Data Klien (pilih tipe Mitra Reseller / Retail); di sini hanya edit & hapus. */
export default function Cabang({ filters, rows, periodeSekarang }) {
    const { auth } = usePage().props;
    const bisa = useBisa();
    const pct = (key, label) => ({ key, label, align: 'end', render: (r) => (kosong(r[key]) ? '-' : persen(r[key])) });
    return (
        <CrudPage
            title="Cabang / Lokasi" entity="Cabang" rows={rows} filters={filters} filterFields={[]}
            deleteLabel={(r) => r.nama_lokasi}
            onSave={saveTo('/cabang')} onDelete={deleteFrom('/cabang')}
            canAdd={false} canEdit={bisa('kelola_data.cabang.update')} canDelete={bisa('kelola_data.cabang.delete')}
            before={
                <div className="alert alert-light-primary d-flex align-items-center gap-2">
                    <Info size={16} className="flex-shrink-0" />
                    <span>
                        Pajak dan bagi hasil yang ditampilkan adalah tarif bulan berjalan (<strong>{periode(periodeSekarang)}</strong>). Untuk mengubahnya per bulan, buka <Link href="/kelola-data/pajak" prefetch className="fw-semibold">Pengaturan Pajak</Link>.
                        {!auth.user?.id_lokasi && <> Cabang baru ditambahkan lewat menu <Link href="/data-pelanggan" prefetch className="fw-semibold">Data Klien</Link>.</>}
                    </span>
                </div>
            }
            columns={[
                { key: 'kode_lokasi', label: 'Kode' }, { key: 'nama_lokasi', label: 'Nama' },
                { key: 'tipe', label: 'Tipe', render: (r) => <span className={`badge badge-light-${TIPE[r.tipe]?.[1] ?? 'secondary'}`}>{TIPE[r.tipe]?.[0] ?? '-'}</span> },
                { key: 'nama_mitra', label: 'Mitra', render: (r) => r.nama_mitra || '-' }, { key: 'alamat_lokasi', label: 'Alamat' },
                { key: 'tlp_lokasi', label: 'Tlp' }, { key: 'tlp_owner', label: 'Tlp Owner' },
                pct('ppn_persen', 'PPN'), pct('pph_persen', 'PPh'), pct('bhp_persen', 'BHP'), pct('uso_persen', 'USO'),
                { key: 'admin_fee', label: 'Admin Fee', align: 'end', render: (r) => (kosong(r.admin_fee) ? '-' : r.admin_fee_tipe === 'persentase' ? persen(r.admin_fee) : rupiah(r.admin_fee)) },
                { key: 'nilai_bagi_hasil', label: 'Bagi Hasil', align: 'end', render: (r) => (kosong(r.nilai_bagi_hasil) ? '-' : r.metode_bagi_hasil === 'persentase' ? persen(r.nilai_bagi_hasil) : rupiah(r.nilai_bagi_hasil)) },
                { key: 'jumlah_area', label: 'Jml Area', align: 'end', render: (r) => <Link href={`/kelola-data/area?cabang=${r.id}`} prefetch title={`Lihat area ${r.nama_lokasi}`}>{angka(r.jumlah_area)}</Link> },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
                { key: 'terima_cash', label: 'Terima Cash', render: (r) => <StatusBadge value={r.terima_cash} /> },
                { key: 'admin_full', label: 'Admin Full', render: (r) => <span className={`badge ${r.admin_full ? 'badge-light-warning' : 'badge-light-secondary'}`}>{r.admin_full ? 'Ya' : 'Tidak'}</span> },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
            ]}
            fields={cabangFields({ adminFullTerkunci: !!auth.user?.id_lokasi })}
            formExtra={(v) => <CabangFormInfo values={v} periodeSekarang={periodeSekarang} />}
        />
    );
}
