# Calendar 6: design

Status: **draft**, being defined. Sections marked **Decided** follow the agreed structure;
sections marked **Proposed** are suggestions still to be confirmed. Later work is collected
under "Future enhancements" at the end.

**Implemented so far:** the five endpoints in `php/` (`config.php`, `calendar.php`, `get.php`,
`html.php`, `auth.php`) on the library in `lib/`, with tests in `tests/` (`php tests/run.php`).
The front end (`app/`) is not started. "Implementation notes" near the end lists the choices the
code made where this document left room.

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
├── php/                      the only scripts a browser requests
│   ├── auth.php              log in / log out (POST only)
│   ├── config.php            the site configuration, without secrets (JSON)
│   ├── calendar.php          everything this viewer may see (JSON)
│   ├── get.php               one file, by key, with its MIME type
│   └── html.php              one HTML page, by key, processed (keys, tags)
├── lib/                      the library behind them (classes only; .htaccess denies all)
├── tests/                    php tests/run.php (unit tests, and HTTP tests on php -S)
├── (proposed) app/           the static front end: index.html, app.js, app.css, vendored
│                             libraries (MathJax, highlight.js; no Ace)
└── template/                 a starting point for a new course: copy it, then link calendar/
    ├── README.md
    ├── config/               a sample of every .ini file (periods.ini is the full default)
    ├── class/, lab/          one sample event each
    └── home.html, ...        the pages named in paths.ini
```

**The template (Decided)** is kept in step with this design: when the design changes, the
template's files change with it.

**Rule (Decided):** `get.php` and `html.php` are the only scripts that return course files.
Everything else references them, for example `<img src="php/get.php?file=KEY">`.

## Course layout (Decided)

Every course has this layout, always; nothing about it is configurable:

```
course_directory/             the course (AB123/); the root of everything the calendar serves
├── calendar/                 the software: a symbolic link to the calendar6 repo anywhere on the
│                             server, or the repo cloned right here
├── config/                   all .ini files; always this directory
│   └── auth/                 all authentication files
└── <event_type>/<sort>.<event title>/    the material
```

In more detail:

```
AB123/                        the course's web directory
├── calendar -> ...           the software (a link, or a clone)
├── config/
│   ├── .htaccess             deny all (config/ is inside the web root)
│   ├── config.ini            the main configuration
│   ├── dates.ini             days that differ from the weekly schedule
│   ├── default.ini           when things become available to anonymous users
│   ├── paths.ini             where the course's own pages are (resources, policy, ...)
│   ├── virtual.ini           files shown in an event but stored elsewhere
│   ├── duplicate.ini         events that are used again (class 5 also as class 6)
│   ├── periods.ini           the school's periods, per period schedule
│   ├── sections.ini          the course's sections: meetings, instructors, rooms (optional)
│   └── auth/
│       └── <username>.ini    one file per user, instructors included
├── pages/                    the pages named in paths.ini (home, resources, syllabus)
├── shared/                   files used by virtual.ini
├── index.html                opens the calendar
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

### The course directory is the boundary (Decided)

- **Every path in every `.ini` file is relative to `course_directory/`** (`paths.ini` pages,
  `virtual.ini` real files). Absolute paths and `..` are rejected and listed as problems for the
  instructor. (Paths inside pages, such as `<img src>`, `<inject src>` and `<calphp src>`, stay
  relative to the page, as in v4, and are held to the same boundary.)
- **Nothing outside the course directory is ever served or run.** Each path is resolved with
  `realpath()` (so symbolic links are followed) and must still be inside `course_directory/`;
  a link that leads outside is treated as missing. Calendar 4's `$ALLOWED_PATHS` (shared material
  elsewhere) is **not** kept: share material by copying it in.
- **Never served, even inside the course:** `config/` (and so `auth/`), `calendar/`, names
  starting with a dot, `.php` files (only run by `<calphp>`), `.ini` files.
- **Only known files are served:** `get.php` and `html.php` serve a key, and keys exist only for
  event files, `virtual.ini` files, `paths.ini` pages and the files their pages link to.
- **The configuration is always `course_directory/config/`**; there is no setting or pointer that
  moves it.
- **The location of calendar6 does not matter.** The only assumption is that
  `course_directory/calendar` reaches it (a link or a clone).

### Samples of every `.ini` file

**The complete samples are the files in `template/config/`** (the course template); the excerpts
in this document follow them. One course, AB123, is used everywhere, also in the JSON samples of
the endpoints:

| File | Sample |
|---|---|
| `config/config.ini` | below |
| `config/dates.ini` | below |
| `config/paths.ini` | below |
| `config/virtual.ini` | below |
| `config/duplicate.ini` | below |
| `config/default.ini` | under "Access model" |
| `config/auth/<username>.ini` | under "Access model" (a user and an instructor) |

Common to all of them: `key = value` lines, `[sections]`, values unquoted. **Comments:** a line
that starts with `;` or `#` is a comment; at the end of a line, `;` starts a comment only after a
space (`period = normal ; the default`); `#` is never a comment at the end of a line.
Dates and times follow "Dates, times and windows"; event targets follow `default.ini` (`lab_6`,
ranges `class_20..27`, lists `class_6,8,22`); in every file **the later line wins**.

### `config.ini` (Decided: the main configuration; proposed keys)

