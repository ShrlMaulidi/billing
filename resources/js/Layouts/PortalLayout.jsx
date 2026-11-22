import { Link, router, usePage } from '@inertiajs/react';
import { createContext, useContext } from 'react';
import { AlignCenter, Grid, LogOut, Moon, Sidebar as SidebarIcon, Sun, User, X } from 'react-feather';
import useNavLayout from './useNavLayout';
import Icon from '../Components/Icon';
import PasangAplikasi from '../Components/PasangAplikasi';
import ToastHost from '../Components/ToastHost';
import { Avatar } from '../Components/ui';

/** Menu dashboard pelanggan; ikon dari sprite Cuba. Navbar bawah (HP) memakai menu yang sama. */
export const MENU_PELANGGAN = [
    { title: 'Home', href: '/akun-saya', icon: 'home' },
    { title: 'Riwayat Tagihan', label: 'Riwayat', href: '/akun-saya/riwayat', icon: 'file' },
    { title: 'Tiket', href: '/akun-saya/tiket', icon: 'support-tickets' },
    { title: 'Akun', href: '/akun-saya/akun', icon: 'user' },
];

/** Mode navigasi di HP untuk halaman (mis. pilihan di menu Akun). */
const NavModeContext = createContext({ navMode: 'navbar', setNavMode: () => {} });
export const useNavMode = () => useContext(NavModeContext);

const aktif = (path, href) => (href === '/akun-saya' ? path === href : path === href || path.startsWith(`${href}/`));

function PortalHeader({ closed, onToggle, dark, onToggleTheme, navMode, onToggleNavMode }) {
    const { auth } = usePage().props;

    return (
        <div className={`page-header ${closed ? 'close_icon' : ''}`}>
            <div className="header-wrapper row m-0">
                <div className="header-logo-wrapper col-auto p-0">
                    <div className="logo-wrapper">
                        <Link href="/akun-saya"><img className="img-fluid" src="/cuba/images/logo/logo.png" alt="MSS" /></Link>
                    </div>
                    <div className="toggle-sidebar" onClick={onToggle}><AlignCenter className="status_toggle middle sidebar-toggle" /></div>
                </div>
                <div className="left-header col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3 p-0">
                    <h6 className="mb-0 d-none d-md-block f-w-600">Dashboard Pelanggan</h6>
                </div>
                <div className="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
                    <ul className="nav-menus flex-row">
                        {/* Ganti mode navigasi (khusus HP); ikon menunjukkan mode tujuan */}
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
                                    <p className="mb-0 font-roboto">Pelanggan <i className="middle fa fa-angle-down" /></p>
                                </div>
                            </div>
                            <ul className="profile-dropdown onhover-show-div">
                                <li><Link href="/akun-saya/akun"><User /><span>Akun Saya</span></Link></li>
                                <li><Link href="/logout" method="post" as="button" className="btn-logout"><LogOut /><span>Logout</span></Link></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    );
}

