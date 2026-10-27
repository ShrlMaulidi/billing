import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

// Toast global sederhana: notify('Pesan', 'success' | 'danger' | 'info')
export const notify = (message, type = 'success') => window.dispatchEvent(new CustomEvent('app:toast', { detail: { message, type } }));

// Placeholder untuk aksi yang logikanya dikerjakan di tahap backend
export const dummy = (what = 'Aksi') => notify(`${what} berhasil (data dummy — belum tersimpan)`, 'success');

// Status navigasi Inertia untuk loading skeleton
export function useNavLoading() {
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const off1 = router.on('start', () => setLoading(true));
        const off2 = router.on('finish', () => setLoading(false));
        return () => { off1(); off2(); };
    }, []);
    return loading;
}

// Ubah filter di query string tanpa reload penuh
export const applyFilters = (values) => {
    const clean = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
    router.get(window.location.pathname, clean, { preserveState: true, preserveScroll: true, replace: true });
};