```ini
; config.ini: the main configuration. Every path is relative to the course directory.

[course]
number   = AB123
name     = Web and Internet Programming
timezone = America/New_York
period   = normal                 ; the default period schedule in periods.ini
; secret: used to make file keys; never sent to the browser. Generate one with
;   php -r 'echo bin2hex(random_bytes(32)), "\n";'
secret   = REPLACE-WITH-64-HEX-DIGITS

[semester]
start    = 2026-08-24
end      = 2026-12-18
weekends = no                     ; yes: Saturday and Sunday can hold events too

[schedule]
; The one course calendar: the event type of each weekday (one type per day; days not listed
; have no events). Events are placed in order: class 1, 2, 3 ... on the class days.
; dates.ini can change any single day. Times come from sections.ini; a course without
; sections.ini may give them here (mon = class 08:00-08:50).
mon = class
tue = class
wed = lab
fri = class

[types]
; event types, in menu order: directory = label
class   = Class
lab     = Lab
project = Project
exam    = Exam

[boxes]
; box = the file names that fill it (the first one found wins) | label
title             = index.html, index.md        | Lecture
notes             = notes.html, notes.md        | Notes
homework          = homework.html, homework.md  | Homework
homework-solution = homework-solution.html      | Homework solution
lab-solution      = lab-solution.html           | Solution
slides            = slides.pdf, slides.pptx     | Slides
instructor        = instructor.html             | Instructor

[access]
; lockdown: from then on anonymous viewers only see what an "always" rule decides
;lockdown    = 2026-12-18 17:00

[users]
valid          = 2026-08-24 until 2026-12-18 23:59   ; the default window for auth/*.ini
session        = 8h               ; a login lasts at most this long
login_attempts = 5 per 15m        ; failed logins allowed per address and per username

[replace]
; values for <replace value="...">
classyear = 27
office    = Michelson 342
```

### `dates.ini` (Decided: days that differ from the weekly schedule; proposed syntax)

Each line is a window (a day, or several) and what happens then, optionally with a label after
`|`:

```ini
[days]
2026-09-07                  = holiday             | Labor Day
2026-10-12                  = holiday             | Columbus Day
2026-10-13                  = mon                 | Monday schedule
2026-10-14                  = lab                 | Lab day
;2026-10-15                 = lab, class          ; the next lab and the next class
;2026-10-16                 = class, class, class ; three classes in one day
;2026-10-19                 = class, lab          ; a class before the lab
2026-11-11                  = holiday             | Veterans Day
2026-11-25 until 2026-11-27 = holiday             | Thanksgiving
;2026-12-03                 = period 2_hour_delay | 2-hour delay (snow)
;2026-12-04                 = holiday             | Snow day
```

- `holiday` / `none`: no events that day (`holiday` is shown as such).
- `class`, `lab`, ... (Decided): that day's events, overriding `config.ini`'s `[schedule]`
  (`2026-10-14 = lab`). Several are allowed (Decided), including the same type more than once;
  they are placed in the order written, each taking the next event of its type, and are shown in
  that order:
  - `lab, class`: the next lab, then the next class;
  - `class, class, class`: the next three classes;
  - `class, lab`: a class before the lab.

  All events of the day share its date, so relative rules (`+7`) and `start` / `end` are the same
  for each of them.
- `mon` ... `sun`: that day follows another weekday's schedule (its type, and the sections that
  meet on that weekday).
- `period <schedule>`: a period schedule from `periods.ini` for the day (times change, lessons do
  not).
- Types or a weekday (not both) can be combined with a period schedule: `mon, period early`,
  `class, lab, period 2_hour_delay`.
- A window with times is only allowed with `holiday` or `none`, and cancels the meetings whose
  first section starts inside it; any other line with times is listed as a problem and ignored.
- Events are placed on the days that have meetings of their type, in order; a holiday moves the
  following events of that type to the next meeting.

### Sections, periods and period schedules (Decided)

A course can be one instructor with one section, or a dozen instructors with 30 sections that
meet on different days and periods. Decided:

- **One calendar, always.** The course calendar comes from `config.ini`'s `[schedule]` and
  `dates.ini`: class 1, 2, 3, ... in order on the course's meeting days. Sections never get their
  own dates or their own pace; a section that meets on Thursday uses the lesson shown on Tuesday,
  and instructors who fall behind shift on their own.
- **Labs are shown on a single day** (a Tuesday/Thursday lab is shown on Tuesday). When a second
  day really needs the same material, `duplicate.ini` repeats it (`lab_7 = lab_6 same`).
- **Students never log in.** Every student is an anonymous viewer; accounts in `config/auth/` are
  for instructors and staff.
- **Only a whole-day cancellation moves the calendar** (a `holiday` / `none` line in `dates.ini`).
  A delay or early schedule changes the times of the day, not the lessons.

So sections and periods are **information**, not scheduling: they say when and where each
section meets, so the site can show it, and they give `start` / `end` a meaning.

#### `periods.ini`: the school's periods, per period schedule

The full file ships in `template/config/periods.ini` (the default for every course). Each
`[period schedule]` lists its periods; a period that is not listed does not exist in that schedule
(NA), and `cancelled` means it exists but does not meet:

```ini
[normal]
label = Normal
X  = 06:45-07:35
1  = 07:45-08:35
; ... 2 to 9
10 = 13:40-14:55

[4_hour_delay]
label = 4 Hour Delay
1  = cancelled
; ...
5  = 13:40-14:30
```

A copy lives in each course (the course directory is the boundary). `config.ini` names the
default (`[course] period = normal`).

#### `sections.ini`: the course's sections

```ini
; days: M T W R F S; then periods; several periods on one day are one meeting (R34 = 09:45-11:35)
[1121]
meets      = MF1, T12
instructor = SMITH
room       = HP111

[4345]
meets      = MF4, R34
instructor = JONES
room       = HP132, HP301    ; one room per entry of meets: Thursday's R34 meets in HP301
```

- Periods are read greedily as `X`, `10`, then single digits (there is no period 0, so `T910`
  is 9 and 10).
- `room`: one room for every meeting, or one per entry of `meets`, in order (Decided). A count
  that matches neither is listed as a problem for the instructor.
- `instructor` is a display name (several: `SMITH, LEE`); it does not have to be a user in
  `auth/`.
- Optional. Without `sections.ini`, `config.ini`'s `[schedule]` may give times directly
  (`mon = class 08:00-08:50`), which suits a one-section course.

