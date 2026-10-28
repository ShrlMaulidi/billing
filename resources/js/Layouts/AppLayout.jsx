import { useEffect, useState } from 'react';
import Header from './Header';
import MobileNav from './MobileNav';
import Sidebar from './Sidebar';
import ToastHost from '../Components/ToastHost';

export default function AppLayout({ children }) {
    const [closed, setClosed] = useState(() => typeof window !== 'undefined' && window.innerWidth <= 991);

    useEffect(() => {
        const onResize = () => window.innerWidth <= 991 && setClosed(true);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    const toggle = () => setClosed((c) => !c);

    // Mode navigasi mobile: 'navbar' (navbar bawah, default) atau 'sidebar'
    const [navMode, setNavMode] = useState(() => {
        try { return localStorage.getItem('navMode') === 'sidebar' ? 'sidebar' : 'navbar'; } catch (e) { return 'navbar'; }
    });
    const toggleNavMode = () => {
        const next = navMode === 'navbar' ? 'sidebar' : 'navbar';
        try { localStorage.setItem('navMode', next); } catch (e) { /* abaikan */ }
        if (next === 'navbar' && window.innerWidth <= 991) setClosed(true);
        setNavMode(next);
    };

    const [dark, setDark] = useState(() => document.body.classList.contains('dark-only'));
    const toggleTheme = () => {
        const next = !dark;
        document.body.classList.toggle('dark-only', next);
        try { localStorage.setItem('theme', next ? 'dark' : 'light'); } catch (e) { /* abaikan */ }
        setDark(next);
    };

    return (
        <div className={`page-wrapper compact-wrapper nav-mode-${navMode} ${dark ? 'dark-sidebar' : ''}`} id="pageWrapper">
            <Header closed={closed} onToggle={toggle} dark={dark} onToggleTheme={toggleTheme} navMode={navMode} onToggleNavMode={toggleNavMode} />
            <div className="page-body-wrapper">
                <Sidebar closed={closed} onToggle={toggle} />
                <div className="page-body">{children}</div>
                <footer className="footer">
                    <div className="container-fluid">
                        <p className="mb-0 text-center">© {new Date().getFullYear()} MSS e-Billing ISP · Super Admin</p>
                    </div>
                </footer>
            </div>
            {navMode === 'navbar' && <MobileNav />}
            <ToastHost />
        </div>
    );
}
