import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronRight, LifeBuoy, Plus } from 'react-feather';
import { Button } from 'reactstrap';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { FormModal, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify } from '../../lib/ui';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Tiket keluhan / permintaan pelanggan: daftar tiket milik sendiri + buat tiket baru. */
export default function Tiket({ rows, langganan, kategori }) {
    // ?buat=1 (menu "Lapor Gangguan" di Home) langsung membuka form buat tiket
    const [buat, setBuat] = useState(() => langganan.length > 0 && new URLSearchParams(window.location.search).get('buat') === '1');

    const kirim = async (values) => {
        const res = await api('POST', '/akun-saya/tiket', values);
        notify(res.message, 'success', 6000);
        router.visit(`/akun-saya/tiket/${res.id}`);
    };

    return (
        <>
            <PitaPortal judul="Tiket" keterangan="Laporkan gangguan atau ajukan pertanyaan">
                {langganan.length > 0 && <button type="button" className="pm-band-tombol" onClick={() => setBuat(true)}><Plus size={15} />Buat Tiket</button>}
            </PitaPortal>
            <div className="container-fluid">
                <div className="pm-wrap pm-wrap-sempit pm-isi">
                    {rows.length ? (
                        <div className="card pm-card pm-list">
                            {rows.map((r) => (
                                <Link key={r.id} href={`/akun-saya/tiket/${r.id}`} className="pm-list-item">
                                    <span className="pm-info-icon"><LifeBuoy size={18} /></span>
                                    <div className="pm-list-isi">
                                        <div className="d-flex justify-content-between align-items-start gap-2">
                                            <strong>{r.judul}</strong>
                                            <StatusBadge value={r.status} />
                                        </div>
                                        <small>{r.no_tiket} · {r.kategori_label}</small>
                                        <small>Update {waktu(r.updated_at)}</small>
                                    </div>
                                    <ChevronRight size={16} className="pm-list-panah" />
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <div className="card pm-card">
                            <div className="pm-card-body text-center">
                                <span className="pm-info-icon mx-auto mb-2"><LifeBuoy size={20} /></span>
                                <strong>Belum ada tiket</strong>
                                <div className="pm-due mb-3">Ada gangguan internet, pertanyaan tagihan, atau ingin ganti paket? Petugas kami siap membantu.</div>
                                {langganan.length > 0 && <Button color="primary" onClick={() => setBuat(true)}><Plus size={14} className="me-1" />Buat Tiket</Button>}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <FormModal isOpen={buat} toggle={() => setBuat(false)} title="Buat Tiket" submitLabel="Kirim Tiket" size="md"
                initial={{ pelanggan_id: langganan.length === 1 ? String(langganan[0].value) : '', kategori: 'gangguan' }} onSubmit={kirim}
                fields={[
                    { name: 'pelanggan_id', label: 'Langganan', type: 'select', required: true, options: langganan, show: () => langganan.length > 1, col: 'col-12' },
                    { name: 'kategori', label: 'Kategori', type: 'select', required: true, options: kategori, col: 'col-12' },
                    { name: 'judul', label: 'Judul', required: true, placeholder: 'Contoh: Internet mati sejak pagi', col: 'col-12' },
                    { name: 'pesan', label: 'Ceritakan kendala Anda', type: 'textarea', rows: 5, required: true, col: 'col-12', help: 'Maksimal 2.000 karakter' },
                ]} />
        </>
    );
}

Tiket.layout = portalLayout;
