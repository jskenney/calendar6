# Calendar 6: review log

A running list of code and security findings for the whole project, and their history.

- **IDs** are numbered once per category and never reused, even if an item is withdrawn:
  **C** critical, **H** high, **M** medium, **L** low, **DOC** documentation, **T** testing,
  **E** enhancement.
- The tables below list the **open** items. Fixed items move to "Fixed items" at the end, with
  the commit that fixed them; withdrawn items would be listed there too, with the reason.
- **Found** is the review that found the item (see "Reviews" at the end). Locations are
  `file:line` at the commit that review read; later commits may move them.

## Suggested order

No critical, high, medium or low item is open. Open now:

1. **T-10**: keep the tests from writing into the repository.
2. **DOC-10**: one note in `DESIGN.md`.
3. **E** items as the front end (E-1) needs them; E-1 is the next big piece of work, then
   E-5 (`bin/cal6 check`, `user add`), E-4 (a login log), E-3 (CSP), E-7 (a status view).

## Critical

None open.

## High

None open.

## Medium

None open.

## Low

None open.

## Documentation

| ID | Found | Where | Finding | Suggestion |
|---|---|---|---|---|
| DOC-10 | 4 | `DESIGN.md` ("Implementation notes", Caching) | The caching note still describes the cache key as the course fingerprint only; since `0118054`/`f87fe96` it also contains a hash of the software's files. | Update the note. |

## Testing

| ID | Found | Where | Finding | Suggestion |
|---|---|---|---|---|
| T-10 | 4 | `tests/unit/CacheTest.php:81` | `testSoftwareVersionFollowsContentsNotTimes` writes `lib/Version.php` into the repository for a moment; if the test is interrupted, the file stays and changes the software version (and `git status`). | Point `Fingerprint::software()` at a directory the test can choose (a parameter or a test copy of `lib/`), or remove the file in a `finally`. |

## Enhancements

| ID | Found | Where | Finding | Suggestion |
|---|---|---|---|---|
| E-1 | 1 | `app/` | The browser front end does not exist yet. | Build it on the endpoint contract (DOC-5). |
| E-3 | 1 | `lib/Http/Response.php` | No `Content-Security-Policy`; course JavaScript makes a strict one hard, but `full=1` pages and JSON answers could carry one (`default-src 'none'` for JSON). | Add CSP where it can not break course pages. |
| E-4 | 1 | `lib/` | No access or login log (Calendar 4 had one, with secrets removed). | Log logins, failed logins and lock-code attempts outside the web root. |
| E-5 | 1 | `bin/` | `DESIGN.md` mentions `bin/cal6 check`; there is no command-line tool (problems, overridden lines, a check that material can not be fetched directly, `user add`). | Add `bin/cal6` with `check` and `user add`. |
| E-7 | 1 | `calendar.php` | Instructors get `problems` in JSON but nothing shows them yet. | A status view in the front end. |

## Fixed items

