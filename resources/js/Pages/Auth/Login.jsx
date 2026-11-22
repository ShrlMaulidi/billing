import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AlertCircle, ArrowLeft, Eye, EyeOff, Lock, MessageCircle, Phone, User } from 'react-feather';
import { linkWa } from '../../Components/TagihanPelanggan';

function GoogleIcon() {
    return (
        <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
            <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z" />
            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
            <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z" />
        </svg>
    );
}

const LANGKAH_PELANGGAN = [
    'Kirim pesan ke CS kami lewat WhatsApp.',
    'CS mencocokkan identitas Anda (nama, alamat pemasangan, dan ID pelanggan).',
    'Password direset ke nomor HP terdaftar Anda.',
    'Masuk dengan nomor HP, lalu buat password baru.',
];

/**
 * Lupa Sandi. Pelanggan: minta reset ke CS lewat WhatsApp (pesan terisi nomor HP); CS memverifikasi identitas lalu
 * menekan Reset Password di User › Akun Pelanggan. Tidak ada reset otomatis: nomor HP & ID pelanggan mudah diketahui
 * orang lain, jadi reset tanpa verifikasi bisa dipakai mengambil alih akun. Staf: hubungi Super Admin.
 */
function LupaSandi({ usernameAwal, onKembali }) {
    const [peran, setPeran] = useState('pelanggan');
    // Isian username yang berupa nomor HP langsung dipakai
    const [nomor, setNomor] = useState(/^[0-9+\-. ]{8,}$/.test(usernameAwal.trim()) ? usernameAwal.trim() : '');
    const pesan = `Halo CS MSS, saya pelanggan dan lupa password akun dashboard pelanggan.${nomor.trim() ? ` Nomor HP terdaftar: ${nomor.trim()}.` : ''} Mohon bantuan reset password.`;

    return (
        <div className="auth-lupa">
            <button type="button" className="auth-kembali" onClick={onKembali}><ArrowLeft size={16} />Kembali ke login</button>
            <h2>Lupa Kata Sandi</h2>
            <div className="auth-tab" role="tablist">
                <button type="button" role="tab" aria-selected={peran === 'pelanggan'} className={peran === 'pelanggan' ? 'active' : ''} onClick={() => setPeran('pelanggan')}>Saya Pelanggan</button>
                <button type="button" role="tab" aria-selected={peran === 'staf'} className={peran === 'staf' ? 'active' : ''} onClick={() => setPeran('staf')}>Saya Staf</button>
            </div>

            {peran === 'pelanggan' ? (
                <>
                    <ol className="auth-langkah">{LANGKAH_PELANGGAN.map((l) => <li key={l}>{l}</li>)}</ol>
                    <div className="auth-field">
                        <label htmlFor="nomor-lupa">Nomor HP terdaftar</label>
                        <div className="auth-input">
                            <Phone size={20} className="auth-input-icon" />
                            <input id="nomor-lupa" type="tel" inputMode="tel" autoComplete="tel" maxLength={20} placeholder="Contoh: 0812xxxxxxxx" value={nomor} onChange={(e) => setNomor(e.target.value)} />
                        </div>
                    </div>
                    <a href={linkWa(pesan)} target="_blank" rel="noopener noreferrer" className="auth-btn-primary auth-btn-wa"><MessageCircle size={18} />Hubungi CS via WhatsApp</a>
                    <p className="auth-catatan">Demi keamanan, password tidak bisa direset tanpa verifikasi CS. Jangan berikan password Anda kepada siapa pun, termasuk petugas.</p>
                </>
            ) : (
                <div className="auth-catatan auth-catatan-kotak">
                    Hubungi <strong>Super Admin</strong> atau admin pusat. Password akun staf direset lewat menu <strong>User › Manajemen User</strong>.
                </div>
            )}
        </div>
    );
}

export default function Login() {
    const [lupa, setLupa] = useState(false);
    const [show, setShow] = useState(false);
    const form = useForm({ username: '', password: '', remember: false });
    const error = form.errors.username || form.errors.password;
    const submit = (e) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };
    return (
        <>
            <Head title="Login" />
            <div className="auth-page">
                <header className="auth-topbar">
                    <a href="/" className="auth-brand">
                        <img src="/cuba/images/logo/logo.png" alt="MSS" />
                        <span>Billing</span>
                    </a>
                    <a href="#" className="auth-btn-outline">Daftar</a>
                </header>

                <main className="auth-main">
                    <div className="auth-heading">
                        <h1>Selamat Datang Kembali</h1>
                        <p>Masuk untuk mengelola billing pelanggan MSS</p>
                    </div>

                    <div className="auth-card">
                        {lupa ? <LupaSandi usernameAwal={form.data.username} onKembali={() => setLupa(false)} /> : (
                        <>
                        <form onSubmit={submit} noValidate>
                            {error && <div className="auth-error" role="alert"><AlertCircle size={18} /><span>{error}</span></div>}
                            <div className="auth-field">
                                <label htmlFor="username">Username atau Email</label>
                                <div className={`auth-input ${form.errors.username ? 'invalid' : ''}`}>
                                    <User size={20} className="auth-input-icon" />
                                    <input id="username" type="text" required autoComplete="username" autoFocus placeholder="Masukkan username atau email"
                                        value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} />
                                </div>
                            </div>

                            <div className="auth-field">
                                <label htmlFor="password">Kata Sandi</label>
                                <div className={`auth-input ${form.errors.password ? 'invalid' : ''}`}>
                                    <Lock size={20} className="auth-input-icon" />
                                    <input id="password" type={show ? 'text' : 'password'} required autoComplete="current-password" placeholder="Masukkan kata sandi"
                                        value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                                    <button type="button" className="auth-toggle" onClick={() => setShow(!show)} aria-label={show ? 'Sembunyikan password' : 'Tampilkan password'} title={show ? 'Sembunyikan password' : 'Tampilkan password'}>
                                        {show ? <EyeOff size={20} /> : <Eye size={20} />}
                                    </button>
                                </div>
                            </div>

                            <div className="auth-row">
                                <label className="auth-check">
                                    <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                                    <span>Ingat Saya</span>
                                </label>
                                <button type="button" className="auth-link auth-link-btn" onClick={() => setLupa(true)}>Lupa Sandi?</button>
                            </div>

                            <button type="submit" className="auth-btn-primary" disabled={form.processing}>{form.processing ? 'Memproses...' : 'Masuk'}</button>
                        </form>

                        <div className="auth-divider"><span>Atau masuk dengan</span></div>

                        <button type="button" className="auth-btn-google">
                            <GoogleIcon />
                            <span>Lanjutkan dengan Google</span>
                        </button>
                        </>
                        )}
                    </div>

                    <p className="auth-register">Belum punya akun? <a href="#" className="auth-link">Daftar Sekarang</a></p>
                </main>

                <footer className="auth-footer">
                    <a href="#">Syarat &amp; Ketentuan</a>
                    <span>•</span>
                    <a href="#">Kebijakan Privasi</a>
                </footer>
            </div>
        </>
    );
}

Login.layout = null;
