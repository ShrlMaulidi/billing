import { useMemo, useRef, useState } from 'react';
import { AlertTriangle, Bell, CheckCircle, Square, XCircle } from 'react-feather';
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Progress, Spinner } from 'reactstrap';
import { periode as namaPeriode } from '../lib/format';
import { api } from '../lib/ui';

/* Jeda acak antar pesan agar tidak terlihat seperti pesan massal oleh WhatsApp (memperkecil risiko nomor diblokir) */
const JEDA_MIN = 4000;
const JEDA_MAX = 8000;
export const MAKS_MASSAL = 100;

const tunggu = (ms) => new Promise((selesai) => setTimeout(selesai, ms));
/* Gagal yang berlaku untuk semua pelanggan (layanan mati) atau satu cabang (WhatsApp cabang belum di-scan) */
const GAGAL_SEMUA = /tidak berjalan|belum dikonfigurasi|kunci tidak cocok/i;
const GAGAL_CABANG = /belum terhubung/i;

/** Alasan pelanggan tidak bisa diingatkan, null bila bisa. */
export function alasanTidakBisa(p) {
    if (p.status !== 'aktif') return 'Pelanggan berhenti';
    if (!p.harga) return 'Paket gratis / belum ada paket';
    if (!p.tlp) return 'Nomor HP kosong';
    if (!p.bulan_wa) return 'Tidak ada tagihan yang belum dibayar';
    return null;
}

/**
 * Ingatkan banyak pelanggan sekaligus via WhatsApp cabang masing-masing (pelanggan yang dicentang). Pesan dikirim satu per
 * satu lewat endpoint yang sama dengan ikon lonceng (server tetap memeriksa cabang, nomor, & status lunas tiap pelanggan),
 * dengan jeda acak. Halaman harus tetap terbuka sampai selesai; bisa dihentikan kapan saja.
 */
