#!/bin/bash
# Makes a test course for the deployment checks: a copy of this repository in /opt/calendar6,
# a course made from template/course/ in /srv/cal6-test/AB123 (outside the web tree), and its
# web directory /var/www/html/AB123 made from template/web/: the calendar link, course.ini.
#
# It deletes and recreates those three directories, so it refuses to run unless asked
# explicitly (--yes, or CI=true), and it never touches a directory it did not make
# (each gets a marker file).
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
software=/opt/calendar6
course=/srv/cal6-test/AB123
web=/var/www/html/AB123
marker=.cal6-deployment-test

if [[ "${1:-}" != "--yes" && "${CI:-}" != "true" ]]; then
    echo "This replaces $software, $course and $web. Run it only on a test machine, with --yes." >&2
    exit 2
fi
for dir in "$software" "$course" "$web"; do
    if [[ -e "$dir" && ! -e "$dir/$marker" ]]; then
        echo "$dir exists and was not made by this script; refusing to replace it." >&2
        exit 3
    fi
done

rm -rf "$software" "$course" "$web"
mkdir -p "$(dirname "$course")"
cp -r "$repo" "$software"
cp -r "$repo/template/course" "$course"
cp -r "$repo/template/web" "$web"
touch "$software/$marker" "$course/$marker" "$web/$marker"
ln -s "$software" "$web/calendar"
printf '[course]\nroot = %s\n' "$course" > "$web/course.ini"
sed -i 's/^secret   = .*/secret   = 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef/' "$course/config/config.ini"
hash="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_DEFAULT);')"
printf 'password_hash = %s\ninstructor = true\nvalid = always\n' "$hash" > "$course/config/auth/prof.ini"
# stray files in the web directory, which its .htaccess (or the nginx rules) must refuse
mkdir -p "$web/.git" && echo 'ref: refs/heads/main' > "$web/.git/HEAD"   # a web directory kept in git
mkdir -p "$web/tools" && echo '<?php echo "RAN-DIRECTLY";' > "$web/tools/report.php"   # stray PHP
for ext in PHP phtml php5 phar phtm phar_old php.save; do cp "$web/tools/report.php" "$web/tools/x.$ext"; done
echo '<?php $db_password = "x";' > "$web/backup.php~" && cp "$web/backup.php~" "$web/db.inc"
echo "secret = x" > "$web/tools/settings.INI"   # a stray settings file, upper case
echo "# notes" > "$web/README.md"
chmod -R a+rX "$software" "$course" "$web"
chmod a+x "$(dirname "$course")"
