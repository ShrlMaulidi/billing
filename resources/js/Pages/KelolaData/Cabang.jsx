import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { angka, rupiah } from '../../lib/format';

export default function Cabang({ filters, rows }) {
    const mitra = [...new Set(rows.map((r) => r.nama_mitra))].map((m, i) => ({ value: rows.find((r) => r.nama_mitra === m).id_mitra ?? i, label: m }));
    return (
        <CrudPage
            title="Cabang / Lokasi" entity="Cabang" rows={rows} filters={filters} filterFields={[]}
            defaults={{ metode_bagi_hasil: 'persentase', status: 'aktif', terima_cash: true }}
            deleteLabel={(r) => r.nama_lokasi}
            columns={[
                { key: 'kode_lokasi', label: 'Kode' }, { key: 'nama_lokasi', label: 'Nama' }, { key: 'alamat_lokasi', label: 'Alamat' },
                { key: 'tlp_lokasi', label: 'Tlp' }, { key: 'tlp_owner', label: 'Tlp Owner' },
                { key: 'metode_bagi_hasil', label: 'Metode Bagi Hasil', render: (r) => (r.metode_bagi_hasil === 'persentase' ? 'Persentase' : 'Nominal') },
                { key: 'nilai_bagi_hasil', label: 'Bagi Hasil', align: 'end', render: (r) => (r.metode_bagi_hasil === 'persentase' ? `${r.nilai_bagi_hasil}%` : rupiah(r.nilai_bagi_hasil)) },
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
                { name: 'id_mitra', label: 'Mitra / Pemilik', type: 'select', options: mitra },
                { name: 'group_wa', label: 'ID Grup WA Notifikasi', placeholder: '1203630xxxx@g.us' },
                { name: 'metode_bagi_hasil', label: 'Metode Bagi Hasil', type: 'radio', options: [{ value: 'persentase', label: 'Persentase' }, { value: 'nominal', label: 'Nominal' }] },
                { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (%)', type: 'number', show: (v) => v.metode_bagi_hasil !== 'nominal' },
                { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (Rp)', type: 'currency', show: (v) => v.metode_bagi_hasil === 'nominal' },
                { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
                { name: 'terima_cash', label: 'Cabang menerima pembayaran cash', type: 'switch' },
            ]}
        />
    );
}
