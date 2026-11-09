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
  `password_verify`), checks the account's `valid` window, then stores the login in the
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

### Dates, times and windows (Decided)

Every date and time a person writes, in every file, uses one grammar:

```
window := when [ "until" when ]        ; the time in which the thing is available
when   := "always" | "never"
        | YYYY-MM-DD [HH:MM]           ; absolute, in the course time zone
        | (+|-)N [HH:MM]               ; N days after / before the event's date
        | (+|-)weekday [HH:MM]         ; -monday: on or before; +monday: strictly after
        | start [(+|-)N(m|h)] | end    ; the meeting's start / end time (config.ini schedule)
```

- **Dates are `YYYY-MM-DD`**, everywhere. v4's `M D Y` and `M D` are not read; the converter
  rewrites them (`1 1 1999` becomes `always`, `99 99 9999` becomes `never`).
- **`HH:MM` (24-hour) is allowed everywhere** a date is. Without it, an opening time is 00:00 and
  an `until` date means through the end of that day (`until 2026-10-22` includes the 22nd).
- **Everything is a window:** `open until close`, visible when `open <= now < close`. A relative
  `until` is measured from the event, like the opening time. Without `until`, it stays available.
- This applies to: `default.ini` rules, grants, an account's validity, the lockdown, and the days
  in `dates.ini` (`2026-11-25 until 2026-11-27 = holiday`).

### `default.ini` (Decided: when things are available to anonymous users)

Requirements, from a real Calendar 4 access file (`cal4.access`) and the v4 manual
(`calendar-core/instructor.html`, "Setting files to be available on a certain date"):

| # | Requirement | In v4 |
|---|---|---|
| R1 | **Targets** at every level: a whole type, one event, a box of every event of a type, a box of one event, one file of one event | `lab`, `lab_6`, `class/notes`, `class_25/notes`, `lab_3/filename.ext` |
| R2 | **A file name alone** matches that file in every event | `homework01.html` |
| R3 | **Wildcards and synonyms** | `class_2/all` (= `class_2`), `class/*`, `class_2/*`, `*/homework`, `*` |
| R4 | **Always / never** | `1 1 1999`, `99 99 9999` |
| R5 | **Absolute dates** | `12 15 2020`, `12 15` (the year from `$YEAR`) |
| R6 | **Relative to the event's date**: `+N` / `-N` days (`-0` = on the day); `-monday` = the Monday on or before the event, `+monday` = the next Monday strictly after it | `-0`, `+7`, `-2`, `-monday` |
| R7 | **Most specific wins, and a box beats an event**, in v4's fixed order (below) | `class_1` always but `class/instructor` never; `lab_6` always but `lab/lab-solution +7`; `class_25` never but `class/notes` always, so `class_25/notes` is hidden on its own line |
| R8 | **No rule: visible** | `class` has no active rule, so classes are visible |
| R9 | **The same target twice: the later line wins** | `lab 99 99 9999`, then `lab -0` |
| R10 | **`#` comments**, used to switch rules on and off during the semester; blank lines ignored | `#project 99 99 9999` |
| R11 | **Many events at once** | `class_20` ... `class_27`, one line each |
| R12 | **A semester-end lockdown** | the block at the top of the example |
| R13 | **Virtual files** (`$CLASS_FILE`: a file shown in an event but stored elsewhere) are checked under both names; the stricter answer wins (Calendar 5 behaviour; v4 mixed them up) | `notes.html  homework/homework01.html  lab  1` |
| R14 | **Events that are on no calendar day** are never shown to anonymous viewers | a third exam folder with two exam days |
| R15 | **Bad lines** are ignored and listed for the instructor; **an unreadable file** hides everything (fail closed) | |
| R16 | **Paths with spaces** | tab-separated lines |

