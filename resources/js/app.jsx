import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AppLayout from './Layouts/AppLayout';
import './app.css';

// Tiap halaman jadi chunk terpisah agar muatan awal ringan
const pages = import.meta.glob('./Pages/**/*.jsx');

// Setelah halaman pertama tampil, muat semua chunk halaman di latar belakang supaya perpindahan menu
// tidak perlu menunggu unduhan JS. Hanya untuk user yang login: pengunjung landing / cek tagihan
// tidak perlu mengunduh JS halaman admin.
let sudahPreload = false;
const preloadPages = () => {
    if (sudahPreload) return;
    sudahPreload = true;
    (window.requestIdleCallback ?? ((cb) => setTimeout(cb, 1500)))(() => Object.values(pages).forEach((load) => load()));
};
const preloadBilaLogin = (page) => page?.props?.auth?.user && preloadPages();

createInertiaApp({
    title: (title) => (title ? `${title} · MSS Billing` : 'MSS Billing'),
    resolve: async (name) => {
        const page = await pages[`./Pages/${name}.jsx`]();
        if (page.default.layout === undefined) page.default.layout = (p) => <AppLayout>{p}</AppLayout>;
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
        preloadBilaLogin(props.initialPage);
        // User yang baru login tanpa muat ulang halaman
        router.on('navigate', (event) => preloadBilaLogin(event.detail.page));
    },
    defaults: {
        // Data jarang berubah: anggap segar 1 menit, lalu tetap ditampilkan
        // instan sambil diperbarui di belakang hingga 10 menit
        // hoverDelay: kursor yang hanya melintas di sidebar tidak memicu permintaan ke server
        prefetch: { cacheFor: ['1m', '10m'], hoverDelay: 150 },
    },
    progress: { color: '#7366ff', delay: 400 },
});
