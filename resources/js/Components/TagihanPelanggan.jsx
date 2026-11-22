import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle, CreditCard, FileText, Printer, RefreshCw } from 'react-feather';
import { periode, rupiah, tanggal } from '../lib/format';
import { api } from '../lib/ui';
import InvoiceTagihan from './InvoiceTagihan';
import PilihKanal from './PilihKanal';

// WhatsApp CS (0857-1839-2893) untuk layanan pelanggan: cek tagihan, bukti transfer, lupa sandi, dashboard pelanggan.
// Tombol marketing (konsultasi & pasang baru) di landing page memakai nomor marketing sendiri.
const WA_CS = '6285718392893';
const JENIS = { prabayar: 'Prabayar', pascabayar: 'Pascabayar' };

export const linkWa = (pesan) => `https://wa.me/${WA_CS}?text=${encodeURIComponent(pesan)}`;

/**
 * Buat (atau lanjutkan) pembayaran online satu invoice lalu arahkan browser ke halaman pembayaran payment gateway.
 * ganti = hentikan pembayaran yang menunggu dibayar agar bisa memilih metode lain. Nominal dihitung ulang di server.
 * Provider yang mewajibkan metode dipilih di billing (Tripay) mengembalikan { pilihKanal: [...] }; panggil ulang dengan kanal.
 * Gagal → melempar pesan yang siap ditampilkan.
 */
export async function bayarOnline(idPelanggan, periodeTagihan, ganti = false, kanal = null) {
    try {
        const res = await api('POST', '/cek-tagihan/bayar', { id_pelanggan: idPelanggan, periode: periodeTagihan, ganti, kanal });
        if (res.pilih_kanal) return { pilihKanal: res.pilih_kanal };
        if (!res.redirect_url) throw { message: 'Pembayaran online sedang tidak tersedia. Silakan coba lagi.' };
        window.location.assign(res.redirect_url);
        return {};
    } catch (err) {
        throw err.errors?.bayar?.[0] ?? err.message;
    }
}

/**
 * Tombol bayar pada invoice. Bila pembayaran online aktif untuk pelanggan ini (Midtrans, cabang non Admin Full),
 * server membuat transaksi lalu browser diarahkan ke halaman pembayaran Midtrans; nominal dihitung ulang di server.
 * Bila sudah ada pembayaran yang menunggu dibayar (mis. QRIS), pelanggan bisa melanjutkannya atau mengganti metode:
 * transaksi lama dihentikan server agar tidak terbayar dua kali. Selain itu menampilkan cara bayar manual.
 */
function TombolBayar({ invoice, pelanggan }) {
    const [info, setInfo] = useState(false);
    const [proses, setProses] = useState(null);
    const [galat, setGalat] = useState('');
    const [pilihan, setPilihan] = useState(null); // { ganti, kanal: [...] } saat provider meminta metode dipilih (Tripay)
    const berjalan = pelanggan.bayar_online ? invoice.online_berjalan : null;
    const metodeLama = berjalan?.metode ?? 'sebelumnya';

    const bayar = async (ganti = false, kanal = null) => {
        if (!pelanggan.bayar_online) {
            setInfo(true);
            return;
        }
        if (ganti && !kanal && !window.confirm(`Pembayaran ${metodeLama} akan dibatalkan dan tidak bisa dipakai lagi. Lanjut memilih metode pembayaran lain?`)) return;
        setProses(ganti ? 'ganti' : 'bayar');
        setGalat('');
        try {
            const hasil = await bayarOnline(pelanggan.id_pelanggan, invoice.periode, ganti, kanal);
            if (hasil.pilihKanal) {
                setPilihan({ ganti, kanal: hasil.pilihKanal });
                setProses(null);
            }
        } catch (pesan) {
            setGalat(pesan);
            setProses(null);
        }
    };

    const label = proses === 'bayar' ? 'Menyiapkan pembayaran…'
        : berjalan ? `Lanjutkan Pembayaran${berjalan.metode ? ` (${berjalan.metode})` : ''}`
            : pelanggan.bayar_online ? 'Bayar Online' : 'Bayar di sini';

    return (
        <>
            <PilihKanal kanal={pilihan?.kanal} onTutup={() => setPilihan(null)} onPilih={(kode) => { const { ganti } = pilihan; setPilihan(null); bayar(ganti, kode); }} />
            <button type="button" className="lp-btn lp-btn-gold ct-bayar" onClick={() => bayar()} disabled={!!proses}>
                <CreditCard size={18} /> {label}
            </button>
            {berjalan && (
                <button type="button" className="ct-ganti-metode" onClick={() => bayar(true)} disabled={!!proses}>
                    <RefreshCw size={14} /> {proses === 'ganti' ? 'Membatalkan pembayaran sebelumnya…' : 'Ganti metode pembayaran'}
                </button>
            )}
            {pelanggan.bayar_online && !galat && (
                <p className="ct-bayar-catatan">
                    {berjalan ? `Pembayaran ${metodeLama} sudah dibuat dan menunggu dibayar. Ingin pakai VA, QRIS, atau e-wallet lain? Pilih Ganti metode pembayaran.`
                        : 'Transfer bank (VA), QRIS, atau e-wallet lewat Midtrans. Status lunas otomatis.'}
                </p>
            )}
            {galat && <div className="ct-bayar-info ct-bayar-galat" role="alert">{galat}</div>}
            {info && (
                <div className="ct-bayar-info" role="status">
                    <strong>Bayar lewat transfer atau tunai.</strong> Silakan transfer ke rekening di bagian Info Pembayaran atau bayar tunai di kantor
                    {pelanggan.cabang?.nama_lokasi ? ` ${pelanggan.cabang.nama_lokasi}` : ' cabang'}, lalu{' '}
                    <a href={linkWa(`Halo MSS, saya sudah membayar invoice ${invoice.no_tagihan} (ID ${pelanggan.id_pelanggan ?? '-'}) sebesar ${rupiah(invoice.nominal)}. Berikut bukti transfernya.`)}
                        target="_blank" rel="noopener noreferrer">kirim bukti via WhatsApp</a>.
                </div>
            )}
        </>
    );
}

