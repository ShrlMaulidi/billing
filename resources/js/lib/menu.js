export const MENU = [
    {
        title: 'Umum',
        items: [
            { title: 'Dashboard', icon: 'home', href: '/dashboard' },
            { title: 'Dashboard Cabang', icon: 'widget', href: '/dashboard-cabang' },
            { title: 'Income Summary', icon: 'charts', href: '/income-summary' },
        ],
    },
    {
        title: 'Data & Transaksi',
        items: [
            {
                title: 'Pelanggan', icon: 'contact', base: '/pelanggan',
                children: [
                    { title: 'Data Pelanggan', href: '/pelanggan' },
                    { title: 'Pelanggan On', href: '/pelanggan/on' },
                    { title: 'Pelanggan Off', href: '/pelanggan/off' },
                ],
            },
            {
                title: 'Kelola Data', icon: 'form', base: '/kelola-data',
                children: [
                    { title: 'Kartu Member', href: '/kelola-data/kartu-member' },
                    { title: 'Cabang / Lokasi', href: '/kelola-data/cabang' },
                    { title: 'Area', href: '/kelola-data/area' },
                    { title: 'Pengaturan Pajak', href: '/kelola-data/pajak' },
                    { title: 'Paket Pelanggan', href: '/kelola-data/paket' },
                    { title: 'Router', href: '/kelola-data/router', superAdmin: true },
                    { title: 'Rekening', href: '/kelola-data/rekening' },
                    { title: 'Notifikasi', href: '/kelola-data/notifikasi' },
                    { title: 'Upload Bukti PPh', href: '/kelola-data/pph' },
                    { title: 'Tunnel', href: '/kelola-data/tunnel', superAdmin: true },
                    { title: 'Template Isolir', href: '/kelola-data/template-isolir', superAdmin: true },
                ],
            },
            {
                title: 'Transaksi', icon: 'ecommerce', base: '/transaksi',
                children: [
                    { title: 'Penarikan Saldo Mitra', href: '/transaksi/penarikan-saldo' },
                    { title: 'Setor Kewajiban Mitra', href: '/transaksi/setor-kewajiban' },
                    { title: 'Pengeluaran', href: '/transaksi/pengeluaran' },
                ],
            },
        ],
    },
    {
        title: 'Laporan & Sistem',
        items: [
            { title: 'Laporan', icon: 'file', href: '/laporan' },
            { title: 'Payment Gateway', icon: 'board', href: '/payment-gateway' },
            { title: 'Logs', icon: 'to-do', href: '/logs' },
            {
                title: 'User', icon: 'user', base: '/user', superAdmin: true,
                children: [
                    { title: 'Manajemen User', href: '/user/manajemen' },
                    { title: 'Hak Akses', href: '/user/hak-akses' },
                ],
            },
        ],
    },
];

// Menu superAdmin (router, tunnel, script isolir, user) disembunyikan dari role lain; server tetap menolak aksesnya
const bolehLihat = (role) => (item) => !item.superAdmin || role === 'Super Admin';
export const menuUntuk = (role) => MENU.map((group) => ({
    ...group,
    items: group.items.filter(bolehLihat(role)).map((item) => (item.children ? { ...item, children: item.children.filter(bolehLihat(role)) } : item)),
}));
