import { useRef } from 'react';

/** Jarak geser (px) atau kecepatan (px/ms) yang cukup untuk menutup panel. */
const JARAK_TUTUP = 90;
const KECEPATAN_TUTUP = 0.5;

/**
 * Geser ke bawah untuk menutup panel bawah (bottom sheet) di HP. Panel mengikuti jari; dilepas cukup jauh / cepat → onTutup,
 * selain itu kembali ke posisinya. Geseran yang dimulai di area gulir (selectorGulir) baru menutup panel bila area itu sudah
 * di paling atas, agar menggulir daftar menu tetap normal.
 *
 * @returns {{ panelRef: import('react').RefObject, backdropRef: import('react').RefObject, sentuh: object }} sentuh = handler untuk panel
 */
export default function useGeserTutup(onTutup, selectorGulir = '.msheet-body') {
    const panelRef = useRef(null);
    const backdropRef = useRef(null);
    const geser = useRef(null); // { awalY, waktu, dy, aktif }

    const atur = (dy, transisi) => {
        const panel = panelRef.current;
        if (!panel) return;
        panel.style.transition = transisi ? '' : 'none';
        panel.style.transform = dy > 0 ? `translateY(${dy}px)` : '';
        if (backdropRef.current) {
            backdropRef.current.style.transition = transisi ? '' : 'none';
            backdropRef.current.style.opacity = dy > 0 ? String(Math.max(0, 1 - dy / (panel.offsetHeight || 1))) : '';
        }
    };

    const sentuh = {
        onTouchStart: (e) => {
            const gulir = e.target.closest?.(selectorGulir);
            // Mulai di daftar yang sedang tergulir ke bawah: biarkan menggulir, bukan menutup
            if (gulir && gulir.scrollTop > 0) {
                geser.current = null;
                return;
            }
            geser.current = { awalY: e.touches[0].clientY, waktu: Date.now(), dy: 0, aktif: false };
        },
        onTouchMove: (e) => {
            const g = geser.current;
            if (!g) return;
            const dy = e.touches[0].clientY - g.awalY;
            if (!g.aktif && dy < 6) return; // gerakan ke atas / sangat kecil: bukan geser tutup
            g.aktif = true;
            g.dy = Math.max(0, dy);
            atur(g.dy, false);
        },
        onTouchEnd: () => {
            const g = geser.current;
            geser.current = null;
            if (!g?.aktif) return;
            const kecepatan = g.dy / Math.max(1, Date.now() - g.waktu);
            const panel = panelRef.current;
            if (panel && (g.dy > JARAK_TUTUP || (g.dy > 30 && kecepatan > KECEPATAN_TUTUP))) {
                // Lanjutkan turun dari posisi jari (tanpa melompat ke atas dulu), lalu kembalikan ke gaya CSS setelah animasi
                panel.style.transition = '';
                panel.style.transform = 'translateY(100%)';
                if (backdropRef.current) {
                    backdropRef.current.style.transition = '';
                    backdropRef.current.style.opacity = '';
                }
                onTutup();
                setTimeout(() => { panel.style.transform = ''; }, 350);
                return;
            }
            atur(0, true);
        },
    };
    sentuh.onTouchCancel = sentuh.onTouchEnd;

    return { panelRef, backdropRef, sentuh };
}
