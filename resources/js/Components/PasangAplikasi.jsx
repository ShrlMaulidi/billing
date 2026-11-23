import { useEffect, useState } from 'react';
import { Download, Share, X } from 'react-feather';

const KUNCI_TUTUP = 'pasangAplikasiDitutup';

const sudahTerpasang = () => window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
const pernahDitutup = () => {
    try { return localStorage.getItem(KUNCI_TUTUP) === '1'; } catch (e) { return false; }
};
// iPhone/iPad (termasuk iPadOS yang mengaku Mac) tidak punya tombol instal otomatis: harus lewat menu Bagikan Safari
const perangkatIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const perangkatHp = () => /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent) || perangkatIos();

/**
 * Banner ajakan memasang aplikasi (PWA) setelah login, di dashboard staf & pelanggan. Tetap tampil di setiap halaman sampai
 * ditutup (X) atau aplikasi terpasang. "Pasang" memakai dialog instal bawaan browser (Chrome/Edge di HP & komputer); di iPhone
 * dan browser tanpa dialog instal, ditampilkan langkah manualnya.
 */
export default function PasangAplikasi() {
    const [tampil, setTampil] = useState(() => !sudahTerpasang() && !pernahDitutup());
    const [tawaran, setTawaran] = useState(() => window.__tawaranPasang ?? null);
    const [panduan, setPanduan] = useState(false);
    const ios = perangkatIos();
    const hp = perangkatHp();

    useEffect(() => {
        const adaTawaran = () => setTawaran(window.__tawaranPasang ?? null);
        const terpasang = () => setTampil(false);
        window.addEventListener('mss:bisa-pasang', adaTawaran);
        window.addEventListener('appinstalled', terpasang);
        return () => {
            window.removeEventListener('mss:bisa-pasang', adaTawaran);
            window.removeEventListener('appinstalled', terpasang);
        };
    }, []);

    if (!tampil) return null;

    const tutup = () => {
        try { localStorage.setItem(KUNCI_TUTUP, '1'); } catch (e) { /* abaikan */ }
        setTampil(false);
    };

    const pasang = async () => {
        if (!tawaran) {
            setPanduan(true);
            return;
        }
        tawaran.prompt();
        const { outcome } = await tawaran.userChoice;
        // Tawaran hanya bisa dipakai sekali; browser mengirim tawaran baru bila pengguna membatalkan
        window.__tawaranPasang = null;
        setTawaran(null);
        if (outcome === 'accepted') setTampil(false);
    };

    return (
        <div className="pasang-aplikasi no-print" role="region" aria-label="Pasang aplikasi">
            <div className="pasang-aplikasi-baris">
                <img src="/pwa/icon-192.png" alt="" className="pasang-aplikasi-ikon" />
                <div className="pasang-aplikasi-teks">
                    {/* HP: teks lebih pendek agar muat satu baris */}
                    <strong>{hp ? 'Pasang MSS Billing' : 'Pasang MSS Billing di komputer'}</strong>
                    <span>{hp ? 'Akses cepat seperti aplikasi' : 'Buka lebih cepat dan akses seperti aplikasi'}</span>
                </div>
                <button type="button" className="pasang-aplikasi-tombol" onClick={pasang}>
                    {ios ? <Share size={16} /> : <Download size={16} />}<span>Pasang</span>
                </button>
                <button type="button" className="pasang-aplikasi-tutup" onClick={tutup} aria-label="Tutup ajakan pasang aplikasi" title="Tutup"><X size={18} /></button>
            </div>
            {panduan && (
                <div className="pasang-aplikasi-panduan">
                    {ios ? (
                        <>Di <strong>Safari</strong>, ketuk ikon <strong>Bagikan</strong> <Share size={13} className="align-baseline" /> di bawah layar, lalu pilih <strong>Tambah ke Layar Utama</strong>.</>
                    ) : (
                        <>Buka menu browser (<strong>⋮</strong> di kanan atas), lalu pilih <strong>Instal aplikasi</strong> atau <strong>Tambahkan ke layar utama</strong>. Bila pilihan itu tidak ada, buka halaman ini di <strong>Google Chrome</strong> atau <strong>Microsoft Edge</strong>.</>
                    )}
                </div>
            )}
        </div>
    );
}
