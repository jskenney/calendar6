# Calendar 6: design

Status: **draft**, being defined. Sections marked **Decided** follow the agreed structure;
sections marked **Proposed** are suggestions still to be confirmed; open questions are
collected at the end.

## Goal

A course site that is **mostly static HTML/JS**: the browser loads a plain page, asks small
PHP endpoints for JSON, and renders the calendar itself. PHP is used **only where something
must be protected**: authentication, the list of what the viewer may see, and handing out
files. Nothing else runs on the server.

One copy of the software serves many courses: each course's web directory links to it (most
likely `calendar -> /path/to/calendar6`), so a course sees `./calendar/php/get.php`, for
example `/home/faculty/username/public_html/AB123/calendar/php/get.php`.

## Repository layout (Decided, with a proposed front end)

```
calendar6/                    the shared software (linked into each course as calendar/)
├── php/                      the only server-side code
│   ├── auth.php              log in / log out (POST only)
│   ├── config.php            the site configuration, without secrets (JSON)
│   ├── calendar.php          everything this viewer may see (JSON)
│   ├── get.php               one file, by key, with its MIME type
│   ├── html.php              one HTML page, by key, processed (keys, tags)
│   └── <pointer>.json        where the course's config directory is (see "Finding the
│                             course", proposed change)
└── (proposed) app/           the static front end: index.html, app.js, app.css, vendored
                              libraries (MathJax, highlight.js)
```

**Rule (Decided):** `get.php` and `html.php` are the only scripts that return course files.
Everything else references them, for example `<img src="php/get.php?file=KEY">`.

## Course layout (Decided)

```
AB123/                        the course's web directory
├── calendar -> ...           the shared software
├── config/
│   ├── .htaccess             deny all (in case config/ is inside the web root)
│   ├── config.ini            the main configuration
│   ├── dates.ini             days that differ from the weekly schedule
│   ├── default.ini           when things become available to anonymous users
│   └── auth/
│       └── <username>.ini    one file per authorized user
└── (material)                proposed: class/, lab/, exam/, ... as today (open question 1)
```

## The PHP endpoints

All endpoints answer with explicit headers: `X-Content-Type-Options: nosniff`, no caching of
personalised answers (`Cache-Control: private, no-store`), and JSON as
`application/json; charset=utf-8`. Errors are JSON too (`{"error": "..."}`) with the right
status code (400, 401, 403, 404, 405, 429).

### `auth.php` (Decided: POST only, session, JSON)

- `POST username + password`: checks `config/auth/<username>.ini` (hashed password,
  `password_verify`), checks the account's valid-from / valid-to, then stores the login in the
  PHP session and returns `{"user": "...", "valid_until": "...", "grants": [...]}`.
- `POST` without credentials: logs out (destroys the login) and returns `{"user": null}`.
- Anything other than POST: `405`.
- Proposed hardening: a new session id at login (`session_regenerate_id`), cookie flags
  `HttpOnly`, `Secure` (on HTTPS), `SameSite=Strict`; rate limiting of failed logins per
  address and per username; constant-time comparisons; a session lifetime from `config.ini`.
  Because it is a JSON POST from our own page, a CSRF token is cheap to add (the token comes
  with `config.php`); proposed, so another site can not log a user out or in.

### `config.php` (Decided: the site config without secrets)

Returns what the front end needs to draw the site: course number and name, semester dates,
weekly schedule, the days from `dates.ini`, box names and labels, menus, time zone, and the
current viewer (`anonymous` or the username). Never: password hashes, the secret, file paths
on the server, or the content of `default.ini` / `auth/`.

### `calendar.php` (Decided: everything this viewer may see)

Walks the course's material, applies the access rules for the current viewer (anonymous, or
the logged-in user's extra grants) **at this moment**, and returns one JSON document: every
calendar day, its events, and for each event the boxes and files the viewer may see, each with
its **key**, kind (page / file), and title. Things the viewer may not see are left out
entirely (not marked hidden), except, proposed, an "opens at" time when the rules allow showing
that something is coming.

Proposed: cache the walk of the file system per course (invalidated by modification times) and
apply only the per-viewer access filter per request; send an `ETag`.

### `get.php` (Decided: one file by key, with the right MIME type)

`get.php?file=KEY`: looks the key up, **checks the access rule for the current viewer again**
(a key alone never grants access), and streams the file with its MIME type, `Content-Length`,
`Range` support (video, large PDFs), and inline/attachment by type. Unknown key or not allowed:
`404` (the same answer for both, so hidden files can not be discovered). Server-side scripts
(`.php` and similar) are never sent.

### `html.php` (Decided: one HTML page by key, processed inline)

`html.php?file=KEY`: the same key lookup and access check as `get.php`, then the page is
processed and returned as `text/html`:

