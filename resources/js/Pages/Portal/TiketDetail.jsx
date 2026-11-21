import { Card, CardBody } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import PercakapanTiket from '../../Components/PercakapanTiket';
import { StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Detail tiket milik pelanggan: info & percakapan dengan petugas. */
export default function TiketDetail({ tiket, pesan }) {
    return (
        <>
            <PageHeader title={tiket.no_tiket} home="/akun-saya" crumbs={[{ label: 'Tiket', href: '/akun-saya/tiket' }]} />
            <div className="container-fluid">
                <Card>
                    <CardBody className="d-flex flex-wrap justify-content-between gap-3">
                        <div>
                            <h5 className="mb-1">{tiket.judul}</h5>
                            <span className="f-light">{tiket.kategori_label}{tiket.pelanggan ? ` · ${tiket.pelanggan.id_pelanggan} ${tiket.pelanggan.nama}` : ''} · dibuat {waktu(tiket.created_at)}</span>
                        </div>
                        <div><StatusBadge value={tiket.status} /></div>
                    </CardBody>
                </Card>
                <PercakapanTiket pesan={pesan} sisi="pelanggan" kirimKe={`/akun-saya/tiket/${tiket.id}/pesan`} bolehBalas={tiket.status !== 'selesai'}
                    alasanTutup="Tiket ini sudah selesai. Bila masih ada kendala, silakan buat tiket baru." />
            </div>
        </>
    );
}

TiketDetail.layout = portalLayout;
