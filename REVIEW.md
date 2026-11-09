# Calendar 6: review log

A running list of code and security findings for the whole project, and their history.

- **IDs** are numbered once per category and never reused, even if an item is withdrawn:
  **C** critical, **H** high, **M** medium, **L** low, **DOC** documentation, **T** testing,
  **E** enhancement.
- **Status:** `Open`, `Fixed in <commit>`, or `Withdrawn (<reason>)`. Fixed items stay in the list.
- Locations are `file:line` at the commit the review read; later commits may move them.

## Reviews

| Review | Date | Commit reviewed | Scope | New items |
|---|---|---|---|---|
| 1 | 2026-11-09 | `ca77445` | everything: `lib/`, `php/`, `tests/`, `template/`, `DESIGN.md` | H-1..H-3, M-1..M-10, L-1..L-14, DOC-1..DOC-6, T-1..T-6, E-1..E-7 |

## Suggested order (after review 1)

1. **H-1, H-2**: a small change each, and each one exposes protected content today.
2. **M-1**: one malformed file name takes the whole calendar down.
3. **T-1, T-2**: lock the boundary and the template down with tests before changing more code.
4. **M-4, M-5**: course pages that use scripts, style sheets or images on `paths.ini` pages do
   not work; L-9 (`srcset`) belongs with them.
5. **M-6**: logins end after 24 idle minutes whatever `config.ini` says.
6. **H-3, M-7, M-8**: rework the rate limiter together (fail closed, lock the file, keep a
   per-address limit that does not lock the account out for everyone).
7. **M-2, M-3**: decide what injected files and unboxed files should obey (design questions
   first), then implement.
8. **M-9, M-10, E-2, E-6**: performance: cache the course model, stop following links out of
   the course in the fingerprint, index places by real path.
9. **T-3** (an injectable clock for HTTP tests), then **T-4, T-5, T-6**.
10. The **L** items, in number order; **L-1** and **L-7** first (compatibility and timing).
11. **DOC** items, ideally alongside the code each one describes.
12. **E** items as the front end (E-1) needs them.

## Critical

None found.

## High

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| H-1 | Open | `lib/Render/PageRenderer.php:144` | `<host server="...">` compares against `$_SERVER['SERVER_NAME']`, which Apache (default `UseCanonicalName Off`) and nginx (`$host`) take from the request's `Host` header. Anyone can send `Host: the-internal-server` and see content meant only for one server (for example solutions). (Calendar 4 had the same weakness.) | Compare only against values the client can not choose: `gethostname()` and `$_SERVER['SERVER_ADDR']`. See L-1 for the rest of v4's behaviour. |
| H-2 | Open | `lib/Access/Rules.php:40`, `lib/Course/Course.php:56` | A `default.ini` line that can not be read is skipped and shown only as a problem, so the target falls back to a weaker rule or to "no rule = visible". A typo such as `exam = nver` or `exam = 2026-13-01` publishes every exam. That fails open, in the one file whose job is to protect. | When the target can be read but the time can not, record the line as `never` for that target (and keep the problem for instructors). When the target itself is invalid (a bad range), keep it as a problem only. |
| H-3 | Open | `lib/Http/TempDir.php:15`, `lib/Http/RateLimiter.php:29` | When the private temporary directory can not be used (wrong owner or permissions, `sys_get_temp_dir()` not writable, or a directory another account created first under the predictable name `cal6-<uid>-<hash>`), `RateLimiter` silently stops counting: unlimited password and lock-code guessing. | Fail closed: when the limiter has no storage, refuse logins and lock codes with a clear error for instructors, or fall back to a directory the course chooses. Add a randomised suffix kept in the session-independent cache, or check ownership before trusting an existing directory (already done) and report when it fails. |

