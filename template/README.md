# Calendar 6 course template

A starting point for a course. A course uses one of two layouts (see `../API.md`, section 2, and
`../DESIGN.md`). The plain layout:

```
course_directory/
├── calendar/            the calendar6 software: a symbolic link to the repo, or a clone
├── config/              every .ini file (never served directly)
│   └── auth/            one file per account (instructors and staff; students never log in)
├── class/, lab/, ...    one directory per event type, then <sort>.<event title>/
├── pages/               the pages named in config/paths.ini
├── shared/              files used by config/virtual.ini
└── index.html           opens the calendar (the front end, calendar/app/, is not built yet:
                         until it is, index.html leads nowhere; the endpoints in
                         calendar/php/ already work)
```

The web layout keeps the software beside a separate front end, under `web/`:

```
course_directory/
├── web/
│   ├── calendar/        the calendar6 software: a symbolic link to the repo, or a clone
│   ├── html/            the front end (a separate repository, or a link to one)
│   └── epub-reader/     optional, if the front end uses it
├── index.html           usually a symbolic link to web/html/index.html
├── config/, class/, lab/, pages/, shared/   as in the plain layout
```

All of `web/` (like `config/`) is closed to the endpoints: nothing in it is ever course material.

## Starting a course

1. Copy this directory to the course's web directory:

   ```bash
   cp -r /path/to/calendar6/template/. /home/faculty/username/public_html/AB123/
   ```

2. Link the software into it (or clone the repo there). For the plain layout:

   ```bash
   ln -s /path/to/calendar6 /home/faculty/username/public_html/AB123/calendar
   ```

   For the web layout (and replace the template's `index.html` with a link to the front end's):

   ```bash
   mkdir -p /home/faculty/username/public_html/AB123/web
   ln -s /path/to/calendar6 /home/faculty/username/public_html/AB123/web/calendar
   ln -sf web/html/index.html /home/faculty/username/public_html/AB123/index.html
   ```

3. Edit `config/config.ini`: course number and name, semester, `secret`
   (`php -r 'echo bin2hex(random_bytes(32)), "\n";'`), the weekly `[schedule]`.
4. Edit `config/dates.ini` (holidays), `config/sections.ini` (or delete it), and
   `config/default.ini` (when things open).
5. Rename `config/auth/instructor.ini` to `<your username>.ini` and put in a password hash
   (`php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'`). Delete
   `assistant.ini` unless you need it.
6. Keep every `.htaccess`: the one at the course root (it refuses `.git/` and other dot names, and
   `.ini` files), and those in `config/`, the material directories, `pages/` and `shared/`, which
   must never be reachable directly. Add the same file to each new event-type directory. `cp -r
   template/.` copies the root `.htaccess` too.
7. Check the result: `php calendar/tests/deploy/smoke.php https://server/AB123/
   /path/to/AB123` (`web/calendar/tests/...` in the web layout) checks the endpoints and that
   nothing protected can be fetched directly; it works out the layout from the course directory.
   Add the password of an account named `prof` to check a login too.

All paths in the `.ini` files are relative to the course directory, and nothing outside it is
ever served.

On nginx, behind a reverse proxy, or on shared hosting, read "Deploying" in `../DESIGN.md`
first (the `.htaccess` files only work on Apache).

`config/periods.ini` holds the school's period schedules; keep it unless the periods change.
