import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'react-feather';
import { Card, CardBody } from 'reactstrap';
import PercakapanTiket from '../../Components/PercakapanTiket';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Detail tiket milik pelanggan: info & percakapan dengan petugas. */
export default function TiketDetail({ tiket, pesan }) {
    return (
        <>
            <PitaPortal judul={tiket.no_tiket} keterangan={tiket.kategori_label}>
                <Link href="/akun-saya/tiket" className="pm-band-tombol is-transparan"><ArrowLeft size={15} />Tiket</Link>
            </PitaPortal>
            <div className="container-fluid">
                <div className="pm-wrap pm-wrap-sempit pm-isi">
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
            </div>
        </>
    );
}

TiketDetail.layout = portalLayout;
