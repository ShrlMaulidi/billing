import { Link, usePage } from '@inertiajs/react';
import { Avatar } from '../Components/ui';
import { AlignCenter, Globe, LogOut, Moon, Sidebar as SidebarIcon, Sun, User, Settings } from 'react-feather';

export default function Header({ closed, onToggle, dark, onToggleTheme, navMode, onToggleNavMode }) {
    const { auth } = usePage().props;

    return (
        <div className={`page-header ${closed ? 'close_icon' : ''}`}>
            <div className="header-wrapper row m-0">
                <div className="header-logo-wrapper col-auto p-0">
                    <div className="logo-wrapper">
                        <Link href="/dashboard" prefetch><img className="img-fluid" src="/cuba/images/logo/logo.png" alt="MSS" /></Link>
                    </div>
                    <div className="toggle-sidebar" onClick={onToggle}><AlignCenter className="status_toggle middle sidebar-toggle" /></div>
                </div>
                <div className="left-header col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3 p-0">
                    <h6 className="mb-0 d-none d-md-block f-w-600">MSS e-Billing ISP</h6>
                </div>
                <div className="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
                    <ul className="nav-menus flex-row">
                        <li className="d-none d-md-block">
                            <div className="ip-chip" title="IP login">
                                <Globe />
                                <span className="ip-chip-label">IP</span>
                                <span className="ip-chip-value">{auth.ip}</span>
                            </div>
                        </li>
                        {/* Ganti mode navigasi (khusus mobile); ikon menunjukkan mode tujuan, seperti tombol tema */}
                        <li className="d-lg-none">
                            <button type="button" className="mode nav-mode-toggle" onClick={onToggleNavMode}
                                title={navMode === 'navbar' ? 'Ganti ke mode Sidebar' : 'Ganti ke mode Navbar bawah'}
                                aria-label={navMode === 'navbar' ? 'Ganti ke mode Sidebar' : 'Ganti ke mode Navbar bawah'}>
                                <SidebarIcon style={navMode === 'navbar' ? undefined : { transform: 'rotate(-90deg)' }} />
                            </button>
                        </li>
                        <li>
                            <div className="mode" onClick={onToggleTheme} title="Dark / Light">{dark ? <Sun /> : <Moon />}</div>
                        </li>
                        <li className="profile-nav onhover-dropdown pe-0 py-0">
                            <div className="media profile-media d-flex">
                                <Avatar name={auth.user.nama} size={37} />
                                <div className="media-body">
                                    <span>{auth.user.nama}</span>
                                    <p className="mb-0 font-roboto">{auth.user.role} <i className="middle fa fa-angle-down" /></p>
                                </div>
                            </div>
                            <ul className="profile-dropdown onhover-show-div">
                                <li><Link href="/profil" prefetch><User /><span>Profil Saya</span></Link></li>
                                <li><Link href="/profil?tab=password" prefetch><Settings /><span>Ganti Password</span></Link></li>
                                <li><Link href="/logout" method="post" as="button" className="btn-logout"><LogOut /><span>Logout</span></Link></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    );
}
