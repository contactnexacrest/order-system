<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-320 (11 Nov 2026, point 26): the short left menu (13
 * entries) and the menu bar at the top of every page of a section, both
 * built from one config, src/Config/navigation.json (the same file, byte for
 * byte, as the Node twin src/config/navigation.json; services/navigation.js
 * holds the same logic).
 *
 * Pure: it is given what the person may do ($can), the facts the config
 * asks about ($flags), the live counts and the page being shown, and returns
 * plain arrays for views/layout/base.php and views/partials/section_nav.php.
 * A page joins its section's menu bar by its path alone, so a new page under
 * a section's prefix gets the bar without any change to the page.
 */
final class Navigation
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            $raw = (string) file_get_contents(dirname(__DIR__) . '/Config/navigation.json');
            self::$config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$config;
    }

    /** Does a path belong under a prefix ("/ca" covers "/ca" and "/ca/zoho-sync", never "/cafin")? */
    public static function under(string $path, string $prefix): bool
    {
        if ($prefix === '/') {
            return $path === '/';
        }
        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }

    /** The key of the section a page belongs to (longest prefix wins), or null (Dashboard, Notifications, My account …). */
    public static function sectionKeyFor(string $path): ?string
    {
        $best = null;
        $bestLen = -1;
        foreach (self::config()['sections'] as $section) {
            foreach ($section['prefixes'] ?? [] as $prefix) {
                if (self::under($path, $prefix) && strlen($prefix) > $bestLen) {
                    $best = (string) $section['key'];
                    $bestLen = strlen($prefix);
                }
            }
        }
        return $best;
    }

    /**
     * @param callable(string):bool $can
     * @param array<string,bool> $flags
     */
    public static function allowed(?array $when, callable $can, array $flags): bool
    {
        if ($when === null) {
            return true;
        }
        foreach ($when['flags'] ?? [] as $flag) {
            if (empty($flags[$flag])) {
                return false;
            }
        }
        foreach ($when['all'] ?? [] as $perm) {
            if (!$can((string) $perm)) {
                return false;
            }
        }
        $any = $when['any'] ?? [];
        if ($any === []) {
            return true;
        }
        foreach ($any as $perm) {
            if ($can((string) $perm)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Everything the layout draws.
     *
     * @param callable(string):bool $can
     * @param array<string,bool> $flags
     * @param array<string,int> $counts
     * @param array<string,mixed> $query the page's query string ($_GET)
     * @return array{sidebar: list<array<string,mixed>>, bar: ?array<string,mixed>, currentKey: ?string}
     */
    public static function build(callable $can, array $flags, array $counts, string $path, array $query = []): array
    {
        $config = self::config();
        $currentKey = $path === '/' ? 'dashboard' : self::sectionKeyFor($path);
        $sidebar = [];
        $bar = null;
        $lastGroup = false;
        foreach ($config['sections'] as $section) {
            $items = self::visibleItems($section['items'] ?? [], $can, $flags, $counts);
            if (($section['items'] ?? []) !== []) {
                if ($items === []) {
                    continue; // nothing in this section this person can open
                }
                $href = self::firstHref($items);
            } else {
                if (!self::allowed($section['when'] ?? null, $can, $flags)) {
                    continue;
                }
                $href = (string) $section['href'];
            }
            $group = $section['group'] ?? null;
            $heading = null;
            if ($group !== null && $group !== $lastGroup) {
                $heading = (string) ($config['groups'][$group] ?? $group);
            }
            $lastGroup = $group;
            $isCurrent = $currentKey === $section['key'];
            $sidebar[] = [
                'key' => (string) $section['key'],
                'label' => (string) $section['label'],
                'href' => $href,
                'icon' => (string) ($section['icon'] ?? ''),
                'heading' => $heading,
                'count' => isset($section['count']) ? (int) ($counts[$section['count']] ?? 0) : 0,
                'active' => $isCurrent,
            ];
            if ($isCurrent && $items !== []) {
                $items = self::markActive($items, $path, $query);
                $cross = [];
                foreach ($section['crossLinks'] ?? [] as $link) {
                    $on = false;
                    foreach ($link['onPaths'] ?? [] as $p) {
                        $on = $on || self::under($path, (string) $p);
                    }
                    if ($on && self::allowed($link['when'] ?? null, $can, $flags)) {
                        $cross[] = ['label' => (string) $link['label'], 'href' => (string) $link['href']];
                    }
                }
                $bar = ['key' => (string) $section['key'], 'label' => (string) $section['label'], 'items' => $items, 'crossLinks' => $cross];
            }
        }
        return ['sidebar' => $sidebar, 'bar' => $bar, 'currentKey' => $currentKey];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function visibleItems(array $items, callable $can, array $flags, array $counts): array
    {
        $out = [];
        foreach ($items as $item) {
            if (isset($item['children'])) {
                $children = self::visibleItems($item['children'], $can, $flags, $counts);
                if ($children === []) {
                    continue;
                }
                $out[] = ['label' => (string) $item['label'], 'children' => $children, 'count' => array_sum(array_column($children, 'count')), 'active' => false];
                continue;
            }
            if (!self::allowed($item['when'] ?? null, $can, $flags)) {
                continue;
            }
            $out[] = [
                'label' => (string) $item['label'],
                'href' => (string) $item['href'],
                'target' => $item['target'] ?? null,
                'count' => isset($item['count']) ? (int) ($counts[$item['count']] ?? 0) : 0,
                'match' => $item['match'] ?? [],
                'activeQuery' => $item['activeQuery'] ?? null,
                'active' => false,
            ];
        }
        return $out;
    }

    /** The left-menu link: the first page this person can open that opens in the same tab (else the first at all). */
    private static function firstHref(array $items): string
    {
        $flat = [];
        foreach ($items as $item) {
            foreach (isset($item['children']) ? $item['children'] : [$item] as $leaf) {
                $flat[] = $leaf;
            }
        }
        foreach ($flat as $leaf) {
            if (empty($leaf['target'])) {
                return (string) $leaf['href'];
            }
        }
        return (string) $flat[0]['href'];
    }

    /** One item is marked as the page shown: the one whose link (or extra prefix) matches the path most closely. */
    private static function markActive(array $items, string $path, array $query): array
    {
        $bestScore = -1;
        $bestAt = null;
        foreach ($items as $i => $item) {
            foreach (isset($item['children']) ? $item['children'] : [$item] as $j => $leaf) {
                $score = self::score($leaf, $path, $query);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestAt = [$i, isset($item['children']) ? $j : null];
                }
            }
        }
        if ($bestAt !== null && $bestScore >= 0) {
            [$i, $j] = $bestAt;
            if ($j === null) {
                $items[$i]['active'] = true;
            } else {
                $items[$i]['active'] = true;
                $items[$i]['children'][$j]['active'] = true;
            }
        }
        return $items;
    }

    /** -1 = does not match; otherwise the longer the matching path, the better (a matching query adds 1000). */
    private static function score(array $leaf, string $path, array $query): int
    {
        $href = (string) $leaf['href'];
        if (str_contains($href, '#')) {
            return -1;
        }
        $parts = explode('?', $href, 2);
        $hrefPath = $parts[0];
        $best = self::under($path, $hrefPath) ? strlen($hrefPath) : -1;
        foreach ($leaf['match'] ?? [] as $extra) {
            if (self::under($path, (string) $extra)) {
                $best = max($best, strlen((string) $extra));
            }
        }
        if ($best < 0) {
            return -1;
        }
        $want = [];
        if (isset($parts[1])) {
            parse_str($parts[1], $want);
        }
        foreach ($want as $k => $v) {
            if ((string) ($query[$k] ?? '') !== (string) $v) {
                return -1;
            }
        }
        foreach ($leaf['activeQuery'] ?? [] as $k => $allowedValues) {
            $value = is_scalar($query[$k] ?? null) ? (string) $query[$k] : '';
            if (!in_array($value, $allowedValues, true)) {
                return -1;
            }
        }
        return $best + ($want !== [] || !empty($leaf['activeQuery']) ? 1000 : 0);
    }
}
