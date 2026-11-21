import { useState } from 'react';
import { api, notify } from '../lib/ui';

const KOSONG = { password_lama: '', password: '', password_confirmation: '' };

/**
 * Form password akun pelanggan (Akun Saya). perluPasswordLama = ganti password biasa; tanpa itu dipakai di halaman
 * wajib buat password baru setelah login pertama (server tidak menanyakan password lama). Sesi lain dikeluarkan server.
 */
export default function FormGantiPassword({ judul, keterangan, perluPasswordLama = false, labelSimpan = 'Simpan Password', onSelesai }) {
    const [data, setData] = useState(KOSONG);
    const [errors, setErrors] = useState({});
    const [proses, setProses] = useState(false);
    const ubah = (e) => setData({ ...data, [e.target.name]: e.target.value });

    const simpan = async (e) => {
        e.preventDefault();
        setProses(true);
        setErrors({});
        try {
            notify((await api('PUT', '/akun-saya/password', data)).message);
            setData(KOSONG);
            onSelesai();
        } catch (err) {
            setErrors(err.errors ?? { [perluPasswordLama ? 'password_lama' : 'password']: [err.message] });
        } finally {
            setProses(false);
        }
    };

    const kolom = (name, label, autoComplete) => (
        <div className="ak-field">
            <label htmlFor={name}>{label}</label>
            <input id={name} name={name} type="password" value={data[name]} onChange={ubah} autoComplete={autoComplete} maxLength={100}
                aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `${name}-error` : undefined} />
            {errors[name] && <p id={`${name}-error`} className="ct-error" role="alert">{errors[name][0]}</p>}
        </div>
    );

    return (
        <form className="ak-password" onSubmit={simpan} noValidate>
            {judul && <h2>{judul}</h2>}
            {keterangan && <p>{keterangan}</p>}
            {perluPasswordLama && kolom('password_lama', 'Password lama', 'current-password')}
            {kolom('password', 'Password baru', 'new-password')}
            {kolom('password_confirmation', 'Ulangi password baru', 'new-password')}
            <button type="submit" className="lp-btn lp-btn-gold" disabled={proses}>{proses ? 'Menyimpan…' : labelSimpan}</button>
        </form>
    );
}
