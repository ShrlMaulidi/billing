import { Link } from '@inertiajs/react';
import { Info } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { angka, periode, persen, rupiah } from '../../lib/format';
import { deleteFrom, saveTo } from '../../lib/ui';

// Tarif bulan berjalan belum ada sampai Pengaturan Pajak terhubung ke database
const kosong = (v) => v === null || v === undefined;

export default function Cabang({ filters, rows, mitraOptions, periodeSekarang }) {
    const pct = (key, label) => ({ key, label, align: 'end', render: (r) => (kosong(r[key]) ? '-' : persen(r[key])) });
    return (
        <CrudPage
            title="Cabang / Lokasi" entity="Cabang" rows={rows} filters={filters} filterFields={[]}
            defaults={{ status: 'aktif', terima_cash: true }}
            deleteLabel={(r) => r.nama_lokasi}
            onSave={saveTo('/cabang')} onDelete={deleteFrom('/cabang')}
            before={
                <div className="alert alert-light-primary d-flex align-items-center gap-2">
                    <Info size={16} className="flex-shrink-0" />
                    <span>Pajak dan bagi hasil yang ditampilkan adalah tarif bulan berjalan (<strong>{periode(periodeSekarang)}</strong>). Untuk mengubahnya per bulan, buka <Link href="/kelola-data/pajak" prefetch className="fw-semibold">Pengaturan Pajak</Link>.</span>
                </div>
            }
            columns={[
                { key: 'kode_lokasi', label: 'Kode' }, { key: 'nama_lokasi', label: 'Nama' }, { key: 'alamat_lokasi', label: 'Alamat' },
                { key: 'tlp_lokasi', label: 'Tlp' }, { key: 'tlp_owner', label: 'Tlp Owner' },
                pct('ppn_persen', 'PPN'), pct('pph_persen', 'PPh'), pct('bhp_persen', 'BHP'), pct('uso_persen', 'USO'),
                { key: 'admin_fee', label: 'Admin Fee', align: 'end', render: (r) => (kosong(r.admin_fee) ? '-' : r.admin_fee_tipe === 'persentase' ? persen(r.admin_fee) : rupiah(r.admin_fee)) },
                { key: 'nilai_bagi_hasil', label: 'Bagi Hasil', align: 'end', render: (r) => (kosong(r.nilai_bagi_hasil) ? '-' : r.metode_bagi_hasil === 'persentase' ? persen(r.nilai_bagi_hasil) : rupiah(r.nilai_bagi_hasil)) },
                { key: 'jumlah_area', label: 'Jml Area', align: 'end', render: (r) => <Link href={`/kelola-data/area?cabang=${r.id}`} prefetch title={`Lihat area ${r.nama_lokasi}`}>{angka(r.jumlah_area)}</Link> },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
                { key: 'terima_cash', label: 'Terima Cash', render: (r) => <StatusBadge value={r.terima_cash} /> },
                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
            ]}
            fields={[
                { name: 'kode_lokasi', label: 'Kode Lokasi', required: true, placeholder: 'C011' },
                { name: 'nama_lokasi', label: 'Nama Lokasi', required: true },
                { name: 'alamat_lokasi', label: 'Alamat', type: 'textarea', required: true, col: 'col-12' },
                { name: 'tlp_lokasi', label: 'Tlp Lokasi', type: 'tel' },
                { name: 'tlp_owner', label: 'Tlp Owner', type: 'tel' },
                { name: 'id_mitra', label: 'Mitra / Pemilik', type: 'select', options: mitraOptions, help: 'User dengan role Mitra' },
                { name: 'group_wa', label: 'ID Grup WA Notifikasi', placeholder: '1203630xxxx@g.us' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
                { name: 'terima_cash', label: 'Cabang menerima pembayaran cash', type: 'switch' },
            ]}
            formExtra={() => <div className="alert alert-light-warning mb-0 mt-2">Pajak dan bagi hasil cabang diatur per bulan di menu <strong>Pengaturan Pajak</strong>.</div>}
        />
    );
}
