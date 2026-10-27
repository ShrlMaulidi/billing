import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AppLayout from './Layouts/AppLayout';
import './app.css';

createInertiaApp({
    title: (title) => (title ? `${title} · MSS Billing` : 'MSS Billing'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        const page = pages[`./Pages/${name}.jsx`];
        if (page.default.layout === undefined) page.default.layout = (p) => <AppLayout>{p}</AppLayout>;
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#7366ff' },
});
