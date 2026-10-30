import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    ArrowDownCircle, ArrowUpCircle, Bell, Box, Circle, CreditCard, Database, GitBranch, List, Map, MapPin, Menu, Percent, Server, Shield,
    ShoppingBag, UploadCloud, UserCheck, Users, UserX, X, Zap,
} from 'react-feather';
import { MENU, menuUntuk } from '../lib/menu';

// Menu utama di navbar bawah (mobile); label dipersingkat agar muat di layar kecil
const PRIMARY = [
    { href: '/dashboard', label: 'Dash' },
    { href: '/dashboard-cabang', label: 'Cabang' },
    { href: '/income-summary', label: 'Income' },
    { href: '/laporan', label: 'Laporan' },
];

const SHEET_ICON = {
    '/pelanggan': Users, '/pelanggan/on': UserCheck, '/pelanggan/off': UserX,
    '/kelola-data/kartu-member': CreditCard, '/kelola-data/cabang': MapPin, '/kelola-data/area': Map, '/kelola-data/pajak': Percent,
    '/kelola-data/paket': Box, '/kelola-data/router': Server, '/kelola-data/rekening': Database, '/kelola-data/notifikasi': Bell,
    '/kelola-data/pph': UploadCloud, '/kelola-data/tunnel': GitBranch, '/transaksi/penarikan-saldo': ArrowUpCircle,
    '/transaksi/setor-kewajiban': ArrowDownCircle, '/transaksi/pengeluaran': ShoppingBag, '/payment-gateway': Zap, '/logs': List,
    '/user/manajemen': Users, '/user/hak-akses': Shield,
};

// Sisa menu sidebar (selain menu utama), dikelompokkan seperti di sidebar
const sectionsOf = (menu) => menu.flatMap((group) => {
    const singles = group.items.filter((i) => !i.children && !PRIMARY.some((p) => p.href === i.href));
    return [
        ...(singles.length ? [{ title: group.title, items: singles }] : []),
        ...group.items.filter((i) => i.children && i.children.length).map((i) => ({ title: i.title, items: i.children })),
    ];
});

export default function MobileNav() {
    const { url, props } = usePage();
    const role = props.auth.user?.role;
    const sections = useMemo(() => sectionsOf(menuUntuk(role)), [role]);
    const sheetHrefs = sections.flatMap((s) => s.items.map((i) => i.href));
    const path = url.split('?')[0];
    const isActive = (href) => path === href || path.startsWith(`${href}/`);
    const [open, setOpen] = useState(false);

    // Tutup panel saat pindah halaman, tombol Esc, dan kunci scroll halaman selama terbuka
    useEffect(() => router.on('navigate', () => setOpen(false)), []);
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = ''; };
    }, [open]);

    return (
        <>
            <nav className="mnav" aria-label="Navigasi utama">
                <div className="mnav-pill">
                    {PRIMARY.map((p) => {
                        const item = MENU.flatMap((g) => g.items).find((i) => i.href === p.href);
                        return (
                            <Link key={p.href} href={p.href} prefetch className={`mnav-item ${isActive(p.href) ? 'active' : ''}`} aria-label={item.title} aria-current={isActive(p.href) ? 'page' : undefined}>
                                <svg><use href={`/cuba/svg/sprite.svg#stroke-${item.icon}`} /></svg>
                                <span>{p.label}</span>
                            </Link>
                        );
                    })}
                </div>
                <button type="button" className={`mnav-menu ${open || sheetHrefs.includes(path) ? 'active' : ''}`} onClick={() => setOpen(true)} aria-haspopup="dialog" aria-expanded={open}>
                    <Menu />
                    <span>Menu</span>
                </button>
            </nav>

            <div className={`msheet-backdrop ${open ? 'show' : ''}`} onClick={() => setOpen(false)} aria-hidden="true" />
            <div className={`msheet ${open ? 'show' : ''}`} role="dialog" aria-modal="true" aria-label="Menu lainnya" aria-hidden={!open}>
                <div className="msheet-handle" />
                <div className="msheet-head">
                    <h6>Menu Lainnya</h6>
                    <button type="button" className="msheet-close" onClick={() => setOpen(false)} aria-label="Tutup menu"><X size={18} /></button>
                </div>
                <div className="msheet-body">
                    {sections.map((s) => (
                        <section key={s.title}>
                            <div className="msheet-title">{s.title}</div>
                            <div className="msheet-grid">
                                {s.items.map((i) => {
                                    const IconCmp = SHEET_ICON[i.href] ?? Circle;
                                    return (
                                        <Link key={i.href} href={i.href} prefetch className={`msheet-item ${path === i.href ? 'active' : ''}`} tabIndex={open ? 0 : -1}>
                                            <span className="msheet-icon"><IconCmp size={20} /></span>
                                            <span>{i.title}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}
