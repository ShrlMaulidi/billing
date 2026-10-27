import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Login() {
    const [show, setShow] = useState(false);
    return (
        <>
            <Head title="Login" />
            <div className="container-fluid p-0">
                <div className="row m-0">
                    <div className="col-12 p-0">
                        <div className="login-card login-dark">
                            <div>
                                <div className="text-center mb-3"><img className="img-fluid logo-login" src="/cuba/images/logo/logo.png" alt="MSS" /></div>
                                <div className="login-main">
                                    <form className="theme-form" onSubmit={(e) => { e.preventDefault(); router.visit('/dashboard'); }}>
                                        <h4>Masuk ke MSS Billing</h4>
                                        <p>Masukkan username & password Anda</p>
                                        <div className="form-group">
                                            <label className="col-form-label">Username</label>
                                            <input className="form-control" type="text" required placeholder="superadmin" />
                                        </div>
                                        <div className="form-group">
                                            <label className="col-form-label">Password</label>
                                            <div className="form-input position-relative">
                                                <input className="form-control" type={show ? 'text' : 'password'} required placeholder="••••••••" />
                                                <div className="show-hide" onClick={() => setShow(!show)}><span className={show ? '' : 'show'} /></div>
                                            </div>
                                        </div>
                                        <div className="form-group mb-0">
                                            <div className="checkbox p-0"><input id="remember" type="checkbox" /><label className="text-muted" htmlFor="remember">Ingat saya</label></div>
                                            <div className="text-end mt-3"><button className="btn btn-primary btn-block w-100" type="submit">Masuk</button></div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

Login.layout = null;
