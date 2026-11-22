import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, CheckCircle, ExternalLink } from 'react-feather';
import { Button, Card, CardBody, Spinner } from 'reactstrap';
import { waktu } from '../lib/format';
import { api, notify, reloadData } from '../lib/ui';

/**
 * Penanda di Dashboard Super Admin: perubahan payment gateway (gateway baru, kunci diganti, Utama baru, status/mode) yang belum
 * diperiksa. Perubahan yang memindahkan arah uang ditandai merah. Hilang setelah "Tandai sudah diperiksa" sampai ada perubahan baru.
 */
export default function PerubahanGateway({ data }) {
    const [proses, setProses] = useState(false);
    if (!data?.baru) return null;

    const tandai = async () => {
        setProses(true);
        try {
            notify((await api('POST', '/dashboard/perubahan-gateway/diperiksa')).message);
            reloadData(['perubahanGateway']);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            setProses(false);
        }
    };

    return (
        <Card className="perubahan-gateway mb-4">
            <CardBody>
                <div className="d-flex flex-wrap align-items-start gap-3 mb-3">
                    <span className="perubahan-gateway-ikon"><AlertTriangle size={20} /></span>
                    <div className="flex-grow-1 min-w-0">
                        <h5 className="mb-1">{data.baru} perubahan payment gateway belum diperiksa</h5>
                        <p className="mb-0 f-light small">
                            Payment gateway menentukan ke rekening mana uang pelanggan masuk. Pastikan setiap perubahan di bawah memang dilakukan oleh orang yang berwenang.
                            Bila ada yang tidak dikenal, segera ganti Server Key di dashboard Midtrans dan periksa akun pelakunya.
                        </p>
                    </div>
                </div>
                <ul className="perubahan-gateway-daftar">
                    {data.daftar.map((d) => (
                        <li key={d.id} className={d.penting ? 'is-penting' : ''}>
                            <div className="d-flex flex-wrap justify-content-between gap-2">
                                <strong>{d.deskripsi}</strong>
                                <small className="f-light text-nowrap">{waktu(d.waktu)}</small>
                            </div>
                            <div className="small">{d.poin.join(' · ')}</div>
                            <small className="f-light">oleh {d.pelaku}{d.role ? ` (${d.role})` : ''}{d.ip ? ` · IP ${d.ip}` : ''}</small>
                        </li>
                    ))}
                </ul>
                {data.baru > data.daftar.length && <p className="small f-light mb-2">Menampilkan {data.daftar.length} dari {data.baru} perubahan terbaru.</p>}
                <div className="d-flex flex-wrap gap-2 justify-content-end">
                    <Link href={`/logs?modul=${encodeURIComponent('Payment Gateway')}`} className="btn btn-light"><ExternalLink size={14} className="me-1" />Lihat di Logs</Link>
                    <Button color="primary" onClick={tandai} disabled={proses}>{proses ? <Spinner size="sm" className="me-1" /> : <CheckCircle size={14} className="me-1" />}Tandai sudah diperiksa</Button>
                </div>
            </CardBody>
        </Card>
    );
}
