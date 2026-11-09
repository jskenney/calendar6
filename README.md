# Calendar 6

A course calendar that is mostly static HTML/JS, with small PHP endpoints only where something
must be protected. See [DESIGN.md](DESIGN.md) for how it works and [template/](template/) for
a starting point for a course.

```
course_directory/
├── calendar/      this repository (a symbolic link to it, or a clone)
├── config/        the course's .ini files
├── class/, lab/   the material: <type>/<sort>.<Title>/
└── ...
```

## Status

The PHP endpoints are implemented: `php/config.php`, `php/calendar.php`, `php/get.php`,
`php/html.php` and `php/auth.php`. The browser front end (`app/`) is not started yet, so the
template's `index.html` leads nowhere for now. Code and security findings are tracked in
[REVIEW.md](REVIEW.md); deployment notes are under "Deploying" in DESIGN.md.

## Tests

PHP 8.3 or newer, with the curl and mbstring extensions:

```bash
php tests/run.php
```

`php tests/run.php unit` or `php tests/run.php http` runs one suite; any other word runs the
test files whose names contain it. The HTTP tests copy `template/` into a temporary course and
serve it with PHP's built-in server.
