# Calendar 6: design

Status: **draft**, being defined. Sections marked **Decided** follow the agreed structure;
sections marked **Proposed** are suggestions still to be confirmed. Later work is collected
under "Future enhancements" at the end.

## Goal

A course site that is **mostly static HTML/JS**: the browser loads a plain page, asks small
PHP endpoints for JSON, and renders the calendar itself. PHP is used **only where something
must be protected**: authentication, the list of what the viewer may see, and handing out
files. The one other use is `<calphp>`, which runs a course's own PHP file and passes its
output through (see "Tags").

There is **no compatibility requirement** with Calendar 4 or Calendar 5 (Decided): their
material layout and tags are kept because they work, but their configuration files are not
read. A converter is a future enhancement.

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
│   └── html.php              one HTML page, by key, processed (keys, tags)
└── (proposed) app/           the static front end: index.html, app.js, app.css, vendored
                              libraries (MathJax, highlight.js; no Ace)
```

**Rule (Decided):** `get.php` and `html.php` are the only scripts that return course files.
Everything else references them, for example `<img src="php/get.php?file=KEY">`.

## Course layout (Decided)

```
AB123/                        the course's web directory
├── calendar -> ...           the shared software (a symbolic link to the calendar6 repo)
├── config/
│   ├── .htaccess             deny all (config/ is inside the web root)
│   ├── config.ini            the main configuration
│   ├── dates.ini             days that differ from the weekly schedule
│   ├── default.ini           when things become available to anonymous users
│   ├── paths.ini             where the course's own pages are (resources, policy, ...)
│   └── auth/
│       └── <username>.ini    one file per user, instructors included
├── class/
│   ├── 01.Introduction/      one directory per event, in natural order of the names
│   └── 02.Loops/
├── lab/
│   └── 01.Setup/
└── ...                       one directory per event type (exam/, project/, review/, ...)
```

**Material layout (Decided): Calendar 4's.** One directory per event type; inside it one
directory per event, named `<sort>.<Title>` and ordered by natural sort (`01.Introduction`,
`B-02.Loops`); names starting with a dot are ignored. Boxes are chosen by file name, as in v4
(for example `index.html` is the title box, `homework.html` the homework box), configured in
`config.ini`. Markdown (`.md`) works wherever HTML does.

**Configuration files are INI (Decided)**, so instructors can edit them; JSON is used only
between the server and the browser.

### `paths.ini` (Decided: the course's own pages)

Pages outside the events, by name, relative to the course directory:

```ini
[pages]
home      = home.html
resources = resources.html
policy    = syllabus.html        ; shown as "Policy" / the syllabus
; more named pages later
```

Proposed: each page gets a key like any file, is served by `html.php`, appears in the menus that
`config.php` returns, and can be targeted by rules and grants as `page/<name>`
(`page/policy = 2026-08-24 08:00`); with no rule it is visible (R8).

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
- every Calendar 4 tag except `<ace>` is processed on the server (see "Tags");
- `<a name>` anchors (and `##` headings in Markdown) become the page's menu, as in v4;
- **JavaScript is passed through unchanged** (Decided): `<script>` elements and inline handlers
  reach the browser as written. The front end runs them after placing the page (see "The front
  end").

### Tags (Decided: every Calendar 4 tag except `<ace>`)

All are handled in `html.php`, so hidden content never reaches the browser:

| Tag | Meaning |
|---|---|
| `<inst>` ... `</inst>` | only for instructors (users with `*`) |
| `<student>` ... `</student>` | answers behind a "reveal" icon (sent to everyone, hidden only visually, as v4) |
| `<postmeeting>` ... `</postmeeting>` | shown once the event's meeting is over |
| `<lock code="a,b">` ... `</lock>` | a section unlocked by a **shared code** (Decided: codes only; grants do not unlock it) |
| `<host server="name">` ... `</host>` | shown only when served by that server |
| `<inject src="file">` | another file of the event, processed as part of the page |
| `<codeinject src="file">` | another file, escaped so it shows as source code |
| `<replace value="name">` | a value from `config.ini` (`[replace]`) or a built-in one (`type`, `ctype`, `event`, `zevent`, `title`, `course`, `coursename`) |
| `<url value="name" default="x">` | a value from the page's URL, **HTML-escaped** (remembered in the session, as v4) |
| `<showlink src="file">` | a link to another file of the event, by key |
| `<calphp src="file.php">` | runs that PHP file and puts its output in place, passed through as-is |

Removed: `<ace>` (and Ace itself), and `<keyword>` / `<search>`, which only made search links
(search is removed).

`<calphp>` details (Decided: passed through): the file must be inside the course (normally the
same event directory); it runs with the course directory as its working directory; its output is
not processed further. Proposed: it only runs if the page itself is visible to the viewer, and a
fatal error in it replaces the tag with an error note for instructors instead of breaking the
page. `.php` files are never sent by `get.php`, so their source stays private.

## Access model

### Viewers (Proposed wording of the decided idea)

| Viewer | Sees |
|---|---|
| **Anonymous** | What `default.ini` releases, at the times it says |
| **A user** (`auth/<username>.ini`) | Everything anonymous sees, plus the user's **grants**, during the user's valid period |
| **Instructor** (Decided) | Everything, always, and `<inst>` content: a user in `auth/` whose grants include `*` |

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

Notes:

- The v4 manual shows `class/01.Course Overview/intro.html` as a target, but v4 never matched
  course-relative paths (only `T_N/F` and `F`); Calendar 6 writes it `class_1/intro.html`.
- Pages from `paths.ini` are targeted as `page/<name>`.

Other v4 security behaviour to keep: names starting with a dot are ignored everywhere; content
files and link targets must be inside the course (or `$ALLOWED_PATHS`, now `config.ini`);
`.php` files are never sent (only run by `<calphp>`); `config/`, logs and access data are never
served; passwords and lock codes are removed from any log.

### `auth/<username>.ini` (Decided fields, proposed syntax)

Users, instructors included, are created by hand for now (Decided): copy a file and put in a
hash made with `php -r 'echo password_hash("secret", PASSWORD_DEFAULT), "\n";'`. A tool is a
future enhancement.

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

An instructor is the same kind of file with `* = always` under `[grants]`.

**How grants combine with `default.ini` (proposed):** a user's rules are `default.ini` with the
user's grants laid over it (a grant replaces the rule for the same target), evaluated with the
**same precedence**. The user sees a file if anonymous viewers see it **or** the user's rules
allow it, so a grant can only add, never hide. Grants apply only inside the account's
`valid` window, and each grant only inside its own window.

Because the precedence is the same, a grant behaves like the rule it replaces:

| Grant | Shows | Does not show |
|---|---|---|
| `lab_6` | lab 6, except boxes that have their own rule | `lab/lab-solution` (a box rule wins, R7) |
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

## Finding the course (Decided)

The software is shared by many courses through the `calendar` link, so it can not hold a
pointer to any one course. Instead:

1. The course directory is found from the request: `$_SERVER['SCRIPT_FILENAME']` keeps the
   linked path (`.../AB123/calendar/php/get.php`), so the course is two levels above `php/`
   (`__DIR__` would resolve the link and point into the shared repository).
2. Its configuration is always `<course>/config/`, and its material is in `<course>/class/`,
   `<course>/lab/`, ... (the types listed in `config.ini`).
3. Because `config/` and the material are inside the web root, `config/.htaccess` denies all, and
   each material directory needs the same (proposed: `calendar/` ships an `.htaccess` template,
   and `config.php` reports to the instructor when a material file can be fetched directly).

## The front end (Proposed)

- `index.html` in the course (a link to `calendar/app/index.html`, or a two-line file) loads
  `app.js`, which fetches `config.php` and `calendar.php` and draws the calendar, menus and
  pages. Navigation uses `#` routes (`#/lab/6`), so every view is a static page and deep links
  work.
- Pages are fetched from `html.php` and placed into the page; then their `<script>` elements are
  run in document order (external ones loaded first, as a browser would), and MathJax and
  highlight.js run over the new content. Scripts are passed through unchanged (Decided).
- After `auth.php`, the app reloads `calendar.php`, so the view matches the new viewer.

## Security summary (Proposed)

- Material directories and `config/` must not be reachable directly: `.htaccess` (Apache) or
  server rules (nginx).
- Access is checked where files are served (`get.php`, `html.php`), not only in `calendar.php`.
- One answer (`404`) for "no such key" and "not allowed".
- No secrets in any JSON; JSON only from `config.php`, `calendar.php`, `auth.php`.

## Decisions on the first open questions

| # | Question | Decided |
|---|---|---|
| 1 | Material layout | Calendar 4's: `class/<sort>.<Title>/`, boxes by file name |
| 2 | Compatibility | None required; a converter is a future enhancement |
| 3 | Pointer file | None: `<course>/config/`, found from the request path |
| 4 | Instructor | A user in `config/auth/` with the grant `*` |
| 5 | Scripts in pages | JavaScript passed through; PHP only through `<calphp>`, output passed through |
| 6 | Tags | Every Calendar 4 tag except `<ace>`; Ace removed |
| 7 | Search | Removed (with `<keyword>` / `<search>`) |
| 8 | Config format | INI, so instructors can edit it |
| 9 | Users | Created by hand; a tool is a future enhancement |
| 10 | Course pages | `config/paths.ini` (resources, policy, ...) |

## Future enhancements

1. **Converter** from Calendar 4 / Calendar 5 courses: `calendar.php` settings to `config.ini`,
   `cal4.access` to `default.ini` (dates to `YYYY-MM-DD`, `1 1 1999` to `always`, `99 99 9999`
   to `never`, a missing year from the semester), the class file, `$LOAD_PAGES` to `paths.ini`.
2. **User tool** (`bin/cal6 user add <name>`): asks for the password, writes the hash, sets the
   `valid` window and grants.
3. More named pages in `paths.ini`.
