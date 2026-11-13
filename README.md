# Calendar 6

A course calendar that is mostly static HTML/JS, with small PHP endpoints only where something
must be protected. See [API.md](API.md) for the endpoints (everything a front end needs), [DESIGN.md](DESIGN.md) for how it works and [template/](template/) for
a starting point for a course.

A course is two directories: the **course directory**, anywhere outside the web tree, and a
**web directory** that the web server serves. `course.ini` in the web directory says where the
course directory is:

```
/home/faculty/bob/AB123/              the course: config/ (the .ini files), class/, lab/, ...
                                      (the material), pages/, shared/

/home/faculty/bob/public_html/AB123/  the web directory
├── calendar/      this repository (a symbolic link to it, or a clone)
├── course.ini     [course] root = /home/faculty/bob/AB123
├── html/          the front end (its own repository)
├── reader/        optional
└── index.html  -> html/index.html
```

The endpoints are at `calendar/php/<name>.php` in the web directory. The course is never in the
web tree, so the web server can not send `config/` or the material directly. See
[API.md](API.md), section 2, and [template/](template/).

## Status

The PHP endpoints are implemented: `php/config.php`, `php/calendar.php`, `php/get.php`,
`php/html.php`, `php/md.php` and `php/auth.php`. The browser front end (`html/` in the web directory, a separate repository) is not started yet, so the
template's `index.html` leads nowhere for now. Code and security findings are tracked in
[REVIEW.md](REVIEW.md); deployment notes are under "Deploying" in DESIGN.md.

## Tests

PHP 8.3 or newer, with the curl and mbstring extensions:

```bash
php tests/run.php
```

`php tests/run.php unit` or `php tests/run.php http` runs one suite; any other word runs the
test files whose names contain it. The HTTP tests copy `template/course/` into a temporary course
directory, make a web directory beside it (the `calendar` link and a `course.ini`) and serve that
with PHP's built-in server.

Time-dependent behaviour (release times, `<postmeeting>`, session and account expiry) is tested
end to end with a clock the tests control: under PHP's built-in server only, the environment
variable `CAL6_TEST_CLOCK` names a file holding the current time as a Unix timestamp. Any other
server ignores it.

### Deployment checks

`tests/deploy/` checks a real web server serving a web directory (the `calendar` link and
`course.ini`) for a course that lives outside the web tree:

```bash
php tests/deploy/smoke.php https://server/~bob/AB123/ /home/faculty/bob/public_html/AB123
```

It checks the endpoints and that `course.ini`, `config/`, the material, `.git/`, stray PHP and
the software's own files can not be fetched directly; with the web directory and the password of
an account named `prof` it checks a login too. On a throwaway machine (or in CI), `apache-setup.sh --yes` and
`nginx-setup.sh --yes` build a test course and serve it with Apache or nginx and PHP-FPM;
`nginx-site.conf` is the reference nginx configuration.
