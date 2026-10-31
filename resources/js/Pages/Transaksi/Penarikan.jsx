import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Image, X } from 'react-feather';
import CrudPage from '../../Components/CrudPage';
import { sumFooter } from '../../Components/DataTable';
import { ActionButton, FormModal, StatusBadge } from '../../Components/ui';
import { maskRekening, periode, rupiah, waktu } from '../../lib/format';
import { api, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

const BASE = '/transaksi/penarikan-saldo';

/** Rekening penerima dana dalam satu baris, mis. "BCA · 1234567890 a.n. Budi". */
const rekeningPenerima = (r) => `${r.bank_penerima} · ${r.no_rekening_penerima} a.n. ${r.atas_nama_penerima}`;

/** Kirim aksi persetujuan (setujui / tolak) lalu muat ulang daftar & saldo. */
const proses = (row, aksi, data) => api('POST', `${BASE}/${row.id}/${aksi}`, data)
    .then((res) => { notify(res.message); reloadData(['rows', 'saldo']); })
    .catch((err) => { if (!err.errors) notify(err.message, 'danger', 7000); throw err; });

/**
 * Penarikan saldo mitra: mitra mengajukan dari saldo satu periode (saldo 0/minus tidak bisa ditarik),
 * Super Admin mentransfer dana lalu menyetujui dengan foto bukti transfer (saldo berkurang), atau menolak dengan alasan.
 */
export default function Penarikan({ filters, rows, saldo, periodeOptions }) {
    const { auth, cabangOptions } = usePage().props;
    const bisa = useBisa();
    // Persetujuan hanya untuk user semua cabang; mitra tidak bisa menyetujui penarikannya sendiri
    const bisaApprove = bisa('transaksi.penarikan.approve') && !auth.user?.id_lokasi;
    const [setujui, setSetujui] = useState(null);
    const [tolak, setTolak] = useState(null);
    const tersedia = (v) => saldo[v.id_lokasi]?.[v.periode_sumber];

    return (
        <>
            <CrudPage
                title="Penarikan Saldo Mitra" crumbs={['Transaksi']} entity="Request Penarikan" rows={rows} filters={filters}
                canAdd={bisa('transaksi.penarikan.create')} canEdit={false} canDelete={false}
                defaults={{ id_lokasi: auth.user?.id_lokasi ?? '', periode_sumber: periodeOptions[0] }}
                onSave={saveTo(BASE, ['rows', 'saldo'])}
                filterFields={['bulan', 'tahun', 'cabang', { name: 'status', label: 'Status', options: ['pending', 'disetujui', 'ditolak'] }, { name: 'semua', label: 'Semua periode', type: 'switch' }]}
                tableProps={{ footer: sumFooter(['nominal'], rupiah) }}
                rowActions={(r) => (
                    <>
                        {r.ada_bukti && <ActionButton icon={Image} label="Lihat Bukti Transfer" color="info" onClick={() => window.open(`${BASE}/${r.id}/bukti`, '_blank', 'noopener')} />}
                        {bisaApprove && r.status === 'pending' && <ActionButton icon={Check} label="Setujui" color="success" onClick={() => setSetujui(r)} />}
                        {bisaApprove && r.status === 'pending' && <ActionButton icon={X} label="Tolak" color="danger" onClick={() => setTolak(r)} />}
                    </>
                )}
                columns={[
                    { key: 'no', label: 'No', sortable: false, render: (r, i) => i + 1 },
                    { key: 'nama_request', label: 'Nama Request', render: (r) => <>{r.nama_request}<br /><small className="f-light">oleh {r.pemohon}</small></> },
                    { key: 'cabang', label: 'Mitra / Cabang', render: (r) => <>{r.nama_mitra}<br /><small className="f-light">{r.cabang}</small></> },
                    { key: 'periode_sumber', label: 'Periode Sumber', render: (r) => periode(r.periode_sumber) },
                    { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                    { key: 'rekening_penerima', label: 'Rekening Penerima', render: (r) => <>{r.bank_penerima} · {maskRekening(r.no_rekening_penerima)}<br /><small className="f-light">a.n. {r.atas_nama_penerima}</small></> },
                    { key: 'keterangan', label: 'Keterangan' },
                    { key: 'created_at', label: 'Waktu Request', render: (r) => waktu(r.created_at) },
                    { key: 'status', label: 'Status', render: (r) => <><StatusBadge value={r.status} />{r.catatan && <><br /><small className="f-light">{r.catatan}</small></>}</> },
                    { key: 'diproses_oleh', label: 'Diproses Oleh', render: (r) => (r.diproses_oleh ? <>{r.diproses_oleh}<br /><small className="f-light">{waktu(r.diproses_at)}</small></> : '-') },
                ]}
                fields={[
                    { name: 'id_lokasi', label: 'Mitra / Cabang', type: 'select', required: true, options: cabangOptions, disabled: !!auth.user?.id_lokasi },
                    {
                        name: 'periode_sumber', label: 'Periode Sumber', type: 'select', required: true,
                        options: (v) => periodeOptions.map((p) => ({ value: p, label: v.id_lokasi ? `${periode(p)} · saldo ${rupiah(saldo[v.id_lokasi]?.[p] ?? 0)}` : periode(p) })),
                    },
                    {
                        name: 'saldo', label: 'Saldo Tersedia', type: 'static', col: 'col-12', render: (v) => {
                            const s = tersedia(v);
                            if (!v.id_lokasi || s === undefined) return '-';
                            return (
                                <>
                                    <h5 className={`mb-0 ${s > 0 ? 'font-success' : 'font-danger'}`}>{rupiah(s)}</h5>
                                    <small className="f-light">{s > 0 ? 'Saldo periode ini dikurangi pengajuan yang masih menunggu persetujuan' : 'Saldo 0 atau minus, penarikan tidak bisa diajukan'}</small>
                                </>
                            );
                        },
                    },
                    { name: 'nama_request', label: 'Nama Request', required: true, placeholder: 'mis. Penarikan bagi hasil Oktober' },
                    { name: 'nominal', label: 'Nominal', type: 'currency', required: true },
                    { name: 'keterangan', label: 'Keterangan', type: 'textarea', required: true, col: 'col-12' },
                    { name: 'judul_rekening', type: 'static', col: 'col-12', render: () => (
                        <div className="border-top pt-3"><h6 className="mb-0">Rekening Penerima Dana</h6><small className="f-light">Dana penarikan ditransfer ke rekening ini. Pastikan data sesuai buku tabungan.</small></div>
                    ) },
                    { name: 'bank_penerima', label: 'Nama Bank / E-wallet', required: true, placeholder: 'BCA, BRI, Mandiri, DANA, …', col: 'col-md-4' },
                    { name: 'no_rekening_penerima', label: 'Nomor Rekening', required: true, placeholder: 'Angka saja', col: 'col-md-4' },
                    { name: 'atas_nama_penerima', label: 'Atas Nama', required: true, placeholder: 'Sesuai buku tabungan', col: 'col-md-4' },
                ]}
            />
            <FormModal isOpen={!!setujui} toggle={() => setSetujui(null)} title={`Setujui Penarikan — ${setujui?.nama_request ?? ''}`} size="md" submitLabel="Setujui Penarikan"
                fields={[
                    {
                        name: 'tujuan', label: 'Transfer ke', type: 'static', render: () => (setujui ? (
                            <>
                                <strong>{rekeningPenerima(setujui)}</strong><br />
                                <span className="font-success fw-bold">{rupiah(setujui.nominal)}</span> · {setujui.cabang}
                                <div className="small f-light mt-1">Transfer dana lebih dulu, lalu unggah fotonya. Saldo mitra periode {periode(setujui.periode_sumber)} berkurang setelah disetujui.</div>
                            </>
                        ) : '-'),
                    },
                    { name: 'bukti_transfer', label: 'Foto Bukti Transfer', type: 'file', required: true, accept: '.jpg,.jpeg,.png,.webp', hint: 'JPG / PNG / WEBP, maks 2 MB' },
                    { name: 'catatan', label: 'Catatan / No. Referensi', type: 'textarea' },
                ]}
                onSubmit={(v) => {
                    const data = new FormData();
                    data.append('bukti_transfer', v.bukti_transfer);
                    if (v.catatan) data.append('catatan', v.catatan);
                    return proses(setujui, 'setujui', data);
                }} />
            <FormModal isOpen={!!tolak} toggle={() => setTolak(null)} title={`Tolak Penarikan — ${tolak?.nama_request ?? ''}`} size="md" submitLabel="Tolak"
                fields={[{ name: 'catatan', label: 'Alasan Penolakan', type: 'textarea', required: true }]}
                onSubmit={(v) => proses(tolak, 'tolak', v)} />
        </>
    );
}
