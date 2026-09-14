#!/bin/sh
set -eu

APP_PORT="${PORT:-8080}"

echo "Starting SmartBiteCare container..."
echo "Railway port: ${APP_PORT}"

# ============================================================
# FIX APACHE MPM CONFLICT
# PHP's Apache image should use mpm_prefork only.
# ============================================================

echo "Configuring Apache MPM..."

rm -f \
    /etc/apache2/mods-enabled/mpm_event.load \
    /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_worker.load \
    /etc/apache2/mods-enabled/mpm_worker.conf

# Make sure prefork is enabled
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -s /etc/apache2/mods-available/mpm_prefork.load \
          /etc/apache2/mods-enabled/mpm_prefork.load
fi

if [ -e /etc/apache2/mods-available/mpm_prefork.conf ] && \
   [ ! -e /etc/apache2/mods-enabled/mpm_prefork.conf ]; then
    ln -s /etc/apache2/mods-available/mpm_prefork.conf \
          /etc/apache2/mods-enabled/mpm_prefork.conf
fi

echo "Enabled MPM modules:"
ls -la /etc/apache2/mods-enabled/mpm* 2>/dev/null || true


# ============================================================
# RAILWAY PORT
# ============================================================

sed -ri \
    "s/^Listen .*/Listen ${APP_PORT}/" \
    /etc/apache2/ports.conf

sed -ri \
    "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${APP_PORT}>/" \
    /etc/apache2/sites-available/000-default.conf


# ============================================================
# SMARTBITECARE APACHE CONFIG
# ============================================================

cat > /etc/apache2/conf-available/smartbitecare.conf <<EOF
<Directory /var/www/html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted

    DirectoryIndex landing.php login.php index.php index.html
</Directory>
EOF

a2enconf smartbitecare >/dev/null 2>&1 || true


# ============================================================
# UPLOAD DIRECTORIES
# ============================================================

mkdir -p /var/www/html/uploads/documents

chown -R www-data:www-data /var/www/html/uploads


# ============================================================
# VERIFY APACHE BEFORE STARTING
# ============================================================

echo "Testing Apache configuration..."

apache2ctl configtest

echo "Apache configuration OK."
echo "Starting Apache on port ${APP_PORT}..."


# ============================================================
# START APACHE
# ============================================================

exec apache2-foreground