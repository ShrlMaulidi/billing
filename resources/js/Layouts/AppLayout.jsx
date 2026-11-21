import Header from './Header';
import MobileNav from './MobileNav';
import Sidebar from './Sidebar';
import useNavLayout from './useNavLayout';
import ToastHost from '../Components/ToastHost';

export default function AppLayout({ children }) {
    const { closed, toggle, navMode, toggleNavMode, dark, toggleTheme } = useNavLayout();

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
