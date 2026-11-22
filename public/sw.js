/*
 * Service worker MSS Billing: syarat agar aplikasi bisa dipasang (PWA) dan menampilkan halaman "tidak ada koneksi".
 * Sengaja TIDAK menyimpan halaman atau data aplikasi (tagihan, data pelanggan) ke cache: semua permintaan tetap ke server,
 * sehingga data selalu terbaru dan tidak tertinggal di perangkat setelah logout. Hanya halaman offline yang disimpan.
 */
const CACHE = 'mss-billing-offline-v1';
const HALAMAN_OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([HALAMAN_OFFLINE, '/pwa/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((nama) => Promise.all(nama.filter((n) => n !== CACHE).map((n) => caches.delete(n)))).then(() => self.clients.claim()),
    );
});

// Hanya pindah halaman (navigasi) yang ditangani: bila jaringan putus, tampilkan halaman offline
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(() => caches.match(HALAMAN_OFFLINE)));
});
