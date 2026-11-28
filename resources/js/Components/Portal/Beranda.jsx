import { Link } from '@inertiajs/react';
import { ChevronRight, Copy, FileText, MessageCircle, Wifi, WifiOff } from 'react-feather';
import TombolBayar, { sisaJatuhTempo } from './TombolBayar';
import { linkWa } from '../TagihanPelanggan';
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

/** Status internet saat ini menurut billing: On = Aktif, Off = Terisolir. */
function StatusInternet({ terisolir }) {
    return (
        <span className={`pm-status ${terisolir ? 'is-isolir' : 'is-aktif'}`}>
            {terisolir ? <WifiOff size={13} /> : <Wifi size={13} />} Internet {terisolir ? 'Terisolir' : 'Aktif'}
        </span>
    );
}

/**
 * Pesan untuk langganan yang terisolir: arahkan membayar tagihan bulan berjalan (tombol Bayar Sekarang ada tepat di bawahnya),
 * tunggakan lain di daftar bawah, atau hubungi CS bila tidak ada tagihan yang belum dibayar (mis. diisolir petugas).
 */
function PesanIsolir({ langganan: p, tagihanBulanIni, bulanIni }) {
    let isi;
    if (tagihanBulanIni) {
        isi = <>Bayar tagihan <strong>{periode(bulanIni)}</strong> sekarang. Internet aktif kembali otomatis setelah pembayaran tercatat.</>;
    } else if (p.tagihan.length) {
        isi = <>Masih ada tagihan yang belum dibayar. <a href="#semua-tagihan">Bayar tagihan</a> agar internet aktif kembali otomatis.</>;
    } else {
        isi = <>Tidak ada tagihan yang belum dibayar. <a href={linkWa(`Halo MSS, internet saya (ID ${p.id_pelanggan ?? '-'}) terisolir padahal tagihan sudah dibayar.`)} target="_blank" rel="noopener noreferrer"><MessageCircle size={13} /> Hubungi kami</a> agar segera diaktifkan.</>;
    }

    return (
        <div className="pm-isolir" role="alert">
            <WifiOff size={18} className="flex-shrink-0" />
            <span><strong className="d-block">Internet Anda sedang terisolir</strong>{isi}</span>
        </div>
    );
}

/**
 * Kartu tagihan bulan ini satu langganan (Home, HP & desktop): strip ID + paket, status internet, nominal & jatuh tempo,
 * Bayar Sekarang, lalu tautan detail. Langganan terisolir: strip merah + pesan ajakan membayar di atas tombol bayar.
 */
export function KartuBulanIni({ langganan: p, bulanIni, hariIni, onDetail, onManual }) {
    const t = p.tagihan.find((x) => x.periode_pembayaran === bulanIni);
    const lunas = p.lunas.find((x) => x.periode_pembayaran === bulanIni);
    const sisa = t && sisaJatuhTempo(t.jatuh_tempo, hariIni);

    return (
        <div className={`card pm-card ${p.terisolir ? 'is-isolir' : ''}`}>
            <div className="pm-card-strip">
                <button type="button" className="pm-chip" onClick={() => salinId(p.id_pelanggan)} aria-label={`Salin ID pelanggan ${p.id_pelanggan ?? ''}`}>
                    ID {p.id_pelanggan ?? '-'} <Copy size={13} />
                </button>
                <span className="pm-card-paket"><Wifi size={14} /> {p.paket ?? 'Langganan'}</span>
            </div>
            <div className="pm-card-body">
                <div className="pm-card-kepala">
                    <span className="pm-card-label">Tagihan {periode(bulanIni)}</span>
                    <StatusInternet terisolir={p.terisolir} />
                </div>
                {t && (
                    <>
                        <div className="pm-nominal">{rupiah(t.nominal)}</div>
                        {t.jatuh_tempo && <div className="pm-due">Jatuh tempo {tanggal(t.jatuh_tempo)} · <span className={`fw-semibold ${sisa.warna}`}>{sisa.teks}</span></div>}
                        {p.terisolir && <PesanIsolir langganan={p} tagihanBulanIni={t} bulanIni={bulanIni} />}
                        <div className="mt-3"><TombolBayar invoice={t} langganan={p} onManual={onManual} size="lg" block /></div>
                    </>
                )}
                {!t && lunas && (
                    <>
                        <div className="pm-nominal text-success">Lunas</div>
                        <div className="pm-due">{rupiah(lunas.jml_bayar)} · dibayar {tanggal(lunas.paid_at)} via {lunas.metode_label}</div>
                        {p.terisolir && <PesanIsolir langganan={p} bulanIni={bulanIni} />}
                    </>
                )}
                {!t && !lunas && (
                    <>
                        <div className="pm-nominal pm-redup">Rp 0</div>
                        <div className="pm-due">Belum ada tagihan untuk bulan ini</div>
                        {p.terisolir && <PesanIsolir langganan={p} bulanIni={bulanIni} />}
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