export default function IngatkanMassal({ rows, onTutup }) {
    const [lewatiTerkirim, setLewatiTerkirim] = useState(true);
    const [tahap, setTahap] = useState('siap'); // siap | kirim | selesai
    const [hasil, setHasil] = useState({}); // id => { status: 'kirim'|'terkirim'|'gagal'|'dilewati', pesan }
    const berhenti = useRef(false);
    const [menghentikan, setMenghentikan] = useState(false);

    const { kirim, lewat } = useMemo(() => {
        const k = [];
        const l = [];
        rows.forEach((p) => {
            const alasan = alasanTidakBisa(p) ?? (lewatiTerkirim && p.wa_terkirim ? 'Sudah dikirim untuk tagihan ini' : null);
            if (alasan) l.push({ p, alasan });
            else k.push(p);
        });
        return { kirim: k.slice(0, MAKS_MASSAL), lewat: [...l, ...k.slice(MAKS_MASSAL).map((p) => ({ p, alasan: `Melebihi ${MAKS_MASSAL} pelanggan per pengiriman` }))] };
    }, [rows, lewatiTerkirim]);

    const selesaiKe = Object.values(hasil).filter((h) => ['terkirim', 'gagal', 'dilewati'].includes(h.status)).length;
    const jumlah = (s) => Object.values(hasil).filter((h) => h.status === s).length;
    const perkiraanMenit = Math.max(1, Math.round((kirim.length * (JEDA_MIN + JEDA_MAX)) / 2 / 60000));

    const mulai = async () => {
        berhenti.current = false;
        setTahap('kirim');
        const cabangGagal = {};
        let gagalSemua = null;
        for (let i = 0; i < kirim.length; i += 1) {
            const p = kirim[i];
            const set = (h) => setHasil((lama) => ({ ...lama, [p.id]: h }));
            if (berhenti.current) {
                set({ status: 'dilewati', pesan: 'Dihentikan' });
                continue;
            }
            const alasanLewat = gagalSemua ?? cabangGagal[p.id_lokasi];
            if (alasanLewat) {
                set({ status: 'gagal', pesan: alasanLewat });
                continue;
            }
            set({ status: 'kirim' });
            try {
                await api('POST', `/pelanggan/${p.id}/tagihan-wa`, { bulan: Number(p.bulan_wa.slice(5, 7)), tahun: Number(p.bulan_wa.slice(0, 4)) });
                set({ status: 'terkirim' });
                // Jeda hanya setelah pesan benar-benar terkirim, dan tidak setelah pesan terakhir
                if (i < kirim.length - 1 && !berhenti.current) await tunggu(JEDA_MIN + Math.random() * (JEDA_MAX - JEDA_MIN));
            } catch (err) {
                set({ status: 'gagal', pesan: err.message });
                if (GAGAL_SEMUA.test(err.message ?? '')) gagalSemua = err.message;
                else if (GAGAL_CABANG.test(err.message ?? '')) cabangGagal[p.id_lokasi] = err.message;
                if (err.status === 429) await tunggu(15000);
            }
        }
        setTahap('selesai');
    };

    const tutup = () => {
        if (tahap === 'kirim') return;
        onTutup(jumlah('terkirim') > 0);
    };

    const ikon = (h) => {
        if (!h) return <span className="f-light small">Menunggu</span>;
        if (h.status === 'kirim') return <Spinner size="sm" />;
        if (h.status === 'terkirim') return <CheckCircle size={16} className="text-success" />;
        if (h.status === 'gagal') return <XCircle size={16} className="text-danger" />;
        return <span className="f-light small">Dilewati</span>;
    };

    return (
        <Modal isOpen toggle={tutup} centered scrollable size="lg" backdrop={tahap === 'kirim' ? 'static' : true}>
            <ModalHeader toggle={tahap === 'kirim' ? undefined : tutup}>Ingatkan Tagihan via WhatsApp ({rows.length} pelanggan)</ModalHeader>
            <ModalBody>
                {tahap === 'siap' && (
                    <>
                        <p className="mb-2"><strong>{kirim.length}</strong> pelanggan akan diingatkan dari WhatsApp cabangnya masing-masing memakai template cabang.{lewat.length > 0 && <> <strong>{lewat.length}</strong> dilewati.</>}</p>
                        <div className="alert alert-light-warning d-flex gap-2 py-2">
                            <AlertTriangle size={16} className="flex-shrink-0 mt-1" />
                            <span>Pesan dikirim satu per satu dengan jeda 4–8 detik agar nomor cabang tidak diblokir WhatsApp (perkiraan ±{perkiraanMenit} menit). <strong>Jangan tutup halaman ini</strong> sampai selesai.</span>
                        </div>
                        <div className="form-check mb-3">
                            <Input type="checkbox" className="form-check-input" id="lewati-terkirim" checked={lewatiTerkirim} onChange={(e) => setLewatiTerkirim(e.target.checked)} />
                            <Label className="form-check-label" for="lewati-terkirim">Lewati pelanggan yang sudah dikirimi tagihan yang sama</Label>
                        </div>
                    </>
                )}
                {tahap !== 'siap' && (
                    <div className="mb-3">
                        <div className="d-flex justify-content-between small mb-1">
                            <span>{tahap === 'selesai' ? 'Selesai' : `Mengirim ${Math.min(selesaiKe + 1, kirim.length)} dari ${kirim.length}…`}</span>
                            <span><span className="text-success">{jumlah('terkirim')} terkirim</span> · <span className="text-danger">{jumlah('gagal')} gagal</span></span>
                        </div>
                        <Progress value={kirim.length ? (selesaiKe / kirim.length) * 100 : 100} color={tahap === 'selesai' ? 'success' : 'primary'} />
                    </div>
                )}
                <div className="ingatkan-daftar">
                    {kirim.map((p) => (
                        <div key={p.id} className="ingatkan-baris">
                            <div className="min-w-0">
                                <strong>{p.nama}</strong> <small className="f-light">{p.tlp} · {p.cabang} · {namaPeriode(p.bulan_wa)}</small>
                                {hasil[p.id]?.pesan && <div className={`small ${hasil[p.id].status === 'gagal' ? 'text-danger' : 'f-light'}`}>{hasil[p.id].pesan}</div>}
                            </div>
                            <div className="flex-shrink-0">{tahap === 'siap' ? <Bell size={14} className="f-light" /> : ikon(hasil[p.id])}</div>
                        </div>
                    ))}
                    {lewat.map(({ p, alasan }) => (
                        <div key={p.id} className="ingatkan-baris is-lewat">
                            <div className="min-w-0"><strong>{p.nama}</strong> <small className="f-light">{p.tlp || '-'}</small><div className="small f-light">{alasan}</div></div>
                            <span className="f-light small flex-shrink-0">Dilewati</span>
                        </div>
                    ))}
                </div>
            </ModalBody>
            <ModalFooter>
                {tahap === 'siap' && (
                    <>
                        <Button color="light" onClick={tutup}>Batal</Button>
                        <Button color="success" onClick={mulai} disabled={!kirim.length}><Bell size={14} className="me-1" />Kirim ke {kirim.length} Pelanggan</Button>
                    </>
                )}
                {tahap === 'kirim' && (
                    <Button color="danger" outline onClick={() => { berhenti.current = true; setMenghentikan(true); }} disabled={menghentikan}>
                        <Square size={13} className="me-1" />{menghentikan ? 'Menghentikan…' : 'Hentikan'}
                    </Button>
                )}
                {tahap === 'selesai' && <Button color="primary" onClick={tutup}>Tutup</Button>}
            </ModalFooter>
        </Modal>
    );
}
