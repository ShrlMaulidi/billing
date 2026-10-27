import { lazy, Suspense } from 'react';

// ApexCharts (~900 kB) dimuat terpisah agar halaman tampil dulu tanpa menunggu library grafik
const ApexChart = lazy(() => import('react-apexcharts'));

export default function Chart(props) {
    return (
        <Suspense fallback={<div className="skeleton" style={{ height: props.height }} />}>
            <ApexChart {...props} />
        </Suspense>
    );
}