/** Riwayat periode yang sudah lunas (cash, transfer, atau Midtrans): tidak bisa dibayar lagi, kwitansi bisa dibuka. */
function RiwayatLunas({ lunas }) {
    return (
        <div className="ct-riwayat no-print">
            <h3>Riwayat Pembayaran</h3>
            <ul>
                {lunas.map((b) => (
                    <li key={b.no_invoice ?? b.periode}>
                        <div className="ct-riwayat-info">
                            <strong>Periode {periode(b.periode_pembayaran)}</strong>
                            <span><span className="ct-badge-lunas"><CheckCircle size={13} /> Lunas</span> {rupiah(b.jml_bayar)} · via {b.metode_label}</span>
                            <small>Dibayar {tanggal(b.paid_at)}{b.no_invoice ? ` · No. ${b.no_invoice}` : ''}</small>
                        </div>
                        <Link href={b.link_kwitansi} className="ct-print"><FileText size={16} /> Lihat Kwitansi</Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Ringkasan satu pelanggan + invoice tiap bulan yang belum dibayar + riwayat lunas. Dipakai Cek Tagihan publik
 * (nama disamarkan server) dan halaman Akun Saya pelanggan.
 */
export function HasilPelanggan({ pelanggan: p }) {
    const total = p.tagihan.reduce((s, t) => s + (t.nominal ?? 0), 0);

    return (
        <section className="ct-result">
            <header className="ct-summary">
                <div>
                    <h2>{p.nama}</h2>
                    <p>ID {p.id_pelanggan ?? '-'} · {p.paket ?? '-'} · {JENIS[p.jns_tagihan] ?? 'Prabayar'}{p.cabang?.nama_lokasi ? ` · ${p.cabang.nama_lokasi}` : ''}</p>
                </div>
                <div className={`ct-status ${p.tagihan.length ? 'is-due' : 'is-paid'}`}>{p.tagihan.length ? 'Belum lunas' : 'Lunas'}</div>
            </header>

            {p.tagihan.length ? (
                <>
                    <div className="ct-summary-bar no-print">
                        <p>{p.tagihan.length} invoice belum dibayar · total <strong>{rupiah(total)}</strong></p>
                        <button type="button" className="ct-print" onClick={() => window.print()}><Printer size={16} /> Cetak / Simpan PDF</button>
                    </div>
                    {p.tagihan.map((t) => (
                        <InvoiceTagihan key={t.no_tagihan}
                            tagihan={{ ...t, nama: p.nama, id_pelanggan: p.id_pelanggan, jns_tagihan: p.jns_tagihan, cabang: p.cabang, rekening: p.rekening, pembayaran_id: null }}
                            aksi={<TombolBayar invoice={t} pelanggan={p} />} />
                    ))}
                </>
            ) : (
                <p className="ct-paid">Tidak ada tagihan yang belum dibayar. Terima kasih telah membayar tepat waktu.</p>
            )}

            {p.lunas.length > 0 && <RiwayatLunas lunas={p.lunas} />}
        </section>
    );
}
