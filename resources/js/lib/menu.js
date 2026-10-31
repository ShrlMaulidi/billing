export const MENU = [
    {
        title: 'Umum',
        items: [
            { title: 'Dashboard', icon: 'home', href: '/dashboard', izin: 'dashboard.view' },
            { title: 'Dashboard Cabang', icon: 'widget', href: '/dashboard-cabang', izin: 'dashboard_cabang.view' },
            { title: 'Income Summary', icon: 'charts', href: '/income-summary', izin: 'income_summary.view' },
        ],
    },
    {
        title: 'Data & Transaksi',
        items: [
            {
                title: 'Pelanggan', icon: 'contact', base: '/pelanggan',
                children: [
                    { title: 'Data Pelanggan', href: '/pelanggan', izin: 'pelanggan.data.view' },
                    { title: 'Pelanggan On', href: '/pelanggan/on', izin: 'pelanggan.on.view' },
                    { title: 'Pelanggan Off', href: '/pelanggan/off', izin: 'pelanggan.off.view' },
                    { title: 'Pelanggan Lunas', href: '/pelanggan/lunas', izin: 'pelanggan.lunas.view' },
                    { title: 'Pelanggan Belum Lunas', href: '/pelanggan/belum-lunas', izin: 'pelanggan.belum_lunas.view' },
                    { title: 'Pelanggan Berhenti', href: '/pelanggan/berhenti', izin: 'pelanggan.berhenti.view' },
                    { title: 'Tagihan Prorate', href: '/pelanggan/prorate', izin: 'pelanggan.prorate.view' },
                ],
            },
            {
                title: 'Kelola Data', icon: 'form', base: '/kelola-data',
                children: [
                    { title: 'Kartu Member', href: '/kelola-data/kartu-member', izin: 'kelola_data.kartu_member.view' },
                    { title: 'Cabang / Lokasi', href: '/kelola-data/cabang', izin: 'kelola_data.cabang.view' },
                    { title: 'Area', href: '/kelola-data/area', izin: 'kelola_data.area.view' },
                    { title: 'Pengaturan Pajak', href: '/kelola-data/pajak', izin: 'kelola_data.pajak.view' },
                    { title: 'Paket Pelanggan', href: '/kelola-data/paket', izin: 'kelola_data.paket.view' },
                    { title: 'Router', href: '/kelola-data/router', izin: 'kelola_data.router.view' },
                    { title: 'Rekening', href: '/kelola-data/rekening', izin: 'kelola_data.rekening.view' },
                    { title: 'Notifikasi', href: '/kelola-data/notifikasi', izin: 'kelola_data.notifikasi.view' },
                    { title: 'Upload Bukti PPh', href: '/kelola-data/pph', izin: 'kelola_data.pph.view' },
                    { title: 'Tunnel', href: '/kelola-data/tunnel', izin: 'kelola_data.tunnel.view' },
                    { title: 'Template Isolir', href: '/kelola-data/template-isolir', izin: 'kelola_data.template_isolir.view' },
                ],
            },
            {
                title: 'Transaksi', icon: 'ecommerce', base: '/transaksi',
                children: [
                    { title: 'Penarikan Saldo Mitra', href: '/transaksi/penarikan-saldo', izin: 'transaksi.penarikan.view' },
                    { title: 'Setor Kewajiban Mitra', href: '/transaksi/setor-kewajiban', izin: 'transaksi.setor.view' },
                    { title: 'Pengeluaran', href: '/transaksi/pengeluaran', izin: 'transaksi.pengeluaran.view' },
                ],
            },
        ],
    },
    {
        title: 'Laporan & Sistem',
        items: [
            { title: 'Laporan', icon: 'file', href: '/laporan', izin: 'laporan.view' },
            { title: 'Payment Gateway', icon: 'board', href: '/payment-gateway', izin: 'payment_gateway.view' },
            { title: 'Logs', icon: 'to-do', href: '/logs', izin: 'logs.view' },
            {
                title: 'User', icon: 'user', base: '/user',
                children: [
                    { title: 'Manajemen User', href: '/user/manajemen', izin: 'user.manajemen.view' },
                    { title: 'Hak Akses', href: '/user/hak-akses', izin: 'user.hak_akses.view' },
                ],
            },
        ],
    },
];

// Menu yang permission lihat-nya tidak dimiliki user disembunyikan; server tetap menolak aksesnya
export const menuUntuk = (bisa) => MENU.map((group) => ({
    ...group,
    items: group.items
        .map((item) => (item.children ? { ...item, children: item.children.filter((c) => bisa(c.izin)) } : item))
        .filter((item) => (item.children ? item.children.length > 0 : bisa(item.izin))),
})).filter((group) => group.items.length > 0);