- links to files of the same event are rewritten to keys, as Calendar 4 does: `<img src="pic.png">`
  becomes `php/get.php?file=KEY`, `<a href="notes.html">` becomes `php/html.php?file=KEY` (or the
  front end's route to it), `<source src>`, `<a href>` to downloads, ...;
- proposed: the content tags that must be decided on the server stay server-side, because
  hidden content must never reach the browser: `<inst>` (removed unless allowed),
  `<student>` (answers), `<postmeeting>`, `<lock>`; the others (`<inject>`, `<replace>`) too,
  for compatibility. Which tags survive is open question 6.

## Access model

### Viewers (Proposed wording of the decided idea)

| Viewer | Sees |
|---|---|
| **Anonymous** | What `default.ini` releases, at the times it says |
| **A user** (`auth/<username>.ini`) | Everything anonymous sees, plus the user's **grants**, during the user's valid period |
| **Instructor** (proposed) | Everything, always: a user with the grant `*` |

### `default.ini` (Decided: when things are available to anonymous users)

Proposed: the same targets and times as Calendar 5's release rules (minutes, `until`, relative
to the meeting), written as INI:

```ini
; target = when
class/title             = -2 08:00
class/homework-solution = +7 17:00 until +21 23:59
lab#6                   = 2026-10-15 13:30
exam                    = never
```

### `auth/<username>.ini` (Decided fields, proposed syntax)

```ini
password_hash = $2y$12$...          ; password_hash(), never plain text
valid_from    = 2026-08-24          ; optional: the default comes from config.ini
valid_to      = 2026-12-18          ; optional
grants        = lab#6, lab#6/solution, lab#6/html, lab#*, exam#*
```

Proposed grant grammar, from specific to general:

| Grant | Means |
|---|---|
| `lab#6` | everything in lab 6 |
| `lab#6/solution` | the `solution` box of lab 6 |
| `lab#6/notes.html` | one file of lab 6 |
| `lab#*` | every lab (`lab#*/solution`: the solution box of every lab) |
| `exam#*` | every exam |
| `*` | everything (the instructor) |

Proposed: a grant may carry its own window, `lab#6 = 2026-10-15 13:30 until 2026-10-22`, for
extensions and accommodations.

## Keys (Proposed)

A key identifies a file; it is **not** a capability, because `get.php` and `html.php` check
access on every request. Proposed: `HMAC-SHA256(secret, relative path)`, shortened, so keys are
stable (bookmarks keep working), and hidden file names can not be guessed or enumerated. The
secret lives in `config.ini`.

## Finding the course (Proposed change)

The software is shared by many courses through a link, so a single JSON pointer file inside
`/php/` can not point to each course's `config/`. Proposed instead:

1. The course directory is found from the request: `$_SERVER['SCRIPT_FILENAME']` keeps the
   linked path (`.../AB123/calendar/php/get.php`), so the course is two levels above `php/`
   (`__DIR__` would resolve the link and point into the shared repository).
2. Its configuration is `<course>/config/` by default.
3. A course may keep its configuration **outside the web root** (recommended: it holds password
   hashes) with a small pointer file in the course directory, for example
   `<course>/calendar.json` → `{"config": "/home/faculty/username/private/AB123"}`.

## The front end (Proposed)

- `index.html` in the course (a link to `calendar/app/index.html`, or a two-line file) loads
  `app.js`, which fetches `config.php` and `calendar.php` and draws the calendar, menus and
  pages. Navigation uses `#` routes (`#/lab/6`), so every view is a static page and deep links
  work.
- Pages are fetched from `html.php` and placed into the page (MathJax and highlight.js run
  after insertion); scripts inside course pages need special handling (open question 5).
- After `auth.php`, the app reloads `calendar.php`, so the view matches the new viewer.

## Security summary (Proposed)

- Material directories and `config/` must not be reachable directly: `.htaccess` (Apache) or
  server rules (nginx). Proposed: keep material and configuration outside the web root, which
  this design makes possible since only `php/` touches them.
- Access is checked where files are served (`get.php`, `html.php`), not only in `calendar.php`.
- One answer (`404`) for "no such key" and "not allowed".
- No secrets in any JSON; JSON only from `config.php`, `calendar.php`, `auth.php`.

## Open questions

1. **Material layout:** keep Calendar 4's `class/03.Name/` directories and boxes chosen by file
   name, or something new (for example a small `event.ini` per event)?
2. **Compatibility:** does Calendar 6 need to read v4 / Cal5 courses (`calendar.php`, access
   files, keys), or is a one-time converter enough?
3. **Pointer file:** keep the JSON pointer in `/php/` (works only with one course per copy), or
   the proposed per-course pointer?
4. **Instructor:** a user with `*`, or a separate role (with tools such as a status page)?
5. **Course pages with scripts:** pages are placed into the app (scripts must be re-run), shown in
   an `<iframe>` from `html.php` (isolated, simplest), or opened as whole pages?
6. **Tags:** which Calendar 4 tags survive (`inst`, `student`, `postmeeting`, `lock`, `inject`,
   `replace`, `url`, `host`, `ace`, `calphp`)? `calphp` runs PHP from course directories;
   dropping it fits "PHP only for protection".
7. **Search:** in the browser over what `calendar.php` returns (titles only), or a `search.php`
   that searches the pages the viewer may see?
8. **`config.json` vs `config.ini`:** the notes mention both; the proposal uses INI for
   everything a person edits and JSON only on the wire.
9. **Users:** created by hand, or by a small command-line tool (`bin/cal6 user add`), which would
   hash the password properly?
