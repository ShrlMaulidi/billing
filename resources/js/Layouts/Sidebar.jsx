import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Grid, X } from 'react-feather';
import { MENU } from '../lib/menu';
import Icon from '../Components/Icon';

const NAV_PREFETCH = ['mount', 'hover'];

export default function Sidebar({ closed, onToggle }) {
    const { url } = usePage();
    const path = url.split('?')[0];
    const isActive = (href) => path === href || path.startsWith(`${href}/`);
    const groupOf = (p) => MENU.flatMap((g) => g.items).find((i) => i.base && p.startsWith(i.base))?.title;
    const [open, setOpen] = useState(() => groupOf(path));
    // Buka grup menu halaman aktif juga saat datang dari link lain (mis. kartu Dashboard)
    useEffect(() => {
        const g = groupOf(path);
        if (g) setOpen(g);
    }, [path]); // eslint-disable-line react-hooks/exhaustive-deps
    const closeOnMobile = () => window.innerWidth <= 991 && !closed && onToggle();

    return (
        <div className={`sidebar-wrapper ${closed ? 'close_icon' : ''}`} sidebar-layout="stroke-svg">
            <div>
                <div className="logo-wrapper">
                    <Link href="/dashboard" prefetch>
                        <img className="img-fluid for-light" src="/cuba/images/logo/logo.png" alt="MSS" />
                        <img className="img-fluid for-dark" src="/cuba/images/logo/logo_dark.png" alt="MSS" />
                    </Link>
                    <button type="button" className="back-btn sidebar-close" onClick={onToggle} aria-label="Tutup menu" title="Tutup menu"><X /></button>
                    <div className="toggle-sidebar" onClick={onToggle}><Grid className="status_toggle middle sidebar-toggle" /></div>
                </div>
                <div className="logo-icon-wrapper">
                    <Link href="/dashboard" prefetch><img className="img-fluid" src="/cuba/images/logo/logo-icon.png" alt="" /></Link>
                </div>
                <nav className="sidebar-main">
                    <div id="sidebar-menu">
                        <ul className="sidebar-links custom-scrollbar">
                            <li className="back-btn">
                                <div className="mobile-back text-end" onClick={onToggle}><span>Kembali</span><i className="fa fa-angle-right ps-2" /></div>
                            </li>
                            {MENU.map((group) => [
                                <li className="sidebar-main-title" key={group.title}><div><h6>{group.title}</h6></div></li>,
                                ...group.items.map((item) => {
                                    if (!item.children) {
                                        return (
                                            <li className="sidebar-list" key={item.title}>
                                                <Link href={item.href} prefetch={NAV_PREFETCH} onClick={closeOnMobile} className={`sidebar-link sidebar-title link-nav ${isActive(item.href) ? 'active' : ''}`}>
                                                    <Icon id={item.icon} />
                                                    <span>{item.title}</span>
                                                </Link>
                                            </li>
                                        );
                                    }
                                    const expanded = open === item.title;
                                    return (
                                        <li className="sidebar-list" key={item.title}>
                                            <a href="#" className={`sidebar-link sidebar-title ${path.startsWith(item.base) ? 'active' : ''}`} onClick={(e) => { e.preventDefault(); setOpen(expanded ? null : item.title); }}>
                                                <Icon id={item.icon} />
                                                <span>{item.title}</span>
                                                <div className="according-menu"><i className={`fa fa-angle-${expanded ? 'down' : 'right'}`} /></div>
                                            </a>
                                            <ul className="sidebar-submenu" style={{ display: expanded ? 'block' : 'none' }}>
                                                {item.children.map((c) => (
                                                    <li key={c.href}>
                                                        <Link href={c.href} prefetch={NAV_PREFETCH} onClick={closeOnMobile} className={path === c.href ? 'active' : ''}>{c.title}</Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        </li>
                                    );
                                }),
                            ])}
                        </ul>
                    </div>
                </nav>
            </div>
        </div>
    );
}