| ID | Found | Fixed in | Finding | Fix |
|---|---|---|---|---|
| H-1 | 1 | `29ca3f2` | `<host server="...">` compares against `$_SERVER['SERVER_NAME']`, which Apache (default `UseCanonicalName Off`) and nginx (`$host`) take from the request's `Host` header. Anyone can send `Host: the-internal-server` and see content meant only for one server (for example solutions). (Calendar 4 had the same weakness.) | `<host>` matches `gethostname()` (and its short form) and `SERVER_ADDR`, never `SERVER_NAME`; a comma-separated list is accepted. |
| H-2 | 1 | `49d9e43` | A `default.ini` line that can not be read is skipped and shown only as a problem, so the target falls back to a weaker rule or to "no rule = visible". A typo such as `exam = nver` or `exam = 2026-13-01` publishes every exam. That fails open, in the one file whose job is to protect. | A line whose time can not be read is `never` for its targets; the problem is listed. |
| H-3 | 1 | `73e5799` | When the private temporary directory can not be used (wrong owner or permissions, `sys_get_temp_dir()` not writable, or a directory another account created first under the predictable name `cal6-<uid>-<hash>`), `RateLimiter` silently stops counting: unlimited password and lock-code guessing. | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| M-1 | 1 | `4f067fc` | `json_encode(... JSON_THROW_ON_ERROR)`: one directory or file name that is not UTF-8 (common for files copied from older systems) makes `calendar.php` and `config.php` answer 500 for everyone. Confirmed with a file named `bad\xe9.txt`. | `JSON_INVALID_UTF8_SUBSTITUTE`; names that are not UTF-8 are listed as problems. |
| M-2 | 1 | `f9ba660` | `<inject>` and `<codeinject>` read any file inside the course boundary without checking its release rule, so a visible page can pull in another event's hidden solution (or a hidden file of its own event). Authors write these tags, but the effect contradicts "access is checked where files are served". | An injected file with a key must be visible to the viewer; without a key it may only come from outside the event directories. |
| M-3 | 1 | `f9ba660` | Files that fill no box are governed by the event's rule (v4 behaviour), so the images or attachments that go with a hidden solution page (for example `solution-figure.png`) are listed in `files` and served while the solution itself is hidden. | Box assets: files named after a box file follow that box's rule. |
| M-4 | 1 | `97f2030` | Links are rewritten only on `a, img, source, video, audio, track, iframe, embed, object`: `<script src="x.js">` and `<link href="style.css">` keep their relative URLs, which do not resolve from the front end. Even if rewritten, `.js` is sent as `text/plain` (with `nosniff`, browsers refuse to run it) and `.css` as an attachment. So "JavaScript is passed through" works only for inline scripts. Confirmed. | `script src`, `link href` and `srcset` are rewritten; `.js`/`.css` served for use; files `paths.ini` pages link to get keys; places indexed by real path. |
| M-5 | 1 | `97f2030` | Files that `paths.ini` pages link to (`pages/logo.png`) have no key, so their links are marked hidden and images break. The design says keys exist for "the files their pages link to". Confirmed. | `script src`, `link href` and `srcset` are rewritten; `.js`/`.css` served for use; files `paths.ini` pages link to get keys; places indexed by real path. |
| M-6 | 1 | `73e5799` | Sessions are garbage-collected after PHP's `session.gc_maxlifetime` (1440 seconds by default), so an instructor who is idle for 24 minutes is logged out, whatever `[users] session = 8h` says. On shared servers the system's session cleanup may use its own lifetime. | `session.gc_maxlifetime` follows `[users] session`; sessions kept in the course's private directory. |
| M-7 | 1 | `73e5799` | Five failed logins for a username block that username for 15 minutes from every address: anyone can keep the instructor locked out. Behind a reverse proxy every request has the proxy's address, so the per-address limit blocks everyone at once. | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| M-8 | 1 | `73e5799` | Read-modify-write without a lock: parallel requests all read the same count, so many guesses in parallel get past the limit. | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| M-9 | 1 | `a6c0b7f` | `get.php`, `html.php` and `config.php` rebuild the whole course model (walk of every directory, calendar, rules) on every request. A page with 40 images makes 40 full walks; a large course can be slow enough to matter. | The course model is cached (signed with the secret); the fingerprint follows links only inside the course. |
| M-10 | 1 | `a6c0b7f` | The fingerprint follows symbolic links to directories, also links that lead outside the course (up to depth 7), on every `calendar.php` request; a link to a large tree (or `/`) makes every request slow. The scanner itself stays inside the boundary. | The course model is cached (signed with the secret); the fingerprint follows links only inside the course. |
| M-11 | 2 | `0118054` | The fingerprint skips `calendar/`, so after the software is updated (`git pull`) the caches keep serving what the old code built: a stale model is unserialised into changed classes (wrong results, or errors until the course changes), and `calendar.php` answers keep the old shape. | Cache keys combine the course fingerprint with `Fingerprint::software()`, a hash of the software's PHP files. |
| M-12 | 2 | `8ff3e50` | Apache serves dot files and directories by default, and the template has no rule at the course root. An instructor who keeps the course in git exposes `course/.git/`: the history of `config/auth/*.ini` (password hashes), `config.ini` (the secret) and every hidden solution. Calendar 6 itself never serves them, but the web server does. | `template/.htaccess` at the course root refuses dot names and `.ini` files everywhere (needs only `AllowOverride AuthConfig`); nginx equivalents documented; `tests/deploy/` serves a template course with Apache + PHP-FPM through a `calendar` link and checks it, in CI. |
| L-1 | 1 | `29ca3f2` | Calendar 4's `<host server="a, b">` accepts a comma-separated list and also matches `SERVER_ADDR`; Calendar 6 takes one name and no address. | `<host>` matches `gethostname()` (and its short form) and `SERVER_ADDR`, never `SERVER_NAME`; a comma-separated list is accepted. |
| L-2 | 1 | `628734a` | A `meets` entry without a type gets the **first type in `[types]`**, not `class`: a course that lists `lab` first makes every section a lab section. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-3 | 1 | `628734a` | A `dates.ini` window with times is partial only for `holiday`/`none`; event types, weekdays and `period` apply to the whole day, although the template says a window with times "only affects meetings that start inside it". | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-4 | 1 | `628734a` | Lock codes are collected from the page's raw source, so a `<lock>` inside an `<inject>`ed file can never be unlocked. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-5 | 1 | `628734a` | Lock codes are compared with `in_array` (not constant time), and an entered code unlocks every `<lock>` with that code on every page for the rest of the session. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-6 | 1 | `628734a` | A lock code POST answers with the page itself; reloading resubmits the form. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-7 | 1 | `73e5799` | The dummy hash used for unknown users has cost 10; PHP 8.4's `PASSWORD_DEFAULT` uses cost 12, so unknown usernames answer measurably faster (an account-existence oracle). | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| L-8 | 1 | `628734a` | `Content-Disposition` puts the raw name in `filename="..."`; non-ASCII names come out garbled in some browsers. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-9 | 1 | `97f2030` | `srcset` (responsive images) is not rewritten. Confirmed. | `script src`, `link href` and `srcset` are rewritten; `.js`/`.css` served for use; files `paths.ini` pages link to get keys; places indexed by real path. |
| L-10 | 1 | `628734a` | A `lockdown` or `[users] valid` value that can not be read is only a problem: the lockdown silently does not happen. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-11 | 1 | `628734a` | The `config/` and `calendar/` checks compare names exactly; on a case-insensitive file system (macOS) a link to `Config/auth/x.ini` passes. | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-12 | 1 | `8be0d30` | `config.php` creates a session for every visitor (to hand out the CSRF token), so every student leaves a session file. | `config.php` creates a session only with `?csrf=1`. |
| L-13 | 1 | `73e5799` | HTTPS is detected only from `HTTPS` and port 443; behind a TLS-terminating proxy the session cookie is sent without `Secure`. | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| L-14 | 1 | `49d9e43`, `628734a` | Code quality: the "overridden by" message re-parses the line number out of the source string; `Policy::open()` has a parameter that is always `true`; `PageRenderer::host()` reads `$_SERVER` directly (hard to test). | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-15 | 2 | `b07f6bf` | The cached model contains the course configuration, including `[course] secret` (confirmed), in the private temporary directory. It is protected like the sessions (owner-only directory), but the secret now exists in a second place. | The model cache stores neither the secret nor the parsed `.ini` files; the secret comes back from `config.ini`. |
| L-16 | 2 | `b07f6bf` | One counter file per address, address+username and username, never removed: someone trying many usernames or addresses grows the directory without limit. | The rate limiter sweeps old counters (1 request in 100) and keeps at most 20000 files. |
| L-17 | 2 | `b07f6bf` | Logging out removes the login but keeps the session's unlocked `<lock>` codes and `<url>` values, so the next person at a shared computer still sees unlocked sections. | Logging out clears the whole session. |
| L-18 | 3 | `f87fe96` | The software version is a hash of the names, sizes and modification times of `lib/` and `php/` files; a deployment that keeps times and sizes (`rsync -t` of a same-size edit, or files restored from an archive) would not invalidate the caches. | The software version hashes the contents of the `lib/` and `php/` files, not their times or sizes. |
| L-19 | 3 | `f3ae06e` | The script removes `/opt/calendar6` and `/var/www/html/AB123` and rewrites Apache's configuration without asking; it says "CI, or a throwaway machine", but run by mistake on a real server it deletes a course called AB123. | `tests/deploy/course-setup.sh` (used by both setup scripts) runs only with `--yes` or `CI=true` and never replaces a directory without its marker file; a unit test checks the refusal. |
| DOC-1 | 1 | `f686841` | Comments: `#` and `;` start a comment only at the beginning of a line, and `;` at the end of a line only after a space. Not written down anywhere. | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-2 | 1 | `f686841` | During the lockdown, pages with no rule (home, syllabus) disappear too, because only `always` rules count. Probably surprising. | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-3 | 1 | `f686841` | Keys include the event's number, so a `numbered` copy in `duplicate.ini` renumbers the following events and changes their keys (bookmarks break). | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-4 | 1 | `f686841` | No deployment notes: nginx rules equivalent to the `.htaccess` files, reverse proxies (client address, HTTPS), PHP session settings (M-6), and that courses served by the same PHP user can read each other's sessions and cache on shared hosting. | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-5 | 1 | `f686841` | The contract `html.php` imposes on the front end is spread out: `#/view/KEY` routes, intercepting the `<lock>` form, `data-cal6-hidden`, absolute `get.php` URLs, running scripts after insertion. | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-6 | 1 | `f686841` | The template's `index.html` redirects to `calendar/app/`, which does not exist yet. | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| DOC-7 | 2 | `b07f6bf` | Targets are case-sensitive (`Class_1` is not `class_1`, `Notes.html` is not `notes.html`), as in v4; not written down. | Targets are case-sensitive: in `DESIGN.md` and the template. |
| DOC-8 | 2 | `8ff3e50` | The nginx notes deny `calendar/lib/` and `calendar/tests/` but not `calendar/template/` or dot files (`.git`) anywhere under the course. | `template/.htaccess` at the course root refuses dot names and `.ini` files everywhere (needs only `AllowOverride AuthConfig`); nginx equivalents documented; `tests/deploy/` serves a template course with Apache + PHP-FPM through a `calendar` link and checks it, in CI. |
| DOC-9 | 3 | `68d3125` | The README's test section does not mention the deployment smoke test (`tests/deploy/`) or the test clock (`CAL6_TEST_CLOCK`, built-in server only). | README: the test clock and the deployment checks (`smoke.php`, the setup scripts, the reference nginx configuration). |
| T-1 | 1 | `628734a`, `467a23d` | No tests for the course boundary: a material link that leads outside the course or into `config/`, `virtual.ini` with `..` or an absolute path, `paths.ini` outside the course, `<inject src="../../config/config.ini">`, `<calphp>` outside. These are the core security promises. | `BoundaryTest`, renderer boundary cases, `TemplateTest`. |
| T-2 | 1 | `467a23d` | Nothing checks that `template/` itself loads without problems, although every new course starts from it. | `BoundaryTest`, renderer boundary cases, `TemplateTest`. |
| T-3 | 1 | `9870c0b` | Time-dependent behaviour is only tested in unit tests: the HTTP tests can not move the clock, so the cache expiring at `next_change`, `<postmeeting>` before the meeting ends, session expiry and an account window ending during a session are untested end to end. | `CAL6_TEST_CLOCK` (built-in server only) and `tests/http/TimeTest.php`. |
| T-4 | 1 | `29ca3f2`, `628734a`, `8cb4efd` | Untested: `<host>` matching, the lock-code rate limit, nested files (`img/pic.png`) and links from pages in subdirectories, a virtual file whose real file is in another event, `same` copies in `calendar.php` and their keys. | New cases in `PolicyTest`, `RendererTest` and `EndpointsTest`. |
| T-5 | 1 | `8ff3e50` | Only PHP's built-in server is tested; `SCRIPT_FILENAME` (which finding the course depends on) differs under Apache with mod_php or PHP-FPM, and with symbolic links. | `template/.htaccess` at the course root refuses dot names and `.ini` files everywhere (needs only `AllowOverride AuthConfig`); nginx equivalents documented; `tests/deploy/` serves a template course with Apache + PHP-FPM through a `calendar` link and checks it, in CI. |
| T-6 | 1 | `8be0d30`, `8cb4efd` | `config.php` `days` and `sections`, and Markdown pages, are only checked lightly. | New cases in `PolicyTest`, `RendererTest` and `EndpointsTest`. |
| T-7 | 2 | `0118054` | Nothing checks that the caches are dropped when the software changes (M-11). | Cache keys combine the course fingerprint with `Fingerprint::software()`, a hash of the software's PHP files. |
| T-8 | 2 | `8cb4efd` | `X-Forwarded-For` from a trusted proxy is tested, `X-Forwarded-Proto` (the `Secure` cookie behind a proxy) and an untrusted sender of both headers are not. | New cases in `PolicyTest`, `RendererTest` and `EndpointsTest`. |
| T-9 | 3 | `f3ae06e` | Only Apache is tested as a real deployment; the nginx rules in "Deploying" (dot files, `.ini`, the protected directories, `SCRIPT_FILENAME` from `$document_root$fastcgi_script_name`) are untested. | `tests/deploy/nginx-site.conf` (a complete, tested server block) and `nginx-setup.sh`; a CI job serves a linked course with nginx + PHP-FPM and runs the smoke test. |
| E-2 | 1 | `a6c0b7f` | No model cache (see M-9). | The course model is cached (signed with the secret); the fingerprint follows links only inside the course. |
| E-6 | 1 | `97f2030` | `placeForPath()` scans every place for each link. | `script src`, `link href` and `srcset` are rewritten; `.js`/`.css` served for use; files `paths.ini` pages link to get keys; places indexed by real path. |

