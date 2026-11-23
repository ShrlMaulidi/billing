import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle, LogOut, RotateCcw, Save, Send, Smartphone } from 'react-feather';
import { Button, Card, CardBody, CardHeader, FormFeedback, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import GelembungWa from '../../Components/GelembungWa';
import PageHeader from '../../Components/PageHeader';
import Select from '../../Components/Select';
import { ConfirmDialog, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify, useBisa } from '../../lib/ui';

const STATUS_WA = { connected: 'Terhubung', qr: 'Menunggu scan', connecting: 'Menghubungkan…', disconnected: 'Belum terhubung' };
const WARNA_WA = { connected: 'connected', qr: 'pending', connecting: 'pending', disconnected: 'disconnected' };
const POLLING_MS = 2500;

/* Data rekaan untuk pratinjau template (sama dengan PesanTagihan::contoh di server) */
const tanggalId = (d, opsi) => d.toLocaleDateString('id-ID', opsi);
function contohPesan(template, cabang) {
    const sekarang = new Date();
    const nilai = {
        '{nama}': 'Budi Santoso', '{id_pelanggan}': 'MSS-000123', '{paket}': 'Home 20 Mbps',
        '{periode}': tanggalId(sekarang, { month: 'long', year: 'numeric' }), '{tagihan}': 'Rp 200.000',
        '{jatuh_tempo}': tanggalId(new Date(sekarang.getFullYear(), sekarang.getMonth(), 10), { day: 'numeric', month: 'long', year: 'numeric' }),
        '{link}': `${window.location.origin}/cek-tagihan/kartu/123?signature=…`, '{cabang}': cabang,
    };
    return Object.entries(nilai).reduce((t, [k, v]) => t.replaceAll(k, v), template ?? '').trim();
}

/** QR dari teks sesi WhatsApp (dibuat library qrcode di browser). */
function KodeQr({ teks }) {
    const [svg, setSvg] = useState('');
    useEffect(() => {
        let aktif = true;
        import('qrcode').then((qr) => qr.toString(teks, { type: 'svg', margin: 1, errorCorrectionLevel: 'L' }))
            .then((hasil) => aktif && setSvg(hasil)).catch(() => aktif && setSvg(''));
        return () => { aktif = false; };
    }, [teks]);
    return <div className="wa-qr" dangerouslySetInnerHTML={{ __html: svg }} />;
}

/** Modal scan QR: minta sesi ke gateway lalu pantau statusnya sampai terhubung atau QR kedaluwarsa. */
function ModalScan({ cabang, onTutup }) {
    const [status, setStatus] = useState(null);
    const [galat, setGalat] = useState(null);
    const terhubung = status?.status === 'connected';

    const mulai = async () => {
        setGalat(null);
        setStatus({ status: 'connecting' });
        try {
            setStatus(await api('POST', `/kelola-data/notifikasi/${cabang.id}/whatsapp`));
        } catch (err) {
            setGalat(err.message);
            setStatus(null);
        }
    };

    useEffect(() => { mulai(); }, []);

    // Pantau selama menunggu scan / menyambung
    useEffect(() => {
        if (!status || !['qr', 'connecting'].includes(status.status)) return undefined;
        const t = setTimeout(async () => {
            try {
                setStatus(await api('GET', `/kelola-data/notifikasi/${cabang.id}/whatsapp`));
            } catch (err) {
                setGalat(err.message);
            }
        }, POLLING_MS);
        return () => clearTimeout(t);
    }, [status]);

    useEffect(() => {
        if (terhubung) notify(`WhatsApp ${cabang.nama} terhubung`);
    }, [terhubung]);

    return (
        <Modal isOpen toggle={onTutup} centered>
            <ModalHeader toggle={onTutup}>Hubungkan WhatsApp · {cabang.nama}</ModalHeader>
            <ModalBody className="text-center">
                {galat && <div className="alert alert-light-danger text-start">{galat}</div>}
                {terhubung ? (
                    <div className="py-3">
                        <CheckCircle size={46} className="text-success mb-2" />
                        <h5 className="mb-1">WhatsApp terhubung</h5>
                        <p className="f-light mb-0">+{status.nomor}{status.nama ? ` · ${status.nama}` : ''}</p>
                    </div>
                ) : status?.status === 'qr' && status.qr ? (
                    <>
                        <KodeQr teks={status.qr} />
                        <ol className="wa-langkah text-start">
                            <li>Buka <strong>WhatsApp</strong> di HP yang dipakai cabang.</li>
                            <li>Ketuk <strong>⋮</strong> (Android) atau <strong>Setelan</strong> (iPhone) › <strong>Perangkat Tertaut</strong> › <strong>Tautkan Perangkat</strong>.</li>
                            <li>Arahkan kamera ke QR ini. QR berganti otomatis tiap ±20 detik.</li>
                        </ol>
                    </>
                ) : status?.status === 'disconnected' ? (
                    <div className="py-3">
                        <p className="mb-3">{status.galat ?? 'QR kedaluwarsa.'}</p>
                        <Button color="primary" onClick={mulai}><RotateCcw size={14} className="me-1" />Tampilkan QR baru</Button>
                    </div>
                ) : !galat && (
                    <div className="py-4"><Spinner /><p className="f-light mt-2 mb-0">Menyiapkan QR…</p></div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color={terhubung ? 'primary' : 'light'} onClick={onTutup}>{terhubung ? 'Selesai' : 'Tutup'}</Button>
            </ModalFooter>
        </Modal>
    );
}

function ModalUji({ cabang, onTutup }) {
    const [nomor, setNomor] = useState('');
    const [galat, setGalat] = useState(null);
    const [proses, setProses] = useState(false);
    const kirim = async (e) => {
        e.preventDefault();
        setProses(true);
        setGalat(null);
        try {
            notify((await api('POST', `/kelola-data/notifikasi/${cabang.id}/whatsapp/uji`, { nomor })).message);
            onTutup(true);
        } catch (err) {
            setGalat(err.errors?.nomor?.[0] ?? err.message);
        } finally {
            setProses(false);
        }
    };
    return (
        <Modal isOpen toggle={() => onTutup(false)} centered>
            <form onSubmit={kirim}>
                <ModalHeader toggle={() => onTutup(false)}>Kirim Pesan Uji · {cabang.nama}</ModalHeader>
                <ModalBody>
                    <Label for="nomor-uji">Nomor WhatsApp tujuan</Label>
                    <Input id="nomor-uji" type="tel" inputMode="tel" placeholder="081234567890" value={nomor} onChange={(e) => setNomor(e.target.value)} invalid={!!galat} autoFocus />
                    {galat && <FormFeedback>{galat}</FormFeedback>}
                    <small className="f-light d-block mt-2">Pastikan WhatsApp cabang bisa mengirim sebelum mengirim tagihan ke pelanggan.</small>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => onTutup(false)}>Batal</Button>
                    <Button color="primary" type="submit" disabled={proses || !nomor.trim()}>{proses ? <Spinner size="sm" className="me-1" /> : <Send size={14} className="me-1" />}Kirim</Button>
                </ModalFooter>
            </form>
        </Modal>
    );
}

function KartuWhatsApp({ c, bisaUbah, gatewaySiap, onScan, onUji, onPutus }) {
    const status = c.wa?.status ?? 'disconnected';
    return (
        <div className="wa-cabang">
            <div className="d-flex justify-content-between align-items-start gap-2">
                <div className="d-flex align-items-center gap-2 min-w-0">
                    <span className="wa-cabang-ikon"><Smartphone size={18} /></span>
                    <div className="min-w-0">
                        <h6 className="mb-0 text-truncate">{c.nama}</h6>
                        <small className="f-light">{status === 'connected' ? `+${c.wa.nomor}${c.wa.nama ? ` · ${c.wa.nama}` : ''}` : 'Belum ada WhatsApp'}</small>
                    </div>
                </div>
                {gatewaySiap && <StatusBadge value={WARNA_WA[status]} text={STATUS_WA[status]} />}
            </div>
            {status === 'connected' && c.wa.sejak && <small className="f-light d-block mt-2">Terhubung sejak {waktu(c.wa.sejak)}</small>}
            {status !== 'connected' && c.wa?.galat && <small className="text-danger d-block mt-2">{c.wa.galat}</small>}
            <div className="wa-cabang-hitung"><span><strong>{c.terkirim}</strong> terkirim</span><span><strong>{c.gagal}</strong> gagal</span><span className="f-light">bulan ini</span></div>
            {bisaUbah && gatewaySiap && (
                <div className="d-flex flex-wrap gap-2 mt-3">
                    {status === 'connected' ? (
                        <>
                            <Button size="sm" color="primary" outline onClick={() => onUji(c)}><Send size={13} className="me-1" />Kirim Uji</Button>
                            <Button size="sm" color="danger" outline onClick={() => onPutus(c)}><LogOut size={13} className="me-1" />Putuskan</Button>
                        </>
                    ) : (
                        <Button size="sm" color="success" onClick={() => onScan(c)}><Smartphone size={13} className="me-1" />Hubungkan (Scan QR)</Button>
                    )}
                </div>
            )}
        </div>
    );
}

function EditorTemplate({ cabang, templateBawaan, placeholder, maksTemplate, bisaUbah }) {
    const [pilih, setPilih] = useState(cabang[0]?.id ?? null);
    const aktif = cabang.find((c) => c.id === pilih) ?? cabang[0];
    const [isi, setIsi] = useState(aktif?.template ?? templateBawaan);
    const [galat, setGalat] = useState(null);
    const [proses, setProses] = useState(null);
    const area = useRef(null);

    // Ganti cabang: muat template cabang itu
    useEffect(() => { setIsi(aktif?.template ?? templateBawaan); setGalat(null); }, [aktif?.id, aktif?.template]);

    const sisip = (kode) => {
        const el = area.current;
        const awal = el?.selectionStart ?? isi.length;
        const akhir = el?.selectionEnd ?? isi.length;
        setIsi(isi.slice(0, awal) + kode + isi.slice(akhir));
        requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(awal + kode.length, awal + kode.length); });
    };

    const simpan = async () => {
        setProses('simpan');
        setGalat(null);
        try {
            notify((await api('PUT', `/kelola-data/notifikasi/${aktif.id}/template`, { isi })).message);
            router.reload({ only: ['cabang'] });
        } catch (err) {
            setGalat(err.errors?.isi?.[0] ?? err.message);
        } finally {
            setProses(null);
        }
    };
    const bawaan = async () => {
        setProses('bawaan');
        try {
            notify((await api('DELETE', `/kelola-data/notifikasi/${aktif.id}/template`)).message);
            router.reload({ only: ['cabang'] });
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            setProses(null);
        }
    };

    if (!aktif) return null;
    const berubah = isi !== (aktif.template ?? templateBawaan);

    return (
        <Card>
            <CardHeader className="pb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 className="mb-0">Template Pesan Tagihan</h5>
                    <small className="f-light">Dipakai saat mengirim tagihan dari menu Pelanggan Belum Lunas (ikon lonceng)</small>
                </div>
                {cabang.length > 1 && (
                    <div style={{ minWidth: 240 }}>
                        <Select value={pilih} onChange={(v) => setPilih(Number(v))} options={cabang.map((c) => ({ value: c.id, label: c.nama }))} ariaLabel="Pilih cabang" />
                    </div>
                )}
            </CardHeader>
            <CardBody>
                <div className="row g-4">
                    <div className="col-lg-7">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                            <Label for="isi-template" className="mb-0">Kalimat tagihan · {aktif.nama}</Label>
                            <StatusBadge value={aktif.template ? 'aktif' : 'pending'} text={aktif.template ? 'Template cabang' : 'Kalimat bawaan'} />
                        </div>
                        <Input innerRef={area} id="isi-template" type="textarea" rows={11} value={isi} maxLength={maksTemplate} disabled={!bisaUbah}
                            invalid={!!galat} onChange={(e) => setIsi(e.target.value)} />
                        {galat && <FormFeedback>{galat}</FormFeedback>}
                        <div className="d-flex justify-content-between mt-1"><small className="f-light">*tebal* dan _miring_ seperti di WhatsApp</small><small className="f-light">{isi.length}/{maksTemplate}</small></div>
                        <div className="wa-placeholder mt-3">
                            {placeholder.map((p) => (
                                <button key={p.kode} type="button" className="wa-placeholder-item" title={p.keterangan} onClick={() => sisip(p.kode)} disabled={!bisaUbah}>{p.kode}</button>
                            ))}
                        </div>
                        <small className="f-light d-block mt-1">Klik kode untuk menyisipkan data pelanggan ke kalimat.</small>
                        {bisaUbah && (
                            <div className="d-flex flex-wrap gap-2 mt-3">
                                <Button color="primary" onClick={simpan} disabled={!!proses || !berubah || !isi.trim()}>{proses === 'simpan' ? <Spinner size="sm" className="me-1" /> : <Save size={14} className="me-1" />}Simpan Template</Button>
                                {aktif.template && <Button color="light" onClick={bawaan} disabled={!!proses}>{proses === 'bawaan' ? <Spinner size="sm" className="me-1" /> : <RotateCcw size={14} className="me-1" />}Kembalikan ke Bawaan</Button>}
                            </div>
                        )}
                    </div>
                    <div className="col-lg-5">
                        <Label className="mb-2">Pratinjau (data contoh)</Label>
                        <GelembungWa teks={contohPesan(isi, aktif.nama)} />
                    </div>
                </div>
            </CardBody>
        </Card>
    );
}

