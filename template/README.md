# Calendar 6 course template

A starting point for a course. A course is two directories (see `../API.md`, section 2, and
"Course layout" in `../DESIGN.md`):

```
/home/faculty/bob/AB123/              the course directory (copy course/ here): anywhere,
├── config/                           OUTSIDE the web tree
│   └── auth/                         one file per account (instructors and staff)
├── class/, lab/, ...                 one directory per event type, then <sort>.<event title>/
├── pages/                            the pages named in config/pages.ini
└── shared/                           files used by config/virtual.ini

/home/faculty/bob/public_html/AB123/  the web directory (copy web/ here): what the web
├── calendar/                         server serves; the calendar6 software (a link or a clone)
├── course.ini                        [course] root = /home/faculty/bob/AB123
├── html/                             the front end (a separate repository, or a link to one)
├── reader/                           optional, if the front end uses it
└── index.html                        usually a link to html/index.html
```

Because the course directory is outside the web tree, the web server can never send its files
directly: no `.htaccess` is needed to protect `config/` or the material. Do **not** link the
course into the web directory; `course.ini` is the only connection.

## Starting a course

1. Copy the two halves:

   ```bash
   cp -r /path/to/calendar6/template/course /home/faculty/bob/AB123
   cp -r /path/to/calendar6/template/web/. /home/faculty/bob/public_html/AB123/
   ```

2. Link the software into the web directory (or clone the repo there), and point `course.ini` at
   the course directory (absolute, or relative to `course.ini`):

   ```bash
   ln -s /path/to/calendar6 /home/faculty/bob/public_html/AB123/calendar
   printf '[course]\nroot = /home/faculty/bob/AB123\n' > /home/faculty/bob/public_html/AB123/course.ini
   ```

   Once the front end is in `html/`, link its entry page:

   ```bash
   ln -sf html/index.html /home/faculty/bob/public_html/AB123/index.html
   ```

3. Edit `config/config.ini` (in the course directory): course number and name, semester,
   `secret` (`php -r 'echo bin2hex(random_bytes(32)), "\n";'`), the weekly `[schedule]`.
4. Edit `config/dates.ini` (holidays), `config/sections.ini` (or delete it), and
   `config/default.ini` (when things open).
5. Rename `config/auth/instructor.ini` to `<your username>.ini` and put in a password hash
   (`php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'`). Delete
   `assistant.ini` unless you need it.
6. Optional hardening: keep `.htaccess` in the web directory (Apache): it refuses dot files,
   `.ini` files (so `course.ini` does not show the course's path), Markdown files, and any PHP
   other than the endpoints in `calendar/php/`. If you name the software's directory something
   other than `calendar`, change `calendar` in that file too (and in nginx rules), or the
   endpoints answer 403.
7. Check the result: `php /path/to/calendar6/tests/deploy/smoke.php https://server/~bob/AB123/
   /home/faculty/bob/public_html/AB123` checks the endpoints and that nothing protected can be
   fetched directly. Add the password of an account named `prof` to check a login too.

All paths in the `.ini` files are relative to the course directory, and nothing outside it is
ever served. PHP must be able to read the course directory (on hosts with `open_basedir`, add it).

On nginx, behind a reverse proxy, or on shared hosting, read "Deploying" in `../DESIGN.md`
first.

`config/periods.ini` holds the school's period schedules; keep it unless the periods change.
