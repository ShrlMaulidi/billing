import { useEffect, useState } from 'react';
import { AlertTriangle, Send } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from 'reactstrap';
import GelembungWa from './GelembungWa';
import { periode as namaPeriode, waktu } from '../lib/format';
import { api, notify } from '../lib/ui';

/**
 * Modal kirim tagihan via WhatsApp cabang (ikon lonceng di Pelanggan Belum Lunas): pratinjau pesan dari template cabang,
 * nomor tujuan, dan peringatan bila sudah pernah dikirim / WhatsApp cabang belum terhubung.
 */
export default function KirimTagihanWa({ pelanggan, periode, onTutup }) {
    const [data, setData] = useState(null);
    const [galat, setGalat] = useState(null);
    const [proses, setProses] = useState(false);
    const query = `bulan=${Number(periode.slice(5, 7))}&tahun=${Number(periode.slice(0, 4))}`;

    useEffect(() => {
        api('GET', `/pelanggan/${pelanggan.id}/tagihan-wa?${query}`).then(setData).catch((err) => setGalat(err.message));
    }, [pelanggan.id, query]);

    const kirim = async () => {
        setProses(true);
        try {
            notify((await api('POST', `/pelanggan/${pelanggan.id}/tagihan-wa`, { bulan: Number(periode.slice(5, 7)), tahun: Number(periode.slice(0, 4)) })).message);
            onTutup(true);
        } catch (err) {
            notify(err.message, 'danger', 8000);
            setProses(false);
        }
    };

    return (
        <Modal isOpen toggle={() => onTutup(false)} centered>
            <ModalHeader toggle={() => onTutup(false)}>Kirim Tagihan via WhatsApp</ModalHeader>
            <ModalBody>
                {galat && <div className="alert alert-light-danger mb-0">{galat}</div>}
                {!data && !galat && <div className="text-center py-4"><Spinner /></div>}
                {data && (
                    <>
                        <dl className="kirim-wa-info">
                            <dt>Pelanggan</dt><dd>{pelanggan.nama}</dd>
                            <dt>Tujuan</dt><dd>{data.nomor}</dd>
                            <dt>Tagihan</dt><dd>{namaPeriode(periode)}</dd>
                            <dt>Dikirim dari</dt><dd>WhatsApp {data.cabang}</dd>
                        </dl>
                        {!data.wa_terhubung && (
                            <div className="alert alert-light-warning d-flex gap-2 py-2"><AlertTriangle size={16} className="flex-shrink-0 mt-1" /><span>WhatsApp cabang {data.cabang} belum terhubung. Scan QR di menu <strong>Kelola Data › Notifikasi</strong> terlebih dahulu.</span></div>
                        )}
                        {data.terakhir && (
                            <div className="alert alert-light-primary py-2">Tagihan ini sudah dikirim {waktu(data.terakhir)}. Kirim lagi hanya bila perlu.</div>
                        )}
                        <GelembungWa teks={data.pesan} />
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={() => onTutup(false)}>Batal</Button>
                <Button color="success" onClick={kirim} disabled={!data || proses || !data.wa_terhubung}>
                    {proses ? <Spinner size="sm" className="me-1" /> : <Send size={14} className="me-1" />}{data?.terakhir ? 'Kirim Lagi' : 'Kirim'}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
