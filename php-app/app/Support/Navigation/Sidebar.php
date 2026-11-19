<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Support\Access\DynamicPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar, search box and account menu from config/navigation.php.
 *
 * Every link is shown to every user -- the user's own explicit decision
 * (2026-09-15, see tests/Feature/Navigation/SidebarLinkPermissionTest.php)
 * that the menu always shows the full application. Links the current user
 * cannot open are marked `locked` so the menu says so up front instead of
 * leaving the user to discover an "Access denied" page; a group whose links
 * are all locked is marked locked too. Server-side authorization on every
 * route is unchanged.
 *
 * The user's permissions are resolved once per render instead of calling
 * Gate::allows() per link: the 'permission' gate (AppServiceProvider) is
 * exactly isActive() && hasAppPermission(), and hasAppPermission() queries
 * organisation-defined role grants on every call. SidebarNavigationTest
 * checks the locked/unlocked split against the real routes for every role.
 */
final class Sidebar
{
    /**
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     activeGroup: ?array<string, mixed>,
     *     activeItem: ?array<string, mixed>,
     *     accountLinks: list<array<string, mixed>>,
     *     searchUrl: ?string
     * }
     */
    public static function build(User $user, Request $request): array
    {
        $granted = self::grantedPermissions($user);
        $groups = [];
        $activeGroup = null;
        $activeItem = null;

        foreach (config('navigation.groups', []) as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }
                $item['url'] = route($item['route'], $item['params'] ?? [])
                    .(isset($item['fragment']) ? '#'.$item['fragment'] : '');
                $item['locked'] = ! self::allows($granted, $item['permission'] ?? null);
                $item['current'] = ! isset($item['fragment']) && self::isCurrent($item, $request);
                $items[] = $item;
                if ($item['current'] && $activeItem === null) {
                    $activeItem = $item;
                }
            }

            if ($items === []) {
                continue;
            }

            $group['items'] = $items;
            $group['locked'] = collect($items)->every(fn (array $item) => $item['locked']);
            $group['current'] = collect($items)->contains('current', true)
                || $request->routeIs(...($group['active'] ?? []));
            $groups[] = $group;

            if ($group['current'] && $activeGroup === null) {
                $activeGroup = $group;
            }
        }

        $accountLinks = collect(config('navigation.account', []))
            ->filter(fn (array $link) => Route::has($link['route']))
            ->map(fn (array $link) => $link + [
                'url' => route($link['route']),
                'locked' => ! self::allows($granted, $link['permission'] ?? null),
                'current' => $request->routeIs($link['route']),
            ])
            ->values()
            ->all();

        $search = config('navigation.search');
        $searchUrl = $search && self::allows($granted, $search['permission'] ?? null) && Route::has($search['route'])
            ? route($search['route'])
            : null;

        return compact('groups', 'activeGroup', 'activeItem', 'accountLinks', 'searchUrl');
    }

    /** @return list<string> */
    private static function grantedPermissions(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $user->rolePermissions(),
            DynamicPermissions::forUser($user),
        )));
    }

    /**
     * @param  list<string>  $granted
     * @param  string|list<string>|null  $permission
     */
    private static function allows(array $granted, string|array|null $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        return array_intersect((array) $permission, $granted) !== [];
    }

    /** @param array<string, mixed> $item */
    private static function isCurrent(array $item, Request $request): bool
    {
        if (! $request->routeIs(...($item['active'] ?? [$item['route']]))) {
            return false;
        }

        foreach ($item['query'] ?? [] as $key => $value) {
            if ($request->query($key) !== $value) {
                return false;
            }
        }

        foreach ($item['not_query'] ?? [] as $key => $value) {
            if ($request->query($key) === $value) {
                return false;
            }
        }

        return true;
    }
}
