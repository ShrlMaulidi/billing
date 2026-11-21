import { useState } from 'react';
import { Send } from 'react-feather';
import { Button, Card, CardBody, CardHeader, FormFeedback, Input, Spinner } from 'reactstrap';
import { waktu } from '../lib/format';
import { api, notify, reloadData } from '../lib/ui';

/**
 * Percakapan satu tiket (dashboard pelanggan & menu staf Tiket). Pesan pihak sendiri tampil di kanan.
 * sisi: 'pelanggan' | 'staf' · kirimKe: URL POST balasan · bolehBalas: false bila tiket sudah selesai.
 */
export default function PercakapanTiket({ pesan, sisi, kirimKe, bolehBalas = true, alasanTutup }) {
    const [teks, setTeks] = useState('');
    const [galat, setGalat] = useState('');
    const [proses, setProses] = useState(false);

    const kirim = async (e) => {
        e.preventDefault();
        if (!teks.trim()) {
            setGalat('Pesan wajib diisi');
            return;
        }
        setProses(true);
        setGalat('');
        try {
            notify((await api('POST', kirimKe, { pesan: teks })).message);
            setTeks('');
            reloadData(['pesan', 'tiket']);
        } catch (err) {
            setGalat(err.errors?.pesan?.[0] ?? err.message);
        } finally {
            setProses(false);
        }
    };

    return (
        <Card>
            <CardHeader className="pb-0"><h5 className="mb-0">Percakapan</h5></CardHeader>
            <CardBody>
                <div className="tiket-chat">
                    {pesan.map((p) => {
                        const milikSendiri = (sisi === 'pelanggan') === p.dari_pelanggan;
                        return (
                            <div key={p.id} className={`tiket-bubble ${milikSendiri ? 'is-mine' : ''}`}>
                                <div className="tiket-bubble-head"><strong>{p.pengirim}</strong><small>{waktu(p.created_at)}</small></div>
                                {/* Teks apa adanya (React meng-escape HTML); baris baru dipertahankan lewat CSS */}
                                <div className="tiket-bubble-body">{p.pesan}</div>
                            </div>
                        );
                    })}
                </div>

                {bolehBalas ? (
                    <form onSubmit={kirim} className="mt-3" noValidate>
                        <label htmlFor="balas-tiket" className="form-label">Tulis balasan</label>
                        <Input id="balas-tiket" type="textarea" rows={3} maxLength={2000} value={teks} invalid={!!galat} onChange={(e) => setTeks(e.target.value)}
                            placeholder={sisi === 'pelanggan' ? 'Tambahkan keterangan untuk petugas…' : 'Balasan untuk pelanggan…'} />
                        {galat && <FormFeedback className="d-block">{galat}</FormFeedback>}
                        <div className="d-flex justify-content-end mt-2">
                            <Button color="primary" type="submit" disabled={proses}>{proses ? <Spinner size="sm" className="me-1" /> : <Send size={14} className="me-1" />}Kirim</Button>
                        </div>
                    </form>
                ) : (
                    <div className="alert alert-light-secondary mt-3 mb-0">{alasanTutup}</div>
                )}
            </CardBody>
        </Card>
    );
}
