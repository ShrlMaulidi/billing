import { useState } from 'react';
import { Card, CardBody, Nav, NavItem, NavLink } from 'reactstrap';
import PageHeader from '../../Components/PageHeader';
import { Avatar, Field } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { dummy } from '../../lib/ui';

export default function Profil({ user }) {
    const [tab, setTab] = useState(() => (new URLSearchParams(window.location.search).get('tab') === 'password' ? 'password' : 'profil'));
    const [v, setV] = useState({ nama: user.nama, username: user.username, email: user.email, tlp: user.tlp });
    const fields = tab === 'profil'
        ? [{ name: 'foto', label: 'Foto', type: 'file', accept: 'image/*' }, { name: 'nama', label: 'Nama', required: true }, { name: 'username', label: 'Username', required: true }, { name: 'email', label: 'Email', type: 'email' }, { name: 'tlp', label: 'Tlp', type: 'tel' }]
        : [{ name: 'password_lama', label: 'Password Lama', type: 'password', required: true }, { name: 'password_baru', label: 'Password Baru', type: 'password', required: true, help: 'Minimal 8 karakter' }, { name: 'password_konfirmasi', label: 'Konfirmasi Password Baru', type: 'password', required: true }];

    return (
        <>
            <PageHeader title="Kelola Profil" crumbs={['User']} />
            <div className="container-fluid">
                <div className="row">
                    <div className="col-xl-4">
                        <Card>
                            <CardBody className="text-center">
                                <div className="d-flex justify-content-center mb-3"><Avatar name={user.nama} size={90} /></div>
                                <h5 className="mb-0">{user.nama}</h5>
                                <span className="f-light">{user.role}</span>
                                <hr />
                                <small className="f-light">Login terakhir {waktu(user.last_login_at)} dari {user.last_login_ip}</small>
                            </CardBody>
                        </Card>
                    </div>
                    <div className="col-xl-8">
                        <Card>
                            <CardBody>
                                <Nav tabs className="border-tab mb-3">
                                    {[['profil', 'Ubah Profil'], ['password', 'Ganti Password']].map(([k, l]) => (
                                        <NavItem key={k}><NavLink href="#" className={tab === k ? 'active' : ''} onClick={(e) => { e.preventDefault(); setTab(k); }}>{l}</NavLink></NavItem>
                                    ))}
                                </Nav>
                                <form onSubmit={(e) => { e.preventDefault(); dummy(tab === 'profil' ? 'Ubah profil' : 'Ganti password'); }}>
                                    {fields.map((f) => <Field key={f.name} field={f} value={v[f.name]} values={v} onChange={(x) => setV((s) => ({ ...s, [f.name]: x }))} />)}
                                    <button className="btn btn-primary" type="submit">Simpan</button>
                                </form>
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
