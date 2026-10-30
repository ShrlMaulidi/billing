import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

// Toast global sederhana: notify('Pesan', 'success' | 'danger' | 'info' | 'warning', durasiMs)
export const notify = (message, type = 'success', duration = 3500) => window.dispatchEvent(new CustomEvent('app:toast', { detail: { message, type, duration } }));

// Placeholder untuk aksi yang logikanya dikerjakan di tahap backend
export const dummy = (what = 'Aksi') => notify(`${what} berhasil (data dummy — belum tersimpan)`, 'success');

/**
 * Status navigasi Inertia untuk loading skeleton.
 * Skeleton hanya muncul bila pemuatan > 400 ms (respons cepat tidak berkedip), dan tidak untuk
 * request latar belakang: prefetch, async, atau muat ulang sebagian data (reloadData) saat data sudah tampil.
 */
export function useNavLoading() {
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        let timer;
        const off1 = router.on('start', (e) => {
            const v = e.detail.visit;
            if (v.prefetch || v.async || v.only?.length) return;
            clearTimeout(timer);
            timer = setTimeout(() => setLoading(true), 400);
        });
        const off2 = router.on('finish', () => {
            clearTimeout(timer);
            setLoading(false);
        });
        return () => { clearTimeout(timer); off1(); off2(); };
    }, []);
    return loading;
}

/**
 * Request JSON ke backend (CSRF lewat cookie XSRF-TOKEN).
 * Gagal → melempar { status, message, errors } agar bisa ditampilkan di form / toast.
 */
export async function api(method, url, data) {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11);
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(xsrf ?? '') },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    if (res.status === 401 || res.status === 419) {
        window.location.href = '/login';
        throw { status: res.status, message: 'Sesi berakhir, silakan login kembali' };
    }
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw { status: res.status, message: json.message ?? `Permintaan gagal (${res.status})`, errors: json.errors };
    return json;
}

// Muat ulang props halaman setelah data berubah (cache prefetch ikut dikosongkan agar tidak basi)
export const reloadData = (only) => {
    router.flushAll();
    router.reload({ only, preserveScroll: true });
};

// Untuk CrudPage onSave/onDelete: simpan / hapus ke backend; pesan error validasi ditampilkan FormModal per field
export const saveTo = (base, only = ['rows']) => async (values, row) => {
    try {
        const res = await api(row ? 'PUT' : 'POST', row ? `${base}/${row.id}` : base, values);
        // type 'warning': data tersimpan tetapi ada langkah lanjutan yang gagal (mis. sinkron ke router)
        notify(res.message, res.type ?? 'success', res.type === 'warning' ? 9000 : undefined);
        reloadData(only);
    } catch (err) {
        if (!err.errors) notify(err.message, 'danger', 7000);
        throw err;
    }
};
export const deleteFrom = (base, only = ['rows']) => (row) => api('DELETE', `${base}/${row.id}`)
    .then((res) => { notify(res.message); reloadData(only); })
    .catch((err) => notify(err.message, 'danger', 7000));

// Ubah filter di query string tanpa reload penuh
export const applyFilters = (values) => {
    const clean = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
    router.get(window.location.pathname, clean, { preserveState: true, preserveScroll: true, replace: true });
};