#### `dates.ini` picks the period schedule for a day

```ini
2026-12-03 = period 2_hour_delay       | 2-hour delay (snow)
2026-10-13 = mon, period early         | Monday schedule, early start
2026-10-14 = lab                       | Lab day
2026-12-04 = holiday                   | Snow day (the calendar moves)
```

#### What is shown

- **The course calendar** shows lessons by day, without times, rooms or instructors (with a dozen
  instructors that is noise). A day with a different period schedule is labelled ("2-hour delay").
- **A section picker** (proposed): a viewer may choose a section; the browser remembers it (it is
  not secret, so `localStorage` is fine). Then the calendar adds that section's meeting time and
  room on the days it meets, adjusted for the day's period schedule ("period 6: 15:45-16:35"), and a
  "Sections" page lists every section, its meetings, instructor and room.
- **One section:** its times are shown without picking.

#### Release rules with sections

Rules are measured from the lesson's date on the one calendar, so nothing differs by section:

| In a rule | Means |
|---|---|
| `+N`, `-N`, `-monday` | from the lesson's calendar date |
| `start` | the **first section of the day**: the earliest meeting start of any section on that date, with that day's period schedule (`start-15m` = 15 minutes before it) |
| `end` | the latest meeting end of any section on that date |

`<postmeeting>` content opens at `end`, so answers stay hidden until every section that meets on
the lesson's day has met. This is fixed (Decided), so `config.ini` has no setting for it.
Without sections, `start` / `end` come from `[schedule]` times (00:00 /
end of day without them).

### `paths.ini` (Decided: the course's own pages)

Pages outside the events, by name, relative to the course directory:

```ini
[pages]
home      = pages/home.html       | Home
resources = pages/resources.html  | Resources
policy    = pages/syllabus.html   | Syllabus
```

### `virtual.ini` (Decided: files shown in an event but stored elsewhere)

Calendar 4's class file, as `event/name = real file` (the real file relative to the course
directory):

```ini
lab_1/notes.html       = homework/homework01.html
class_4/notes.tex      = docs/latex-template.tex
class_1..30/style.css  = shared/style.css        ; ranges and lists work here too
lab_2,4,6/checklist.md = shared/checklist.md
```

- The virtual file is part of that event like any other: it can fill a box (by its name), its
  links are rewritten relative to its **real** directory, and it gets its own key.
- Access is checked under the virtual name (`lab_1/notes.html`) **and** under the real file's
  path; both must allow it (R13).
- The real file is relative to the course directory and must be inside it (never in `config/`
  or `calendar/`); its directory (`homework/`, `shared/`, ...) needs the same `.htaccess` as the
  material directories. Later lines win, as everywhere.

### `duplicate.ini` (Decided: an event used again)

One event's material used for other calendar slots, with or without a new number:

```ini
# copies = source [numbered | same]
class_6       = class_5            ; numbered (the default): the calendar shows class 5 and class 6
class_6..10   = class_5            ; five more slots with class 5's material
class_6,8,22  = class_5 same       ; the calendar shows "class 5" on each of those days
```

Proposed meaning:

- Event numbers on the left are **slots on the calendar** (the 6th class meeting, ...). The copies
  take those slots, and the remaining event directories fill the other slots in order, so with
  `class_6 = class_5` the directory that would have been class 6 moves to the next slot.
- **`numbered`**: each copy is a separate event with its own number (`class_6`), title and date;
  the following events keep counting (directory 6 becomes class 7).
- **`same`**: each copy is shown as the source again (`class 5`, same title); the repeat does not
  use up a number (directory 6 stays class 6, on the next free slot).
- Rules and grants use the numbers the calendar shows: a `numbered` copy is `class_6` (its own
  rules; relative times from its own date); a `same` copy is `class_5` (relative times from each
  day it appears on). A grant for `class_5` covers every `same` repeat.
- The source and copies are the same type (proposed); a copy of a copy is not allowed.

Pages from `paths.ini` (Decided): each gets a key like any file, is served by `html.php`, is
listed in `calendar.php`'s `pages` when visible, and is targeted by rules and grants as
`page/<name>` (`page/policy = 2026-08-24 08:00`); with no rule it is visible (R8). The files a
page links to get keys too, decided by the page's rule (see `html.php`).

## The PHP endpoints

All endpoints answer with explicit headers: `X-Content-Type-Options: nosniff`, no caching of
personalised answers (`Cache-Control: private, no-store`), and JSON as
`application/json; charset=utf-8`. Errors are JSON too (`{"error": "..."}`) with the right
status code (400, 401, 403, 404, 405, 429).

Every request is answered for **the current viewer**: anonymous, or the user stored in the PHP
session by `auth.php`. Requests that change something (`auth.php`, a lock code sent to
`html.php`) must carry the session's **CSRF token**, which `config.php` hands out.

### `auth.php` (Decided: POST only, session, JSON)

**Inputs** (`POST`, form-encoded or JSON):

| Field | Required | Meaning |
|---|---|---|
| `username` | to log in | the name of a file in `config/auth/` (letters, digits, `.`, `_`, `-` only) |
| `password` | to log in | checked with `password_verify` against `password_hash` |
| `csrf` | always | the token from `config.php?csrf=1` |

No `username`/`password`: log out. Any method other than POST: `405`.

**Output:**

```json
{"user": "jdoe", "instructor": false, "valid_until": "2026-12-18T17:00:00-05:00", "csrf": "q9Xb..."}
```

```json
{"user": null, "instructor": false, "csrf": "Lm2c..."}
```

```json
{"error": "Wrong username or password."}
```

- Logged in: `200`, the first form. The session id is replaced, so a **new CSRF token** comes
  back. Grants are not listed (they could name hidden files); `calendar.php` shows their effect.
