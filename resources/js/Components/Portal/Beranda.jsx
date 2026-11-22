import { Link } from '@inertiajs/react';
import { ChevronRight, Copy, FileText, Wifi } from 'react-feather';
import TombolBayar, { sisaJatuhTempo } from './TombolBayar';
import { periode, rupiah, tanggal } from '../../lib/format';
import { notify } from '../../lib/ui';

/** Salin ID pelanggan (mis. untuk konfirmasi ke CS); browser lama tanpa clipboard API cukup diberi tahu ID-nya. */
const salinId = (id) => {
    if (!id) return;
    if (!navigator.clipboard) {
        notify(`ID pelanggan: ${id}`);
        return;
    }
    navigator.clipboard.writeText(id).then(() => notify('ID pelanggan disalin'), () => notify(`ID pelanggan: ${id}`));
};

/** Kartu tagihan bulan ini satu langganan (Home, HP & desktop): strip ID + paket, nominal & jatuh tempo, Bayar Sekarang, lalu tautan detail. */
export function KartuBulanIni({ langganan: p, bulanIni, hariIni, onDetail, onManual }) {
    const t = p.tagihan.find((x) => x.periode_pembayaran === bulanIni);
    const lunas = p.lunas.find((x) => x.periode_pembayaran === bulanIni);
    const sisa = t && sisaJatuhTempo(t.jatuh_tempo, hariIni);

    return (
        <div className="card pm-card">
            <div className="pm-card-strip">
                <button type="button" className="pm-chip" onClick={() => salinId(p.id_pelanggan)} aria-label={`Salin ID pelanggan ${p.id_pelanggan ?? ''}`}>
                    ID {p.id_pelanggan ?? '-'} <Copy size={13} />
                </button>
                <span className="pm-card-paket"><Wifi size={14} /> {p.paket ?? 'Langganan'}</span>
            </div>
            <div className="pm-card-body">
                <span className="pm-card-label">Tagihan {periode(bulanIni)}</span>
                {t && (
                    <>
                        <div className="pm-nominal">{rupiah(t.nominal)}</div>
                        {t.jatuh_tempo && <div className="pm-due">Jatuh tempo {tanggal(t.jatuh_tempo)} · <span className={`fw-semibold ${sisa.warna}`}>{sisa.teks}</span></div>}
                        <div className="mt-3"><TombolBayar invoice={t} langganan={p} onManual={onManual} size="lg" block /></div>
                    </>
                )}
                {!t && lunas && (
                    <>
                        <div className="pm-nominal text-success">Lunas</div>
                        <div className="pm-due">{rupiah(lunas.jml_bayar)} · dibayar {tanggal(lunas.paid_at)} via {lunas.metode_label}</div>
                    </>
                )}
                {!t && !lunas && (
                    <>
                        <div className="pm-nominal pm-redup">Rp 0</div>
                        <div className="pm-due">Belum ada tagihan untuk bulan ini</div>
                    </>
                )}
            </div>
            {t && <button type="button" className="pm-card-foot" onClick={() => onDetail(t, p)}><FileText size={17} /><span>Lihat Detail Tagihan</span><ChevronRight size={17} /></button>}
            {!t && lunas && <Link href={`/akun-saya/riwayat/${lunas.id}/kuitansi`} className="pm-card-foot"><FileText size={17} /><span>Lihat Kuitansi</span><ChevronRight size={17} /></Link>}
            {!t && !lunas && <Link href="/akun-saya/riwayat" className="pm-card-foot"><FileText size={17} /><span>Riwayat Tagihan</span><ChevronRight size={17} /></Link>}
        </div>
    );
}

/** Satu tombol menu utama: ikon dalam kotak bulat + label; href internal, url eksternal, atau onClick. */
export function MenuItem({ icon: IconCmp, label, href, url, onClick, badge }) {
    const isi = (
        <>
            <span className="pm-menu-icon"><IconCmp size={22} />{badge > 0 && <span className="pm-menu-badge">{badge}</span>}</span>
            <span className="pm-menu-label">{label}</span>
        </>
    );
    if (url) return <a href={url} target="_blank" rel="noopener noreferrer" className="pm-menu-item">{isi}</a>;
    if (href) return <Link href={href} className="pm-menu-item">{isi}</Link>;
    return <button type="button" className="pm-menu-item" onClick={onClick}>{isi}</button>;
}
