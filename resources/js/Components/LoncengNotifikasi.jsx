import { useEffect, useRef, useState } from 'react';
import { Bell } from 'react-feather';

/**
 * Lonceng notifikasi di header (dashboard pelanggan). Untuk sekarang hanya kerangka: panel berisi keadaan kosong, siap diisi
 * daftar notifikasi (tagihan terbit, pembayaran diterima, balasan tiket) dan angka belum dibaca lewat prop `jumlah`.
 * Dibuka dengan klik/ketuk (bukan hover) agar nyaman di HP; tertutup saat klik di luar panel atau menekan Esc.
 */
export default function LoncengNotifikasi({ jumlah = 0 }) {
    const [buka, setBuka] = useState(false);
    const wadah = useRef(null);

    useEffect(() => {
        if (!buka) return undefined;
        const klikLuar = (e) => !wadah.current?.contains(e.target) && setBuka(false);
        const tombolEsc = (e) => e.key === 'Escape' && setBuka(false);
        document.addEventListener('pointerdown', klikLuar);
        document.addEventListener('keydown', tombolEsc);
        return () => {
            document.removeEventListener('pointerdown', klikLuar);
            document.removeEventListener('keydown', tombolEsc);
        };
    }, [buka]);

    return (
        <div className="notif-lonceng" ref={wadah}>
            <button type="button" className="mode notif-tombol" onClick={() => setBuka((b) => !b)} aria-expanded={buka} aria-haspopup="dialog"
                aria-label={jumlah > 0 ? `Notifikasi, ${jumlah} belum dibaca` : 'Notifikasi'} title="Notifikasi">
                <Bell />
                {jumlah > 0 && <span className="notif-angka">{jumlah > 9 ? '9+' : jumlah}</span>}
            </button>
            {buka && (
                <div className="notif-pop" role="dialog" aria-label="Notifikasi">
                    <div className="notif-pop-judul">Notifikasi</div>
                    <div className="notif-kosong">
                        <span className="notif-kosong-ikon"><Bell size={22} /></span>
                        <strong>Belum ada notifikasi</strong>
                        <p>Info tagihan, pembayaran, dan balasan tiket akan muncul di sini.</p>
                    </div>
                </div>
            )}
        </div>
    );
}
