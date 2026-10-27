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
