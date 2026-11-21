import { useState } from 'react';
import { Card, CardBody, CardHeader } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import PercakapanTiket from '../../Components/PercakapanTiket';
import Select from '../../Components/Select';
import { StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, notify, reloadData, useBisa } from '../../lib/ui';

/** Detail tiket untuk staf: data pelanggan, ubah status, dan balas percakapan. */
export default function TiketDetail({ tiket, pesan, statusOptions }) {
    const bisa = useBisa();
    const bolehUbah = bisa('tiket.update');
    const [proses, setProses] = useState(false);

    const ubahStatus = async (status) => {
        if (status === tiket.status) return;
        setProses(true);
        try {
            notify((await api('PUT', `/tiket/${tiket.id}/status`, { status })).message);
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            setProses(false);
            reloadData(['tiket']);
        }
    };

    return (
        <>
            <PageHeader title={tiket.no_tiket} crumbs={[{ label: 'Tiket', href: '/tiket' }]} />
            <div className="container-fluid">
                <div className="row">
                    <div className="col-xl-4">
                        <Card>
                            <CardHeader className="pb-0"><h5 className="mb-0">{tiket.judul}</h5></CardHeader>
                            <CardBody>
                                <dl className="portal-dl mb-3">
                                    <dt>Kategori</dt><dd>{tiket.kategori_label}</dd>
                                    <dt>Pelanggan</dt><dd>{tiket.pelanggan ? `${tiket.pelanggan.nama} (${tiket.pelanggan.id_pelanggan ?? '-'})` : '-'}</dd>
                                    <dt>Telepon</dt><dd>{tiket.tlp ?? '-'}</dd>
                                    <dt>Alamat</dt><dd>{tiket.alamat ?? '-'}</dd>
                                    <dt>Cabang</dt><dd>{tiket.cabang ?? '-'}</dd>
                                    <dt>Dibuat</dt><dd>{waktu(tiket.created_at)}</dd>
                                </dl>
                                <label htmlFor="status-tiket" className="form-label">Status</label>
                                {bolehUbah
                                    ? <Select id="status-tiket" value={tiket.status} onChange={ubahStatus} options={statusOptions} disabled={proses} />
                                    : <div><StatusBadge value={tiket.status} /></div>}
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-8">
                        <PercakapanTiket pesan={pesan} sisi="staf" kirimKe={`/tiket/${tiket.id}/pesan`} bolehBalas={bolehUbah}
                            alasanTutup="Anda hanya bisa melihat tiket ini." />
                    </div>
                </div>
            </div>
        </>
    );
}