function PortalSidebar({ closed, onToggle }) {
    const path = usePage().url.split('?')[0];
    const closeOnMobile = () => window.innerWidth <= 991 && !closed && onToggle();

    return (
        <div className={`sidebar-wrapper ${closed ? 'close_icon' : ''}`} sidebar-layout="stroke-svg">
            <div>
                <div className="logo-wrapper">
                    <Link href="/akun-saya">
                        <img className="img-fluid for-light" src="/cuba/images/logo/logo.png" alt="MSS" />
                        <img className="img-fluid for-dark" src="/cuba/images/logo/logo_dark.png" alt="MSS" />
                    </Link>
                    <button type="button" className="back-btn sidebar-close" onClick={onToggle} aria-label="Tutup menu" title="Tutup menu"><X /></button>
                    <div className="toggle-sidebar" onClick={onToggle}><Grid className="status_toggle middle sidebar-toggle" /></div>
                </div>
                <div className="logo-icon-wrapper">
                    <Link href="/akun-saya"><img className="img-fluid" src="/cuba/images/logo/logo-icon.png" alt="" /></Link>
                </div>
                <nav className="sidebar-main">
                    <div id="sidebar-menu">
                        <ul className="sidebar-links custom-scrollbar">
                            <li className="back-btn">
                                <div className="mobile-back text-end" onClick={onToggle}><span>Kembali</span><i className="fa fa-angle-right ps-2" /></div>
                            </li>
                            <li className="sidebar-main-title"><div><h6>Pelanggan</h6></div></li>
                            {MENU_PELANGGAN.map((m) => (
                                <li className="sidebar-list" key={m.href}>
                                    <Link href={m.href} prefetch="hover" onClick={closeOnMobile} className={`sidebar-link sidebar-title link-nav ${aktif(path, m.href) ? 'active' : ''}`}>
                                        <Icon id={m.icon} />
                                        <span>{m.title}</span>
                                    </Link>
                                </li>
                            ))}
                            <li className="sidebar-list">
                                {/* Berupa <a> agar mendapat gaya menu sidebar tema; logout tetap lewat POST */}
                                <a href="/logout" role="button" className="sidebar-link sidebar-title link-nav" onClick={(e) => { e.preventDefault(); router.post('/logout'); }}>
                                    <LogOut className="stroke-icon" /><span>Logout</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </nav>
            </div>
        </div>
    );
}

/** Navbar bawah di HP: empat menu pelanggan langsung terlihat, tanpa panel menu tambahan. */
function PortalMobileNav() {
    const path = usePage().url.split('?')[0];

    return (
        <nav className="mnav" aria-label="Navigasi utama">
            <div className="mnav-pill">
                {MENU_PELANGGAN.map((m) => (
                    <Link key={m.href} href={m.href} prefetch className={`mnav-item ${aktif(path, m.href) ? 'active' : ''}`} aria-label={m.title} aria-current={aktif(path, m.href) ? 'page' : undefined}>
                        <svg><use href={`/cuba/svg/sprite.svg#stroke-${m.icon}`} /></svg>
                        <span>{m.label ?? m.title}</span>
                    </Link>
                ))}
            </div>
        </nav>
    );
}

/** Layout dashboard pelanggan bergaya template Cuba (sama dengan dashboard staf), dengan pilihan Sidebar / Navbar bawah di HP. */
export default function PortalLayout({ children }) {
    const { closed, toggle, navMode, setNavMode, toggleNavMode, dark, toggleTheme } = useNavLayout();

    return (
        <NavModeContext.Provider value={{ navMode, setNavMode }}>
            {/* portal-wrapper: gaya khusus dashboard pelanggan di HP (header biru menyatu dengan pita Home, lihat app.css) */}
            <div className={`page-wrapper compact-wrapper portal-wrapper nav-mode-${navMode} ${dark ? 'dark-sidebar' : ''}`} id="pageWrapper">
                <PortalHeader closed={closed} onToggle={toggle} dark={dark} onToggleTheme={toggleTheme} navMode={navMode} onToggleNavMode={toggleNavMode} />
                <div className="page-body-wrapper">
                    <PortalSidebar closed={closed} onToggle={toggle} />
                    <div className="page-body">{children}</div>
                    <footer className="footer">
                        <div className="container-fluid">
                            <p className="mb-0 text-center">© {new Date().getFullYear()} PT Media Solusi Sukses · Dashboard Pelanggan</p>
                        </div>
                    </footer>
                </div>
                {navMode === 'navbar' && <PortalMobileNav />}
                {/* Ajakan pasang aplikasi: melayang di depan konten (tidak ikut tergulir) sampai ditutup */}
                <PasangAplikasi />
                <ToastHost />
            </div>
        </NavModeContext.Provider>
    );
}

/** Pasang di halaman pelanggan: Halaman.layout = portalLayout */
export const portalLayout = (page) => <PortalLayout>{page}</PortalLayout>;
