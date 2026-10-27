import { usePage } from '@inertiajs/react';
import CrudPage from '../../Components/CrudPage';
import { StatusBadge } from '../../Components/ui';
import { angka, rupiah } from '../../lib/format';

export default function Paket({ filters, rows, routerOptions }) {
    const { cabangOptions } = usePage().props;
    return (
        <CrudPage
            title="Paket Pelanggan" entity="Paket" rows={rows} filters={filters} deleteLabel={(r) => r.nama_paket}
            defaults={{ jenis: 'bulanan', jns_koneksi: 'pppoe', tampil_registrasi: true, buat_profile: true }}
            columns={[
                { key: 'nama_paket', label: 'Nama Paket' }, { key: 'keterangan', label: 'Keterangan' },
                { key: 'harga', label: 'Harga', align: 'end', render: (r) => (r.harga ? rupiah(r.harga) : <StatusBadge value="free" text="Free" />) },
                { key: 'jenis', label: 'Jenis', render: (r) => (r.jenis === 'bulanan' ? 'Bulanan' : 'Non-bulanan') },
                { key: 'profile_mikrotik', label: 'Profile MikroTik' },
                { key: 'cabang', label: 'Cabang', render: (r) => (r.filter_lokasi ? r.cabang : 'Semua Cabang') },
                { key: 'tampil_registrasi', label: 'Tampil di Registrasi', render: (r) => <StatusBadge value={r.tampil_registrasi} /> },
                { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
            ]}
            fields={[
                { name: 'nama_paket', label: 'Nama Paket', required: true, placeholder: 'Ultra 20 Mbps' },
                { name: 'keterangan', label: 'Keterangan', required: true },
                { name: 'harga', label: 'Harga', type: 'currency', required: true, help: 'Isi 0 untuk paket free' },
                { name: 'jenis', label: 'Jenis', type: 'radio', required: true, options: [{ value: 'bulanan', label: 'Bulanan' }, { value: 'non_bulanan', label: 'Non-bulanan' }] },
                { name: 'id_lokasi', label: 'Cabang', type: 'select', required: true, options: cabangOptions },
                { name: 'id_router', label: 'Router', type: 'select', options: routerOptions, help: 'Kosongkan = semua router' },
                { name: 'jns_koneksi', label: 'Jenis Koneksi', type: 'radio', options: [{ value: 'pppoe', label: 'PPPoE' }, { value: 'hotspot', label: 'Hotspot' }] },
                { name: 'profile_mikrotik', label: 'Profile MikroTik', required: true },
                { name: 'filter_lokasi', label: 'Hanya untuk cabang ini', type: 'switch' },
                { name: 'tampil_registrasi', label: 'Tampil di form registrasi', type: 'switch' },
                { name: 'buat_profile', label: 'Buat profile otomatis di MikroTik', type: 'switch' },
                { name: 'buat_pool', label: 'Buat IP pool', type: 'switch' },
                { name: 'nama_pool', label: 'Nama Pool', show: (v) => v.buat_pool },
                { name: 'local_address', label: 'Local Address', show: (v) => v.buat_pool },
                { name: 'remote_address', label: 'Remote Address', show: (v) => v.buat_pool },
            ]}
        />
    );
}
