import { useEffect, useState } from 'react';
import Header from './Header';
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

    const [dark, setDark] = useState(() => document.body.classList.contains('dark-only'));
    const toggleTheme = () => {
        const next = !dark;
        document.body.classList.toggle('dark-only', next);
        try { localStorage.setItem('theme', next ? 'dark' : 'light'); } catch (e) { /* abaikan */ }
        setDark(next);
    };

    return (
        <div className={`page-wrapper compact-wrapper ${dark ? 'dark-sidebar' : ''}`} id="pageWrapper">
            <Header closed={closed} onToggle={toggle} dark={dark} onToggleTheme={toggleTheme} />
            <div className="page-body-wrapper">
                <Sidebar closed={closed} onToggle={toggle} />
                <div className="page-body">{children}</div>
                <footer className="footer">
                    <div className="container-fluid">
                        <p className="mb-0 text-center">© {new Date().getFullYear()} MSS e-Billing ISP · Super Admin</p>
                    </div>
                </footer>
            </div>
            <ToastHost />
        </div>
    );
}