## Medium

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| M-1 | Open | `lib/Http/Response.php:30` | `json_encode(... JSON_THROW_ON_ERROR)`: one directory or file name that is not UTF-8 (common for files copied from older systems) makes `calendar.php` and `config.php` answer 500 for everyone. Confirmed with a file named `bad\xe9.txt`. | Use `JSON_INVALID_UTF8_SUBSTITUTE` and report such names as problems for instructors. |
| M-2 | Open | `lib/Render/PageRenderer.php:79` | `<inject>` and `<codeinject>` read any file inside the course boundary without checking its release rule, so a visible page can pull in another event's hidden solution (or a hidden file of its own event). Authors write these tags, but the effect contradicts "access is checked where files are served". | If the injected file is a known place, require that the viewer may see it; otherwise allow only files outside the event directories (for example `shared/`). Design decision needed. |
| M-3 | Open | `lib/Endpoint/CalendarJson.php`, `lib/Access/Policy.php` | Files that fill no box are governed by the event's rule (v4 behaviour), so the images or attachments that go with a hidden solution page (for example `solution-figure.png`) are listed in `files` and served while the solution itself is hidden. | Document clearly; consider a convention (a `<box>/` subdirectory, or a name prefix matched to the box) so a box rule covers its assets; or hide files linked only from hidden pages. |
| M-4 | Open | `lib/Render/PageRenderer.php:238`, `lib/Http/Download.php:17,20` | Links are rewritten only on `a, img, source, video, audio, track, iframe, embed, object`: `<script src="x.js">` and `<link href="style.css">` keep their relative URLs, which do not resolve from the front end. Even if rewritten, `.js` is sent as `text/plain` (with `nosniff`, browsers refuse to run it) and `.css` as an attachment. So "JavaScript is passed through" works only for inline scripts. Confirmed. | Rewrite `script src` and `link href`; serve `.js` as `text/javascript` and `.css` as `text/css`, inline. |
| M-5 | Open | `lib/Course/Course.php:142` | Files that `paths.ini` pages link to (`pages/logo.png`) have no key, so their links are marked hidden and images break. The design says keys exist for "the files their pages link to". Confirmed. | Give each page's linked files keys (a `page/<name>/<file>` place, decided by the page's rule), or key every file under the page's directory. |
| M-6 | Open | `lib/Http/Session.php:31` | Sessions are garbage-collected after PHP's `session.gc_maxlifetime` (1440 seconds by default), so an instructor who is idle for 24 minutes is logged out, whatever `[users] session = 8h` says. On shared servers the system's session cleanup may use its own lifetime. | Set `session.gc_maxlifetime` to the course's session length, and consider a course-specific `session.save_path` inside the private temporary directory. Document it (DOC-4). |
| M-7 | Open | `lib/Endpoint/AuthEndpoint.php:51` | Five failed logins for a username block that username for 15 minutes from every address: anyone can keep the instructor locked out. Behind a reverse proxy every request has the proxy's address, so the per-address limit blocks everyone at once. | Limit per (address, username) and per address; make the per-username limit much higher or a slowdown instead of a block; optionally trust a configured proxy header. |
| M-8 | Open | `lib/Http/RateLimiter.php:29` | Read-modify-write without a lock: parallel requests all read the same count, so many guesses in parallel get past the limit. | Use `flock()` on the counter file for the whole check-and-record, and record the attempt before verifying the password. |
| M-9 | Open | `lib/Endpoint/GetEndpoint.php:29`, `lib/Endpoint/HtmlEndpoint.php`, `lib/Endpoint/ConfigEndpoint.php` | `get.php`, `html.php` and `config.php` rebuild the whole course model (walk of every directory, calendar, rules) on every request. A page with 40 images makes 40 full walks; a large course can be slow enough to matter. | Cache the model (E-2), keyed by the same fingerprint as `calendar.php`'s answers; `config.php` needs only the days, not the walk. |
| M-10 | Open | `lib/Http/Fingerprint.php:37` | The fingerprint follows symbolic links to directories, also links that lead outside the course (up to depth 7), on every `calendar.php` request; a link to a large tree (or `/`) makes every request slow. The scanner itself stays inside the boundary. | Skip links that resolve outside the course (the same `Boundary` check), and do not descend into `config/auth` contents beyond their stat. |

