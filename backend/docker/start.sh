#!/usr/bin/env bash
set -e

# Render assigns a dynamic port via the PORT environment variable (default: 80)
PORT="${PORT:-80}"
echo "==> Configuring Apache to listen on port ${PORT}..."
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# Ensure storage directories exist with correct permissions
echo "==> Ensuring storage and cache permissions..."
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create storage symlink
echo "==> Linking storage..."
php artisan storage:link || true

# Optimize configuration and route caching in production
echo "==> Caching configuration, routes, and views..."
php artisan config:cache || echo "Config cache skipped or failed"
php artisan route:cache || echo "Route cache skipped or failed"
php artisan view:cache || echo "View cache skipped or failed"

# Run database migrations
echo "==> Running database migrations against Supabase..."
php artisan migrate --force || echo "Warning: Migration failed. Check Supabase connection credentials."

# Optionally seed the database on initial deployment (set SEED_DATABASE=true in Render environment)
if [ "${SEED_DATABASE}" = "true" ]; then
    echo "==> SEED_DATABASE is true. Seeding database..."
    php artisan db:seed --force || echo "Warning: Database seeding failed."
fi

echo "==> Starting Apache web server..."
exec apache2-foreground
