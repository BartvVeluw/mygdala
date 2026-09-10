#!/bin/sh
set -e

if [ ! -d vendor ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# Contact-request attachments live outside the webroot, on their own named
# Docker volume (/var/www/storage — see docker-compose.yml and
# src/Service/ContactAttachmentStorage.php), which is a real ext4 mount
# (unlike the drvfs/9p-backed ./:/var/www/html bind mount, whose permission
# bits Windows ignores). Docker creates a fresh volume's mount point as
# root:root, which Apache's www-data user can't write into — fix that once
# here rather than relying on ContactAttachmentStorage's own mkdir(), which
# would run as www-data and fail on a root-owned parent.
mkdir -p /var/www/storage
chown www-data:www-data /var/www/storage

echo "Waiting for MySQL..."
tries=0
until php -r "new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" > /dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "MySQL did not become ready in time, starting anyway (check 'docker compose logs mysql')."
        break
    fi
    sleep 2
done

echo "Running database migrations..."
php vendor/bin/phinx migrate -e development || echo "Migration failed - check DB_* settings in .env and 'docker compose logs mysql'."

exec "$@"
