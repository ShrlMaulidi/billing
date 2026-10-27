import { Card, CardBody } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import { StatusBadge } from '../../Components/ui';
import { rupiah, tanggal } from '../../lib/format';

const KATEGORI = { total: 'Data Pelanggan', lunas: 'Pelanggan Sudah Lunas', belum_lunas: 'Pelanggan Belum Lunas', on: 'Pelanggan On', off: 'Pelanggan Off', berhenti: 'Pelanggan Berhenti', baru: 'Pelanggan Baru Bulan Ini' };

export default function Pelanggan({ filters, rows }) {
    return (
        <>
            <PageHeader title={KATEGORI[filters.kategori] ?? 'Daftar Pelanggan'} crumbs={[{ label: 'Dashboard', href: '/dashboard' }]} />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <DataTable
                            data={rows}
                            toolbar={<FilterBar filters={filters} fields={[{ name: 'kategori', label: 'Kategori', options: Object.entries(KATEGORI).map(([value, label]) => ({ value, label })) }, 'cabang']} />}
                            columns={[
                                { key: 'id_pelanggan', label: 'ID Pelanggan' },
                                { key: 'nama', label: 'Nama' },
                                { key: 'alamat', label: 'Alamat', hidden: true },
                                { key: 'tlp', label: 'Tlp' },
                                { key: 'cabang', label: 'Cabang' },
                                { key: 'paket', label: 'Paket' },
                                { key: 'harga', label: 'Harga', align: 'end', render: (r) => rupiah(r.harga) },
                                { key: 'tgl_registrasi', label: 'Registrasi', render: (r) => tanggal(r.tgl_registrasi) },
                                { key: 'status_tagihan', label: 'Tagihan Bulan Ini', render: (r) => (r.status_tagihan ? <StatusBadge value={r.status_tagihan} /> : '-') },
                                { key: 'status_langganan', label: 'Internet', render: (r) => <StatusBadge value={r.status_langganan} /> },
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                            ]}
                        />
                    </CardBody>
                </Card>
            </div>
        </>
    );
}
