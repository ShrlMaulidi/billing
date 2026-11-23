import { useEffect, useState } from 'react';
import { rupiah } from '../lib/format';

/**
 * QR asli berisi link Cek Tagihan bertanda tangan milik pelanggan (dari server). Library QR dimuat saat kartu tampil
 * agar tidak membebani halaman lain. SVG dihasilkan library dari teks link, bukan masukan pengguna.
 */
function QrKartu({ link }) {
    const [svg, setSvg] = useState('');
    useEffect(() => {
        let aktif = true;
        if (!link) return undefined;
        import('qrcode').then((qr) => qr.toString(link, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' }))
            .then((hasil) => aktif && setSvg(hasil))
            .catch(() => aktif && setSvg(''));
        return () => { aktif = false; };
    }, [link]);
    return svg ? <div className="qr-svg" dangerouslySetInnerHTML={{ __html: svg }} /> : null;
}

const baris = (p) => [`${p.paket ?? '-'} · ${rupiah(p.harga)}`, `Jatuh tempo tiap tgl ${p.tgl_jatuh_tempo ?? '-'}`, p.tlp ?? ''];

/** Kartu member (340 × 210) untuk preview & cetak: menu Kartu Member staf dan menu Akun pelanggan. */
export function MemberCard({ p, className = 'mb-3' }) {
    return (
        <div className={`member-card ${className}`}>
            <div className="d-flex justify-content-between"><strong>MSS NET</strong><small>KARTU MEMBER</small></div>
            <h5 className="text-white mt-3 mb-0">{p.nama}</h5>
            <div style={{ fontSize: 13, opacity: 0.9 }}>{p.id_pelanggan}</div>
            <div style={{ fontSize: 12, opacity: 0.85, marginTop: 10, maxWidth: 220 }}>{baris(p).map((t, i) => <span key={i}>{i > 0 && <br />}{t}</span>)}</div>
            <div className="qr" title="Scan untuk cek tagihan"><QrKartu link={p.link_kartu} /></div>
        </div>
    );
}

/* Ukuran kartu di layar (px); gambar unduhan digambar SKALA kali lebih besar agar tajam saat dicetak/diperbesar */
const LEBAR = 340;
const TINGGI = 210;
const SKALA = 3;
const FONT = 'Rubik, Roboto, "Segoe UI", Arial, sans-serif';

/** Potong teks yang melebihi lebar dengan elipsis. */
function muat(ctx, teks, lebarMaks) {
    if (ctx.measureText(teks).width <= lebarMaks) return teks;
    let t = teks;
    while (t.length > 1 && ctx.measureText(`${t}…`).width > lebarMaks) t = t.slice(0, -1);
    return `${t.trimEnd()}…`;
}

/**
 * Gambar kartu member ke canvas (tata letak sama dengan MemberCard). Digambar langsung tanpa library tangkapan layar, jadi
 * hasilnya sama di semua browser. QR berisi link Cek Tagihan bertanda tangan dari server.
 */
export async function gambarKartuMember(p) {
    try { await document.fonts?.load(`700 18px ${FONT}`); } catch (e) { /* pakai font cadangan */ }

    const canvas = document.createElement('canvas');
    canvas.width = LEBAR * SKALA;
    canvas.height = TINGGI * SKALA;
    const ctx = canvas.getContext('2d');
    ctx.scale(SKALA, SKALA);

    // Latar gradasi bersudut bulat + dua lingkaran samar sebagai hiasan
    ctx.save();
    ctx.beginPath();
    ctx.roundRect(0, 0, LEBAR, TINGGI, 14);
    ctx.clip();
    const latar = ctx.createLinearGradient(0, 0, LEBAR, TINGGI);
    latar.addColorStop(0, '#7366ff');
    latar.addColorStop(1, '#3e33c9');
    ctx.fillStyle = latar;
    ctx.fillRect(0, 0, LEBAR, TINGGI);
    ctx.fillStyle = 'rgba(255, 255, 255, .08)';
    ctx.beginPath(); ctx.arc(LEBAR - 20, 10, 70, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(10, TINGGI + 30, 90, 0, Math.PI * 2); ctx.fill();
    ctx.restore();

    ctx.fillStyle = '#fff';
    ctx.textBaseline = 'alphabetic';
    ctx.font = `700 14px ${FONT}`;
    ctx.fillText('MSS NET', 18, 34);
    ctx.font = `500 10.5px ${FONT}`;
    ctx.textAlign = 'right';
    ctx.fillText('KARTU MEMBER', LEBAR - 18, 33);
    ctx.textAlign = 'left';

    ctx.font = `600 18px ${FONT}`;
    ctx.fillText(muat(ctx, p.nama ?? '', LEBAR - 36), 18, 74);
    ctx.globalAlpha = 0.9;
    ctx.font = `400 13px ${FONT}`;
    ctx.fillText(p.id_pelanggan ?? '', 18, 93);
    ctx.globalAlpha = 0.85;
    ctx.font = `400 12px ${FONT}`;
    baris(p).forEach((t, i) => ctx.fillText(muat(ctx, t, 205), 18, 124 + i * 17));
    ctx.globalAlpha = 1;

    // QR di kotak putih kanan bawah
    const kotak = 84;
    const x = LEBAR - 16 - kotak;
    const y = TINGGI - 16 - kotak;
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.roundRect(x, y, kotak, kotak, 6);
    ctx.fill();
    if (p.link_kartu) {
        const qr = await import('qrcode');
        const kanvasQr = await qr.toCanvas(document.createElement('canvas'), p.link_kartu, { margin: 0, width: (kotak - 10) * SKALA, errorCorrectionLevel: 'M' });
        ctx.drawImage(kanvasQr, x + 5, y + 5, kotak - 10, kotak - 10);
    }

    return canvas;
}

/** Unduh kartu member sebagai PNG (kartu-member-{ID}.png). */
export async function unduhKartuMember(p) {
    const canvas = await gambarKartuMember(p);
    const blob = await new Promise((selesai) => canvas.toBlob(selesai, 'image/png'));
    if (!blob) throw new Error('Gagal membuat gambar kartu');
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `kartu-member-${String(p.id_pelanggan ?? 'mss').replace(/[^\w-]+/g, '_')}.png`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/** Pratinjau berupa gambar yang sama persis dengan file unduhan; lebarnya menyesuaikan layar (HP). */
export function GambarKartuMember({ p }) {
    const [src, setSrc] = useState(null);
    useEffect(() => {
        let aktif = true;
        gambarKartuMember(p).then((c) => aktif && setSrc(c.toDataURL('image/png'))).catch(() => aktif && setSrc(null));
        return () => { aktif = false; };
    }, [p]);

    return src
        ? <img src={src} alt={`Kartu member ${p.nama} (${p.id_pelanggan})`} className="kartu-member-gambar" width={LEBAR} height={TINGGI} />
        : <div className="kartu-member-gambar kartu-member-memuat" aria-hidden="true" />;
}
