#!/bin/sh
set -e
cd /var/www/backend
if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# MySQL 起動待ち（初回 compose up で connection refused になりやすい）
for i in 1 2 3 4 5 6 7 8 9 10; do
  if php artisan migrate --force 2>/dev/null; then
    break
  fi
  echo "Waiting for database... ($i/10)"
  sleep 2
done

php artisan migrate --force
exec "$@"
