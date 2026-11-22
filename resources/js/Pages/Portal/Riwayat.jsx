import { Link } from '@inertiajs/react';
import { CheckCircle, CheckSquare, FileText } from 'react-feather';
import PitaPortal from '../../Components/Portal/PitaPortal';
import { periode, rupiah, tanggal } from '../../lib/format';
import { portalLayout } from '../../Layouts/PortalLayout';

/** Daftar pembayaran berbentuk kartu, dikelompokkan per tahun, dengan tombol Invoice & Kuitansi. */
function DaftarRiwayat({ rows, jumlahLangganan }) {
    if (!rows.length) {
        return <div className="card pm-card"><div className="pm-card-body text-center"><span className="pm-card-label">Belum ada pembayaran</span></div></div>;
    }

    return (
        <div className="card pm-card pm-list">
            {rows.map((r, i) => {
                const tahun = r.periode_pembayaran?.slice(0, 4);
                return [
                    tahun !== rows[i - 1]?.periode_pembayaran?.slice(0, 4) && <div key={`th-${tahun}`} className="pm-list-tahun">{tahun}</div>,
                    <div key={r.id} className="pm-list-item">
                        <span className="pm-info-icon is-sukses"><CheckCircle size={18} /></span>
                        <div className="pm-list-isi">
                            <div className="d-flex justify-content-between gap-2">
                                <strong>{periode(r.periode_pembayaran)}</strong>
                                <span className="pm-list-nominal">{rupiah(r.jml_bayar)}</span>
                            </div>
                            <small>{r.nama_paket ?? '-'} · {r.metode_label}</small>
                            <small>Dibayar {tanggal(r.paid_at)}{jumlahLangganan > 1 && r.pelanggan ? ` · ID ${r.pelanggan.id_pelanggan}` : ''}</small>
                            <div className="pm-list-aksi">
                                <Link href={`/akun-saya/riwayat/${r.id}/invoice`} className="pm-pill"><FileText size={13} />Invoice</Link>
                                <Link href={`/akun-saya/riwayat/${r.id}/kuitansi`} className="pm-pill is-hijau"><CheckSquare size={13} />Kuitansi</Link>
                            </div>
                        </div>
                    </div>,
                ];
            })}
        </div>
    );
}

/** Riwayat tagihan yang sudah lunas (cash, transfer, atau online); tiap periode bisa dibuka invoice & kuitansinya. */
export default function Riwayat({ rows, jumlahLangganan }) {
    return (
        <>
            <PitaPortal judul="Riwayat Tagihan" keterangan={rows.length ? `${rows.length} pembayaran lunas` : 'Pembayaran & kuitansi'} />
            <div className="container-fluid">
                <div className="pm-wrap pm-wrap-sempit pm-isi"><DaftarRiwayat rows={rows} jumlahLangganan={jumlahLangganan} /></div>
            </div>
        </>
    );
}

Riwayat.layout = portalLayout;
