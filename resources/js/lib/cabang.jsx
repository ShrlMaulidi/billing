/**
 * Form cabang / lokasi, dipakai menu Kelola Data › Cabang (edit) dan Data Klien (tambah & edit tipe Mitra Reseller / Retail).
 * Tarif pajak awal hanya diisi saat tambah cabang; setelah itu diubah per bulan di Pengaturan Pajak.
 */
const TIPE_TARIF = [{ value: 'persentase', label: 'Persentase' }, { value: 'nominal', label: 'Nominal' }];
const baru = (v) => !v.id;

export const CABANG_DEFAULTS = {
    status: 'aktif', terima_cash: true, admin_full: false,
    ppn_persen: 0, pph_persen: 0, bhp_persen: 0, uso_persen: 0, admin_fee_tipe: 'nominal', admin_fee: 0, metode_bagi_hasil: 'persentase', nilai_bagi_hasil: 0,
};

/** @param {{ adminFullTerkunci: boolean }} opsi  adminFullTerkunci: user cabang tidak boleh mengubah Admin Full */
export const cabangFields = ({ adminFullTerkunci }) => [
    { name: 'kode_lokasi', label: 'Kode Lokasi', required: true, placeholder: 'C011' },
    { name: 'nama_lokasi', label: 'Nama Lokasi', required: true },
    { name: 'alamat_lokasi', label: 'Alamat', type: 'textarea', required: true, col: 'col-12' },
    { name: 'tlp_lokasi', label: 'Tlp Lokasi', type: 'tel' },
    { name: 'tlp_owner', label: 'Tlp Owner', type: 'tel' },
    { name: 'nama_mitra', label: 'Mitra / Pemilik', placeholder: 'Nama pemilik cabang', help: 'Akun login untuk mitra dibuat di User › Manajemen User' },
    { name: 'group_wa', label: 'ID Grup WA Notifikasi', placeholder: '1203630xxxx@g.us' },
    { name: 'status', label: 'Status', type: 'radio', required: true, options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif' }] },
    { name: 'terima_cash', label: 'Cabang menerima pembayaran cash', type: 'switch' },
    {
        name: 'admin_full', label: 'Admin Full', type: 'radio', col: 'col-12', disabled: adminFullTerkunci,
        options: [{ value: false, label: 'Tidak' }, { value: true, label: 'Ya' }],
        help: 'Tidak: pembayaran online memakai payment gateway perusahaan, mitra tidak bisa menambah payment gateway sendiri. Ya: mitra memakai payment gateway sendiri (uang masuk ke mitra) lalu menyetor kewajiban ke perusahaan sesuai Saldo / Balance. Hanya bisa diubah admin pusat.',
    },
    { name: 'judul_pajak', type: 'static', col: 'col-12', show: baru, render: () => <h6 className="border-top pt-3 mb-0">Pajak &amp; Bagi Hasil</h6> },
    { name: 'ppn_persen', label: 'PPN (%)', type: 'number', required: true, show: baru },
    { name: 'pph_persen', label: 'PPh 25 (%)', type: 'number', required: true, show: baru },
    { name: 'bhp_persen', label: 'BHP (%)', type: 'number', required: true, show: baru },
    { name: 'uso_persen', label: 'USO (%)', type: 'number', required: true, show: baru },
    { name: 'admin_fee_tipe', label: 'Tipe Admin Fee', type: 'radio', options: TIPE_TARIF, show: baru },
    { name: 'admin_fee', label: 'Admin Fee (%)', type: 'number', required: true, show: (v) => baru(v) && v.admin_fee_tipe === 'persentase' },
    { name: 'admin_fee', label: 'Admin Fee (Rp)', type: 'currency', required: true, show: (v) => baru(v) && v.admin_fee_tipe !== 'persentase' },
    { name: 'metode_bagi_hasil', label: 'Metode Bagi Hasil', type: 'radio', options: TIPE_TARIF, show: baru },
    { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (%)', type: 'number', required: true, show: (v) => baru(v) && v.metode_bagi_hasil !== 'nominal' },
    { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (Rp)', type: 'currency', required: true, show: (v) => baru(v) && v.metode_bagi_hasil === 'nominal' },
];

export function CabangFormInfo({ values, periodeSekarang }) {
    return (
        <div className="alert alert-light-warning mb-0 mt-2">
            {baru(values)
                ? <>Tarif di atas otomatis berlaku untuk <strong>Januari–Desember {periodeSekarang.slice(0, 4)}</strong>. Setelah disimpan, tarif tiap bulan bisa diubah di menu <strong>Pengaturan Pajak</strong>.</>
                : <>Pajak dan bagi hasil cabang diubah per bulan di menu <strong>Pengaturan Pajak</strong>.</>}
        </div>
    );
}