## Reviews

| Review | Date | Commit reviewed | Scope | New items |
|---|---|---|---|---|
| 1 | 2026-11-09 | `ca77445` | everything: `lib/`, `php/`, `tests/`, `template/`, `DESIGN.md` | H-1..H-3, M-1..M-10, L-1..L-14, DOC-1..DOC-6, T-1..T-6, E-1..E-7 |
| 2 | 2026-11-09 | `467a23d` | everything again, after fixing every H, M, L and DOC item of review 1 (`29ca3f2`..`467a23d`) | M-11, M-12, L-15..L-17, DOC-7, DOC-8, T-7, T-8 |
| 3 | 2026-11-09 | `78086bf` | everything again, after fixing every M, L, DOC and T item of review 2 (`0118054`..`8cb4efd`); the dot-file rule probed on a real Apache with encoded paths (`%2egit`, `.%67it`, `config%2eini`, `..%2f`): all refused | L-18, L-19, DOC-9, T-9 |
| 4 | 2026-11-09 | `68d3125` | everything again, after fixing every L, DOC and T item of review 3 (`f87fe96`..`68d3125`); both deployments (Apache and nginx + PHP-FPM) probed with encoded paths; CI green on all four jobs | DOC-10, T-10 |

## CI notes

- Several runs on 2026-11-09 were marked failed with "Internal server error" annotations from
  GitHub: some jobs were never created, and every job that started passed. `a139cc1` cancels
  superseded runs; `78086bf` moved to `actions/checkout@v5` and a pinned `ubuntu-24.04` image
  after GitHub's warnings about Node 20 and the `ubuntu-latest` migration.
