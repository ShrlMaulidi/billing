import { useState } from 'react';
import { Eye, Info } from 'react-feather';
import { Card, CardBody, Modal, ModalBody, ModalHeader } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import { ActionButton } from '../../Components/ui';
import { angka, label, waktu } from '../../lib/format';

/** Label & warna badge tiap jenis aksi jejak audit (lihat AuditLog::AKSI). */
const AKSI = {
    create: ['Tambah', 'success'], update: ['Ubah', 'info'], delete: ['Hapus', 'danger'],
    login: ['Login', 'primary'], login_gagal: ['Login Gagal', 'danger'], logout: ['Logout', 'secondary'],
    isolir: ['Isolir', 'warning'], aktifkan: ['Aktifkan', 'success'], sinkron: ['Sinkron Mikrotik', 'info'],
    approve: ['Setujui', 'success'], reject: ['Tolak', 'danger'], export: ['Buat Script', 'dark'], lihat: ['Lihat Dokumen', 'warning'],
};
const badgeAksi = (aksi) => <span className={`badge badge-light-${AKSI[aksi]?.[1] ?? 'secondary'}`}>{AKSI[aksi]?.[0] ?? label(aksi)}</span>;

/** Nilai perubahan untuk ditampilkan: kosong → "—", daftar → dipisah koma, lainnya teks apa adanya. */
const tampil = (v) => {
    if (v === null || v === undefined || v === '') return <em className="f-light">—</em>;
    if (Array.isArray(v)) return v.join(', ');
    if (typeof v === 'boolean') return v ? 'Ya' : 'Tidak';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
};

/**
 * Jejak audit: siapa melakukan apa, kapan, dan dari mana. Catatan hanya bisa dibaca (tidak bisa diubah/dihapus)
 * dan disimpan 1 tahun.
 */
export default function Logs({ filters, rows, options, total, batas }) {
    const [detail, setDetail] = useState(null);
    const perubahan = Object.entries(detail?.perubahan ?? {});

    return (
        <>
            <PageHeader title="Logs" crumbs={[]} />
            <div className="container-fluid">
                {total > batas && (
                    <div className="alert alert-light-warning d-flex align-items-center gap-2">
                        <Info size={16} className="flex-shrink-0" />
                        <span>Ditampilkan {angka(batas)} aktivitas terbaru dari {angka(total)}. Persempit rentang tanggal atau filter untuk melihat sisanya.</span>
                    </div>
                )}
                <Card>
                    <CardBody>
                        <DataTable data={rows} pageSize={25} onRowClick={setDetail} exportable={false} emptyText="Belum ada aktivitas pada rentang ini"
                            actions={(r) => <ActionButton icon={Eye} label="Detail" color="info" onClick={() => setDetail(r)} />}
                            toolbar={<FilterBar filters={filters} fields={[
                                { name: 'dari', label: 'Dari tanggal', type: 'date' }, { name: 'sampai', label: 'Sampai tanggal', type: 'date' },
                                { name: 'user', label: 'User', options: options.user.map((u) => ({ value: u, label: u })) },
                                { name: 'modul', label: 'Modul', options: options.modul.map((m) => ({ value: m, label: m })) },
                                { name: 'aksi', label: 'Aksi', options: options.aksi.map((a) => ({ value: a, label: AKSI[a]?.[0] ?? label(a) })), width: 160 },
                            ]} />}
                            columns={[
                                { key: 'created_at', label: 'Waktu', render: (r) => waktu(r.created_at) },
                                { key: 'user', label: 'User', render: (r) => <>{r.user ?? '-'}{r.role && <><br /><small className="f-light">{r.role}</small></>}</> },
                                { key: 'modul', label: 'Modul' },
                                { key: 'aksi', label: 'Aksi', sortValue: (r) => AKSI[r.aksi]?.[0] ?? r.aksi, render: (r) => badgeAksi(r.aksi) },
                                { key: 'deskripsi', label: 'Deskripsi', kartu: 'penuh', nowrap: false },
                                { key: 'ip_address', label: 'IP Address', render: (r) => r.ip_address ?? '-' },
                                { key: 'user_agent', label: 'Perangkat', kartu: 'penuh', hidden: true, render: (r) => r.user_agent ?? '-' },
                            ]} />
                    </CardBody>
                </Card>
            </div>
            <Modal isOpen={!!detail} toggle={() => setDetail(null)} size="lg" centered scrollable fullscreen="sm">
                <ModalHeader toggle={() => setDetail(null)}>Detail Aktivitas #{detail?.id}</ModalHeader>
                <ModalBody>
                    {detail && (
                        <>
                            <div className="row mb-3 g-2">
                                {[
                                    ['Waktu', waktu(detail.created_at)], ['User', `${detail.user ?? '-'}${detail.role ? ` (${detail.role})` : ''}`],
                                    ['Modul / Aksi', <>{detail.modul} · {badgeAksi(detail.aksi)}</>], ['IP', detail.ip_address ?? '-'],
                                    ['Perangkat', detail.user_agent ?? '-'], ['Deskripsi', detail.deskripsi],
                                ].map(([k, v]) => (
                                    <div className="col-md-6" key={k}><small className="f-light d-block">{k}</small><span className="text-break">{v}</span></div>
                                ))}
                            </div>
                            <h6>Data yang Berubah</h6>
                            {perubahan.length ? (
                                <div className="table-responsive">
                                    <table className="table table-bordered table-sm align-middle">
                                        <thead><tr><th>Kolom</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                                        <tbody>
                                            {perubahan.map(([kolom, p]) => (
                                                <tr key={kolom}>
                                                    <td className="text-nowrap">{label(kolom)}</td>
                                                    <td className="text-break">{tampil(p?.dari)}</td>
                                                    <td className="text-break">{tampil(p?.ke)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : <p className="f-light mb-0">Tidak ada data yang berubah pada aktivitas ini.</p>}
                        </>
                    )}
                </ModalBody>
            </Modal>
        </>
    );
}
