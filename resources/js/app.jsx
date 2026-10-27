import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AppLayout from './Layouts/AppLayout';
import './app.css';

// Tiap halaman jadi chunk terpisah agar muatan awal ringan
const pages = import.meta.glob('./Pages/**/*.jsx');

// Setelah halaman pertama tampil, muat semua chunk halaman di latar belakang
// supaya perpindahan menu tidak perlu menunggu unduhan JS
const preloadPages = () => Object.values(pages).forEach((load) => load());

createInertiaApp({
    title: (title) => (title ? `${title} · MSS Billing` : 'MSS Billing'),
    resolve: async (name) => {
        const page = await pages[`./Pages/${name}.jsx`]();
        if (page.default.layout === undefined) page.default.layout = (p) => <AppLayout>{p}</AppLayout>;
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
        (window.requestIdleCallback ?? ((cb) => setTimeout(cb, 1500)))(preloadPages);
    },
    defaults: {
        // Data dummy jarang berubah: anggap segar 1 menit, lalu tetap ditampilkan
        // instan sambil diperbarui di belakang hingga 10 menit
        prefetch: { cacheFor: ['1m', '10m'], hoverDelay: 50 },
    },
    progress: { color: '#7366ff', delay: 400 },
});
