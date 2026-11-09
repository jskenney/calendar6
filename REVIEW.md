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
| 2 | 2026-11-09 | `467a23d` | everything again, after fixing every H, M, L and DOC item of review 1 (`29ca3f2`..`467a23d`) | M-11, M-12, L-15..L-17, DOC-7, DOC-8, T-7, T-8 |
| 3 | 2026-11-09 | `78086bf` | everything again, after fixing every M, L, DOC and T item of review 2 (`0118054`..`8cb4efd`); the dot-file rule probed on a real Apache with encoded paths (`%2egit`, `.%67it`, `config%2eini`, `..%2f`): all refused | L-18, L-19, DOC-9, T-9 |

## Suggested order (after review 3)

No critical, high or medium item is open. Open now:

1. **L-19**: the deployment setup script deletes fixed paths; guard it before anyone runs it on a
   real server.
2. **L-18**, then **DOC-9**.
3. **T-9** (an nginx deployment job), when nginx deployments matter.
4. **E** items as the front end (E-1) needs them; E-1 is the next big piece of work.

## Suggested order (after review 2, kept for history)

All H, M, L and DOC items of review 1 are fixed. Open now:

1. **M-11** (with **T-7**): caches must not outlive a software update; a small change.
2. **M-12** (with **DOC-8**): stop serving dot directories (`.git`) of the course; a template
   change and a deployment note.
3. **L-15, L-16, L-17**: small hardening, in that order.
4. **DOC-7**.
5. **T-3** (an injectable clock for HTTP tests), then **T-4, T-5, T-6, T-8**.
6. **E** items as the front end (E-1) needs them; E-1 is the next big piece of work.

## Suggested order (after review 1, kept for history)

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
| H-1 | Fixed in `29ca3f2` | `lib/Render/PageRenderer.php:144` | `<host server="...">` compares against `$_SERVER['SERVER_NAME']`, which Apache (default `UseCanonicalName Off`) and nginx (`$host`) take from the request's `Host` header. Anyone can send `Host: the-internal-server` and see content meant only for one server (for example solutions). (Calendar 4 had the same weakness.) | Compare only against values the client can not choose: `gethostname()` and `$_SERVER['SERVER_ADDR']`. See L-1 for the rest of v4's behaviour. |
| H-2 | Fixed in `49d9e43` | `lib/Access/Rules.php:40`, `lib/Course/Course.php:56` | A `default.ini` line that can not be read is skipped and shown only as a problem, so the target falls back to a weaker rule or to "no rule = visible". A typo such as `exam = nver` or `exam = 2026-13-01` publishes every exam. That fails open, in the one file whose job is to protect. | When the target can be read but the time can not, record the line as `never` for that target (and keep the problem for instructors). When the target itself is invalid (a bad range), keep it as a problem only. |
| H-3 | Fixed in `73e5799` | `lib/Http/TempDir.php:15`, `lib/Http/RateLimiter.php:29` | When the private temporary directory can not be used (wrong owner or permissions, `sys_get_temp_dir()` not writable, or a directory another account created first under the predictable name `cal6-<uid>-<hash>`), `RateLimiter` silently stops counting: unlimited password and lock-code guessing. | Fail closed: when the limiter has no storage, refuse logins and lock codes with a clear error for instructors, or fall back to a directory the course chooses. Add a randomised suffix kept in the session-independent cache, or check ownership before trusting an existing directory (already done) and report when it fails. |

