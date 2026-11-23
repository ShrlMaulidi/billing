import { lazy, Suspense, useEffect, useMemo, useState } from 'react';

// ApexCharts (~900 kB) dimuat terpisah agar halaman tampil dulu tanpa menunggu library grafik
const ApexChart = lazy(() => import('react-apexcharts'));

const TEKS_GELAP = 'rgba(255, 255, 255, .7)';
const GARIS_GELAP = '#374558';
const KARTU_GELAP = '#262932';

const temaGelap = () => typeof document !== 'undefined' && document.body.classList.contains('dark-only');

/** Ikuti tombol dark mode (class dark-only di <body>) agar grafik langsung berganti warna. */
function useTemaGelap() {
    const [gelap, setGelap] = useState(temaGelap);
    useEffect(() => {
        const observer = new MutationObserver(() => setGelap(temaGelap()));
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);
    return gelap;
}

/**
 * Warna bawaan ApexCharts gelap (teks #373d3f, garis #e0e0e0, label total donut #373d3f) sehingga tak terbaca di dark mode.
 * Di dark mode warna-warna itu diganti; warna seri data dari halaman tidak diubah.
 */
function sesuaikanTema(options = {}, type, gelap) {
    if (!gelap) return options;
    const donut = options.plotOptions?.pie?.donut;
    return {
        ...options,
        chart: { ...options.chart, foreColor: TEKS_GELAP, background: 'transparent' },
        grid: { ...options.grid, borderColor: GARIS_GELAP },
        tooltip: { ...options.tooltip, theme: 'dark' },
        ...((type === 'donut' || type === 'pie') && {
            // Garis pemisah irisan mengikuti warna kartu, bukan putih
            stroke: { ...options.stroke, colors: [KARTU_GELAP] },
            plotOptions: {
                ...options.plotOptions,
                pie: { ...options.plotOptions?.pie, donut: { ...donut, labels: { ...donut?.labels, total: { ...donut?.labels?.total, color: TEKS_GELAP } } } },
            },
        }),
    };
}

export default function Chart(props) {
    const gelap = useTemaGelap();
    const options = useMemo(() => sesuaikanTema(props.options, props.type, gelap), [props.options, props.type, gelap]);

    return (
        <Suspense fallback={<div className="skeleton" style={{ height: props.height }} />}>
            <ApexChart {...props} options={options} />
        </Suspense>
    );
}
