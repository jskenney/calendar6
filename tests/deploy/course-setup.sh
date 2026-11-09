#!/bin/bash
# Makes a test course for the deployment checks: a copy of this repository in /opt/calendar6
# and a course made from template/ in /var/www/html/AB123, linked as course/calendar.
#
# It deletes and recreates those two directories, so it refuses to run unless asked
# explicitly (--yes, or CI=true), and it never touches a directory it did not make
# (each gets a marker file).
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
software=/opt/calendar6
course=/var/www/html/AB123
marker=.cal6-deployment-test

if [[ "${1:-}" != "--yes" && "${CI:-}" != "true" ]]; then
    echo "This replaces $software and $course. Run it only on a test machine, with --yes." >&2
    exit 2
fi
for dir in "$software" "$course"; do
    if [[ -e "$dir" && ! -e "$dir/$marker" ]]; then
        echo "$dir exists and was not made by this script; refusing to replace it." >&2
        exit 3
    fi
done

rm -rf "$software" "$course"
cp -r "$repo" "$software"
cp -r "$repo/template" "$course"
touch "$software/$marker" "$course/$marker"
ln -s "$software" "$course/calendar"
sed -i 's/^secret   = .*/secret   = 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef/' "$course/config/config.ini"
hash="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_DEFAULT);')"
printf 'password_hash = %s\ninstructor = true\nvalid = always\n' "$hash" > "$course/config/auth/prof.ini"
mkdir -p "$course/.git" && echo 'ref: refs/heads/main' > "$course/.git/HEAD"   # a course kept in git
mkdir -p "$course/tools" && echo '<?php echo "RAN-DIRECTLY";' > "$course/tools/report.php"   # stray PHP
for ext in PHP phtml php5 phar; do cp "$course/tools/report.php" "$course/tools/x.$ext"; done
echo '<?php $db_password = "x";' > "$course/backup.php~" && cp "$course/backup.php~" "$course/db.inc"
chmod -R a+rX "$software" "$course"