- Logged out: `200`, the second form (also a new token).
- Wrong password, unknown user, or outside the account's `valid` window: `401`, the same message
  for all three (an unknown user costs a password hash, so it takes as long). Too many attempts:
  `429` with `Retry-After`. Bad or missing token: `403`. The limiter can not keep count (its
  private directory is unusable): `503`, nobody can log in until it is fixed (fail closed).
- Attempts are limited per address and username (`[users] login_attempts`, 5 per 15 minutes in
  the template), per address (four times that) and per username (ten times that), so guessing
  from one address can not lock an instructor out. Behind a reverse proxy, list it in
  `[server] trusted_proxies` so the client's address is used (see "Deploying").
- Hardening: cookie flags `HttpOnly`, `Secure` (on HTTPS, also behind a trusted proxy that says
  so), `SameSite=Strict`; a session lifetime from `config.ini`; the login ends by itself when the
  `valid` window does.

### `config.php` (Decided: the site config without secrets)

**Inputs:** `GET`. `csrf=1` (optional): start a session and include its CSRF token, for a login
form. Without it, no session is created (anonymous visitors leave no session files), and `csrf`
is `null` unless the browser already has a session.

**Output:** what the front end needs to draw the site. It changes only when `config/*.ini`
changes, apart from `viewer` and `csrf`.

```json
{
  "course":   {"number": "AB123", "name": "Web and Internet Programming", "timezone": "America/New_York"},
  "semester": {"start": "2026-08-24", "end": "2026-12-18"},
  "schedule": {"mon": "class", "tue": "class", "wed": "lab", "fri": "class"},
  "days":     [{"date": "2026-10-13", "as": "mon", "label": "Monday schedule"},
               {"date": "2026-11-25", "until": "2026-11-27", "kind": "holiday", "label": "Thanksgiving"},
               {"date": "2026-12-03", "period": "2_hour_delay", "label": "2-hour delay (snow)"}],
  "periods":  {"normal": {"label": "Normal", "times": {"1": "07:45-08:35", "4": "10:45-11:35"}},
               "2_hour_delay": {"label": "2 Hour Delay", "times": {"1": "09:45-10:35", "4": "13:45-14:35"}}},
  "sections": [{"section": "1121", "meetings": [{"meets": "MF1", "room": "HP111"}, {"meets": "T12", "room": "HP111"}], "instructor": "SMITH"},
               {"section": "4345", "meetings": [{"meets": "MF4", "room": "HP132"}, {"meets": "R34", "room": "HP301"}], "instructor": "JONES"}],
  "types":    {"class": {"label": "Class"}, "lab": {"label": "Lab"}, "exam": {"label": "Exam"}},
  "boxes":    [{"box": "title", "label": "Lecture"}, {"box": "homework", "label": "Homework"},
               {"box": "lab-solution", "label": "Solution"}],
  "replace":  {"classyear": "27"},
  "viewer":   {"user": null, "instructor": false},
  "csrf":     "Lm2c..."
}
```

Never included: password hashes, the secret, server file paths, `default.ini`, `auth/`, lock
codes, the lockdown date (its effect shows in `calendar.php`).

### `calendar.php` (Decided: everything this viewer may see, cached)

**Inputs:** `GET`, none for everyone. For instructors only (ignored otherwise):

| Field | Meaning |
|---|---|
| `as=anonymous` or `as=<username>` | the answer that viewer would get |
| `at=YYYY-MM-DD HH:MM` | the answer at that moment instead of now |

**Output:** the calendar days, the events on them, and for each event the boxes and files the
viewer may see, with their keys. Things the viewer may not see are left out entirely.

```json
{
  "generated":   "2026-11-09T14:05:00-05:00",
  "next_change": "2026-11-10T08:00:00-05:00",
  "viewer":      {"user": "jdoe", "instructor": false},
  "pages":  [{"name": "policy", "title": "Policy", "key": "p7Kq2"}],
  "days": [
    {"date": "2026-11-09", "events": ["class_30", "lab_10"]},
    {"date": "2026-11-11", "events": ["class_5@2026-11-11"]},
    {"date": "2026-11-26", "kind": "holiday", "label": "Thanksgiving", "events": []}
  ],
  "events": {
    "class_30": {
      "type": "class", "number": 30, "title": "Sessions and Cookies",
      "date": "2026-11-09", "start": "08:00", "end": "08:50",
      "boxes": [
        {"box": "title",    "files": [{"name": "index.html", "key": "a1Xc9", "kind": "page"}]},
        {"box": "homework", "files": [{"name": "homework.html", "key": "b4Rt0", "kind": "page"}]}
      ],
      "files": [{"name": "slides.pdf", "key": "c8Ld3", "kind": "file", "size": 482113}]
    },
    "lab_10": {"type": "lab", "number": 10, "title": "Cookies", "date": "2026-11-09",
               "boxes": [], "files": [], "opens": "2026-11-10T08:00:00-05:00"},
    "class_5@2026-11-11": {"type": "class", "number": 5, "title": "Forms", "date": "2026-11-11",
                "copy_of": "class_5", "mode": "same", "boxes": [], "files": []}
  }
}
```

- `boxes` follow the order of `config.php`'s `boxes`; `files` are the event's other files (v4's
  Files menu). `kind` says which script serves it: `page` → `html.php`, `file` → `get.php`.
- `opens`: the next time something in the event becomes visible to this viewer (proposed: only
  when the rule that hides it is a date, not `never`).
- `copy_of` / `mode`: an event from `duplicate.ini`. A `same` repeat is shown under the source's
  number, so its id adds the date (`class_5@2026-11-11`); its files have their own keys, since
  relative rules can give each showing a different window. Events on no calendar day are left out.