## Medium

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| M-1 | Fixed in `4f067fc` | `lib/Http/Response.php:30` | `json_encode(... JSON_THROW_ON_ERROR)`: one directory or file name that is not UTF-8 (common for files copied from older systems) makes `calendar.php` and `config.php` answer 500 for everyone. Confirmed with a file named `bad\xe9.txt`. | Use `JSON_INVALID_UTF8_SUBSTITUTE` and report such names as problems for instructors. |
| M-2 | Fixed in `f9ba660` | `lib/Render/PageRenderer.php:79` | `<inject>` and `<codeinject>` read any file inside the course boundary without checking its release rule, so a visible page can pull in another event's hidden solution (or a hidden file of its own event). Authors write these tags, but the effect contradicts "access is checked where files are served". | If the injected file is a known place, require that the viewer may see it; otherwise allow only files outside the event directories (for example `shared/`). Design decision needed. |
| M-3 | Fixed in `f9ba660` | `lib/Endpoint/CalendarJson.php`, `lib/Access/Policy.php` | Files that fill no box are governed by the event's rule (v4 behaviour), so the images or attachments that go with a hidden solution page (for example `solution-figure.png`) are listed in `files` and served while the solution itself is hidden. | Document clearly; consider a convention (a `<box>/` subdirectory, or a name prefix matched to the box) so a box rule covers its assets; or hide files linked only from hidden pages. |
| M-4 | Fixed in `97f2030` | `lib/Render/PageRenderer.php:238`, `lib/Http/Download.php:17,20` | Links are rewritten only on `a, img, source, video, audio, track, iframe, embed, object`: `<script src="x.js">` and `<link href="style.css">` keep their relative URLs, which do not resolve from the front end. Even if rewritten, `.js` is sent as `text/plain` (with `nosniff`, browsers refuse to run it) and `.css` as an attachment. So "JavaScript is passed through" works only for inline scripts. Confirmed. | Rewrite `script src` and `link href`; serve `.js` as `text/javascript` and `.css` as `text/css`, inline. |
| M-5 | Fixed in `97f2030` | `lib/Course/Course.php:142` | Files that `paths.ini` pages link to (`pages/logo.png`) have no key, so their links are marked hidden and images break. The design says keys exist for "the files their pages link to". Confirmed. | Give each page's linked files keys (a `page/<name>/<file>` place, decided by the page's rule), or key every file under the page's directory. |
| M-6 | Fixed in `73e5799` | `lib/Http/Session.php:31` | Sessions are garbage-collected after PHP's `session.gc_maxlifetime` (1440 seconds by default), so an instructor who is idle for 24 minutes is logged out, whatever `[users] session = 8h` says. On shared servers the system's session cleanup may use its own lifetime. | Set `session.gc_maxlifetime` to the course's session length, and consider a course-specific `session.save_path` inside the private temporary directory. Document it (DOC-4). |
| M-7 | Fixed in `73e5799` | `lib/Endpoint/AuthEndpoint.php:51` | Five failed logins for a username block that username for 15 minutes from every address: anyone can keep the instructor locked out. Behind a reverse proxy every request has the proxy's address, so the per-address limit blocks everyone at once. | Limit per (address, username) and per address; make the per-username limit much higher or a slowdown instead of a block; optionally trust a configured proxy header. |
| M-8 | Fixed in `73e5799` | `lib/Http/RateLimiter.php:29` | Read-modify-write without a lock: parallel requests all read the same count, so many guesses in parallel get past the limit. | Use `flock()` on the counter file for the whole check-and-record, and record the attempt before verifying the password. |
| M-9 | Fixed in `a6c0b7f` | `lib/Endpoint/GetEndpoint.php:29`, `lib/Endpoint/HtmlEndpoint.php`, `lib/Endpoint/ConfigEndpoint.php` | `get.php`, `html.php` and `config.php` rebuild the whole course model (walk of every directory, calendar, rules) on every request. A page with 40 images makes 40 full walks; a large course can be slow enough to matter. | Cache the model (E-2), keyed by the same fingerprint as `calendar.php`'s answers; `config.php` needs only the days, not the walk. |
| M-10 | Fixed in `a6c0b7f` | `lib/Http/Fingerprint.php:37` | The fingerprint follows symbolic links to directories, also links that lead outside the course (up to depth 7), on every `calendar.php` request; a link to a large tree (or `/`) makes every request slow. The scanner itself stays inside the boundary. | Skip links that resolve outside the course (the same `Boundary` check), and do not descend into `config/auth` contents beyond their stat. |
| M-11 | Fixed in `0118054` | `lib/Http/Fingerprint.php`, `lib/Http/ModelCache.php`, `lib/Http/AnswerCache.php` | (review 2) The fingerprint skips `calendar/`, so after the software is updated (`git pull`) the caches keep serving what the old code built: a stale model is unserialised into changed classes (wrong results, or errors until the course changes), and `calendar.php` answers keep the old shape. | Add a software version to the cache keys (a `Version` constant bumped with each change, or a hash of `lib/` file times computed once per request). |
| M-12 | Fixed in `8ff3e50` | `template/` | (review 2) Apache serves dot files and directories by default, and the template has no rule at the course root. An instructor who keeps the course in git exposes `course/.git/`: the history of `config/auth/*.ini` (password hashes), `config.ini` (the secret) and every hidden solution. Calendar 6 itself never serves them, but the web server does. | Ship a `template/.htaccess` at the course root that denies names starting with a dot (and `*.ini`) everywhere, without affecting `calendar/php/`; document the nginx equivalent (DOC-8). |