## Low

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| L-1 | Open | `lib/Render/PageRenderer.php:144` | Calendar 4's `<host server="a, b">` accepts a comma-separated list and also matches `SERVER_ADDR`; Calendar 6 takes one name and no address. | Accept the list and the address (together with H-1). |
| L-2 | Open | `lib/Schedule/Sections.php:31` | A `meets` entry without a type gets the **first type in `[types]`**, not `class`: a course that lists `lab` first makes every section a lab section. | Default to `class` when it is a type, else the first type; or require a default in `config.ini`. |
| L-3 | Open | `lib/Schedule/CalendarBuilder.php:89` | A `dates.ini` window with times is partial only for `holiday`/`none`; event types, weekdays and `period` apply to the whole day, although the template says a window with times "only affects meetings that start inside it". | Either apply times to all changes or reject times on lines that can not use them (and say so in the template). |
| L-4 | Open | `lib/Endpoint/HtmlEndpoint.php:58` | Lock codes are collected from the page's raw source, so a `<lock>` inside an `<inject>`ed file can never be unlocked. | Collect codes after injection (render once to find them, or keep codes from the injected text). |
| L-5 | Open | `lib/Render/PageRenderer.php:157`, `lib/Endpoint/HtmlEndpoint.php:58` | Lock codes are compared with `in_array` (not constant time), and an entered code unlocks every `<lock>` with that code on every page for the rest of the session. | Use `hash_equals` in a loop; document the "per session, per code" behaviour (or scope it per page). |
| L-6 | Open | `lib/Endpoint/HtmlEndpoint.php` | A lock code POST answers with the page itself; reloading resubmits the form. | Answer `303 See Other` to the same `html.php` URL. |
| L-7 | Open | `lib/Endpoint/AuthEndpoint.php:21` | The dummy hash used for unknown users has cost 10; PHP 8.4's `PASSWORD_DEFAULT` uses cost 12, so unknown usernames answer measurably faster (an account-existence oracle). | Create the dummy hash with the current default once per process (or store one per cost) and match the cost of real hashes. |
| L-8 | Open | `lib/Http/Download.php:94` | `Content-Disposition` puts the raw name in `filename="..."`; non-ASCII names come out garbled in some browsers. | Add `filename*=UTF-8''<percent-encoded>` with an ASCII fallback. |
| L-9 | Open | `lib/Render/PageRenderer.php:238` | `srcset` (responsive images) is not rewritten. Confirmed. | Rewrite each URL in `srcset` (with M-4). |
| L-10 | Open | `lib/Course/CourseConfig.php:136` | A `lockdown` or `[users] valid` value that can not be read is only a problem: the lockdown silently does not happen. | Treat an unreadable lockdown as "locked from now" or refuse to serve until fixed, and show it prominently to instructors. |
| L-11 | Open | `lib/Course/Boundary.php:36` | The `config/` and `calendar/` checks compare names exactly; on a case-insensitive file system (macOS) a link to `Config/auth/x.ini` passes. | Compare case-insensitively, or compare resolved paths against `realpath(course/config)` and `realpath(course/calendar)`. |
| L-12 | Open | `lib/Endpoint/ConfigEndpoint.php:24` | `config.php` creates a session for every visitor (to hand out the CSRF token), so every student leaves a session file. | Hand out the token only when a login form needs it (`config.php?csrf=1`, or in `auth.php`'s own GET-free flow). |
| L-13 | Open | `lib/Http/Session.php:49` | HTTPS is detected only from `HTTPS` and port 443; behind a TLS-terminating proxy the session cookie is sent without `Secure`. | Optional trusted proxy setting (`X-Forwarded-Proto`), and document it (DOC-4). |
| L-14 | Open | `lib/Access/Rules.php:35`, `lib/Access/Policy.php`, `lib/Render/PageRenderer.php:144` | Code quality: the "overridden by" message re-parses the line number out of the source string; `Policy::open()` has a parameter that is always `true`; `PageRenderer::host()` reads `$_SERVER` directly (hard to test). | Keep the previous line number in the rule; drop the parameter; pass the server names in. |

## Documentation

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| DOC-1 | Open | `DESIGN.md`, `template/config/*.ini` | Comments: `#` and `;` start a comment only at the beginning of a line, and `;` at the end of a line only after a space. Not written down anywhere. | One line in "Samples of every `.ini` file" and in the template headers. |
| DOC-2 | Open | `DESIGN.md` (lockdown) | During the lockdown, pages with no rule (home, syllabus) disappear too, because only `always` rules count. Probably surprising. | Document it and suggest `page/home = always`, or decide that pages are not affected. |
| DOC-3 | Open | `DESIGN.md` (keys, duplicate.ini) | Keys include the event's number, so a `numbered` copy in `duplicate.ini` renumbers the following events and changes their keys (bookmarks break). | Document it, or base keys on the directory for regular events. |
| DOC-4 | Open | `DESIGN.md`, `template/README.md` | No deployment notes: nginx rules equivalent to the `.htaccess` files, reverse proxies (client address, HTTPS), PHP session settings (M-6), and that courses served by the same PHP user can read each other's sessions and cache on shared hosting. | A "Deploying" section. |
| DOC-5 | Open | `DESIGN.md` (front end) | The contract `html.php` imposes on the front end is spread out: `#/view/KEY` routes, intercepting the `<lock>` form, `data-cal6-hidden`, absolute `get.php` URLs, running scripts after insertion. | Collect it under "The front end". |
| DOC-6 | Open | `template/index.html`, `README.md` | The template's `index.html` redirects to `calendar/app/`, which does not exist yet. | Say so in the template README until E-1 exists. |

## Testing

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| T-1 | Open | `tests/` | No tests for the course boundary: a material link that leads outside the course or into `config/`, `virtual.ini` with `..` or an absolute path, `paths.ini` outside the course, `<inject src="../../config/config.ini">`, `<calphp>` outside. These are the core security promises. | A `BoundaryTest` (unit and HTTP). |
| T-2 | Open | `tests/` | Nothing checks that `template/` itself loads without problems, although every new course starts from it. | Load the template (with a secret) and assert no problems, every sample event and page present. |
| T-3 | Open | `tests/`, `lib/Http/Context.php` | Time-dependent behaviour is only tested in unit tests: the HTTP tests can not move the clock, so the cache expiring at `next_change`, `<postmeeting>` before the meeting ends, session expiry and an account window ending during a session are untested end to end. | A test-only clock (an environment variable read only when a test marker file exists in the course, or a `Clock` injected through `Context`). |
| T-4 | Open | `tests/` | Untested: `<host>` matching, the lock-code rate limit, nested files (`img/pic.png`) and links from pages in subdirectories, a virtual file whose real file is in another event, `same` copies in `calendar.php` and their keys. | Add cases to the existing tests. |
| T-5 | Open | `tests/lib/Server.php` | Only PHP's built-in server is tested; `SCRIPT_FILENAME` (which finding the course depends on) differs under Apache with mod_php or PHP-FPM, and with symbolic links. | A CI job with Apache + PHP-FPM serving a linked course, or at least a documented manual check. |
| T-6 | Open | `tests/http/EndpointsTest.php` | `config.php` `days` and `sections`, and Markdown pages, are only checked lightly. | Assert the full shape for the template course. |

## Enhancements

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| E-1 | Open | `app/` | The browser front end does not exist yet. | Build it on the endpoint contract (DOC-5). |
| E-2 | Open | `lib/Course/Course.php` | No model cache (see M-9). | Cache the built model as JSON-safe data, keyed by the fingerprint. |
| E-3 | Open | `lib/Http/Response.php` | No `Content-Security-Policy`; course JavaScript makes a strict one hard, but `full=1` pages and JSON answers could carry one (`default-src 'none'` for JSON). | Add CSP where it can not break course pages. |
| E-4 | Open | `lib/` | No access or login log (Calendar 4 had one, with secrets removed). | Log logins, failed logins and lock-code attempts outside the web root. |
| E-5 | Open | `bin/` | `DESIGN.md` mentions `bin/cal6 check`; there is no command-line tool (problems, overridden lines, a check that material can not be fetched directly, `user add`). | Add `bin/cal6` with `check` and `user add`. |
| E-6 | Open | `lib/Course/Course.php:161` | `placeForPath()` scans every place for each link. | Index places by real path once per model. |
| E-7 | Open | `calendar.php` | Instructors get `problems` in JSON but nothing shows them yet. | A status view in the front end. |
