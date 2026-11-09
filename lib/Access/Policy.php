<?php

declare(strict_types=1);

namespace Cal6\Access;

use Cal6\Course\Course;
use Cal6\Course\Place;
use Cal6\Time\EventTime;

/**
 * The access decision, used by calendar.php, get.php and html.php alike
 * (DESIGN.md, "Access model"):
 *
 * - instructors see everything;
 * - anonymous viewers see what default.ini releases (v4 precedence, the later
 *   line wins, no rule = visible); during the lockdown only what an `always` rule
 *   decides; never events on no calendar day; nothing when default.ini is unreadable;
 * - a user sees what anonymous viewers see, or what one of the user's grants
 *   (laid over default.ini, same precedence) opens, inside the account's window;
 * - a virtual file must be allowed under its name and under its real file.
 */
final class Policy
{
    /** @var array<string, Place>|null */
    private ?array $byRealPath = null;

    public function __construct(private readonly Course $course)
    {
    }

    public function decide(Viewer $viewer, Place $place, int $now): Decision
    {
        $zone = $this->course->config->zone;
        $lists = $this->candidateLists($place);
        $anonRule = $this->deciding($this->course->rules, $lists[0][0]);

        if ($viewer->instructor && $viewer->isValidAt($now, $zone)) {
            [$visibleAnon, $opens, $next] = $this->anonymous($lists, $now);
            return new Decision(true, $anonRule, $visibleAnon ? null : $opens, $next);
        }
        if (($place->event !== null && $place->event->date === null) || $this->course->rulesUnreadable || $this->realUnscheduled($place)) {
            return new Decision(false, $anonRule);
        }
        [$visible, $opens, $next] = $this->anonymous($lists, $now);
        if (!$visible && $viewer->user !== null && $viewer->isValidAt($now, $zone)) {
            $overlay = $this->course->rules->overlay($viewer->grants);
            $lockdown = $this->course->config->lockdown;
            $locked = $lockdown !== null && $lockdown->isOpen($now, $zone, null);
            $userVisible = true;
            $userOpens = null;
            foreach ($lists as [$targets, $time]) {
                $rule = $this->deciding($overlay, $targets);
                $anonRuleHere = $this->deciding($this->course->rules, $targets);
                $anonOpen = $locked ? ($anonRuleHere !== null && $anonRuleHere->window->isAlways()) : $this->open($anonRuleHere, $now, $time);
                $grantOpen = $rule !== null && $rule->grant && $rule->window->isOpen($now, $zone, $time);
                if ($rule !== null && $rule->grant) {
                    $next = self::min($next, $rule->window->nextChange($now, $zone, $time));
                    [$o] = $rule->window->bounds($zone, $time);
                    if ($o !== null && $o > $now && $o !== PHP_INT_MAX) {
                        $userOpens = max($userOpens ?? 0, $o);
                    }
                }
                $userVisible = $userVisible && ($anonOpen || $grantOpen);
            }
            $next = self::min($next, $viewer->validUntil($now, $zone));
            if ($userVisible) {
                return new Decision(true, $anonRule, null, $next);
            }
            $opens = self::min($opens, $userOpens);
        }
        return new Decision($visible, $anonRule, $visible ? null : $opens, $next);
    }

    /**
     * @param list<array{0: list<string>, 1: ?EventTime}> $lists
     * @return array{0: bool, 1: ?int, 2: ?int} visible, opens, next change
     */
    private function anonymous(array $lists, int $now): array
    {
        $zone = $this->course->config->zone;
        $lockdown = $this->course->config->lockdown;
        $locked = $lockdown !== null && $lockdown->isOpen($now, $zone, null);
        $next = $lockdown?->nextChange($now, $zone, null);
        $visible = true;
        $opens = null;
        $neverOpens = false;
        foreach ($lists as [$targets, $time]) {
            $rule = $this->deciding($this->course->rules, $targets);
            if ($rule !== null) {
                $next = self::min($next, $rule->window->nextChange($now, $zone, $time));
            }
            $open = $locked ? ($rule !== null && $rule->window->isAlways()) : $this->open($rule, $now, $time);
            if (!$open) {
                $visible = false;
                [$o] = $rule === null ? [null] : $rule->window->bounds($zone, $time);
                if ($rule === null || $o === PHP_INT_MAX || $o === null || $o <= $now || $locked) {
                    $neverOpens = true;
                } else {
                    $opens = max($opens ?? 0, $o);
                }
            }
        }
        return [$visible, $neverOpens ? null : $opens, $next];
    }

