import { useState } from 'react';
import { CreditCard, RefreshCw } from 'react-feather';
import { Button, Spinner } from 'reactstrap';
import PilihKanal from '../PilihKanal';
import { bayarOnline } from '../TagihanPelanggan';

/**
 * Tombol Bayar Sekarang satu invoice. Cabang dengan pembayaran online: langsung ke halaman payment gateway (Tripay: pilih metode dulu)
 * (atau lanjutkan pembayaran yang menunggu dibayar, dengan opsi ganti metode). Selain itu tampilkan cara bayar manual.
 */
export default function TombolBayar({ invoice, langganan, onManual, block = false, size }) {
    const [proses, setProses] = useState(null);
    const [galat, setGalat] = useState('');
    const [pilihan, setPilihan] = useState(null); // { ganti, kanal: [...] } saat provider meminta metode dipilih (Tripay)
    const berjalan = langganan.bayar_online ? invoice.online_berjalan : null;

    const bayar = async (ganti = false, kanal = null) => {
        if (!langganan.bayar_online) {
            onManual(invoice, langganan);
            return;
        }
        if (ganti && !kanal && !window.confirm(`Pembayaran ${berjalan?.metode ?? 'sebelumnya'} akan dibatalkan dan tidak bisa dipakai lagi. Lanjut memilih metode pembayaran lain?`)) return;
        setProses(ganti ? 'ganti' : 'bayar');
        setGalat('');
        try {
            const hasil = await bayarOnline(langganan.id_pelanggan, invoice.periode, ganti, kanal);
            if (hasil.pilihKanal) {
                setPilihan({ ganti, kanal: hasil.pilihKanal });
                setProses(null);
            }
        } catch (pesan) {
            setGalat(pesan);
            setProses(null);
        }
    };

    return (
        <div className={block ? 'd-grid gap-1' : 'd-inline-flex flex-column align-items-end gap-1'}>
            <PilihKanal kanal={pilihan?.kanal} onTutup={() => setPilihan(null)} onPilih={(kode) => { const { ganti } = pilihan; setPilihan(null); bayar(ganti, kode); }} />
            <Button color="primary" size={size} onClick={() => bayar()} disabled={!!proses}>
                {proses === 'bayar' ? <Spinner size="sm" className="me-1" /> : <CreditCard size={15} className="me-1" />}
                {proses === 'bayar' ? 'Menyiapkan…' : berjalan ? `Lanjutkan Pembayaran${berjalan.metode ? ` (${berjalan.metode})` : ''}` : 'Bayar Sekarang'}
            </Button>
            {berjalan && (
                <button type="button" className="btn btn-link btn-sm p-0" onClick={() => bayar(true)} disabled={!!proses}>
                    <RefreshCw size={12} className="me-1" />{proses === 'ganti' ? 'Membatalkan…' : 'Ganti metode pembayaran'}
                </button>
            )}
            {galat && <small className="text-danger" role="alert">{galat}</small>}
        </div>
    );
}

/** Selisih hari dari hari ini ke jatuh tempo (tanggal Y-m-d dari server), sebagai teks & warna. */
export function sisaJatuhTempo(jatuhTempo, hariIni) {
    if (!jatuhTempo) return null;
    const hari = Math.round((Date.parse(jatuhTempo) - Date.parse(hariIni)) / 86400000);
    if (hari > 0) return { teks: `${hari} hari lagi`, warna: hari <= 3 ? 'text-warning' : 'f-light' };
    if (hari === 0) return { teks: 'jatuh tempo hari ini', warna: 'text-danger' };
    return { teks: `lewat ${-hari} hari`, warna: 'text-danger' };
}