- **Instructors** get everything, with each hidden file marked:
  `{"name": "solution.html", "key": "d2Wq7", "kind": "page", "visible": false, "rule":
  "lab/lab-solution = +7 17:00", "opens": "2026-11-16T17:00:00-05:00"}`, events on no calendar
  day (with `"date": null`), and `problems` (bad lines in the `.ini` files, overridden lines,
  material directories that can be fetched directly).

**Caching (Decided: cache when possible):**

1. **The course model** (the walk of the material, `config/*.ini`, `virtual.ini`,
   `duplicate.ini`, parsed rules) is stored in a cache file per course and reused while nothing
   changed: the check compares the modification times of `config/`'s files, the type
   directories, the event directories and the real files of `virtual.ini` (adding, removing or
   renaming a file changes its directory's time; boxes depend only on names).
2. **The answer per viewer** is stored too: one answer shared by all anonymous viewers, one per
   user. It stays valid until `next_change`, the earliest moment at which any rule, grant,
   window or lockdown changes what that viewer sees, or until the course model changes. So most
   requests are one `stat()` sweep and one file read.
3. **HTTP:** `ETag` (a hash of the answer) and `Cache-Control: private, no-cache`; the browser
   asks again each time and gets `304 Not Modified` when nothing changed. (Not `public`: the
   answer depends on the session cookie.)
4. Not cached: instructors' `as` / `at` previews.
5. The cache is never served and is only a speed-up: deleting it is always safe. Proposed: the
   system temporary directory, in a folder named by a hash of the course path, so the web server
   never needs write access to the course directory (the same for the login rate limiter).

### `get.php` (Decided: one file by key, with the right MIME type; never a page)

**Which endpoint serves what (Decided):** `get.php` serves every file except pages; `html.php`
serves only pages. A page is an `.html`, `.htm` or `.md` file (by its name and by the real file
behind a link). `get.php` answers `404` for a page key, for everyone, so the raw source of a page
(`<inst>`, `<lock>` codes, `<postmeeting>`, `<host>`) never leaves the server; Markdown stays a
page type, processed like HTML.

**Inputs:**

| Field | Required | Meaning |
|---|---|---|
| `file` | yes | the key, from `calendar.php` or a rewritten link |
| `download=1` | no | send as an attachment instead of inline |
| header `Range` | no | part of the file (video, large PDFs): `206 Partial Content` |
| header `If-None-Match` / `If-Modified-Since` | no | `304` when unchanged |

`GET` and `HEAD` only. The key is looked up and **the access rule is checked again for the
current viewer** (a key alone never grants access); then the file is streamed with its MIME
type, `Content-Length`, `Accept-Ranges`, `ETag`, `Last-Modified`, `Cache-Control: private`, and
inline or attachment by type (HTML and SVG always as attachments, so they can not run as pages
of the site). Unknown key or not allowed: `404`, the same answer for both. `.php` files are
never sent.

### `html.php` (Decided: one HTML page by key, processed inline)

**Inputs:**

| Field | Required | Meaning |
|---|---|---|
| `file` | yes | the key of a page (`.html`, `.htm`, `.md`), from `calendar.php` or a rewritten link |
| `full=1` | no | a whole HTML document (the site's CSS, MathJax, highlight.js) for a new tab or printing, instead of the fragment the front end inserts |
| `lock` (POST, with `csrf`) | no | a `<lock>` code; when it matches, it is remembered in the session for the rest of the session and opens every `<lock>` with that code. The answer is `303` back to the page (`GET`), so a reload does not resend it. Rate limited per address |
| any other parameter | no | values for `<url value="...">` tags (HTML-escaped, remembered in the session, as v4) |

**Output:** `text/html; charset=utf-8`, by default an HTML **fragment** (the page's body). The
same key lookup and access check as `get.php`; unknown or not allowed: `404`. Then the page is
processed:

- links to files are rewritten to keys, as Calendar 4 does: `<img src="pic.png">` becomes
  `/<course>/calendar/php/get.php?file=KEY`, `<a href="notes.html">` becomes the front end's
  route `#/view/KEY`, `<a href="data.zip">`, `<script src>`, `<link href>`, `<source src>`,
  `<video poster>` and each `srcset` candidate become `get.php` URLs; `<iframe>`, `<embed>` and
  `<object>` pointing at a page get `html.php?file=KEY&full=1` (a page is never sent by
  `get.php`, and is never an image or script); links to files the viewer may not see keep their
  text but lose their target (`data-cal6-hidden="1"`);
- files that a `paths.ini` page links to (its images, style sheets, the pages next to it) get
  keys of their own, decided by that page's rule;
- every Calendar 4 tag except `<ace>` is processed on the server (see "Tags");
- `<a name>` anchors (and `##` headings in Markdown) get ids; the front end builds the page's menu
  from them, as v4 did on the server;
- **JavaScript is passed through unchanged** (Decided): `<script>` elements and inline handlers
  reach the browser as written. The front end runs them after placing the page (see "The front
  end").

### Tags (Decided: every Calendar 4 tag except `<ace>`)

All are handled in `html.php`, so hidden content never reaches the browser. Blocks
(`<inst>`, `<student>`, `<postmeeting>`, `<host>`, `<lock>`, `<ace>`) are found by a tag scanner,
not regular expressions: tag names are not case-sensitive, nested blocks of the same tag are
matched by depth, any size works, and **a block that is never closed hides the rest of the
page** (fail closed). If any other pattern fails on a page, the request fails rather than sending
the page half processed.

| Tag | Meaning |
|---|---|
| `<inst>` ... `</inst>` | only for instructors (`instructor = true`) |
| `<student>` ... `</student>` | answers behind a "reveal" icon (sent to everyone, hidden only visually, as v4) |
| `<postmeeting>` ... `</postmeeting>` | shown once the event's meetings are over: after the last section's end on the event's day (end of day without times) |
| `<lock code="a,b">` ... `</lock>` | a section unlocked by a **shared code** (Decided: codes only; grants do not unlock it) |
| `<host server="name">` ... `</host>` | shown only when served by that server |
| `<inject src="file">` | another file, processed as part of the page. The file obeys its own rule: a file with a key must be visible to the viewer; a file without one may come only from outside the event directories (`shared/`, `pages/`) |
| `<codeinject src="file">` | another file, escaped so it shows as source code |
| `<replace value="name">` | a value from `config.ini` (`[replace]`) or a built-in one (`type`, `ctype`, `event`, `zevent`, `title`, `course`, `coursename`) |
| `<url value="name" default="x">` | a value from the page's URL, **HTML-escaped** (remembered in the session, as v4) |
| `<showlink src="file">` | a link to another file of the event, by key |
| `<calphp src="file.php">` | runs that PHP file and puts its output in place, passed through as-is |

Removed: `<ace>` (and Ace itself), and `<keyword>` / `<search>`, which only made search links
(search is removed).

`<calphp>` details (Decided: passed through): the file must be inside the course directory (normally the
same event directory); it runs with the course directory as its working directory; its output is
not processed further. Proposed: it only runs if the page itself is visible to the viewer, and a
fatal error in it replaces the tag with an error note for instructors instead of breaking the
page. `.php` files are never sent by `get.php`, so their source stays private.

## Access model

### Viewers (Proposed wording of the decided idea)

| Viewer | Sees |
|---|---|
| **Anonymous** | What `default.ini` releases, at the times it says |
| **A user** (`auth/<username>.ini`; staff, never students) | Everything anonymous sees, plus the user's **grants**, during the user's valid period |
| **Instructor** (Decided) | Everything, always, and `<inst>` content: a user in `auth/` with `instructor = true` |

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
| R13 | **Virtual files** (v4 `$CLASS_FILE`, now `virtual.ini`: a file shown in an event but stored elsewhere) are checked under both names; the stricter answer wins (Calendar 5 behaviour; v4 mixed them up) | `notes.html  homework/homework01.html  lab  1` |
| R14 | **Events that are on no calendar day** are never shown to anonymous viewers | a third exam folder with two exam days |
| R15 | **Bad lines** are ignored and listed for the instructor; **an unreadable file** hides everything (fail closed) | |
| R16 | **Paths with spaces** | tab-separated lines |

Targets are **not** case-sensitive (Decided, M-14): `Class_1/Notes.html`, `class_1/notes.html` and
`page/Solutions` / `page/solutions` mean the same thing, in `default.ini`, grants, `virtual.ini`,
`duplicate.ini`, `dates.ini` and `sections.ini`. Because of that, two names in one place that
differ only in case (`Notes.html` and `notes.html` in one event) would share every rule; such
pairs are listed as problems for instructors.

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
- **Ranges and lists** (R11): `class_20..27` is the same as eight lines, `class_20` ...
  `class_27`, written where the range is, so a later line for one of them still wins. Lists
  (`class_6,8,22`) and both together (`class_1..3,7`) work the same way. Allowed wherever `T_N`
  is, in every file (`default.ini`, grants, `virtual.ini`, `duplicate.ini`):
  `class_20..27/notes`, `class_6,8,22/slides.pdf`.
- **Lockdown** (R12) is a setting, a window like everything else: `config.ini` `[access]
  lockdown = 2026-12-18 17:00` (or `2026-12-18 17:00 until 2027-01-04 08:00` to end it). From then on, anonymous viewers see a file only when the rule that decides it is
  `always`. Precedence still runs, so in the example `class_1 = always` keeps class 1 open,
  `class/notes = always` keeps every class's notes open, and `class/instructor = never` still
  hides instructor material in class 1. Users' grants are not affected (they have their own
  `valid` window). **Pages count too:** a page with no rule (home, the syllabus) disappears
  during the lockdown unless `default.ini` says `page/home = always`. An unreadable lockdown
  value locks at once (and is listed for instructors).
- **A line whose time can not be read hides its target** (fail closed): `exam = nver` hides the
  exams and is listed for instructors; it never releases anything. A line whose target can not
  be read (`class_9..2`) is only listed.
- **Box assets:** a file named after a box's file, followed by `-`, `_`, `.` or `/`, follows that
  box's rule instead of the event's: with `lab-solution.html` in the `lab-solution` box,
  `lab-solution-figure.png` and `lab-solution/data.csv` stay hidden as long as the solution does.
  The longest matching name wins (`homework-solution-1.png` goes with `homework-solution.html`,
  not `homework.html`). Other files follow the event's rule, as in v4.

Notes:

- The v4 manual shows `class/01.Course Overview/intro.html` as a target, but v4 never matched
  course-relative paths (only `T_N/F` and `F`); Calendar 6 writes it `class_1/intro.html`.
- Pages from `paths.ini` are targeted as `page/<name>`.

Other v4 security behaviour to keep: names starting with a dot are ignored everywhere; content
files and link targets must be inside the course directory (no `$ALLOWED_PATHS`);
`.php` files are never sent (only run by `<calphp>`); `config/`, logs and access data are never
served; passwords and lock codes are removed from any log.

### `auth/<username>.ini` (Decided fields, proposed syntax)

Users, instructors included, are created by hand for now (Decided): copy a file and put in a
hash made with `php -r 'echo password_hash("secret", PASSWORD_DEFAULT), "\n";'`. A tool is a
future enhancement.

A user, `config/auth/jdoe.ini`:

```ini
password_hash = $2y$12$...          ; password_hash(), never plain text
instructor    = false               ; true: complete access (every file, always, and <inst>)
valid         = 2026-08-24 until 2026-12-18 17:00   ; optional window; default from config.ini

[grants]                            ; the same targets and "when" as default.ini
lab_6              = always
lab_6/lab-solution = +7 08:00 until +7 17:00             ; relative to lab 6, like default.ini
lab/lab-solution   = 2026-10-15 13:30 until 2026-10-22   ; an extension, with its own window
exam_2             = 2026-11-06 08:00 until 2026-11-06 11:50   ; extra time
class_20..27       = always                             ; ranges work in grants too
```

An instructor, `config/auth/kenney.ini`:

```ini
password_hash = $2y$12$Wq0...
instructor    = true
valid         = always              ; not limited to the semester
```

An instructor is the same kind of file with `instructor = true` (Decided); grants are then not
needed. The `valid` window still applies, so a teaching assistant can be an instructor for a
semester only.

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
| `*` | as v4: the weakest target, so only what no other rule decides | most things |

This matches the first notes, which list lab 6 **and** its solution as separate grants.
Complete access is not a grant: it is `instructor = true`.

## Keys (Decided)

A key identifies a file **in a place**; it is **not** a capability, because `get.php` and
`html.php` check access on every request. `HMAC-SHA256(secret, "class_6/notes.html")`
(the event as the calendar shows it, plus the name in it; `page/policy` for pages), shortened.
So keys are stable (bookmarks keep working), hidden file names can not be guessed or
enumerated, and a file that appears in several places (a virtual file, a duplicated event) has
one key per place, each checked with that place's rules. The secret lives in `config.ini`.

Keys change when the place changes: a `numbered` copy in `duplicate.ini` renumbers the events
after it (directory 6 becomes class 7), so their keys (and bookmarks to them) change; changing
the secret changes every key. Holidays do not renumber events, so they do not change keys.

## Finding the course (Decided)

The software is shared by many courses through the `calendar` link, so it can not hold a
pointer to any one course. Instead:

1. The course directory is found from the request: `$_SERVER['SCRIPT_FILENAME']` keeps the
   path the script was reached by (`.../AB123/calendar/php/get.php`), so the course is two levels
   above `php/`. This works the same for a link and for a clone (`__DIR__` would resolve the
   link and point into the repository).
2. **Checked before anything else:** `<course>/calendar` must resolve (`realpath`) to the code
   that is running, and `<course>/config/config.ini` must exist. Otherwise (for example a server
   that resolves links in `SCRIPT_FILENAME`), every endpoint answers `500` with a short error and
   reads nothing.
3. Its configuration is always `<course>/config/`, and its material is in `<course>/class/`,
   `<course>/lab/`, ... (the types listed in `config.ini`).
4. Because `config/` and the material are inside the web root, `config/.htaccess` denies all, and
   each material directory needs the same; the template ships them (see "Deploying").

## The front end (Proposed; not built yet)

What the endpoints expect from it (the contract):

- **Routes:** `html.php` writes links to other pages as `#/view/KEY`; the front end shows that page
  (`html.php?file=KEY`) when the route changes. Links to other files are absolute `get.php` URLs
  and work as they are.
- **Hidden links:** `<a data-cal6-hidden="1">` (no `href`) is a link to something the viewer may
  not see; show it as plain text.
- **Lock forms:** `<form class="cal6-lock" method="post" action="...html.php?file=KEY">` posts
  `lock` and `csrf`; submit it with `fetch()` (it answers `303` to the page) and replace the page
  with the result, instead of letting the browser leave the app.
- **Scripts:** inserted pages keep their `<script>` elements; run them after insertion, in
  document order.
- **Login:** get a token from `config.php?csrf=1`, post to `auth.php`, keep the new token it
  returns, then reload `calendar.php`.

The plan:

- `index.html` in the course (a link to `calendar/app/index.html`, or a two-line file) loads
  `app.js`, which fetches `config.php` and `calendar.php` and draws the calendar, menus and
  pages. Navigation uses `#` routes (`#/lab/6`), so every view is a static page and deep links
  work.
- Pages are fetched from `html.php` and placed into the page; then their `<script>` elements are
  run in document order (external ones loaded first, as a browser would), and MathJax and
  highlight.js run over the new content. Scripts are passed through unchanged (Decided).
- After `auth.php`, the app reloads `calendar.php`, so the view matches the new viewer.

## Deploying (Decided)

- **Apache:** keep the `.htaccess` files the template ships: the one at the course root (it
  refuses every name that starts with a dot, such as `.git/` when the course is kept in git, and
  every `.ini` file, anywhere under the course, also through `calendar/`), and the deny-all
  files in `config/`, `pages/`, `shared/` and every event-type directory (add one to each new
  type). They need `AllowOverride AuthConfig` (or `All`) and Apache 2.4 for the dot rule.
- **nginx:** no `.htaccess`; `tests/deploy/nginx-site.conf` is a complete, tested server block
  (CI serves a course with it). In short: deny the same directories in the server block, for example
  `location ~ ^/AB123/(config|pages|shared|class|lab|exam|project)/ { deny all; }`, deny dot
  files and `.ini` files everywhere (`location ~ /\.(?!well-known/) { deny all; }` and
  `location ~ \.ini$ { deny all; }`), and pass only `calendar/php/*.php` to PHP-FPM
  (`calendar/lib/`, `calendar/tests/` and `calendar/template/` must not be reachable).
- **Checking a server:** `tests/deploy/smoke.php <course URL> <course directory>` checks a real
  deployment: the endpoints, a login, and that `config/`, the material, `.git/`, `calendar/lib/`,
  `calendar/tests/` and `calendar/template/` can not be fetched directly. CI runs it against
  Apache + PHP-FPM and nginx + PHP-FPM with a linked course (`tests/deploy/apache-setup.sh`,
  `nginx-setup.sh`). Those setup scripts replace `/opt/calendar6` and `/var/www/html/AB123`, so
  they run only with `--yes` (or `CI=true`) and never replace a directory they did not make.
- **Finding the course** relies on `SCRIPT_FILENAME` keeping the linked path; with PHP-FPM set it
  from `$document_root$fastcgi_script_name` (not `$realpath_root`). If it is resolved, every
  endpoint answers `500` instead of guessing.
- **Reverse proxies:** list them in `config.ini` `[server] trusted_proxies`; only then are
  `X-Forwarded-For` (the client's address, for rate limits) and `X-Forwarded-Proto` (HTTPS, for
  the `Secure` cookie flag) believed.
- **Sessions:** a login lasts `[users] session`; Calendar 6 sets PHP's `session.gc_maxlifetime`
  to match and keeps the course's sessions in its private directory
  (`<temp>/cal6-<uid>-<hash>/sessions`) when it can, so another application's cleanup does not
  end them early.
- **Shared hosting:** the private directory (sessions, caches, rate limits) must belong to the
  PHP user and be closed to others, or Calendar 6 does not use it (logins are then refused, as a
  safe default). Courses that run as the **same** PHP user can read each other's files anyway:
  for real separation, run each faculty member's PHP as their own user (suEXEC, PHP-FPM pools).

## Security summary (Proposed)

- The course directory is the boundary: nothing outside it is read for serving, served or run;
  paths are resolved with `realpath()` and checked; `config/`, `calendar/`, dot files, `.php` and
  `.ini` files are never served.
- Material directories and `config/` must not be reachable directly: `.htaccess` (Apache) or
  server rules (nginx).
- Access is checked where files are served (`get.php`, `html.php`), not only in `calendar.php`.
- One answer (`404`) for "no such key" and "not allowed".
- No secrets in any JSON; JSON only from `config.php`, `calendar.php`, `auth.php`.

## Implementation notes

Choices the code made where the design left room (change them here if they are wrong):

- **Library layout:** `lib/Ini` (our own INI reader), `lib/Time` (the date/time/window grammar),
  `lib/Schedule` (periods, sections, the calendar builder), `lib/Content` (the walk),
  `lib/Course` (configuration, the boundary, keys, the course model), `lib/Access` (rules,
  viewers, the policy), `lib/Render` (html.php's processing, the Markdown converter from
  Calendar 4/5), `lib/Http` and `lib/Endpoint` (the endpoints).
- **Finding the course:** the endpoints refuse to run (500) unless the script was reached as
  `course/calendar/php/...` and `course/calendar` resolves to the running code; `config.ini`
  must have a real `secret` (32+ characters), or nothing is served.
- **Caching:** the cache key combines a fingerprint of the course directory (the names, sizes and
  modification times of everything in it except `calendar/`, following links only while they
  stay inside) with the software's version (a hash of the contents of its `lib/` and `php/`
  files, so an update never serves what older code built). It keys two caches: the built course model (shared by every endpoint, signed with the course secret) and
  `calendar.php`'s answer per viewer (valid until `next_change`). Both live in
  `<temp>/cal6-<uid>-<hash of course path>/`, used only when that directory is ours and closed
  to others.
- **Calendar:** event numbers follow the calendar slots; when a type runs out of directories its
  remaining slots stay empty; directories that do not fit are events on no day (instructors
  only). A `dates.ini` line with one date means that day only; a window with times only cancels
  meetings whose first section starts inside it.
- **calendar.php:** an event whose files are all hidden is listed without its title (so hidden
  titles do not leak). Only files directly in the event directory are listed; files in
  subdirectories (`img/pic.png`) get keys so pages can link to them.
- **Keys:** 22 characters of base64url HMAC-SHA256.
- **Sessions:** one cookie per course (`cal6_<hash>`, path = the course's URL), opened by
  read-only endpoints only when the browser already has it; created by `config.php?csrf=1`,
  `auth.php`, and `html.php` for pages with `<lock>` or `<url>` tags.
- **html.php:** links to pages become `#/view/KEY`, other files the absolute path
  `/<course>/calendar/php/get.php?file=KEY`; links to files the viewer may not see keep their
  text but lose their target (`data-cal6-hidden`); links to files that do not exist are left as
  written. `<student>` becomes `<details>`; `<ace>` shows its text in `<pre>`; `<lock>` becomes
  a small form that posts the code (with the CSRF token) back to `html.php`; `<host>` matches the
  server's host name or address, never the request's `Host` header; `<calphp>` runs after
  hidden sections are removed and its output is not processed. A Markdown page is converted
  first, then processed like HTML.
- **auth.php:** the username is the file name in `config/auth/`; a missing account costs a
  password hash at the current default cost, so it takes as long as a wrong password.

## Decisions on the first open questions

| # | Question | Decided |
|---|---|---|
| 1 | Material layout | Calendar 4's: `class/<sort>.<Title>/`, boxes by file name |
| 2 | Compatibility | None required; a converter is a future enhancement |
| 3 | Pointer file | None: `<course>/config/`, found from the request path |
| 4 | Instructor | A user in `config/auth/` with `instructor = true` |
| 5 | Scripts in pages | JavaScript passed through; PHP only through `<calphp>`, output passed through |
| 6 | Tags | Every Calendar 4 tag except `<ace>`; Ace removed |
| 7 | Search | Removed (with `<keyword>` / `<search>`) |
| 8 | Config format | INI, so instructors can edit it |
| 9 | Users | Created by hand; a tool is a future enhancement |
| 10 | Course pages | `config/paths.ini` (resources, policy, ...) |
| - | Virtual files | `config/virtual.ini` |
| - | Reusing an event | `config/duplicate.ini`, `numbered` or `same` |

## Future enhancements

1. **Converter** from Calendar 4 / Calendar 5 courses: `calendar.php` settings to `config.ini`,
   `cal4.access` to `default.ini` (dates to `YYYY-MM-DD`, `1 1 1999` to `always`, `99 99 9999`
   to `never`, a missing year from the semester), the class file, `$LOAD_PAGES` to `paths.ini`.
2. **User tool** (`bin/cal6 user add <name>`): asks for the password, writes the hash, sets the
   `valid` window and grants.
3. More named pages in `paths.ini`.
