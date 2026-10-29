#!/usr/bin/env bash
# Update aplikasi di server (Ubuntu 24.04 + Nginx + PHP 8.4-FPM).
# Jalankan dari folder aplikasi:  sudo bash deploy.sh
set -euo pipefail
cd "$(dirname "$0")"
export COMPOSER_ALLOW_SUPERUSER=1

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
systemctl reload php8.4-fpm

echo "✓ Deploy selesai"
