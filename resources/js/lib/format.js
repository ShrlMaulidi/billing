const nf = new Intl.NumberFormat('id-ID');

export const rupiah = (n) => `Rp ${nf.format(Math.round(Number(n) || 0))}`;
export const angka = (n) => nf.format(Number(n) || 0);
export const persen = (n) => `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(n) || 0)}%`;

const toDate = (d) => (d instanceof Date ? d : new Date(String(d).replace(' ', 'T')));

// 27 Okt 2026
export const tanggal = (d) => (d ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(toDate(d)).replace('.', '') : '-');
export const waktu = (d) => (d ? `${tanggal(d)} ${toDate(d).toTimeString().slice(0, 5)}` : '-');
export const periode = (d) => (d ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(toDate(d)) : '-');

export const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
export const METODE = { cash: 'Cash', transfer: 'Transfer Bank', tripay: 'Tripay', xendit: 'Xendit', pakar_digital: 'Pakar Digital', moota: 'Moota' };

export const maskRekening = (no) => (no && no.length > 4 ? `${'•'.repeat(no.length - 4)}${no.slice(-4)}` : no);
export const label = (s) => (s ? String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '-');

const SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
const ejaan = (n) => {
    if (n < 12) return SATUAN[n];
    if (n < 20) return `${ejaan(n - 10)} belas`;
    if (n < 100) return `${ejaan(Math.floor(n / 10))} puluh ${ejaan(n % 10)}`;
    if (n < 200) return `seratus ${ejaan(n - 100)}`;
    if (n < 1000) return `${ejaan(Math.floor(n / 100))} ratus ${ejaan(n % 100)}`;
    if (n < 2000) return `seribu ${ejaan(n - 1000)}`;
    if (n < 1e6) return `${ejaan(Math.floor(n / 1000))} ribu ${ejaan(n % 1000)}`;
    if (n < 1e9) return `${ejaan(Math.floor(n / 1e6))} juta ${ejaan(n % 1e6)}`;
    return `${ejaan(Math.floor(n / 1e9))} miliar ${ejaan(n % 1e9)}`;
};
// 175000 → "Seratus tujuh puluh lima ribu rupiah" (untuk kwitansi)
export const terbilang = (n) => {
    const nilai = Math.round(Math.abs(Number(n) || 0));
    const teks = nilai === 0 ? 'nol' : ejaan(nilai).replace(/\s+/g, ' ').trim();
    return `${teks.charAt(0).toUpperCase()}${teks.slice(1)} rupiah`;
};
