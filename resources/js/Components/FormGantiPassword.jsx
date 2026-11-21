import { useState } from 'react';
import { Save } from 'react-feather';
import { Button, FormFeedback, Input, Label, Spinner } from 'reactstrap';
import { api, notify } from '../lib/ui';

const KOSONG = { password_lama: '', password: '', password_confirmation: '' };

/**
 * Form password akun pelanggan bergaya Cuba (menu Akun & modal wajib buat password baru).
 * perluPasswordLama = ganti password biasa; tanpa itu untuk login pertama / setelah reset (server tidak menanyakan password lama).
 * aksiLain: tombol tambahan di sebelah tombol simpan (mis. Logout). Sesi di perangkat lain dikeluarkan server.
 */
export default function FormGantiPassword({ perluPasswordLama = false, labelSimpan = 'Simpan Password', keterangan, aksiLain, onSelesai }) {
    const [data, setData] = useState(KOSONG);
    const [errors, setErrors] = useState({});
    const [proses, setProses] = useState(false);

    const simpan = async (e) => {
        e.preventDefault();
        setProses(true);
        setErrors({});
        try {
            notify((await api('PUT', '/akun-saya/password', data)).message);
            setData(KOSONG);
            onSelesai?.();
        } catch (err) {
            setErrors(err.errors ?? { [perluPasswordLama ? 'password_lama' : 'password']: [err.message] });
        } finally {
            setProses(false);
        }
    };

    const kolom = (name, label, autoComplete) => (
        <div className="mb-3">
            <Label for={name}>{label}</Label>
            <Input id={name} type="password" value={data[name]} autoComplete={autoComplete} maxLength={100} invalid={!!errors[name]}
                onChange={(e) => setData({ ...data, [name]: e.target.value })} />
            {errors[name] && <FormFeedback>{errors[name][0]}</FormFeedback>}
        </div>
    );

    return (
        <form onSubmit={simpan} noValidate>
            {perluPasswordLama && kolom('password_lama', 'Password lama', 'current-password')}
            {kolom('password', 'Password baru', 'new-password')}
            {kolom('password_confirmation', 'Ulangi password baru', 'new-password')}
            {keterangan && <p className="small f-light">{keterangan}</p>}
            <div className="d-flex flex-wrap justify-content-end gap-2">
                {aksiLain}
                <Button color="primary" type="submit" disabled={proses}>{proses ? <Spinner size="sm" className="me-1" /> : <Save size={14} className="me-1" />}{labelSimpan}</Button>
            </div>
        </form>
    );
}
