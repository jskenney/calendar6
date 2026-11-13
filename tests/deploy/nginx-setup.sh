#!/bin/bash
# Serves the test course (course-setup.sh) with nginx + PHP-FPM on port 8080, using
# nginx-site.conf.
#   sudo tests/deploy/nginx-setup.sh --yes           (CI sets CI=true instead)
# Then: php tests/deploy/smoke.php http://localhost:8080/AB123/ /var/www/html/AB123 smoke-test-password
# (the web directory; the course itself is in /srv/cal6-test/AB123, outside the web tree)
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
bash "$here/course-setup.sh" "$@"
fpm="$(ls /usr/sbin/php-fpm* | head -1)"
version="$(basename "$fpm" | sed 's/php-fpm//')"
sed "s/PHPVERSION/php$version/" "$here/nginx-site.conf" > /etc/nginx/conf.d/cal6-test.conf
mkdir -p /run/php
"$fpm" -D 2>/dev/null || true
nginx -t -q
nginx -s reload 2>/dev/null || nginx
sleep 1
echo "nginx serves the web directory /var/www/html/AB123 at http://localhost:8080/AB123/ (PHP-FPM $version)"
