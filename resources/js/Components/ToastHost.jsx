import { useEffect, useState } from 'react';
import { Toast, ToastBody } from 'reactstrap';

export default function ToastHost() {
    const [items, setItems] = useState([]);

    useEffect(() => {
        const onToast = (e) => {
            const id = Date.now() + Math.random();
            setItems((list) => [...list, { id, ...e.detail }]);
            setTimeout(() => setItems((list) => list.filter((t) => t.id !== id)), 3500);
        };
        window.addEventListener('app:toast', onToast);
        return () => window.removeEventListener('app:toast', onToast);
    }, []);

    return (
        <div className="toast-host">
            {items.map((t) => (
                <Toast key={t.id} className={`bg-${t.type} text-white border-0`}>
                    <ToastBody>{t.message}</ToastBody>
                </Toast>
            ))}
        </div>
    );
}
