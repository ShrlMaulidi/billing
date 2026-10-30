import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Edit2, Info } from 'react-feather';
import { Card, CardBody } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import Select from '../../Components/Select';
import { ActionButton, FormModal } from '../../Components/ui';
import { BULAN, persen, rupiah } from '../../lib/format';
import { applyFilters, saveTo, useBisa } from '../../lib/ui';

// Cabang yang belum pernah diatur tarifnya bernilai null
const ada = (v) => v !== null && v !== undefined;
const pct = (key) => (r) => (ada(r[key]) ? persen(r[key]) : '-');
const adminFee = (r) => (!ada(r.admin_fee) ? '-' : r.admin_fee_tipe === 'persentase' ? persen(r.admin_fee) : rupiah(r.admin_fee));
const bagiHasil = (r) => (!ada(r.nilai_bagi_hasil) ? '-' : r.metode_bagi_hasil === 'persentase' ? persen(r.nilai_bagi_hasil) : rupiah(r.nilai_bagi_hasil));
const TIPE = [{ value: 'persentase', label: 'Persentase' }, { value: 'nominal', label: 'Nominal' }];

/**
 * Tarif pajak & bagi hasil per cabang per bulan (Januari–Desember terisi otomatis saat cabang ditambah).
 * Mengedit satu bulan hanya mengubah bulan itu; bulan yang belum diatur mengikuti tarif bulan terakhir.
 */
export default function Pajak({ filters, rows, tahunOptions, periodeSekarang }) {
    const { cabangOptions, auth } = usePage().props;
    const bisa = useBisa();
    // Server hanya mengizinkan user semua cabang mengubah tarif (bagi hasil = setoran cabang)
    const bisaUbah = bisa('kelola_data.pajak.update') && !auth.user?.id_lokasi;
    const [edit, setEdit] = useState(null);
    const cabang = cabangOptions.find((o) => o.value === filters.cabang)?.label;
    const prevOf = (r) => rows.find((x) => x.bulan === r.bulan - 1);

    // Tandai nilai yang berbeda dari bulan sebelumnya
    const cell = (key, format) => ({
        key, align: 'end',
        render: (r) => {
            const prev = prevOf(r);
            const tipe = { admin_fee: 'admin_fee_tipe', nilai_bagi_hasil: 'metode_bagi_hasil' }[key];
            const changed = prev && (prev[key] !== r[key] || (tipe && prev[tipe] !== r[tipe]));
            return changed
                ? <span className="badge badge-light-warning" title={`Bulan sebelumnya: ${format(prev)}`}>{format(r)}</span>
                : format(r);
        },
    });

    return (
        <>
            <PageHeader title="Pengaturan Pajak" crumbs={['Kelola Data']} />
            <div className="container-fluid">
                <Card>
                    <CardBody>
                        <div className="alert alert-light-primary position-relative mb-3">
                            <Info />
                            <p>Tarif pajak dan bagi hasil berlaku <strong>per bulan</strong> untuk setiap cabang. Tarif yang diisi saat tambah cabang otomatis berlaku Januari–Desember, lalu bisa diubah per bulan di sini. Bulan berlabel <span className="badge badge-light-secondary">Belum diatur</span> mengikuti tarif bulan terakhir. Nilai yang <span className="badge badge-light-warning">ditandai</span> berbeda dari bulan sebelumnya.</p>
                        </div>
                        <DataTable
                            rowKey="bulan"
                            data={rows}
                            pageSize={25}
                            searchable={false}
                            columns={[
                                {
                                    key: 'bulan', label: 'Bulan',
                                    render: (r) => (
                                        <span className="d-inline-flex align-items-center gap-2">
                                            <strong>{BULAN[r.bulan - 1]}</strong>
                                            {r.periode === periodeSekarang && <span className="badge badge-light-success">Bulan ini</span>}
                                            {!r.tersimpan && <span className="badge badge-light-secondary">Belum diatur</span>}
                                        </span>
                                    ),
                                },
                                { ...cell('ppn_persen', pct('ppn_persen')), label: 'PPN' },
                                { ...cell('pph_persen', pct('pph_persen')), label: 'PPh 25' },
                                { ...cell('bhp_persen', pct('bhp_persen')), label: 'BHP' },
                                { ...cell('uso_persen', pct('uso_persen')), label: 'USO' },
                                { ...cell('admin_fee', adminFee), label: 'Admin Fee' },
                                { ...cell('nilai_bagi_hasil', bagiHasil), label: 'Bagi Hasil' },
                            ]}
                            actions={bisaUbah ? (r) => <ActionButton icon={Edit2} label={`Edit ${BULAN[r.bulan - 1]}`} color="warning" onClick={() => setEdit({ admin_fee_tipe: 'nominal', metode_bagi_hasil: 'persentase', ...r })} /> : undefined}
                            toolbar={
                                <div className="d-flex flex-wrap gap-2">
                                    <Select ariaLabel="Cabang" style={{ width: 220 }} value={filters.cabang} onChange={(v) => applyFilters({ ...filters, cabang: v })} options={cabangOptions} />
                                    <Select ariaLabel="Tahun" style={{ width: 110 }} value={filters.tahun} onChange={(v) => applyFilters({ ...filters, tahun: v })}
                                        options={tahunOptions.map((y) => ({ value: y, label: String(y) }))} />
                                </div>
                            }
                        />
                    </CardBody>
                </Card>
            </div>

            <FormModal
                isOpen={!!edit}
                toggle={() => setEdit(null)}
                title={edit ? `Edit Pajak & Bagi Hasil ${BULAN[edit.bulan - 1]} ${edit.tahun}` : ''}
                initial={edit ?? {}}
                fields={[
                    { name: 'ppn_persen', label: 'PPN (%)', type: 'number', required: true },
                    { name: 'pph_persen', label: 'PPh 25 (%)', type: 'number', required: true },
                    { name: 'bhp_persen', label: 'BHP (%)', type: 'number', required: true },
                    { name: 'uso_persen', label: 'USO (%)', type: 'number', required: true },
                    { name: 'admin_fee_tipe', label: 'Tipe Admin Fee', type: 'radio', options: TIPE },
                    { name: 'admin_fee', label: 'Admin Fee (%)', type: 'number', required: true, show: (v) => v.admin_fee_tipe === 'persentase' },
                    { name: 'admin_fee', label: 'Admin Fee (Rp)', type: 'currency', required: true, show: (v) => v.admin_fee_tipe !== 'persentase' },
                    { name: 'metode_bagi_hasil', label: 'Metode Bagi Hasil', type: 'radio', options: TIPE },
                    { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (%)', type: 'number', required: true, show: (v) => v.metode_bagi_hasil !== 'nominal' },
                    { name: 'nilai_bagi_hasil', label: 'Nilai Bagi Hasil (Rp)', type: 'currency', required: true, show: (v) => v.metode_bagi_hasil === 'nominal' },
                ]}
                onSubmit={(v) => saveTo('/pajak')({ ...v, tahun: edit.tahun, bulan: edit.bulan }, { id: filters.cabang })}
            >
                {() => edit && (
                    <div className="alert alert-light-warning mb-0 mt-2">
                        Perubahan hanya berlaku untuk <strong>{cabang}</strong> pada <strong>{BULAN[edit.bulan - 1]} {edit.tahun}</strong>. Bulan dan tahun lain tidak ikut berubah.
                    </div>
                )}
            </FormModal>
        </>
    );
}
