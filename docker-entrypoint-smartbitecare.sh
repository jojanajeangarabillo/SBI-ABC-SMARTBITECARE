#!/bin/sh
set -eu

APP_PORT="${PORT:-8080}"

# Railway assigns a PORT. Make Apache listen on it.
sed -ri "s/^Listen .*/Listen ${APP_PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${APP_PORT}>/" /etc/apache2/sites-available/000-default.conf

cat > /etc/apache2/conf-available/smartbitecare.conf <<EOF
<Directory /var/www/html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex landing.php login.php index.php index.html
</Directory>
EOF

a2enconf smartbitecare >/dev/null 2>&1 || true

mkdir -p /var/www/html/uploads/documents
chown -R www-data:www-data /var/www/html/uploads

exec apache2-foreground
