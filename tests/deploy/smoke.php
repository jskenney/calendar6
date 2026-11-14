<?php

declare(strict_types=1);

/*
 * Deployment smoke test (T-5): a course served by a real web server.
 *   php tests/deploy/smoke.php <web directory URL> [web directory] [password of the account "prof"]
 *   php tests/deploy/smoke.php http://localhost/AB123/ /var/www/html/AB123 smoke-test-password
 * Checks that the endpoints find the course through course.ini, that files are served only
 * through keys, and that nothing protected can be fetched directly (course.ini, config/ and the
 * material through any path, dot directories, stray PHP, the library).
 */

[$base, $web, $password] = [rtrim($argv[1] ?? '', '/') . '/', $argv[2] ?? '', $argv[3] ?? ''];
$sw = 'calendar/';
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
                $GLOBALS['setCookies'][] = trim(substr($line, 11));
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
check($status === 200 && isset(json_decode($body, true)['course']), "config.php finds the course through course.ini ($status)");
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
// URLs the server writes point into this web directory's software (T-32)
$path = (string) parse_url($base, PHP_URL_PATH);
preg_match_all('#(?:src|action)="([^"]*(?:get|html)\.php[^"]*)"#', $body, $written);
check($written[1] !== [] && array_filter($written[1], static fn (string $u): bool => !str_starts_with(html_entity_decode($u), $path . $sw . 'php/')) === [], 'written URLs start with ' . $path . $sw . 'php/ (' . count($written[1]) . ')');
if (preg_match('/<form class="cal6-lock" method="post" action="([^"]+)">.*?name="csrf" value="([^"]+)"/s', $body, $form)) {
    $lock = curl_init((str_starts_with($base, 'https') ? 'https://' : 'http://') . parse_url($base, PHP_URL_HOST) . (($p = parse_url($base, PHP_URL_PORT)) ? ":$p" : '') . html_entity_decode($form[1]));
    curl_setopt_array($lock, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_POSTFIELDS => http_build_query(['lock' => 'wrong', 'csrf' => $form[2]])]);
    $answer = (string) curl_exec($lock);
    check(curl_getinfo($lock, CURLINFO_RESPONSE_CODE) === 303 && preg_match('#^Location: ' . preg_quote($path . $sw . 'php/html.php?file=', '#') . '#mi', $answer) === 1, 'the lock form answers 303 back to the page in this web directory');
}
check(request($base . $sw . 'php/get.php?file=' . rawurlencode($key))[0] === 404, "get.php never sends a page's source");
check(request($base . $sw . 'php/get.php?file=AAAAAAAAAAAAAAAAAAAAAA')[0] === 404, 'an unknown key is 404');

if ($password !== '') {
    [, $body] = request($base . $sw . 'php/config.php?csrf=1');
    $csrf = json_decode($body, true)['csrf'] ?? '';
    [$status, $body] = request($base . $sw . 'php/auth.php', ['username' => 'prof', 'password' => $password, 'csrf' => $csrf]);
    check($status === 200 && (json_decode($body, true)['user'] ?? '') === 'prof', "logging in works ($status)");
    $paths = array_map(static fn (string $c): string => preg_match('/;\s*path=([^;]+)/i', $c, $m) ? $m[1] : '', $GLOBALS['setCookies'] ?? []);
    check($paths !== [] && array_unique($paths) === [(string) parse_url($base, PHP_URL_PATH)], 'cookies belong to the web directory (' . implode(', ', array_unique($paths)) . ')');
    [$status, $body] = request($base . $sw . 'php/calendar.php');
    check(str_contains($body, '"instructor": true'), 'the session is kept (instructor view)');
}

foreach ([
    'course.ini', 'calendar/course.ini', 'course/config/config.ini', 'config/config.ini', 'config/auth/prof.ini', 'config/', 'class/01.Introduction/index.html', 'lab/01.Setup/lab-solution.html',
    'pages/syllabus.html', 'shared/checklist.md', '.git/HEAD', $sw . 'lib/App.php', $sw . 'lib/autoload.php', $sw . 'tests/run.php',
    $sw . 'template/course/config/config.ini', $sw . 'template/web/index.html', $sw . 'template/README.md',
    $sw . '.git/HEAD', $sw . 'DESIGN.md.ini', $sw . 'REVIEW.md', $sw . 'DESIGN.md', 'tools/report.php', 'backup.php~', 'db.inc',
    'tools/report.php/' . $sw . 'php/get.php', 'tools/x.PHP', 'tools/x.phtml', 'tools/x.php5', 'tools/x.phar', 'tools/x.phtm', 'tools/x.phar_old', 'tools/x.php.save', 'tools/settings.INI', 'README.md',
] as $path) {
    $status = request($base . $path)[0];
    check(in_array($status, [403, 404], true), "not reachable directly: $path ($status)");
}
check(request($base . $sw . 'php/config.php')[0] === 200, 'the endpoints still answer');

if ($web !== '' && is_dir($web)) {
    check(is_file("$web/course.ini") && !is_dir("$web/config"), 'the web directory holds course.ini, and no course');
}
echo $failures === 0 ? "\nall deployment checks passed\n" : "\n$failures deployment checks failed\n";
exit($failures === 0 ? 0 : 1);
