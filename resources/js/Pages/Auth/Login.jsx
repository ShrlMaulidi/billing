import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Eye, EyeOff, Lock, User } from 'react-feather';

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

export default function Login() {
    const [show, setShow] = useState(false);
    return (
        <>
            <Head title="Login" />
            <div className="auth-page">
                <header className="auth-topbar">
                    <a href="/login" className="auth-brand">
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
                        <form onSubmit={(e) => { e.preventDefault(); router.visit('/dashboard'); }}>
                            <div className="auth-field">
                                <label htmlFor="username">Username</label>
                                <div className="auth-input">
                                    <User size={20} className="auth-input-icon" />
                                    <input id="username" type="text" required autoComplete="username" placeholder="Masukkan username" />
                                </div>
                            </div>

                            <div className="auth-field">
                                <label htmlFor="password">Kata Sandi</label>
                                <div className="auth-input">
                                    <Lock size={20} className="auth-input-icon" />
                                    <input id="password" type={show ? 'text' : 'password'} required autoComplete="current-password" placeholder="Masukkan kata sandi" />
                                    <button type="button" className="auth-toggle" onClick={() => setShow(!show)} aria-label={show ? 'Sembunyikan password' : 'Tampilkan password'} title={show ? 'Sembunyikan password' : 'Tampilkan password'}>
                                        {show ? <EyeOff size={20} /> : <Eye size={20} />}
                                    </button>
                                </div>
                            </div>

                            <div className="auth-row">
                                <label className="auth-check">
                                    <input type="checkbox" />
                                    <span>Ingat Saya</span>
                                </label>
                                <a href="#" className="auth-link">Lupa Sandi?</a>
                            </div>

                            <button type="submit" className="auth-btn-primary">Masuk</button>
                        </form>

                        <div className="auth-divider"><span>Atau masuk dengan</span></div>

                        <button type="button" className="auth-btn-google">
                            <GoogleIcon />
                            <span>Lanjutkan dengan Google</span>
                        </button>
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
