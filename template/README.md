# Calendar 6 course template

A starting point for a course. Every course has this layout (see `../DESIGN.md`):

```
course_directory/
├── calendar/            the calendar6 software: a symbolic link to the repo, or a clone
├── config/              every .ini file (never served directly)
│   └── auth/            one file per account (instructors and staff; students never log in)
├── class/, lab/, ...    one directory per event type, then <sort>.<event title>/
├── pages/               the pages named in config/paths.ini
├── shared/              files used by config/virtual.ini
└── index.html           opens the calendar
```

## Starting a course

1. Copy this directory to the course's web directory:

   ```bash
   cp -r /path/to/calendar6/template/. /home/faculty/username/public_html/AB123/
   ```

2. Link the software into it (or clone the repo there as `calendar/`):

   ```bash
   ln -s /path/to/calendar6 /home/faculty/username/public_html/AB123/calendar
   ```

3. Edit `config/config.ini`: course number and name, semester, `secret`
   (`php -r 'echo bin2hex(random_bytes(32)), "\n";'`), the weekly `[schedule]`.
4. Edit `config/dates.ini` (holidays), `config/sections.ini` (or delete it), and
   `config/default.ini` (when things open).
5. Rename `config/auth/instructor.ini` to `<your username>.ini` and put in a password hash
   (`php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'`). Delete
   `assistant.ini` unless you need it.
6. Keep every `.htaccess`: `config/`, the material directories, `pages/` and `shared/` must never
   be reachable directly. Add the same file to each new event-type directory.

All paths in the `.ini` files are relative to the course directory, and nothing outside it is
ever served.

`config/periods.ini` is the school's default bell schedule; keep it unless the periods change.
