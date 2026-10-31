import { useEffect, useState } from 'react';
import { Edit2, FileText, Trash2 } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import { BULAN, periode as namaPeriode, rupiah, tanggal, waktu } from '../lib/format';
import { api, notify, useBisa } from '../lib/ui';
import { ActionButton, ActionGroup, ConfirmDialog, EmptyState, FormModal } from './ui';

const DISKON = [{ value: 'nominal', label: 'Nominal (Rp)' }, { value: 'persentase', label: 'Persentase (%)' }];
export const kunciPeriode = (tahun, bulan) => `${tahun}-${String(bulan).padStart(2, '0')}-01`;
export const JENIS_TAGIHAN = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };
/** Periode pembayaran dari periode pemakaian: prabayar bulan yang sama, pascabayar bulan berikutnya. */
const periodePembayaran = (tahun, bulan, jenis) => (jenis === 'pascabayar' ? (bulan === 12 ? kunciPeriode(tahun + 1, 1) : kunciPeriode(tahun, bulan + 1)) : kunciPeriode(tahun, bulan));
// Perkiraan di form; server menghitung ulang dari harga di database
const totalBayar = (harga, v) => {
    const nilai = Number(v.diskon_nilai) || 0;
    const diskon = v.diskon_tipe === 'persentase' ? Math.round((harga * nilai) / 100) : v.diskon_tipe === 'nominal' ? nilai : 0;
    return Math.max(harga - diskon, 0);
};

/**
 * Form input / edit pembayaran.
 * target: { judul, nama, paket, harga, jns_tagihan, prorate?: { periode, nominal, hari, hari_bulan }, id_lokasi, terima_cash, lunas: Set periode terbayar, initial, method, url } | null
 */
export function FormPembayaran({ target, onClose, onSaved, rekeningOptions, tahunBayar }) {
    const simpan = async (v) => {
        try {
            const res = await api(target.method, target.url, { bulan: v.bulan, tahun: v.tahun, metode: v.metode, diskon_tipe: v.diskon_tipe || null, diskon_nilai: v.diskon_tipe ? v.diskon_nilai : null });
            notify(res.message, 'success', 7000);
            onSaved?.();
        } catch (err) {
            if (!err.errors) notify(err.message, 'danger', 7000);
            throw err;
        }
    };
    const t = target;
    // Periode prorate memakai nominal prorate; server menghitung ulang nominal yang sama
    const prorateDipilih = (v) => !!t?.prorate && kunciPeriode(v.tahun, v.bulan) === t.prorate.periode;
    const hargaPeriode = (v) => (prorateDipilih(v) ? t.prorate.nominal : t.harga);

    return (
        <FormModal
            isOpen={!!t}
            toggle={onClose}
            title={t?.judul ?? ''}
            submitLabel="Simpan Pembayaran"
            initial={t?.initial ?? {}}
            fields={t ? [
                { name: 'nama', label: 'Nama Pelanggan', type: 'static', render: () => t.nama },
                { name: 'paket', label: 'Paket Langganan', type: 'static', render: () => t.paket },
                {
                    name: 'metode', label: 'Metode Pembayaran', type: 'select', required: true,
                    // Rekening tanpa cabang berlaku di semua cabang
                    options: [...(t.terima_cash ? [{ value: 'cash', label: 'Cash' }] : []), ...rekeningOptions.filter((o) => !o.id_lokasi || String(o.id_lokasi) === String(t.id_lokasi))],
                    help: t.terima_cash ? 'Transfer: pilih rekening tujuan (diatur di Kelola Data › Rekening)' : 'Cabang ini tidak menerima cash',
                },
                {
                    name: 'bulan', label: 'Periode Pemakaian · Bulan', type: 'select', required: true,
                    options: (v) => BULAN.map((b, i) => ({ value: i + 1, label: t.lunas.has(kunciPeriode(v.tahun, i + 1)) ? `${b} (sudah dibayar)` : b })),
                },
                { name: 'tahun', label: 'Periode Pemakaian · Tahun', type: 'select', required: true, options: tahunBayar.map((y) => ({ value: y, label: String(y) })) },
                {
                    name: 'periode_pembayaran', label: 'Periode Pembayaran', type: 'static', show: (v) => !!(v.bulan && v.tahun),
                    render: (v) => <>{namaPeriode(periodePembayaran(Number(v.tahun), Number(v.bulan), t.jns_tagihan))} <span className="badge badge-light-secondary">{JENIS_TAGIHAN[t.jns_tagihan] ?? 'Prabayar'}</span></>,
                },
                {
                    name: 'peringatan_periode', type: 'static', col: 'col-12', show: (v) => t.lunas.has(kunciPeriode(v.tahun, v.bulan)),
                    render: (v) => <div className="alert alert-light-warning mb-0">{BULAN[v.bulan - 1]} {v.tahun} sudah dibayar. Pilih periode lain.</div>,
                },
                {
                    name: 'harga', label: 'Nominal Tagihan', type: 'static',
                    render: (v) => (prorateDipilih(v)
                        ? <>{rupiah(t.prorate.nominal)} <span className="badge badge-light-info">Prorate {t.prorate.hari}/{t.prorate.hari_bulan} hari</span></>
                        : rupiah(t.harga)),
                },
                { name: 'diskon_tipe', label: 'Diskon', type: 'select', options: DISKON, resets: ['diskon_nilai'], help: 'Kosongkan bila tanpa diskon' },
                { name: 'diskon_nilai', label: 'Diskon (Rp)', type: 'currency', required: true, show: (v) => v.diskon_tipe === 'nominal' },
                { name: 'diskon_nilai', label: 'Diskon (%)', type: 'number', required: true, show: (v) => v.diskon_tipe === 'persentase' },
                { name: 'total', label: 'Nominal Bayar', type: 'static', col: 'col-12', render: (v) => <span className="fs-5 fw-bold text-success">{rupiah(totalBayar(hargaPeriode(v), v))}</span> },
            ] : []}
            onSubmit={simpan}
        />
    );
}

