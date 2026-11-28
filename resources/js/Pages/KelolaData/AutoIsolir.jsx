import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, CheckCircle, Clock, Info, XCircle } from 'react-feather';
import { Button, Card, CardBody, Input, Label } from 'reactstrap';
import DataTable, { sumFooter } from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import { ConfirmDialog } from '../../Components/ui';
import { angka, periode, rupiah } from '../../lib/format';
import { api, notify, useBisa } from '../../lib/ui';

/** Tanggal & jam dalam WIB, apa pun zona waktu perangkat. */
const formatWib = (iso, opsi) => new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', ...opsi }).format(new Date(iso));
const tanggalWib = (iso) => formatWib(iso, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const jamWib = (iso) => formatWib(iso, { hour: '2-digit', minute: '2-digit', hour12: false }).replace('.', ':');
const tanggalPendek = (ymd) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${ymd}T00:00:00`));

const PILIHAN_HARI = [
    { value: 0, judul: 'Hari H', ket: 'Tepat di tanggal jatuh tempo' },
    { value: 1, judul: 'H+1', ket: 'Sehari setelah jatuh tempo' },
];

/**
 * Kelola Data › Auto Isolir: atur kapan pelanggan yang belum bayar diisolir otomatis (Hari H / H+1 dari jatuh tempo,
 * jam WIB), lihat hasil pengecekan terakhir, dan pratinjau pelanggan yang akan diisolir pada pengecekan berikutnya.
 */
export default function AutoIsolir({ pengaturan, hasil, berikutnya, tanggalPratinjau, penjadwalAktif, batas, pratinjau }) {
    const bisa = useBisa();
    const bolehUbah = bisa('kelola_data.auto_isolir.update');
    const [form, setForm] = useState(pengaturan);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [konfirmasi, setKonfirmasi] = useState(false);
    const berubah = form.aktif !== pengaturan.aktif || form.hari !== pengaturan.hari || form.jam !== pengaturan.jam;
    const contohTanggal = 10 + form.hari;

    const simpan = async () => {
        setBusy(true);
        setErrors({});
        try {
            const res = await api('PUT', '/kelola-data/auto-isolir', form);
            notify(res.message);
            router.reload();
        } catch (err) {
            if (err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, [].concat(v)[0]])));
            else notify(err.message, 'danger', 7000);
        } finally {
            setBusy(false);
            setKonfirmasi(false);
        }
    };
    // Menyalakan auto isolir berdampak ke banyak pelanggan: minta konfirmasi dengan jumlah pelanggan yang akan diisolir
    const kirim = (e) => {
        e.preventDefault();
        if (form.aktif && !pengaturan.aktif) setKonfirmasi(true);
        else simpan();
    };

    return (
        <>
            <PageHeader title="Auto Isolir" crumbs={['Kelola Data']} />
            <div className="container-fluid">
                {!penjadwalAktif && (
                    <div className="alert alert-light-warning d-flex gap-2 align-items-start">
                        <AlertTriangle size={18} className="flex-shrink-0 mt-1" />
                        <div>
                            <strong>Penjadwal server belum berjalan.</strong> Auto isolir tidak akan jalan sampai cron Laravel aktif di server.
                            Jalankan <code>sudo bash deploy.sh</code> versi terbaru (otomatis memasang cron), lalu muat ulang halaman ini setelah 1–2 menit.
                        </div>
                    </div>
                )}

                <div className="row">
                    <div className="col-xl-5">
                        <Card>
                            <CardBody>
                                <h5 className="mb-3">Pengaturan</h5>
                                <form onSubmit={kirim}>
                                    <div className="form-check form-switch ai-saklar mb-3">
                                        <Input type="switch" id="ai-aktif" role="switch" checked={form.aktif} disabled={!bolehUbah} onChange={(e) => setForm({ ...form, aktif: e.target.checked })} />
                                        <Label for="ai-aktif" check>
                                            <span className="fw-semibold">Auto isolir {form.aktif ? 'aktif' : 'nonaktif'}</span>
                                            <small className="d-block f-light">Pelanggan yang tagihannya lewat jatuh tempo diisolir otomatis</small>
                                        </Label>
                                    </div>

                                    <Label className="form-label">Isolir pada</Label>
                                    <div className="ai-pilihan mb-1">
                                        {PILIHAN_HARI.map((p) => (
                                            <label key={p.value} className={`ai-opsi ${form.hari === p.value ? 'is-aktif' : ''}`}>
                                                <input type="radio" name="hari" value={p.value} checked={form.hari === p.value} disabled={!bolehUbah} onChange={() => setForm({ ...form, hari: p.value })} />
                                                <span className="ai-opsi-judul">{p.judul}</span>
                                                <span className="ai-opsi-ket">{p.ket}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {errors.hari && <div className="text-danger small mb-2">{errors.hari}</div>}

                                    <Label className="form-label mt-3" for="ai-jam">Jam isolir (WIB)</Label>
                                    <Input type="time" id="ai-jam" step={300} value={form.jam} disabled={!bolehUbah} invalid={!!errors.jam} onChange={(e) => setForm({ ...form, jam: e.target.value })} style={{ maxWidth: 180 }} />
                                    {errors.jam && <div className="invalid-feedback d-block">{errors.jam}</div>}

                                    <div className="ai-contoh mt-3">
                                        <Info size={14} className="flex-shrink-0" />
                                        <span>Contoh: pelanggan jatuh tempo <strong>tanggal 10</strong> yang belum bayar tagihan bulan ini diisolir <strong>tanggal {contohTanggal} jam {form.jam || '--:--'} WIB</strong>.
                                            Begitu pembayarannya diinput kasir atau dibayar lewat payment gateway, internetnya <strong>otomatis aktif kembali</strong> (status On).</span>
                                    </div>

                                    {bolehUbah && (
                                        <div className="d-flex justify-content-end mt-3">
                                            <Button color="primary" type="submit" disabled={busy || !berubah}>{busy ? 'Menyimpan…' : 'Simpan'}</Button>
                                        </div>
                                    )}
                                </form>
                            </CardBody>
                        </Card>
                    </div>

                    <div className="col-xl-7">
                        <Card>
                            <CardBody>
                                <h5 className="mb-3">Status</h5>
                                <div className="ai-status">
                                    <div className="ai-status-baris">
                                        <Clock size={16} className="font-primary flex-shrink-0" />
                                        <div>
                                            <span className="f-light small d-block">Pengecekan berikutnya</span>
                                            {berikutnya ? <strong>{tanggalWib(berikutnya)}, {jamWib(berikutnya)} WIB</strong> : <strong className="f-light">Auto isolir nonaktif</strong>}
                                        </div>
                                    </div>
                                    <div className="ai-status-baris">
                                        <CheckCircle size={16} className="font-success flex-shrink-0" />
                                        <div className="min-w-0">
                                            <span className="f-light small d-block">Pengecekan terakhir</span>
                                            {hasil ? (
                                                <>
                                                    <strong>{tanggalWib(hasil.waktu)}, {jamWib(hasil.waktu)} WIB</strong>
                                                    <div className="small mt-1">
                                                        <span className="font-success">{angka(hasil.diisolir)} diisolir</span> · <span className={hasil.gagal ? 'font-danger' : 'f-light'}>{angka(hasil.gagal)} gagal</span>
                                                        {hasil.ditunda > 0 && <> · <span className="font-warning">{angka(hasil.ditunda)} ditunda ke besok (batas {angka(batas)} per hari)</span></>}
                                                    </div>
                                                </>
                                            ) : <strong className="f-light">Belum pernah berjalan</strong>}
                                        </div>
                                    </div>
                                </div>
                                <p className="small f-light mb-0 mt-3">
                                    Dicek setiap hari. Tiap pelanggan hanya diisolir sekali per tagihan, saat lewat jatuh tempo dan belum bayar.
                                </p>
                                {hasil?.rincian_gagal?.length > 0 && (
                                    <div className="ai-gagal mt-3">
                                        <div className="small fw-semibold mb-1"><XCircle size={13} className="font-danger me-1" />Gagal diisolir</div>
                                        {hasil.rincian_gagal.map((g, i) => <div key={i} className="small"><strong>{g.nama}</strong> <span className="f-light">— {g.pesan}</span></div>)}
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    </div>
                </div>

                <Card>
                    <CardBody>
                        <DataTable
                            title={`${pengaturan.aktif ? 'Akan diisolir' : 'Akan diisolir bila diaktifkan'} · ${tanggalPendek(tanggalPratinjau)} (${angka(pratinjau.length)} pelanggan)`}
                            data={pratinjau}
                            exportable={false}
                            emptyText="Tidak ada pelanggan yang akan diisolir"
                            footer={sumFooter(['nominal'], rupiah)}
                            columns={[
                                { key: 'nama', label: 'Nama', kartu: 'judul', render: (r) => <>{r.nama}{r.id_pelanggan && <><br /><small className="f-light">{r.id_pelanggan}</small></>}</> },
                                { key: 'cabang', label: 'Cabang' },
                                { key: 'paket', label: 'Paket' },
                                { key: 'periode', label: 'Tagihan', render: (r) => periode(r.periode) },
                                { key: 'jatuh_tempo', label: 'Jatuh Tempo', render: (r) => tanggalPendek(r.jatuh_tempo) },
                                { key: 'nominal', label: 'Nominal', align: 'end', render: (r) => rupiah(r.nominal) },
                            ]}
                        />
                        <p className="small f-light mb-0 mt-3">
                            Yang diisolir hanya pelanggan yang <strong>tagihan bulan berjalannya</strong> belum terinput pembayarannya; tunggakan bulan sebelumnya tidak dihitung.
                            Tidak ikut diisolir: paket gratis, internet sudah Off, belum punya tanggal jatuh tempo atau router,
                            dan pelanggan yang sengaja dinyalakan kembali oleh staf setelah lewat jatuh tempo (sampai tagihan berikutnya).
                        </p>
                    </CardBody>
                </Card>
            </div>

            <ConfirmDialog
                isOpen={konfirmasi}
                toggle={() => setKonfirmasi(false)}
                title="Aktifkan Auto Isolir"
                color="warning"
                confirmLabel="Ya, aktifkan"
                message={<>Mulai jam <strong>{form.jam} WIB</strong>, sistem mengecek setiap hari dan mengisolir pelanggan yang lewat jatuh tempo dan belum bayar (sekali per tagihan). Saat ini ada <strong>{angka(pratinjau.length)} pelanggan</strong> yang sudah lewat jatuh tempo{pengaturan.hari !== form.hari ? ' (menurut pengaturan lama)' : ''} dan akan ikut diisolir pada pengecekan pertama. Periksa daftar di bawah sebelum mengaktifkan.</>}
                onConfirm={simpan}
            />
        </>
    );
}
