<?php

declare(strict_types=1);

/*
 * Deployment smoke test (T-5): a course served by a real web server.
 *   php tests/deploy/smoke.php <course URL> [course directory] [password of the account "prof"]
 *   php tests/deploy/smoke.php http://localhost/AB123/ /var/www/html/AB123 smoke-test-password
 * Checks that the endpoints find the course through the calendar link, that
 * files are served only through keys, and that nothing protected can be
 * fetched directly (config/, material, dot directories, the library).
 */

[$base, $course, $password] = [rtrim($argv[1] ?? '', '/') . '/', $argv[2] ?? '', $argv[3] ?? ''];
// the software at course/calendar, or in the web layout at course/web/calendar (E-13)
$sw = $course !== '' && is_dir("$course/web/calendar") && !is_dir("$course/calendar") ? 'web/calendar/' : 'calendar/';
$failures = 0;
$cookies = '';

function request(string $url, ?array $post = null): array
{
    global $cookies;
    $curl = curl_init($url);
    $headers = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $cookies !== '' ? ["Cookie: $cookies"] : [],
        CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$headers): int {
            if (stripos($line, 'set-cookie:') === 0) {
                $GLOBALS['cookies'] = trim(explode(';', substr($line, 11), 2)[0]);
            }
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        }]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body, $headers];
}

function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}

[$status, $body] = request($base . $sw . 'php/config.php');
check($status === 200 && isset(json_decode($body, true)['course']), "config.php finds the course through the link ($status)");
[$status, $body] = request($base . $sw . 'php/calendar.php');
$calendar = json_decode($body, true) ?: [];
$key = '';
foreach ($calendar['events'] ?? [] as $event) {
    foreach ($event['boxes'] as $box) {
        if ($key === '' && $box['kind'] === 'html') {
            $key = $box['key'];
        }
    }
}
check($status === 200 && $key !== '', 'calendar.php lists a visible page with a key');
[$status, $body] = request($base . $sw . 'php/html.php?file=' . rawurlencode($key));
check($status === 200 && $body !== '', 'html.php serves the page');
check(request($base . $sw . 'php/get.php?file=' . rawurlencode($key))[0] === 404, "get.php never sends a page's source");
check(request($base . $sw . 'php/get.php?file=AAAAAAAAAAAAAAAAAAAAAA')[0] === 404, 'an unknown key is 404');

if ($password !== '') {
    [, $body] = request($base . $sw . 'php/config.php?csrf=1');
    $csrf = json_decode($body, true)['csrf'] ?? '';
    [$status, $body] = request($base . $sw . 'php/auth.php', ['username' => 'prof', 'password' => $password, 'csrf' => $csrf]);
    check($status === 200 && (json_decode($body, true)['user'] ?? '') === 'prof', "logging in works ($status)");
    [$status, $body] = request($base . $sw . 'php/calendar.php');
    check(str_contains($body, '"instructor": true'), 'the session is kept (instructor view)');
}

foreach ([
    'config/config.ini', 'config/auth/prof.ini', 'config/', 'class/01.Introduction/index.html', 'lab/01.Setup/lab-solution.html',
    'pages/syllabus.html', 'shared/checklist.md', '.git/HEAD', $sw . 'lib/App.php', $sw . 'lib/autoload.php', $sw . 'tests/run.php',
    $sw . 'template/config/config.ini', $sw . 'template/index.html', $sw . 'template/README.md',
    $sw . '.git/HEAD', $sw . 'DESIGN.md.ini', $sw . 'REVIEW.md', $sw . 'DESIGN.md', 'tools/report.php', 'backup.php~', 'db.inc',
    'tools/report.php/' . $sw . 'php/get.php', 'tools/x.PHP', 'tools/x.phtml', 'tools/x.php5', 'tools/x.phar', 'tools/x.phtm', 'tools/x.phar_old', 'tools/x.php.save', 'tools/settings.INI', 'README.md',
] as $path) {
    $status = request($base . $path)[0];
    check(in_array($status, [403, 404], true), "not reachable directly: $path ($status)");
}
check(request($base . $sw . 'php/config.php')[0] === 200, 'the endpoints still answer');

if ($course !== '' && is_dir($course)) {
    check(is_file("$course/config/config.ini"), 'the course directory is where the test expects');
}
echo $failures === 0 ? "\nall deployment checks passed\n" : "\n$failures deployment checks failed\n";
exit($failures === 0 ? 0 : 1);
