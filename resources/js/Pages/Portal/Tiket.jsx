import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Eye, Plus } from 'react-feather';
import { Button, Card, CardBody } from 'reactstrap';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import { ActionButton, FormModal, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify } from '../../lib/ui';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Tiket keluhan / permintaan pelanggan: daftar tiket milik sendiri + buat tiket baru. */
export default function Tiket({ rows, langganan, kategori }) {
    const [buat, setBuat] = useState(false);

    const kirim = async (values) => {
        const res = await api('POST', '/akun-saya/tiket', values);
        notify(res.message, 'success', 6000);
        router.visit(`/akun-saya/tiket/${res.id}`);
    };

    return (
        <>
            <PageHeader title="Tiket" home="/akun-saya" />
            <div className="container-fluid">
                <div className="alert alert-light-primary">Ada gangguan internet, pertanyaan tagihan, atau ingin ganti paket? Buat tiket, petugas kami akan menanggapi di sini.</div>
                <Card>
                    <CardBody>
                        <DataTable
                            data={rows} exportable={false} emptyText="Belum ada tiket"
                            toolbar={langganan.length > 0 && <Button color="primary" onClick={() => setBuat(true)}><Plus size={14} className="me-1" />Buat Tiket</Button>}
                            onRowClick={(r) => router.visit(`/akun-saya/tiket/${r.id}`)}
                            actions={(r) => <ActionButton icon={Eye} label="Lihat" href={`/akun-saya/tiket/${r.id}`} />}
                            columns={[
                                { key: 'no_tiket', label: 'No. Tiket', render: (r) => <span className="fw-semibold">{r.no_tiket}</span> },
                                { key: 'judul', label: 'Judul', render: (r) => <>{r.judul}<br /><small className="f-light">{r.kategori_label}</small></> },
                                { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                                { key: 'updated_at', label: 'Update Terakhir', render: (r) => waktu(r.updated_at) },
                            ]}
                        />
                    </CardBody>
                </Card>
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
