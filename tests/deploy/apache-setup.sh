#!/bin/bash
# Serves a course made from template/ with Apache + PHP-FPM, the way a real server would:
# course_directory/calendar is a symbolic link to a copy of this repository.
#   sudo tests/deploy/apache-setup.sh            (CI, or a throwaway machine: changes Apache's config)
# Then: php tests/deploy/smoke.php http://localhost/AB123/ /var/www/html/AB123 smoke-test-password
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
software=/opt/calendar6
course=/var/www/html/AB123
fpm="$(ls /usr/sbin/php-fpm* | head -1)"
version="$(basename "$fpm" | sed 's/php-fpm//')"

rm -rf "$software" "$course"
cp -r "$repo" "$software"
cp -r "$repo/template" "$course"
ln -s "$software" "$course/calendar"
sed -i 's/^secret   = .*/secret   = 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef/' "$course/config/config.ini"
hash="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_DEFAULT);')"
printf 'password_hash = %s\ninstructor = true\nvalid = always\n' "$hash" > "$course/config/auth/prof.ini"
mkdir -p "$course/.git" && echo 'ref: refs/heads/main' > "$course/.git/HEAD"   # a course kept in git
chmod -R a+rX "$software" "$course"

cat > /etc/apache2/conf-available/cal6-test.conf <<CONF
<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory /opt/calendar6>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
CONF
a2enmod -q proxy_fcgi setenvif alias rewrite > /dev/null
a2enconf -q "php${version}-fpm" cal6-test > /dev/null
mkdir -p /run/php
"$fpm" -D 2>/dev/null || true
apache2ctl -k restart 2>/dev/null || apache2ctl start
sleep 1
echo "serving $course at http://localhost/AB123/ (PHP-FPM $version)"
