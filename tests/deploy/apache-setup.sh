#!/bin/bash
# Serves the test course (course-setup.sh) with Apache + PHP-FPM on port 80.
#   sudo tests/deploy/apache-setup.sh --yes          (CI sets CI=true instead)
# Then: php tests/deploy/smoke.php http://localhost/AB123/ /var/www/html/AB123 smoke-test-password
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
bash "$here/course-setup.sh" "$@"
fpm="$(ls /usr/sbin/php-fpm* | head -1)"
version="$(basename "$fpm" | sed 's/php-fpm//')"

cat > /etc/apache2/conf-available/cal6-test.conf <<CONF
<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride AuthConfig
    Require all granted
</Directory>
<Directory /opt/calendar6>
    Options FollowSymLinks
    AllowOverride AuthConfig
    Require all granted
</Directory>
CONF
a2enmod -q proxy_fcgi setenvif > /dev/null
a2enconf -q "php${version}-fpm" cal6-test > /dev/null
mkdir -p /run/php
"$fpm" -D 2>/dev/null || true
apache2ctl -k restart 2>/dev/null || apache2ctl start
sleep 1
echo "Apache serves /var/www/html/AB123 at http://localhost/AB123/ (PHP-FPM $version)"