/**
 * Menu Notifikasi: WhatsApp tiap cabang (scan QR dari HP cabang) untuk mengirim tagihan, dan template kalimat tagihan per
 * cabang. User cabang hanya melihat cabangnya sendiri.
 */
export default function Notifikasi({ gateway, cabang, templateBawaan, placeholder, maksTemplate }) {
    const bisa = useBisa();
    const bisaUbah = bisa('kelola_data.notifikasi.update');
    const [scan, setScan] = useState(null);
    const [uji, setUji] = useState(null);
    const [putus, setPutus] = useState(null);
    const muatUlang = () => router.reload({ only: ['cabang', 'gateway'] });
    const terhubung = useMemo(() => cabang.filter((c) => c.wa?.status === 'connected').length, [cabang]);

    const putuskan = async (c) => {
        try {
            notify((await api('DELETE', `/kelola-data/notifikasi/${c.id}/whatsapp`)).message);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        }
        muatUlang();
    };

    return (
        <>
            <PageHeader title="Notifikasi" crumbs={['Kelola Data']} />
            <div className="container-fluid">
                {gateway !== 'siap' && (
                    <div className="alert alert-light-warning d-flex gap-2 align-items-start">
                        <AlertTriangle size={18} className="flex-shrink-0 mt-1" />
                        <div>
                            {gateway === 'belum_dikonfigurasi'
                                ? <>Layanan WhatsApp belum dikonfigurasi di server. Admin server perlu mengisi <code>WA_GATEWAY_KEY</code> di <code>.env</code> dan menjalankan service <code>mss-wa-gateway</code>.</>
                                : <>Layanan WhatsApp di server sedang tidak berjalan, jadi status WhatsApp cabang tidak bisa dibaca dan tagihan belum bisa dikirim. Admin server: jalankan <code>sudo systemctl restart mss-wa-gateway</code>.</>}
                        </div>
                    </div>
                )}
                <Card>
                    <CardHeader className="pb-0">
                        <h5 className="mb-0">WhatsApp Cabang</h5>
                        <small className="f-light">
                            Setiap cabang mengirim tagihan dari WhatsApp-nya sendiri.{gateway === 'siap' && ` ${terhubung} dari ${cabang.length} cabang terhubung.`} Kirim seperlunya: WhatsApp bisa memblokir nomor yang mengirim pesan massal ke banyak nomor yang belum menyimpan kontaknya.
                        </small>
                    </CardHeader>
                    <CardBody>
                        {cabang.length === 0 && <p className="f-light mb-0">Belum ada cabang.</p>}
                        <div className="wa-cabang-daftar">
                            {cabang.map((c) => (
                                <KartuWhatsApp key={c.id} c={c} bisaUbah={bisaUbah} gatewaySiap={gateway === 'siap'} onScan={setScan} onUji={setUji} onPutus={setPutus} />
                            ))}
                        </div>
                    </CardBody>
                </Card>
                <EditorTemplate cabang={cabang} templateBawaan={templateBawaan} placeholder={placeholder} maksTemplate={maksTemplate} bisaUbah={bisaUbah} />
            </div>
            {scan && <ModalScan cabang={scan} onTutup={() => { setScan(null); muatUlang(); }} />}
            {uji && <ModalUji cabang={uji} onTutup={(terkirim) => { setUji(null); if (terkirim) muatUlang(); }} />}
            <ConfirmDialog isOpen={!!putus} toggle={() => setPutus(null)} title="Putuskan WhatsApp"
                message={<>WhatsApp <strong>{putus?.nama}</strong> akan dikeluarkan dari billing. Tagihan cabang ini tidak bisa dikirim sampai QR di-scan lagi.</>}
                confirmLabel="Ya, putuskan" onConfirm={() => putuskan(putus)} />
        </>
    );
}
