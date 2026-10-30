#!/usr/bin/env bash
# Update aplikasi di server (Ubuntu 24.04 + Nginx + PHP 8.4-FPM).
# Jalankan dari folder aplikasi:  sudo bash deploy.sh
set -euo pipefail
cd "$(dirname "$0")"
export COMPOSER_ALLOW_SUPERUSER=1

echo "→ Cek konfigurasi keamanan .env"
# APP_DEBUG=true menampilkan detail error (termasuk isi .env) ke pengunjung
grep -qE '^APP_ENV=production$' .env || { echo "✗ APP_ENV di .env harus production"; exit 1; }
grep -qE '^APP_DEBUG=false$' .env || { echo "✗ APP_DEBUG di .env harus false"; exit 1; }
grep -qE '^SESSION_SECURE_COOKIE=true$' .env || echo "! Peringatan: set SESSION_SECURE_COOKIE=true bila situs diakses lewat HTTPS"

echo "→ Ambil kode terbaru"
git -c safe.directory="$PWD" pull --ff-only origin main

echo "→ Dependency PHP & build frontend"
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
rm -f public/hot

echo "→ Database & cache"
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

echo "→ Hak akses file & reload PHP"
chown -R www-data:www-data .
chmod -R 775 storage bootstrap/cache database
# .env berisi APP_KEY (kunci enkripsi password router) & password database: jangan bisa dibaca user lain
chmod 640 .env
systemctl reload php8.4-fpm

echo "✓ Deploy selesai"
