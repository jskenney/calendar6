<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Course\CourseConfig;
use Cal6\Http\Context;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * config.php (DESIGN.md): what the front end needs to draw the site, without
 * secrets.  With ?csrf=1 (or an existing session) it also hands out the session's
 * CSRF token.
 */
final class ConfigEndpoint
{
    public static function handle(Context $c): void
    {
        if ($c->method() !== 'GET' && $c->method() !== 'HEAD') {
            Response::error(405, 'GET only', ['Allow: GET, HEAD']);
            return;
        }
        // A session (and its CSRF token) only when one exists or is asked for with
        // ?csrf=1 (a login form), so anonymous visitors leave no session files (L-12).
        // A cross-site request (Sec-Fetch-Site, sent by current browsers) never creates one (L-43).
        $crossSite = strtolower((string) ($c->server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site';
        $viewer = $c->viewer($c->query('csrf') === '1' && !$crossSite);
        $csrf = Session::isOpen() ? Session::csrf() : null;
        Session::close();
        Response::json(self::data($c->config, $c) + [
            'viewer' => ['user' => $viewer->user, 'instructor' => $viewer->instructor],
            'csrf' => $csrf,
        ]);
    }

    /** @return array<string, mixed> */
    public static function data(CourseConfig $config, Context $c): array
    {
        $names = array_flip(CourseConfig::WEEKDAYS);
        $schedule = [];
        foreach ($config->schedule as $day => $entry) {
            $schedule[$names[$day]] = $entry['start'] === null ? $entry['type'] : $entry['type'] . " $entry[start]-$entry[end]";
        }
        $days = [];
        foreach ($c->course()->days as $day) {
            $item = ['date' => $day->key()];
            if ($day->explicit) {
                $item['events'] = $day->types;
            }
            if ($day->weekday !== (int) $day->date->format('N')) {
                $item['as'] = $names[$day->weekday];
            }
            if ($day->period !== $config->defaultPeriod) {
                $item['period'] = $day->period;
            }
            if ($day->kind !== null) {
                $item['kind'] = $day->kind;
            }
            if ($day->label !== null) {
                $item['label'] = $day->label;
            }
            if (count($item) > 1) {
                $days[] = $item;
            }
        }
        return [
            'course' => ['number' => $config->number, 'name' => $config->name, 'timezone' => $config->zone->getName()],
            'semester' => ['start' => $config->start->format('Y-m-d'), 'end' => $config->end->format('Y-m-d'), 'weekends' => $config->weekends],
            'schedule' => $schedule,
            'days' => $days,
            'period' => $config->defaultPeriod,
            'periods' => $config->periods->toArray(),
            'sections' => $config->sections->toArray(),
            'types' => array_map(static fn (string $label): array => ['label' => $label], $config->types),
            'boxes' => array_values(array_map(static fn (array $b): array => ['box' => $b['box'], 'label' => $b['label']], array_filter($config->boxes, static fn (array $b): bool => !($b['notes'] ?? false)))),
            'replace' => (object) $config->replace,
        ];
    }
}
