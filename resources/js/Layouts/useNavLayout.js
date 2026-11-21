import { useEffect, useState } from 'react';

/**
 * State layout Cuba yang dipakai dashboard staf & dashboard pelanggan: sidebar buka/tutup, mode navigasi di HP
 * ('navbar' = navbar bawah, 'sidebar' = sidebar seperti desktop; disimpan di perangkat), dan tema gelap/terang.
 */
export default function useNavLayout() {
    const [closed, setClosed] = useState(() => typeof window !== 'undefined' && window.innerWidth <= 991);

    useEffect(() => {
        const onResize = () => window.innerWidth <= 991 && setClosed(true);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    const toggle = () => setClosed((c) => !c);

    const [navMode, setNavModeState] = useState(() => {
        try { return localStorage.getItem('navMode') === 'sidebar' ? 'sidebar' : 'navbar'; } catch (e) { return 'navbar'; }
    });
    const setNavMode = (next) => {
        try { localStorage.setItem('navMode', next); } catch (e) { /* abaikan */ }
        if (next === 'navbar' && window.innerWidth <= 991) setClosed(true);
        setNavModeState(next);
    };
    const toggleNavMode = () => setNavMode(navMode === 'navbar' ? 'sidebar' : 'navbar');

    const [dark, setDark] = useState(() => document.body.classList.contains('dark-only'));
    const toggleTheme = () => {
        const next = !dark;
        document.body.classList.toggle('dark-only', next);
        try { localStorage.setItem('theme', next ? 'dark' : 'light'); } catch (e) { /* abaikan */ }
        setDark(next);
    };

    return { closed, toggle, navMode, setNavMode, toggleNavMode, dark, toggleTheme };
}