## Low

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| L-1 | Fixed in `29ca3f2` | `lib/Render/PageRenderer.php:144` | Calendar 4's `<host server="a, b">` accepts a comma-separated list and also matches `SERVER_ADDR`; Calendar 6 takes one name and no address. | Accept the list and the address (together with H-1). |
| L-2 | Fixed in `628734a` | `lib/Schedule/Sections.php:31` | A `meets` entry without a type gets the **first type in `[types]`**, not `class`: a course that lists `lab` first makes every section a lab section. | Default to `class` when it is a type, else the first type; or require a default in `config.ini`. |
| L-3 | Fixed in `628734a` | `lib/Schedule/CalendarBuilder.php:89` | A `dates.ini` window with times is partial only for `holiday`/`none`; event types, weekdays and `period` apply to the whole day, although the template says a window with times "only affects meetings that start inside it". | Either apply times to all changes or reject times on lines that can not use them (and say so in the template). |
| L-4 | Fixed in `628734a` | `lib/Endpoint/HtmlEndpoint.php:58` | Lock codes are collected from the page's raw source, so a `<lock>` inside an `<inject>`ed file can never be unlocked. | Collect codes after injection (render once to find them, or keep codes from the injected text). |
| L-5 | Fixed in `628734a` | `lib/Render/PageRenderer.php:157`, `lib/Endpoint/HtmlEndpoint.php:58` | Lock codes are compared with `in_array` (not constant time), and an entered code unlocks every `<lock>` with that code on every page for the rest of the session. | Use `hash_equals` in a loop; document the "per session, per code" behaviour (or scope it per page). |
| L-6 | Fixed in `628734a` | `lib/Endpoint/HtmlEndpoint.php` | A lock code POST answers with the page itself; reloading resubmits the form. | Answer `303 See Other` to the same `html.php` URL. |
| L-7 | Fixed in `73e5799` | `lib/Endpoint/AuthEndpoint.php:21` | The dummy hash used for unknown users has cost 10; PHP 8.4's `PASSWORD_DEFAULT` uses cost 12, so unknown usernames answer measurably faster (an account-existence oracle). | Create the dummy hash with the current default once per process (or store one per cost) and match the cost of real hashes. |
| L-8 | Fixed in `628734a` | `lib/Http/Download.php:94` | `Content-Disposition` puts the raw name in `filename="..."`; non-ASCII names come out garbled in some browsers. | Add `filename*=UTF-8''<percent-encoded>` with an ASCII fallback. |
| L-9 | Fixed in `97f2030` | `lib/Render/PageRenderer.php:238` | `srcset` (responsive images) is not rewritten. Confirmed. | Rewrite each URL in `srcset` (with M-4). |
| L-10 | Fixed in `628734a` | `lib/Course/CourseConfig.php:136` | A `lockdown` or `[users] valid` value that can not be read is only a problem: the lockdown silently does not happen. | Treat an unreadable lockdown as "locked from now" or refuse to serve until fixed, and show it prominently to instructors. |
| L-11 | Fixed in `628734a` | `lib/Course/Boundary.php:36` | The `config/` and `calendar/` checks compare names exactly; on a case-insensitive file system (macOS) a link to `Config/auth/x.ini` passes. | Compare case-insensitively, or compare resolved paths against `realpath(course/config)` and `realpath(course/calendar)`. |
| L-12 | Fixed in `8be0d30` | `lib/Endpoint/ConfigEndpoint.php:24` | `config.php` creates a session for every visitor (to hand out the CSRF token), so every student leaves a session file. | Hand out the token only when a login form needs it (`config.php?csrf=1`, or in `auth.php`'s own GET-free flow). |
| L-13 | Fixed in `73e5799` | `lib/Http/Session.php:49` | HTTPS is detected only from `HTTPS` and port 443; behind a TLS-terminating proxy the session cookie is sent without `Secure`. | Optional trusted proxy setting (`X-Forwarded-Proto`), and document it (DOC-4). |
| L-14 | Fixed in `49d9e43`, `628734a` | `lib/Access/Rules.php:35`, `lib/Access/Policy.php`, `lib/Render/PageRenderer.php:144` | Code quality: the "overridden by" message re-parses the line number out of the source string; `Policy::open()` has a parameter that is always `true`; `PageRenderer::host()` reads `$_SERVER` directly (hard to test). | Keep the previous line number in the rule; drop the parameter; pass the server names in. |
| L-15 | Fixed in `b07f6bf` | `lib/Http/ModelCache.php` | (review 2) The cached model contains the course configuration, including `[course] secret` (confirmed), in the private temporary directory. It is protected like the sessions (owner-only directory), but the secret now exists in a second place. | Blank the secret in the cached copy (keys are computed when the model is built), and keep using the configuration's secret for the signature. |
| L-16 | Fixed in `b07f6bf` | `lib/Http/RateLimiter.php` | (review 2) One counter file per address, address+username and username, never removed: someone trying many usernames or addresses grows the directory without limit. | Remove counter files whose newest attempt is older than the window (occasionally, e.g. 1 request in 100), and cap the number of files. |
| L-17 | Fixed in `b07f6bf` | `lib/Endpoint/AuthEndpoint.php:36` | (review 2) Logging out removes the login but keeps the session's unlocked `<lock>` codes and `<url>` values, so the next person at a shared computer still sees unlocked sections. | Clear the whole session on logout (keep only a fresh CSRF token). |
| L-18 | Open | `lib/Http/Fingerprint.php` | (review 3) The software version is a hash of the names, sizes and modification times of `lib/` and `php/` files; a deployment that keeps times and sizes (`rsync -t` of a same-size edit, or files restored from an archive) would not invalidate the caches. | Add a `Version` constant to the hash (bumped by each release), or hash file contents when the stat data is unchanged since a recorded deploy. |
| L-19 | Open | `tests/deploy/apache-setup.sh` | (review 3) The script removes `/opt/calendar6` and `/var/www/html/AB123` and rewrites Apache's configuration without asking; it says "CI, or a throwaway machine", but run by mistake on a real server it deletes a course called AB123. | Refuse to run unless `CI=true` or `--yes` is given, and refuse when the target exists and was not made by the script (a marker file). |

## Documentation

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| DOC-1 | Fixed in `f686841` | `DESIGN.md`, `template/config/*.ini` | Comments: `#` and `;` start a comment only at the beginning of a line, and `;` at the end of a line only after a space. Not written down anywhere. | One line in "Samples of every `.ini` file" and in the template headers. |
| DOC-2 | Fixed in `f686841` | `DESIGN.md` (lockdown) | During the lockdown, pages with no rule (home, syllabus) disappear too, because only `always` rules count. Probably surprising. | Document it and suggest `page/home = always`, or decide that pages are not affected. |
| DOC-3 | Fixed in `f686841` | `DESIGN.md` (keys, duplicate.ini) | Keys include the event's number, so a `numbered` copy in `duplicate.ini` renumbers the following events and changes their keys (bookmarks break). | Document it, or base keys on the directory for regular events. |
| DOC-4 | Fixed in `f686841` | `DESIGN.md`, `template/README.md` | No deployment notes: nginx rules equivalent to the `.htaccess` files, reverse proxies (client address, HTTPS), PHP session settings (M-6), and that courses served by the same PHP user can read each other's sessions and cache on shared hosting. | A "Deploying" section. |
| DOC-5 | Fixed in `f686841` | `DESIGN.md` (front end) | The contract `html.php` imposes on the front end is spread out: `#/view/KEY` routes, intercepting the `<lock>` form, `data-cal6-hidden`, absolute `get.php` URLs, running scripts after insertion. | Collect it under "The front end". |
| DOC-6 | Fixed in `f686841` | `template/index.html`, `README.md` | The template's `index.html` redirects to `calendar/app/`, which does not exist yet. | Say so in the template README until E-1 exists. |
| DOC-7 | Fixed in `b07f6bf` | `DESIGN.md` (`default.ini`) | (review 2) Targets are case-sensitive (`Class_1` is not `class_1`, `Notes.html` is not `notes.html`), as in v4; not written down. | One line under `default.ini`; `bin/cal6 check` (E-5) could warn about targets that match nothing. |
| DOC-8 | Fixed in `8ff3e50` | `DESIGN.md` ("Deploying") | (review 2) The nginx notes deny `calendar/lib/` and `calendar/tests/` but not `calendar/template/` or dot files (`.git`) anywhere under the course. | Add both, together with M-12. |
| DOC-9 | Open | `README.md` | (review 3) The README's test section does not mention the deployment smoke test (`tests/deploy/`) or the test clock (`CAL6_TEST_CLOCK`, built-in server only). | Two short paragraphs. |

## Testing

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| T-1 | Fixed in `628734a`, `467a23d` | `tests/` | No tests for the course boundary: a material link that leads outside the course or into `config/`, `virtual.ini` with `..` or an absolute path, `paths.ini` outside the course, `<inject src="../../config/config.ini">`, `<calphp>` outside. These are the core security promises. | A `BoundaryTest` (unit and HTTP). |
| T-2 | Fixed in `467a23d` | `tests/` | Nothing checks that `template/` itself loads without problems, although every new course starts from it. | Load the template (with a secret) and assert no problems, every sample event and page present. |
| T-3 | Fixed in `9870c0b` | `tests/`, `lib/Http/Context.php` | Time-dependent behaviour is only tested in unit tests: the HTTP tests can not move the clock, so the cache expiring at `next_change`, `<postmeeting>` before the meeting ends, session expiry and an account window ending during a session are untested end to end. | A test-only clock (an environment variable read only when a test marker file exists in the course, or a `Clock` injected through `Context`). |
| T-4 | Fixed in `29ca3f2`, `628734a`, `8cb4efd` | `tests/` | Untested: `<host>` matching, the lock-code rate limit, nested files (`img/pic.png`) and links from pages in subdirectories, a virtual file whose real file is in another event, `same` copies in `calendar.php` and their keys. | Add cases to the existing tests. |
| T-5 | Fixed in `8ff3e50` | `tests/lib/Server.php` | Only PHP's built-in server is tested; `SCRIPT_FILENAME` (which finding the course depends on) differs under Apache with mod_php or PHP-FPM, and with symbolic links. | A CI job with Apache + PHP-FPM serving a linked course, or at least a documented manual check. |
| T-6 | Fixed in `8be0d30`, `8cb4efd` | `tests/http/EndpointsTest.php` | `config.php` `days` and `sections`, and Markdown pages, are only checked lightly. | Assert the full shape for the template course. |
| T-7 | Fixed in `0118054` | `tests/` | (review 2) Nothing checks that the caches are dropped when the software changes (M-11). | Test that a different software version misses both caches. |
| T-8 | Fixed in `8cb4efd` | `tests/http/EndpointsTest.php` | (review 2) `X-Forwarded-For` from a trusted proxy is tested, `X-Forwarded-Proto` (the `Secure` cookie behind a proxy) and an untrusted sender of both headers are not. | Add both cases. |
| T-9 | Open | `.github/workflows/ci.yml` | (review 3) Only Apache is tested as a real deployment; the nginx rules in "Deploying" (dot files, `.ini`, the protected directories, `SCRIPT_FILENAME` from `$document_root$fastcgi_script_name`) are untested. | A second deployment job with nginx + PHP-FPM running `tests/deploy/smoke.php`. |

## Enhancements

| ID | Status | Where | Finding | Suggestion |
|---|---|---|---|---|
| E-1 | Open | `app/` | The browser front end does not exist yet. | Build it on the endpoint contract (DOC-5). |
| E-2 | Fixed in `a6c0b7f` | `lib/Course/Course.php` | No model cache (see M-9). | Cache the built model as JSON-safe data, keyed by the fingerprint. |
| E-3 | Open | `lib/Http/Response.php` | No `Content-Security-Policy`; course JavaScript makes a strict one hard, but `full=1` pages and JSON answers could carry one (`default-src 'none'` for JSON). | Add CSP where it can not break course pages. |
| E-4 | Open | `lib/` | No access or login log (Calendar 4 had one, with secrets removed). | Log logins, failed logins and lock-code attempts outside the web root. |
| E-5 | Open | `bin/` | `DESIGN.md` mentions `bin/cal6 check`; there is no command-line tool (problems, overridden lines, a check that material can not be fetched directly, `user add`). | Add `bin/cal6` with `check` and `user add`. |
| E-6 | Fixed in `97f2030` | `lib/Course/Course.php:161` | `placeForPath()` scans every place for each link. | Index places by real path once per model. |
| E-7 | Open | `calendar.php` | Instructors get `problems` in JSON but nothing shows them yet. | A status view in the front end. |

## How review 1's items were fixed

| Item | Fix |
|---|---|
| H-1, L-1 | `<host>` matches `gethostname()` (and its short form) and `SERVER_ADDR`, never `SERVER_NAME`; a comma-separated list is accepted. |
| H-2 | A line whose time can not be read is `never` for its targets; the problem is listed. |
| H-3, M-7, M-8, L-7, L-13 | New `RateLimiter`: fails closed (503), `flock()` around each check-and-record, attempts recorded before the password check and forgiven on success; limits per address+username, per address (x4), per username (x10); `[server] trusted_proxies` for `X-Forwarded-For` / `-Proto`; unknown users cost a `password_hash()`. |
| M-1 | `JSON_INVALID_UTF8_SUBSTITUTE`; names that are not UTF-8 are listed as problems. |
| M-2 | An injected file with a key must be visible to the viewer; without a key it may only come from outside the event directories. |
| M-3 | Box assets: files named after a box file follow that box's rule. |
| M-4, M-5, L-9, E-6 | `script src`, `link href` and `srcset` are rewritten; `.js`/`.css` served for use; files `paths.ini` pages link to get keys; places indexed by real path. |
| M-6 | `session.gc_maxlifetime` follows `[users] session`; sessions kept in the course's private directory. |
| M-9, M-10, E-2 | The course model is cached (signed with the secret); the fingerprint follows links only inside the course. |
| L-2, L-3, L-4, L-5, L-6, L-8, L-10, L-11, L-14 | See `628734a`: default meeting type `class`; times only with holiday/none; lock codes after inject, constant-time, 303 after POST; `filename*`; unreadable lockdown locks; config/calendar compared by real path and without case; dead parameter removed. |
| L-12 | `config.php` creates a session only with `?csrf=1`. |
| DOC-1..DOC-6 | `DESIGN.md` ("Deploying", the front end contract, keys, lockdown, comments), template comments and READMEs. |
| T-1, T-2 | `BoundaryTest`, renderer boundary cases, `TemplateTest`. |

## How review 2's items were fixed

| Item | Fix |
|---|---|
| M-11, T-7 | Cache keys combine the course fingerprint with `Fingerprint::software()`, a hash of the software's PHP files. |
| M-12, DOC-8, T-5 | `template/.htaccess` at the course root refuses dot names and `.ini` files everywhere (needs only `AllowOverride AuthConfig`); nginx equivalents documented; `tests/deploy/` serves a template course with Apache + PHP-FPM through a `calendar` link and checks it, in CI. |
| L-15 | The model cache stores neither the secret nor the parsed `.ini` files; the secret comes back from `config.ini`. |
| L-16 | The rate limiter sweeps old counters (1 request in 100) and keeps at most 20000 files. |
| L-17 | Logging out clears the whole session. |
| DOC-7 | Targets are case-sensitive: in `DESIGN.md` and the template. |
| T-3 | `CAL6_TEST_CLOCK` (built-in server only) and `tests/http/TimeTest.php`. |
| T-4, T-6, T-8 | New cases in `PolicyTest`, `RendererTest` and `EndpointsTest`. |

## CI notes

- Several runs on 2026-11-09 were marked failed with "Internal server error" annotations from
  GitHub: some jobs were never created, and every job that started passed. `a139cc1` cancels
  superseded runs; `78086bf` moved to `actions/checkout@v5` and a pinned `ubuntu-24.04` image
  after GitHub's warnings about Node 20 and the `ubuntu-latest` migration.