/**
 * Input pembayaran banyak pelanggan sekaligus untuk bulan tagihan terpilih (tanpa diskon).
 * rows: baris Pelanggan Belum Lunas yang dicentang; periode: bulan tagihan (Y-m-01).
 */
export function BayarMassal({ rows, periode, isOpen, onClose, onSaved, rekeningOptions }) {
    // Cash bila semua cabang menerima cash; rekening semua cabang, atau rekening cabang bila semua pelanggan satu cabang
    const cabang = [...new Set(rows.map((p) => String(p.id_lokasi)))];
    const metode = [
        ...(rows.every((p) => p.terima_cash) ? [{ value: 'cash', label: 'Cash' }] : []),
        ...rekeningOptions.filter((o) => !o.id_lokasi || (cabang.length === 1 && String(o.id_lokasi) === cabang[0])),
    ];
    const total = rows.reduce((s, p) => s + (p.tagihan ?? 0), 0);
    const simpan = async (v) => {
        try {
            const res = await api('POST', '/pembayaran/massal', {
                pelanggan_ids: rows.map((p) => p.id), bulan: Number(periode.slice(5, 7)), tahun: Number(periode.slice(0, 4)), metode: v.metode,
            });
            notify(res.message, res.type ?? 'success', res.type ? 15000 : 7000);
            onSaved?.();
        } catch (err) {
            // Hanya error metode yang punya field di form ini
            if (!err.errors?.metode) notify(err.message, 'danger', 7000);
            throw err;
        }
    };

    return (
        <FormModal
            isOpen={isOpen}
            toggle={onClose}
            title={`Input Pembayaran Massal · ${rows.length} pelanggan`}
            submitLabel={`Simpan ${rows.length} Pembayaran`}
            initial={{ metode: metode[0]?.value === 'cash' ? 'cash' : '' }}
            fields={[
                {
                    name: 'daftar', type: 'static', col: 'col-12',
                    render: () => (
                        <div className="table-responsive border rounded" style={{ maxHeight: 320 }}>
                            <table className="table table-sm align-middle mb-0">
                                <thead className="table-light"><tr><th>Pelanggan</th><th>Tipe</th><th>Periode</th><th className="text-end">Tagihan</th></tr></thead>
                                <tbody>
                                    {rows.map((p) => (
                                        <tr key={p.id}>
                                            <td>{p.nama}<br /><small className="f-light">{p.id_pelanggan} · {p.paket}</small></td>
                                            <td>{JENIS_TAGIHAN[p.jns_tagihan] ?? 'Prabayar'}</td>
                                            <td>{namaPeriode(periode)}<br /><small className="f-light">untuk pemakaian {namaPeriode(p.periode_tagihan)}</small></td>
                                            <td className="text-end">
                                                {rupiah(p.tagihan)}
                                                {p.tagihan_prorate && <><br /><span className="badge badge-light-info">Prorate</span></>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ),
                },
                {
                    name: 'metode', label: 'Metode Pembayaran', type: 'select', required: true, options: metode,
                    help: cabang.length > 1 ? 'Pelanggan dari beberapa cabang: hanya cash (bila semua cabang menerima) & rekening semua cabang' : 'Berlaku untuk semua pelanggan terpilih',
                },
                { name: 'total', label: 'Total Bayar', type: 'static', render: () => <span className="fs-5 fw-bold text-success">{rupiah(total)}</span> },
                {
                    name: 'info', type: 'static', col: 'col-12',
                    render: () => <small className="f-light">Tanpa diskon; untuk diskon gunakan Input Pembayaran per pelanggan. Nominal dihitung ulang di server, pelanggan yang sudah lunas dilewati.</small>,
                },
            ]}
            onSubmit={simpan}
        />
    );
}

/** Ringkasan data pelanggan di atas tabel riwayat. */
function InfoPelanggan({ p }) {
    const info = [
        ['ID Pelanggan', p.id_pelanggan ?? '-'],
        ['Tanggal Registrasi', tanggal(p.tgl_registrasi)],
        ['Tipe Pembayaran', `${JENIS_TAGIHAN[p.jns_tagihan] ?? '-'} · ${p.skema_tagihan === 'prorate' ? 'Prorate' : 'Tagihan Full'}`],
        ['Nama', p.nama],
        ['Alamat', p.alamat ?? '-'],
        ['No. Telepon', p.tlp ?? '-'],
        ['Paket Langganan', p.harga != null ? `${p.paket} · ${rupiah(p.harga)}` : p.paket ?? '-'],
        ['Jatuh Tempo', p.tgl_jatuh_tempo ? `Tanggal ${p.tgl_jatuh_tempo} setiap bulan` : '-'],
    ];
    return (
        <div className="row g-2 border rounded p-2 mb-3 mx-0">
            {info.map(([label, nilai]) => (
                <div key={label} className="col-sm-6 col-lg-3">
                    <small className="f-light d-block">{label}</small>
                    <span className="fw-semibold">{nilai}</span>
                </div>
            ))}
        </div>
    );
}

/** Modal riwayat pembayaran satu pelanggan: edit, batalkan (hapus), dan buka kwitansi. */
export function RiwayatPembayaran({ pelanggan, onClose, onChanged, rekeningOptions, tahunBayar }) {
    const bisa = useBisa();
    const [state, setState] = useState({ loading: false, rows: [] });
    const [muatUlang, setMuatUlang] = useState(0);
    const [edit, setEdit] = useState(null);
    const [hapus, setHapus] = useState(null);

    useEffect(() => {
        if (!pelanggan) return undefined;
        let batal = false;
        setState((s) => ({ ...s, loading: true }));
        api('GET', `/pelanggan/${pelanggan.id}/pembayaran`)
            .then((res) => !batal && setState({ loading: false, rows: res.data }))
            .catch((err) => { if (!batal) { setState({ loading: false, rows: [] }); notify(err.message, 'danger', 7000); } });
        return () => { batal = true; };
    }, [pelanggan, muatUlang]);

    const berubah = () => { setMuatUlang((n) => n + 1); onChanged?.(); };
    const bukaEdit = (r) => setEdit({
        judul: `Edit Pembayaran · ${r.no_invoice}`, nama: pelanggan.nama, paket: r.nama_paket, harga: r.harga, jns_tagihan: r.jns_tagihan,
        id_lokasi: pelanggan.id_lokasi, terima_cash: pelanggan.terima_cash || r.metode === 'cash',
        // Periode milik pembayaran ini sendiri tidak dianggap bentrok
        lunas: new Set(state.rows.filter((x) => x.id !== r.id).map((x) => x.periode)),
        initial: {
            bulan: Number(r.periode.slice(5, 7)), tahun: Number(r.periode.slice(0, 4)), metode: r.metode === 'cash' ? 'cash' : String(r.rekening_id ?? ''),
            diskon_tipe: r.diskon_tipe ?? '', diskon_nilai: r.diskon_tipe ? r.diskon_nilai : '',
        },
        method: 'PUT', url: `/pembayaran/${r.id}`,
    });
    const batalkan = async (r) => {
        try {
            notify((await api('DELETE', `/pembayaran/${r.id}`)).message);
            berubah();
        } catch (err) {
            notify(err.message, 'danger', 7000);
        }
    };

    return (
        <>
            <Modal isOpen={!!pelanggan} toggle={onClose} size="xl" centered scrollable>
                <ModalHeader toggle={onClose}>Riwayat Pembayaran · {pelanggan?.nama}</ModalHeader>
                <ModalBody>
                    {pelanggan && <InfoPelanggan p={pelanggan} />}
                    {state.loading && <div className="text-center py-4"><Spinner /></div>}
                    {!state.loading && !state.rows.length && <EmptyState text="Belum ada pembayaran" />}
                    {!state.loading && state.rows.length > 0 && (
                        <div className="table-responsive">
                            <table className="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>No Invoice</th><th>Periode Pembayaran</th><th>Periode Pemakaian</th><th className="text-end">Harga</th><th className="text-end">Diskon</th>
                                        <th className="text-end">Dibayar</th><th>Metode</th><th>Waktu Bayar</th><th>Petugas</th><th className="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {state.rows.map((r) => (
                                        <tr key={r.id}>
                                            <td>{r.no_invoice}</td>
                                            <td>{namaPeriode(r.periode_pembayaran)}<br /><small className="f-light">{JENIS_TAGIHAN[r.jns_tagihan]}</small></td>
                                            <td>{namaPeriode(r.periode)}{r.is_prorate && <> <span className="badge badge-light-info">Prorate</span></>}</td>
                                            <td className="text-end">{rupiah(r.harga)}</td>
                                            <td className="text-end">{r.diskon > 0 ? rupiah(r.diskon) : '-'}</td>
                                            <td className="text-end fw-semibold">{rupiah(r.jml_bayar)}</td>
                                            <td>{r.metode === 'cash' ? 'Cash' : <>Transfer<br /><small className="f-light">{r.rekening ?? '-'}</small></>}</td>
                                            <td>{waktu(r.paid_at)}</td>
                                            <td>{r.petugas ?? '-'}</td>
                                            <td className="text-end">
                                                <ActionGroup>
                                                    <ActionButton icon={FileText} label="Kwitansi" href={`/pembayaran/${r.id}/kwitansi`} />
                                                    {bisa('pelanggan.pembayaran.update') && <ActionButton icon={Edit2} label="Edit" color="warning" onClick={() => bukaEdit(r)} />}
                                                    {bisa('pelanggan.pembayaran.delete') && <ActionButton icon={Trash2} label="Hapus (batalkan)" color="danger" onClick={() => setHapus(r)} />}
                                                </ActionGroup>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter><Button color="light" onClick={onClose}>Tutup</Button></ModalFooter>
            </Modal>

            <FormPembayaran target={edit} onClose={() => setEdit(null)} onSaved={berubah} rekeningOptions={rekeningOptions} tahunBayar={tahunBayar} />
            <ConfirmDialog
                isOpen={!!hapus}
                toggle={() => setHapus(null)}
                title="Hapus Pembayaran"
                confirmLabel="Hapus"
                message={hapus && <>Batalkan pembayaran <strong>{hapus.no_invoice}</strong> periode pemakaian <strong>{namaPeriode(hapus.periode)}</strong> ({rupiah(hapus.jml_bayar)})? Pelanggan akan kembali belum lunas untuk periode itu. Tindakan ini tercatat di log.</>}
                onConfirm={() => batalkan(hapus)}
            />
        </>
    );
}
