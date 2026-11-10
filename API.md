# Calendar 6: API

How to talk to Calendar 6: where the endpoints are, what each one takes and returns, and what
a web front end needs to know to build a course site on them. It is written for a developer, or
the AI they work with, who has not read the code.

- **This file is the contract.** Any change to an endpoint's inputs, outputs, status codes or
  the HTML that `html.php` produces is made here in the same commit.
- `DESIGN.md` explains *why* (the design, the configuration files, the access rules, the tags);
  `REVIEW.md` is the review log. Neither is needed to use the API.
- JSON format version: **1** (`config.php` → `version.format`). It is raised whenever the shape
  of an answer changes.

Contents: [1. What Calendar 6 is](#1-what-calendar-6-is) ·
[2. Files and URLs](#2-files-and-urls) · [3. Concepts](#3-concepts) ·
[4. Conventions for every endpoint](#4-conventions-for-every-endpoint) ·
[5. config.php](#5-configphp) · [6. calendar.php](#6-calendarphp) · [7. auth.php](#7-authphp) ·
[8. html.php](#8-htmlphp) · [9. get.php](#9-getphp) ·
[10. Building a front end](#10-building-a-front-end) · [11. Quick reference](#11-quick-reference)

---

## 1. What Calendar 6 is

A course web site whose browser side is static HTML and JavaScript (the **front end**, which
you build), backed by five small PHP scripts (the **endpoints**, in this repository):

| Endpoint | Returns | Purpose |
|---|---|---|
| `config.php` | JSON | the course's settings: name, semester, weekly schedule, special days, period times, sections, event types, box labels, pages, and the current viewer |
| `calendar.php` | JSON | everything **this viewer** may see now: every day of the semester, the events on them, and for each event the files (as keys) and notes the viewer may open |
| `auth.php` | JSON | log in (POST) and log out (GET or POST) |
| `html.php` | HTML | one course page (`.html`, `.htm`, `.md`) by key, **processed**: hidden parts removed, links rewritten to keys |
| `get.php` | the file | any other course file (PDF, image, zip, CSS, JS, ...) by key, byte for byte |

The server decides who may see what. The front end never sees file paths, rules or hidden
material: it gets **keys** from `calendar.php` (and inside pages from `html.php`), and fetches
files with them. Every fetch is checked again on the server, so a key alone grants nothing.

---

## 2. Files and URLs

### 2.1 The course directory

Each course is one directory, the **course directory** (for example
`/home/faculty/jdoe/public_html/AB123/`). Its layout is fixed:

```
AB123/                         the course directory: the boundary of everything that is served
├── calendar/                  this repository (calendar6), as a symbolic link or a clone (see 2.2)
├── config/                    REQUIRED: all configuration (.ini files); never served
│   ├── config.ini             REQUIRED: course name, semester, schedule, types, boxes, secret, ...
│   ├── dates.ini              special days (holidays, swapped weekdays, delays)
│   ├── default.ini            release rules: when material becomes visible to students
│   ├── paths.ini              the course's own pages (home, syllabus, ...)
│   ├── virtual.ini            files shown in an event but stored elsewhere in the course
│   ├── duplicate.ini          events shown again on other days
│   ├── periods.ini            the school's period times, per period schedule
│   ├── sections.ini           the course's sections: meetings, rooms, instructors
│   └── auth/<username>.ini    one file per account (password hash, instructor flag, grants)
├── index.html                 the front end's entry page (yours)
├── pages/                     pages named in paths.ini
├── shared/                    files used through virtual.ini or <inject>
├── class/                     one directory per event type ...
│   ├── 01.Introduction/       ... and in it one directory per event, "<sort>.<Title>"
│   └── 02.Loops/
└── lab/
    └── 01.Setup/
```

- **`config/` must be directly inside the course directory**, and the course directory is
  exactly the directory that contains `config/`. There is no setting that moves it.
- **Nothing outside the course directory is ever served or run.** Every path (in `.ini` files,
  and links inside pages) is resolved with symbolic links followed and must still lie inside the
  course directory; anything that leads outside is treated as missing.
- **Never served even inside the course:** `config/` (so `auth/` too), `calendar/`, names
  starting with a dot, `.ini` files, PHP files under any spelling (`.php`, `.php5`, `.phtml`,
  `.phar`, ...), and editors' backups of pages (`index.html~`, `notes.md.bak`, `#index.html#`, ...).
- **Only known files get keys:** event files, `virtual.ini` files, `paths.ini` pages, and the
  files those pages link to. There is no way to ask for a file by path.

Event directories: `<type>/<sort>.<Title>/`, natural sort order, names starting with a dot
ignored. `class/01.Introduction/` is the first class meeting's material, titled "Introduction".
Which file fills which **box** (title page, homework, solution, slides, ...) is set by exact file
names in `config.ini` `[boxes]`; `[notes]` names a short remarks file per event.

### 2.2 Where calendar6 goes, and the endpoint URLs

The repository is meant to sit at **`<course>/calendar`**, either cloned there or (usually, so
one copy serves many courses) as a symbolic link:

```bash
ln -s /opt/calendar6 /home/faculty/jdoe/public_html/AB123/calendar
```

Then the endpoints are at:

```
<course URL>/calendar/php/config.php
<course URL>/calendar/php/calendar.php
<course URL>/calendar/php/auth.php
<course URL>/calendar/php/html.php
<course URL>/calendar/php/get.php
```

for example `https://www.example.edu/~jdoe/AB123/calendar/php/calendar.php`. A front end whose
`index.html` sits in the course directory reaches them with the relative URLs
`calendar/php/config.php` and so on.

How the endpoints find the course: from the path the script was requested under
(`SCRIPT_FILENAME`), the course directory is two levels above `php/`. In the current code that
directory must be named `calendar` and must resolve to the running code, and
`<course>/config/config.ini` must exist with a real `secret`; otherwise every endpoint answers
`500` with a short JSON error and reads nothing. (With PHP-FPM, `SCRIPT_FILENAME` must keep the
linked path: `$document_root$fastcgi_script_name`, not `$realpath_root`.)

### 2.3 The web server

`config/`, the material directories (`class/`, `lab/`, `pages/`, `shared/`, ...), dot files,
`.ini` files and every PHP file other than `calendar/php/<name>.php` must not be reachable
directly; only the endpoints hand out course files. The template course ships `.htaccess` files
for Apache; for nginx see `tests/deploy/nginx-site.conf`. `tests/deploy/smoke.php <course URL>
<course directory>` checks a real deployment. Files at the course root (next to `index.html`)
*are* served as they are by the web server, so keep material in the event directories and pages
in `pages/`.

The front end must be served **from the same host** as the endpoints: the session cookie is
`SameSite=Strict` and limited to the course's URL path.

---

## 3. Concepts

**Viewer.** Every request is answered for the current viewer:

| Viewer | How | Sees |
|---|---|---|
| anonymous | no login | what the release rules (`default.ini`) allow now |
| user | logged in, `instructor` false | the same, plus their **grants** (from `auth/<user>.ini`) |
| instructor | logged in, `instructor` true | everything, plus problems, hidden-item markers and previews |

A logged-in viewer (user or instructor) is called **logged-on** below.

**Event.** One meeting of an event type on a calendar day, for example the 5th class. Its id is
`<type>_<number>` (`class_5`); a `same` repeat from `duplicate.ini` adds its date
(`class_5@2026-11-11`); in rare collisions a suffix `-2`, `-3` is added. Ids are stable while
the calendar and material are unchanged. An event has a type, a number, a title (from its
directory name), a date (or `null` for material that does not fit on the calendar: instructors
only), boxes and notes.

**Box.** A named slot in an event (`title`, `homework`, `lab-solution`, `slides`, ...), filled by
the file `config.ini` names for it. The box list and labels come from `config.php`; the files
the viewer may open come from `calendar.php`.

**Notes.** A short remark per event (`notes.txt` or whatever `[notes]` names), returned as text
inside `calendar.php`, at most 2000 bytes. It is **not processed**: show it as plain text, or, if
you choose to render HTML notes, sanitize them first.

**Page.** A course page from `paths.ini` (home, syllabus, resources), identified by name.

**Key.** A 22-character base64url string (`[A-Za-z0-9_-]{22}`) naming one file in one place.
Keys are stable (bookmarkable) until the course's secret, or the event the file belongs to,
changes. A key is not a capability: every request with it is checked for the current viewer.

**kind.** Which endpoint serves a key: `"html"` → `html.php?file=KEY` (a page: `.html`, `.htm`,
`.md`), `"get"` → `get.php?file=KEY` (anything else).

**Section, period, period schedule.** A course has sections (`1121`, `4345`, ...), each meeting
on given weekdays in given periods (`MF1` = Mondays and Fridays, period 1), in rooms, with
instructors. A period schedule (`normal`, `2_hour_delay`, ...) gives the clock times of each
period; a day may follow a non-default schedule. Times only appear in `calendar.php` when a
section is requested.

**Times.** Moments are ISO 8601 with offset in the course's timezone
(`2026-11-16T17:00:00-05:00`); dates are `YYYY-MM-DD`; clock times `HH:MM` (24 h). The course's
timezone is `config.php` → `course.timezone`.

---

## 4. Conventions for every endpoint

- **Methods.** Each endpoint lists its methods; anything else is `405` with an `Allow` header.
  `HEAD` is accepted wherever `GET` is (except `auth.php`).
- **Request bodies.** POST bodies may be `application/x-www-form-urlencoded`,
  `multipart/form-data`, or `application/json` (an object of scalar values).
- **Credentials.** Use `fetch(url, {credentials: 'same-origin'})` (the default) so the session
  cookie goes along.
- **Headers on every answer:** `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  same-origin`, `X-Frame-Options: SAMEORIGIN`.
- **Caching.** `config.php`, `auth.php` and `html.php`: `Cache-Control: private, no-store`.
  `calendar.php` and `get.php`: `Cache-Control: private, no-cache` with an `ETag`; send
  `If-None-Match` and you get `304 Not Modified` when nothing changed (browsers do this for you).
- **JSON** is `application/json; charset=utf-8`, UTF-8, with slashes and Unicode unescaped.
- **Errors** are JSON `{"error": "message"}` with the status code, from every endpoint
  (`html.php` and `get.php` included). `auth.php` errors carry its full shape (see 7). The
  message is for people; branch on the status code.

| Status | Meaning |
|---|---|
| 400 | a malformed parameter (a list where one value is expected, a bad `as`/`at`) |
| 401 | `auth.php`: wrong username or password (or the account is outside its window) |
| 403 | a missing or expired CSRF / lock-form token, or a cross-site lock-form POST |
| 404 | no such key, **or not allowed** (the same answer, on purpose); an unknown section |
| 405 | method not allowed |
| 416 | `get.php`: a `Range` past the end of the file |
| 429 | too many failed logins or lock codes; wait `Retry-After` seconds |
| 500 | the course can not be found or `config.ini` is unusable (see 2.2) |
| 503 | logins or lock codes can not be checked (the server's private directory is unusable) |

---

## 5. `config.php`

The course's settings, the same for every viewer apart from `viewer` and `csrf`. It changes only
when the configuration files change.

**Methods:** `GET`, `HEAD`.

**Input:**

| Parameter | Required | Meaning |
|---|---|---|
| `csrf=1` | no | start a session (if none) and return its CSRF token in `csrf`, for a login form. Without it, no session is created and `csrf` is `null` unless the browser already has a session. A request the browser marks cross-site (`Sec-Fetch-Site: cross-site`) never creates one. |

**Output:**

```json
{
  "course":   {"number": "AB123", "name": "Web and Internet Programming", "timezone": "America/New_York"},
  "semester": {"start": "2026-08-24", "end": "2026-12-18", "weekends": false},
  "schedule": {"mon": "class", "tue": "class", "wed": "lab", "fri": "class 08:00-08:50"},
  "days": [
    {"date": "2026-09-07", "events": [], "as": "holiday", "label": "Labor Day"},
    {"date": "2026-09-18", "cancel": [{"from": "10:00", "until": "12:00", "as": "holiday"}], "label": "assembly"},
    {"date": "2026-10-13", "events": ["class"], "as": "mon", "label": "Monday schedule"},
    {"date": "2026-10-14", "events": ["lab"], "label": "Lab day"},
    {"date": "2026-12-03", "period": "2_hour_delay", "label": "2-hour delay (snow)"}
  ],
  "period":   "normal",
  "periods": {
    "normal":       {"label": "Normal", "times": {"X": "06:45-07:35", "1": "07:45-08:35", "2": "08:45-09:35", "4": "10:45-11:35"}},
    "2_hour_delay": {"label": "2 Hour Delay", "times": {"1": "09:45-10:35", "4": "13:45-14:35"}},
    "4_hour_delay": {"label": "4 Hour Delay", "times": {"1": "cancelled", "5": "13:40-14:30"}}
  },
  "sections": [
    {"section": "1121", "meetings": [{"meets": "MF1", "type": "class", "room": "HP111"}, {"meets": "T12", "type": "class", "room": "HP111"}], "instructor": "SMITH"},
    {"section": "4345", "meetings": [{"meets": "MF4", "type": "class", "room": "HP132"}, {"meets": "R34", "type": "class", "room": "HP301"}], "instructor": "JONES"}
  ],
  "types":    {"class": {"label": "Class"}, "lab": {"label": "Lab"}, "project": {"label": "Project"}, "exam": {"label": "Exam"}},
  "boxes":    [{"box": "title", "label": "Lecture"}, {"box": "homework", "label": "Homework"}, {"box": "lab-solution", "label": "Solution"}, {"box": "slides", "label": "Slides"}],
  "pages":    [{"page": "home", "label": "Home"}, {"page": "resources", "label": "Resources"}, {"page": "policy", "label": "Syllabus"}],
  "duplicates": [{"event": "class_6", "copy_of": "class_5", "mode": "numbered"},
                 {"event": "lab_6@2026-10-07", "copy_of": "lab_6", "mode": "same"}],
  "session_seconds": 28800,
  "version":  {"format": 1, "software": "9afc69441175"},
  "viewer":   {"user": null, "instructor": false},
  "csrf":     null
}
```

| Field | Type | Meaning |
|---|---|---|
| `course.number`, `course.name` | string | the course's number and name |
| `course.timezone` | string | IANA timezone of every time in the API |
| `semester.start`, `semester.end` | date | first and last day of the semester |
| `semester.weekends` | bool | whether Saturdays and Sundays can hold events |
| `schedule` | object | weekday (`mon`…`sun`) → event type that meets that day, optionally followed by fixed times (`"class 08:00-08:50"`); weekdays with nothing are absent |
| `days` | array | **only the days `dates.ini` changes**, one entry per date, in date order (see below) |
| `period` | string | the default period schedule's name |
| `periods` | object | schedule name → `label` and `times`: period name → `"HH:MM-HH:MM"`, or `"cancelled"` (the period exists but does not meet on that schedule). Period names are strings (`"1"`, `"X"`, `"10"`) |
| `sections` | array | each section: `section` (its number, a string), `meetings` (`meets`: weekday letters `M T W R F S U` then period names, e.g. `MF1`, `R34`; `type`: the event type; `room`), and `instructor` (comma-separated names) |
| `types` | object | event type → `label`, in the course's order |
| `boxes` | array | the boxes in display order, `box` (id) and `label` |
| `pages` | array | the course's pages in menu order: `page` (name, the key into `calendar.php`'s `pages`) and `label` |
| `duplicates` | array | events that repeat another, as the calendar applied them: `event` (id as in `calendar.php`), `copy_of` (the source event's id), `mode` (`numbered`: a copy under its own number; `same`: the source shown again under its number) |
| `session_seconds` | int | the longest a login lasts |
| `version.format` | int | the JSON format version (this document's) |
| `version.software` | string | changes with every update of the server code; a front end that cached anything can reload when it changes |
| `viewer.user` | string\|null | the logged-in username, or `null` |
| `viewer.instructor` | bool | whether the viewer is an instructor |
| `csrf` | string\|null | the session's CSRF token (see `csrf=1`) |

**`days` entries** (each has `date`; the rest only when they apply):

| Field | Meaning |
|---|---|
| `events` | the event types meeting that day (`[]` for a holiday or `none`); present when `dates.ini` chose them (a type list, a weekday swap, `holiday`, `none`) |
| `as` | `holiday`, `none` (no meetings, not a holiday), or the weekday whose schedule the day follows (`mon`) |
| `period` | a period schedule other than the default, for the whole day |
| `cancel` | windows with times: `from`, `until` (`HH:MM`; `24:00` when the window runs past midnight, and the next day's entry then starts at `00:00`), `as` (`holiday` or `none`). Meetings whose start falls inside a window are cancelled |
| `label` | the text to show for the day |

The day-by-day picture, with these changes applied, is in `calendar.php`; `config.php` is for
showing *why* (a legend, a list of holidays) and for drawing before `calendar.php` answers.

Never in `config.php`: the secret, password hashes, release rules, grants, the lockdown date,
file names or paths, `[replace]` values.

---

## 6. `calendar.php`

Everything the current viewer may see now: every day of the semester, the events, the files
(as keys) and notes the viewer may open, and the pages.

**Methods:** `GET`, `HEAD`, `POST`.

**Input:** every parameter may come in the query string or in a POST body (form or JSON). For
each name the first non-empty value counts, the query string first. A list (`section[]=1121`) is
`400`.

| Parameter | Who | Meaning |
|---|---|---|
| `section` | everyone | a section number from `config.php` → `sections`. Events that section meets for then carry its times, period, instructor, room (see below). Unknown section: `404`. Without it, no times are sent. |
| `as` | instructors (ignored for others) | `anonymous` or a username: the answer that viewer would get (a preview). Unknown user: `400` |
| `at` | instructors (ignored for others) | `YYYY-MM-DD HH:MM` or `YYYY-MM-DD`, in the course's timezone: the answer at that moment instead of now. Malformed: `400` |

**Output:**

```json
{
  "generated": "2026-11-09T14:05:00-05:00",
  "viewer":    {"user": null, "instructor": false},
  "section":   "1121",
  "pages": {
    "home":      {"title": "Home", "key": "IfPCJDMatGX2jBxNZx2Lyc", "filename": "home.html", "kind": "html"},
    "resources": {"title": "Resources"},
    "policy":    {"title": "Syllabus", "key": "W-LC1JEUPEkJXjv6eD_d_v", "filename": "syllabus.html", "kind": "html"}
  },
  "days": {
    "2026-11-09": [
      {"event": "class_30", "type": "class", "number": 30, "title": "Sessions and Cookies",
       "start": "07:45", "end": "08:35", "period": "1", "instructor": "SMITH", "room": "HP111"},
      {"event": "lab_10", "type": "lab", "number": 10, "title": "Cookies"}
    ],
    "2026-11-10": [{"event": "", "title": "", "label": "lab day"},
                   {"event": "lab_11", "type": "lab", "number": 11, "title": "Forms"}],
    "2026-11-11": [{"event": "", "title": "holiday", "label": "Veterans Day"}],
    "2026-11-12": [],
    "2026-12-03": [{"event": "", "title": "", "label": "2-hour delay (snow)", "schedule": "2_hour_delay"},
                   {"event": "class_35", "type": "class", "number": 35, "title": "Review",
                    "period": "1", "instructor": "SMITH", "room": "HP111", "cancelled": true}]
  },
  "events": {
    "class_30": {
      "type": "class", "number": 30, "title": "Sessions and Cookies", "date": "2026-11-09",
      "start": "07:45", "end": "08:35", "period": "1", "instructor": "SMITH", "room": "HP111",
      "section": "1121",
      "boxes": [
        {"box": "title",    "filename": "index.html",    "key": "Gw0S97OKpDLkvJHgmGccVY", "kind": "html"},
        {"box": "homework", "filename": "homework.html", "key": "UAAGsUAfL9QMgUtQxDPdur", "kind": "html"},
        {"box": "slides",   "filename": "slides.pdf",    "key": "5RJa1Nu_itLTZx_mudCkhS", "kind": "get"}
      ],
      "notes": [{"filename": "notes.txt", "text": "The class meets in the auditorium today."}]
    },
    "lab_10": {"type": "lab", "number": 10, "title": "Cookies", "date": "2026-11-09", "boxes": []}
  }
}
```

**Top level:**

| Field | Type | Meaning |
|---|---|---|
| `generated` | moment | when the answer was built (it may come from the server's cache) |
| `viewer` | object | `user` (string\|null) and `instructor` (bool); for an instructor's `as` preview, the previewed viewer |
| `section` | string | only when a section was requested |
| `pages` | object | page name → page (see below), in `paths.ini` order |
| `days` | object | **every** date from `semester.start` to `semester.end` → list of items (see below), in date order; `[]` for a day with nothing on it |
| `events` | object | event id → event (see below) |
| `problems` | array | instructors only: `{"message": string, "file": string, "line": int}` (bad configuration lines, overridden lines, odd file names, copies that could not be made, files named like a box's file that follow the event's rule, ...). `line` is `0` when it does not apply |

**`pages` entries:** `title` always. When the viewer may open the page, also `key`, `filename`
and `kind` (always `"html"`). Logged-on viewers may also see `visible` and `opens` (see "Hints").

**`days` items.** A day's list starts with at most one **marker** (`"event": ""`) when
`dates.ini` changed the day (a cancel window with times gets a marker only when it has a label;
its times are in `config.php` → `days`), followed by one item per event shown that day:

| Marker field | Meaning |
|---|---|
| `event` | `""` |
| `title` | what the day is instead of its weekday: `holiday`, `none`, a weekday (`mon`) it follows, or `""` (the day only got other event types, a label or a period schedule) |
| `label` | optional: the text to show |
| `schedule` | optional: the period schedule the day follows, when not the default |

| Event item field | Meaning |
|---|---|
| `event` | the event's id, a key into `events` |
| `type`, `number`, `title` | as in the event |
| `start`, `end`, `period`, `instructor`, `room`, `cancelled` | only with `section`, as in the event (below) |

**`events` entries:**

| Field | Type | When | Meaning |
|---|---|---|---|
| `type` | string | always | event type (a key of `config.php` → `types`) |
| `number` | int | always | the number shown (`Class 5`) |
| `title` | string | always | the event's title, shown to **everyone** even when nothing in it is visible yet (may be `""`) |
| `date` | date\|null | always | its calendar day; `null` only for instructors (material that does not fit on the calendar) |
| `start`, `end` | `HH:MM` | with `section`, when the section meets for this event that day and the meeting has times | the section's meeting times that day |
| `period` | string | with `section` and a meeting | the section's periods, concatenated (`"1"`, `"12"`, `"34"`) |
| `instructor` | string | with `section` and a meeting | comma-separated names |
| `room` | string | with `section` and a meeting | the meeting's room |
| `cancelled` | `true` | with `section`, when the meeting is cancelled | the meeting does not take place: its period is `cancelled` on that day's schedule (then no `start`/`end`), or it starts inside a `dates.ini` cancel window (then `start`/`end` are the times it would have had) |
| `section` | string | with `section` and a meeting | the requested section |
| `boxes` | array | always | the box files the viewer may open, in `config.php` box order: `box`, `filename`, `key`, `kind` (`html`/`get`), plus hints for logged-on viewers. `[]` when nothing is visible yet |
| `notes` | array | when a notes file is visible | `filename` and `text` (as written, trimmed, ≤ 2000 bytes, a longer text cut and ending in `…`), plus hints |
| `opens` | moment | logged-on viewers | the earliest `opens` of its items (see "Hints") |
| `copy_of`, `mode` | string | `duplicate.ini` copies | the source event's id; `numbered` or `same` |
| `directory` | string\|null | instructors | the event's material directory relative to the course (`class/01.Introduction`), `null` if it has none |

There is **no list of an event's other files**: only box files and notes. Other files (images, a
dataset a page links to) are reached through links in pages, which `html.php` rewrites to keys.

**Hints (logged-on viewers only).** Anonymous viewers get only what they may open, with no hint
of what is hidden or when it opens. A logged-on viewer, on a page, box file or note it may open
but anonymous viewers may not yet, also gets `"visible": false`, and `"opens": moment` when a
date will release it (no `opens` for something that is never released to students). Instructors
see every file; users see what their grants add. Items anonymous viewers already see carry
neither field.

**Caching.** Answers are cached on the server per viewer and section and revalidated with
`ETag`; there is no "valid until" in the answer (an instructor can change material at any time),
so fetch `calendar.php` again whenever you need fresh data (on load, after login or logout,
when the tab becomes visible again, or on a timer); a `304` costs almost nothing.

---

## 7. `auth.php`

**Methods:** `GET` (log out), `POST` (log in or out).

**Log out:** `GET auth.php`, or `POST` with no `username` and no `password`. No token needed.
It clears the browser's session (the login, unlocked lock codes, remembered `<url>` values) and
never creates one.

**Log in:** `POST` with:

| Field | Required | Meaning |
|---|---|---|
| `username` | yes | the account name: a letter or digit, then letters, digits, `.`, `_`, `-`; at most 64 characters |
| `password` | yes | the password |
| `csrf` | yes | the token from `config.php?csrf=1` (or from the last `auth.php` answer) |

**Output** (every answer has all five fields, in this order):

```json
{"user": "jdoe", "instructor": false, "valid_until": "2026-12-18T17:00:00-05:00", "csrf": "q9Xb...", "error": null}
{"user": null, "instructor": false, "valid_until": null, "csrf": "Lm2c...", "error": null}
{"user": null, "instructor": false, "valid_until": null, "csrf": null, "error": "Wrong username or password."}
```

| Field | Type | Meaning |
|---|---|---|
| `user` | string\|null | the logged-in username |
| `instructor` | bool | whether that account is an instructor |
| `valid_until` | moment\|null | when the login ends by itself (the account's window or `session_seconds`, whichever is first) |
| `csrf` | string\|null | a **new** token (the session id changes at login and logout); use it for the next login |
| `error` | string\|null | the error message, or `null` |

| Situation | Status | Answer |
|---|---|---|
| logged in | 200 | `user`, `instructor`, `valid_until`, `csrf` set |
| logged out | 200 | `csrf` set if the browser had a session, else `null` |
| wrong password, unknown user, or account outside its valid window | 401 | `error`: the same message for all three |
| missing/expired token, or no session (call `config.php?csrf=1` first) | 403 | `error` |
| too many attempts | 429 | `error`, header `Retry-After` (seconds) |
| attempts can not be counted | 503 | `error` |
| other methods | 405 | `error` |

Failed logins are limited per address and username, and per address; a browser that has logged
in as that user before gets a signed device cookie that keeps the real user from being locked
out by others' guesses. After any change of login, fetch `calendar.php` again.

---

## 8. `html.php`

One course page (`.html`, `.htm` or `.md`), **processed** for the current viewer.

**html.php returns only the contents of the page file, processed by the rules, and never
anything else**: no surrounding document is added, nothing is stripped apart from what the rules
remove, and no site CSS or JavaScript is added. A page that is a whole HTML document (with
`<!DOCTYPE>`, `<html>`, `<head>`, `<title>`, `<style>`, `<script>`, `<body>`) comes back as a whole
document; a page that is a fragment comes back as a fragment. Markdown comes back as the HTML it
converts to.

**Methods:** `GET`, `HEAD`; `POST` only to send a lock code.

**Input:**

| Parameter | Required | Meaning |
|---|---|---|
| `file` | yes | a page key (`kind: "html"`) |
| any other query parameter | no | a value for the page's `<url value="name">` tags. Names: letters, digits, `_`, `-`, at most 64 characters, not `file`, `lock` or `csrf`; values: at most 200 characters, HTML-escaped when placed. Values are remembered in an existing session (not one created for this) |
| `lock` (POST) | no | a code for a `<lock>` section, with `csrf` (POST): the token that is in the page's own lock form |

**Output:** `200`, `text/html; charset=utf-8`, the processed page. A key that does not exist, is
not a page, or that this viewer may not open: `404` (JSON error). A get-file key (`kind: "get"`)
is `404` here.

**What processing does** (all on the server, before anything reaches the browser):

1. **Removes what the viewer may not see:** `<inst>…</inst>` (instructors only),
   `<postmeeting>…</postmeeting>` (until the event's meetings are over), `<host server="…">`
   (other servers), `<lock>` sections not yet unlocked (replaced by an unlock form). The tags
   themselves are always removed.
2. **Removes HTML comments**, for everyone (`<!-- … -->`). A `<!DOCTYPE …>` is kept.
3. **Rewrites links to course files to keys**, as described in the table below.
4. **Handles the other tags:** `<inject src>` (another file's processed content in place),
   `<codeinject src>` (another file's source, escaped, in place: wrap it in `<pre>` yourself in
   the page), `<replace value>` (course values), `<url value default>` (query values),
   `<showlink src>` (a link to another event file), `<calphp src>` (a course PHP script's output).
5. **Leaves JavaScript and CSS as written.** `<script>` and `<style>` elements and inline
   handlers reach the browser unchanged; the front end must run scripts itself (see 10.4).

**Links in the returned HTML:**

| In the page | In the answer |
|---|---|
| `<a href="other.html">` (or `<area>`) to a course page the viewer may open | `href="#/view/KEY"` (the query string goes along: `#/view/KEY?week=3`; the fragment too) |
| `<img src>`, `<script src>`, `<link href>`, `<source src>`, `<video poster>`, `<input type=image src>`, svg `<image href>`, `background=`, `srcset` candidates, and `<a href>` to a non-page file | `get.php` URLs, absolute paths: `/~jdoe/AB123/calendar/php/get.php?file=KEY` |
| `<iframe>`, `<embed>`, `<object>` pointing at a page | `/~jdoe/AB123/calendar/php/html.php?file=KEY` (with the link's query string) |
| any link to a course file the viewer may not open | the element keeps its text, loses its target, and gets `data-cal6-hidden="1"` |
| external links (`https://…`, `mailto:`, `/absolute/path`, `#anchor`, `?query`), and links to missing files or paths outside the course | unchanged |

**HTML that processing inserts** (style these; the front end may enhance them):

| Markup | Where | What to do |
|---|---|---|
| `<a data-cal6-hidden="1">text</a>` (no `href`; other elements likewise) | a link to something the viewer may not open | show as plain text |
| `<form class="cal6-lock" method="post" action="/…/calendar/php/html.php?file=KEY"><input type="hidden" name="csrf" value="…"><label>Enter the code to see this section <input type="password" name="lock" autocomplete="off"></label><button type="submit">Unlock</button></form>` | a `<lock>` section not yet unlocked | submit with `fetch` (see 10.5) |
| `<details class="cal6-student"><summary>Show answer</summary>…</details>` | `<student>…</student>` | works as is; content is sent to everyone, hidden only visually |
| `<pre class="cal6-ace"><code>…</code></pre>` | legacy `<ace>` blocks | style as code |
| `<p class="cal6-error">…</p>` | instructors only: a `<calphp>` script that is missing or failed | show it |
| `id` on `<a name="x">` (becomes `<a id="x" name="x">`) and on Markdown headings | anchors | build an in-page menu from them; `#x` links work within the page |

Math (`\( \)`, `\[ \]`, `$$ $$`) is left as written for MathJax; code blocks for highlight.js.

**Lock codes (POST).** The form posts `lock` and `csrf`. Answers:

| Situation | Status |
|---|---|
| right or wrong code, valid token | `303 See Other`, `Location: …/calendar/php/html.php?file=KEY` (fetch it to get the page, now unlocked if the code was right) |
| missing or expired token (tokens last 1–2 hours), or a cross-site POST | 403 |
| too many wrong codes | 429 with `Retry-After` (503 if attempts can not be counted) |

A right code is remembered in the session (one is created then) and opens every `<lock>` with
that code on every page until logout or the session ends.

---

## 9. `get.php`

Any course file that is not a page, by key, **byte for byte**.

**Methods:** `GET`, `HEAD`.

**Input:**

| Parameter | Required | Meaning |
|---|---|---|
| `file` | yes | a file key (`kind: "get"`, or a `get.php` URL from a processed page) |
| `download=1` | no | `Content-Disposition: attachment` instead of inline |
| header `Range` | no | one byte range (`bytes=0-999`, `bytes=1000-`, `bytes=-500`): `206 Partial Content` with `Content-Range` |
| header `If-None-Match` / `If-Modified-Since` | no | `304 Not Modified` when unchanged |

**Output:** `200` with the whole file, `Content-Type` by extension (`application/pdf`,
`image/png`, `text/css`, `text/javascript`, ...), `Content-Length`, `Accept-Ranges: bytes`,
`ETag`, `Last-Modified`, `Cache-Control: private, no-cache`, and `Content-Disposition` with the
file's name: `inline` for types a browser shows (PDF, images, audio, video, text, CSS, JS, JSON,
...), `attachment` for everything else and always with `download=1`. HTML and SVG files are
always attachments, so they can not run as pages of the site; unknown types are sent as
`application/octet-stream`.

| Situation | Status |
|---|---|
| unknown key, not allowed, a page key, or a file that is never served | 404 (the same answer for all) |
| `Range` past the end of the file | 416 |
| other methods | 405 |

Pages (`.html`, `.htm`, `.md`) are never sent by `get.php`, not even to instructors: their raw
source contains hidden material. Use `html.php`.

---

## 10. Building a front end

### 10.1 Start-up

1. `GET calendar/php/config.php` → settings and the viewer.
2. `GET calendar/php/calendar.php` (with `?section=` if the visitor chose one, e.g. remembered
   in `localStorage`) → days, events, pages.
3. Draw the calendar from `calendar.php` → `days` (one cell per date; markers for holidays and
   labels; event items with their titles). Use `config.php` → `types` for type labels,
   `boxes` for box labels and order, `pages` for the menu.

The two requests are independent; send them together.

### 10.2 Views and routes

Use `#` routes so every view is a static page and deep links work. `html.php` writes links to
other pages as **`#/view/KEY`** (optionally `#/view/KEY?name=value`), so the app must handle that
route: fetch `calendar/php/html.php?file=KEY` plus the query, and show the result. Choose any
other routes you like (`#/day/2026-11-09`, `#/event/class_5`, `#/page/policy`).

To open a box file: `kind: "html"` → `#/view/KEY`; `kind: "get"` → link to
`calendar/php/get.php?file=KEY` (add `&download=1` for a download button).

### 10.3 Sections and times

Offer a section picker from `config.php` → `sections`. With a section chosen, request
`calendar.php?section=N`; show `start`–`end`, `room`, `instructor`; mark `cancelled` meetings.
Without one, show no times. A day's period schedule is in the day's marker (`schedule`) and in
`config.php` → `days`.

### 10.4 Showing a page

1. Fetch `html.php?file=KEY[&query]`. A `404` means it does not exist or the viewer may not
   open it (they may need to log in, or it is not released yet).
2. Insert the HTML. If it is a whole document (starts with `<!DOCTYPE` or `<html`), take its
   `<body>` content and `<head>` styles and scripts, or show it in an `<iframe srcdoc>`/blob
   frame; otherwise insert it as a fragment.
3. **Run its scripts**: elements inserted with `innerHTML` do not run. Replace each `<script>`
   with a new one (copy attributes and text), in document order, waiting for external ones to
   load before the next, as a browser would.
4. Typeset math (MathJax) and highlight code (highlight.js) over the new content.
5. Intercept clicks on links `#/view/KEY…` (they are routes already) and handle `#anchor` links
   within the page. Show `[data-cal6-hidden]` elements as plain text.

Never insert `notes` text as HTML unless you sanitize it: it is the file as written.

### 10.5 Lock forms

On submit of `form.cal6-lock`, prevent the default and
`fetch(form.action, {method: 'POST', body: new FormData(form)})`. `fetch` follows the `303` and
returns the page again (unlocked when the code was right); show it as in 10.4. On `403`, reload
the page (the form's token expired); on `429`, show "try again later".

### 10.6 Logging in and out

```js
const {csrf} = await (await fetch('calendar/php/config.php?csrf=1')).json();
const r = await fetch('calendar/php/auth.php', {method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({username, password, csrf})});
const a = await r.json();          // a.error is null on success
// then: reload calendar.php (and config.php for the viewer), keep a.csrf for next time
```

Log out with `fetch('calendar/php/auth.php')` (a GET, or a plain link), then reload
`calendar.php`. `valid_until` says when the login ends by itself; after that the viewer is
anonymous again (a fresh `calendar.php` shows it in `viewer`).

### 10.7 Instructors

When `viewer.instructor` is true: show `problems` (a status panel); show `visible: false` items
marked as hidden from students, with their `opens` date; list events with `date: null`; offer a
preview "as anonymous" / "as user" and "at a moment" (`calendar.php?as=anonymous&at=2026-12-01
08:00`). Users with grants also get `visible` / `opens` hints on what their grants open.

### 10.8 Freshness

Refetch `calendar.php` on load, after login/logout, when the tab becomes visible, and on a
modest timer (it answers `304` when unchanged). If `config.php` → `version.software` changes,
the server was updated: reload the app.

### 10.9 Things the API does not do

No search, no file listing, no uploads, no editing; no server-rendered calendar or site layout;
no CSS or JavaScript for the front end (serve your own from the course directory or `calendar/`,
or through `get.php` as course files).

---

## 11. Quick reference

| Request | Answer |
|---|---|
| `GET config.php` | settings (5) |
| `GET config.php?csrf=1` | settings + a CSRF token (starts a session) |
| `GET calendar.php` | days, events, pages for this viewer (6) |
| `GET calendar.php?section=1121` | the same with section times |
| `GET calendar.php?as=anonymous&at=2026-12-01 08:00` | instructors: preview |
| `POST auth.php` `username`, `password`, `csrf` | log in (7) |
| `GET auth.php` | log out |
| `GET html.php?file=KEY[&name=value]` | a processed page (8) |
| `POST html.php?file=KEY` `lock`, `csrf` | unlock, `303` to the page |
| `GET get.php?file=KEY[&download=1]` | a file, byte for byte (9) |
