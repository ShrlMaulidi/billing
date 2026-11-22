import { Head } from '@inertiajs/react';

/**
 * Pita biru di atas setiap halaman dashboard pelanggan (HP & desktop): judul, keterangan, ikon opsional di kiri
 * (mis. avatar), dan aksi di kanan (children). Diletakkan langsung di .page-body sebelum .container-fluid agar selebar
 * area konten; isi halaman dibungkus .pm-wrap.pm-isi supaya kartu pertama menumpuk di pita. Tidak ikut tercetak.
 */
export default function PitaPortal({ judul, keterangan, ikon, judulTab, children }) {
    return (
        <>
            <Head title={judulTab ?? judul} />
            <section className="pm-band pm-band-halaman no-print">
                <div className="pm-band-dalam pm-band-isi">
                    {ikon && <span className="pm-band-ikon">{ikon}</span>}
                    <div className="flex-grow-1 min-w-0">
                        <h1 className="pm-judul">{judul}</h1>
                        {keterangan && <p className="pm-user">{keterangan}</p>}
                    </div>
                    {children}
                </div>
            </section>
        </>
    );
}