The order, from strongest to weakest (v4's `get_file_security()`, kept exactly): `T_N/F`, `F`,
`T_N/C`, `T/C`, `T_N`, `T/*`, `T_N/all`, `T_N/*`, `*/C`, `T`, `*`.

**Format (Decided):** one rule per line, `target = when`, read by our own parser (not
`parse_ini_file`), so every line keeps its number for messages, `#` and `;` comments both work,
and targets may contain spaces (R16, no more tabs).

```ini
# target = window   (see "Dates, times and windows")
class_1                 = always
class/notes             = always
class/instructor        = never
class/homework-solution = never
homework01.html         = 2026-12-15         ; R2: this file in every event
lab                     = -0
lab/lab-solution        = +7 17:00
project                 = -monday 08:00
exam                    = never
class_20..27            = never              ; R11: a range
class_20..27/notes      = never              ; a range with a box
class_25                = always             ; R9: later line wins, so class 25 is open
class/quiz.html         = start until end    ; only during the meeting
exam_2/notes            = 2026-11-06 08:00 until 2026-11-06 09:50
lab/slides              = -monday 08:00 until +14 23:59
```

Decided:

- **Target names are v4's:** `lab_6`, `lab_6/lab-solution`, in `default.ini` and in grants.
- **The later line wins** (R9), as v4. `bin/cal6 check` (or the instructor's status view) lists
  lines that a later line overrides, as information, since that is how the lockdown example
  silently failed to lock labs.
- **Ranges** (R11): `class_20..27` is the same as eight lines, `class_20` ... `class_27`, written
  where the range is, so a later line for one of them still wins. Allowed wherever `T_N` is:
  `class_20..27/notes`, `class_20..27/slides.pdf`.
- **Lockdown** (R12) is a setting, a window like everything else: `config.ini` `[access]
  lockdown = 2026-12-18 17:00` (or `2026-12-18 17:00 until 2027-01-04 08:00` to end it). From then on, anonymous viewers see a file only when the rule that decides it is
  `always`. Precedence still runs, so in the example `class_1 = always` keeps class 1 open,
  `class/notes = always` keeps every class's notes open, and `class/instructor = never` still
  hides instructor material in class 1. Users' grants are not affected (they have their own
  `valid` window).

Proposed:

- The converter fills a missing v4 year (`12 15`) from the semester.
- The manual shows `class/01.Course Overview/intro.html` as a target, but v4 never matched
  course-relative paths (only `T_N/F` and `F`); Calendar 6 keeps that and writes it
  `class_1/intro.html`. The converter reports such lines.
- **Root pages** (`home`, `syllabus`, ... v4's `?load=` and `$LOAD_PAGES`) need targets too, for
  example `page/syllabus = 2026-08-24`. Open question 10.

### Protection inside pages (`html.php`), from the v4 manual

These are decided per request on the server, so hidden content never reaches the browser:

| Tag | v4 meaning | Calendar 6 (proposed) |
|---|---|---|
| `<inst>` | only for the logged-in instructor | removed unless the viewer has `*` |
| `<student>` | answers behind a "reveal" icon (sent to everyone, only hidden visually) | kept as is (it never protected anything) |
| `<postmeeting>` | shown once the meeting is over | kept, decided on the server |
| `<lock code="a,b">` | a section unlocked by a shared password | kept, shared codes only (Decided); grants do not unlock it |
| `<host server="name">` | shown only when served by that server | kept |

Other v4 security behaviour to keep: names starting with a dot are ignored everywhere; content
files and link targets must be inside the course (or `$ALLOWED_PATHS`, now `config.ini`);
`.php` files are never sent; `config/`, logs and access data are never served; passwords and
lock codes are removed from any log.

### `auth/<username>.ini` (Decided fields, proposed syntax)

```ini
password_hash = $2y$12$...          ; password_hash(), never plain text
valid         = 2026-08-24 until 2026-12-18 17:00   ; optional window; default from config.ini

[grants]                            ; the same targets and "when" as default.ini
lab_6              = always
lab_6/lab-solution = +7 08:00 until +7 17:00             ; relative to lab 6, like default.ini
lab/lab-solution   = 2026-10-15 13:30 until 2026-10-22   ; an extension, with its own window
exam_2             = 2026-11-06 08:00 until 2026-11-06 11:50   ; extra time
class_20..27       = always                             ; ranges work in grants too
```

**How grants combine with `default.ini` (proposed):** a user's rules are `default.ini` with the
user's grants laid over it (a grant replaces the rule for the same target), evaluated with the
**same precedence**. The user sees a file if anonymous viewers see it **or** the user's rules
allow it, so a grant can only add, never hide. Grants apply only inside the account's
`valid` window, and each grant only inside its own window.

Because the precedence is the same, a grant behaves like the rule it replaces:

| Grant | Shows | Does not show |
|---|---|---|
| `lab_6` | lab 6, except boxes that have their own rule | `lab/lab-solution` (a box rule wins, R5) |
| `lab_6/lab-solution` | the solution box of lab 6 | |
| `lab_6/notes.html` | one file | |
| `lab` | every lab, as `lab_6` | boxes with their own rule |
| `lab/lab-solution` | the solution box of every lab | |
| `*` | everything, always (the instructor) | - |

This matches the first notes, which list lab 6 **and** its solution as separate grants.
`*` is the one exception: in v4 order it would lose to every other rule, so it is checked first
and means "everything".

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
10. **Root pages:** how `default.ini` and grants name pages outside events (`home`, `syllabus`,
    `policy`), for example `page/syllabus`.
