import { Link } from '@inertiajs/react';
import { Info } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { angka, periode, persen, rupiah } from '../../lib/format';
import { deleteFrom, saveTo, useBisa } from '../../lib/ui';

// Cabang lama yang belum punya tarif di Pengaturan Pajak tampil '-'
const kosong = (v) => v === null || v === undefined;
const TIPE = [{ value: 'persentase', label: 'Persentase' }, { value: 'nominal', label: 'Nominal' }];
// Tarif awal hanya diisi saat tambah cabang; setelah itu diubah per bulan di Pengaturan Pajak
const baru = (v) => !v.id;

export default function Cabang({ filters, rows, periodeSekarang }) {
    const bisa = useBisa();
    const pct = (key, label) => ({ key, label, align: 'end', render: (r) => (kosong(r[key]) ? '-' : persen(r[key])) });
    return (
        <CrudPage
            title="Cabang / Lokasi" entity="Cabang" rows={rows} filters={filters} filterFields={[]}
            defaults={{
                status: 'aktif', terima_cash: true,
                ppn_persen: 0, pph_persen: 0, bhp_persen: 0, uso_persen: 0, admin_fee_tipe: 'nominal', admin_fee: 0, metode_bagi_hasil: 'persentase', nilai_bagi_hasil: 0,
            }}
            deleteLabel={(r) => r.nama_lokasi}
            onSave={saveTo('/cabang')} onDelete={deleteFrom('/cabang')}
            canAdd={bisa('kelola_data.cabang.create')} canEdit={bisa('kelola_data.cabang.update')} canDelete={bisa('kelola_data.cabang.delete')}
            before={
                <div className="alert alert-light-primary d-flex align-items-center gap-2">
                    <Info size={16} className="flex-shrink-0" />
                    <span>Pajak dan bagi hasil yang ditampilkan adalah tarif bulan berjalan (<strong>{periode(periodeSekarang)}</strong>). Untuk mengubahnya per bulan, buka <Link href="/kelola-data/pajak" prefetch className="fw-semibold">Pengaturan Pajak</Link>.</span>
                </div>
            }
            columns={[
                { key: 'kode_lokasi', label: 'Kode' }, { key: 'nama_lokasi', label: 'Nama' }, { key: 'nama_mitra', label: 'Mitra', render: (r) => r.nama_mitra || '-' }, { key: 'alamat_lokasi', label: 'Alamat' },
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
                { name: 'nama_mitra', label: 'Mitra / Pemilik', placeholder: 'Nama pemilik cabang', help: 'Akun login untuk mitra dibuat di User › Manajemen User' },
                { name: 'group_wa', label: 'ID Grup WA Notifikasi', placeholder: '1203630xxxx@g.us' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
                { name: 'terima_cash', label: 'Cabang menerima pembayaran cash', type: 'switch' },
                { name: 'judul_pajak', type: 'static', col: 'col-12', show: baru, render: () => <h6 className="border-top pt-3 mb-0">Pajak &amp; Bagi Hasil</h6> },
                { name: 'ppn_persen', label: 'PPN (%)', type: 'number', required: true, show: baru },
                { name: 'pph_persen', label: 'PPh 25 (%)', type: 'number', required: true, show: baru },
                { name: 'bhp_persen', label: 'BHP (%)', type: 'number', required: true, show: baru },
                { name: 'uso_persen', label: 'USO (%)', type: 'number', required: true, show: baru },
                { name: 'admin_fee_tipe', label: 'Tipe Admin Fee', type: 'radio', options: TIPE, show: baru },
                { name: 'admin_fee', label: 'Admin Fee (%)', type: 'number', required: true, show: (v) => baru(v) && v.admin_fee_tipe === 'persentase' },
                { name: 'admin_fee', label: 'Admin Fee (Rp)', type: 'currency', required: true, show: (v) => baru(v) && v.admin_fee_tipe !== 'persentase' },
                { name: 'metode_bagi_hasil', label: 'Metode Bagi Hasil', type: 'radio', options: TIPE, show: baru },
                { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (%)', type: 'number', required: true, show: (v) => baru(v) && v.metode_bagi_hasil !== 'nominal' },
                { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (Rp)', type: 'currency', required: true, show: (v) => baru(v) && v.metode_bagi_hasil === 'nominal' },
            ]}
            formExtra={(v) => (
                <div className="alert alert-light-warning mb-0 mt-2">
                    {baru(v)
                        ? <>Tarif di atas otomatis berlaku untuk <strong>Januari–Desember {periodeSekarang.slice(0, 4)}</strong>. Setelah disimpan, tarif tiap bulan bisa diubah di menu <strong>Pengaturan Pajak</strong>.</>
                        : <>Pajak dan bagi hasil cabang diubah per bulan di menu <strong>Pengaturan Pajak</strong>.</>}
                </div>
            )}
        />
    );
}