    /** No rule: visible (v4). */
    private function open(?Rule $rule, int $now, ?EventTime $time): bool
    {
        return $rule === null || $rule->window->isOpen($now, $this->course->config->zone, $time);
    }

    /** @param list<string> $targets */
    private function deciding(Rules $rules, array $targets): ?Rule
    {
        foreach ($targets as $target) {
            $rule = $rules->get($target);
            if ($rule !== null) {
                return $rule;
            }
        }
        return null;
    }

    /** @return list<array{0: list<string>, 1: ?EventTime}> one list of targets per name the file must be allowed under */
    public function candidateLists(Place $place): array
    {
        if ($place->assetOf !== null && isset($this->course->places[$place->assetOf])) {
            return $this->candidateLists($this->course->places[$place->assetOf]); // decided by the page that links to it
        }
        if ($place->event === null) {
            return [[['page/' . $place->page, 'page', '*'], null]];
        }
        $lists = [[self::eventTargets($place->event->type, $place->event->number, $place->file->name, $place->box), $place->event->time]];
        if ($place->file->virtualOf !== null) {
            $real = $this->realPlace($place->file->realPath);
            $lists[] = match (true) {
                $real !== null && $real->event !== null => [self::eventTargets($real->event->type, $real->event->number, $real->file->name, $real->box), $real->event->time],
                $real !== null => [['page/' . $real->page, 'page', '*'], null], // a paths.ini page keeps its rule (L-31)
                default => [[$place->file->virtualOf, basename($place->file->virtualOf), '*'], null],
            };
            // a file that paths.ini pages link to (pages/answers.pdf) also follows each of those
            // pages' rules, so a virtual name does not publish a hidden page's attachment (L-49)
            if ($real === null || $real->event === null) {
                foreach ($this->pageAssetsOf($place->file->realPath) as $page) {
                    $lists[] = [['page/' . $page, 'page', '*'], null];
                }
            }
        }
        return $lists;
    }

    /** v4's order, from strongest to weakest (get_file_security()). @return list<string> */
    public static function eventTargets(string $type, int $number, string $file, ?string $box): array
    {
        $event = "{$type}_$number";
        $targets = ["$event/$file", $file];
        if ($box !== null) {
            $targets[] = "$event/$box";
            $targets[] = "$type/$box";
        }
        array_push($targets, $event, "$type/*", "$event/all", "$event/*");
        if ($box !== null) {
            $targets[] = "*/$box";
        }
        $targets[] = $type;
        $targets[] = '*';
        return $targets;
    }

    /** The place that owns a virtual file's real file: an event's file, else a paths.ini page. */
    private function realPlace(string $real): ?Place
    {
        if ($this->byRealPath === null) {
            $this->byRealPath = [];
            $pages = [];
            foreach ($this->course->places as $place) {
                if ($place->event !== null && $place->file->virtualOf === null && $place->event->mode === null && $place->assetOf === null) {
                    $this->byRealPath[$place->file->realPath] ??= $place;
                } elseif ($place->event === null && !$place->asset) {
                    $pages[$place->file->realPath] ??= $place;
                }
            }
            $this->byRealPath += $pages;
        }
        return $this->byRealPath[$real] ?? null;
    }

    /** @var array<string, list<string>>|null real path => the paths.ini pages that link to it */
    private ?array $assetPages = null;

    /** The paths.ini pages whose links give the file at $real a key of its own. @return list<string> */
    private function pageAssetsOf(string $real): array
    {
        if ($this->assetPages === null) {
            $this->assetPages = [];
            foreach ($this->course->places as $place) {
                if ($place->asset && $place->event === null && $place->file->virtualOf === null) {
                    $this->assetPages[$place->file->realPath][] = (string) $place->page;
                }
            }
        }
        return array_values(array_unique($this->assetPages[$real] ?? []));
    }

    /** A virtual file whose real file belongs to an event on no calendar day is not shown (R14, L-31). */
    private function realUnscheduled(Place $place): bool
    {
        if ($place->file->virtualOf === null) {
            return false;
        }
        $real = $this->realPlace($place->file->realPath);
        return $real !== null && $real->event !== null && $real->event->date === null;
    }

    public static function min(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
