<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use App\Support\Navigation\Sidebar;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sidebar shows every link to every user (the user's own 2026-09-15
 * decision -- see SidebarLinkPermissionTest), and marks the ones the
 * current user cannot open with a lock (App\Support\Navigation\Sidebar).
 *
 * This test keeps that lock honest for every seeded role: a locked link
 * must really be refused (403), and an unlocked link must really open. A
 * mismatch means config/navigation.php names a different permission from
 * the one the destination controller checks.
 */
class SidebarLockStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sidebar_lock_matches_what_the_destination_allows_for_every_seeded_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        // One user per role. Taxpayer roles prefer a user whose organisation
        // holds a licence: licence-gated pages refuse unlicensed organisations
        // whatever the role allows, and the 403 page explains that case
        // (see test_an_unlicensed_organisation_gets_a_licence_explanation).
        $licensed = DB::table('organisations as o')->join('organisation_licenses as l', 'l.organisation_id', '=', 'o.id')->pluck('o.taxpayer_id')->all();
        $users = User::query()->where('status', 'ACTIVE')->orderBy('email')->get()
            ->sortBy(fn (User $user) => $user->taxpayer_id && ! in_array($user->taxpayer_id, $licensed, true) ? 1 : 0)
            ->unique('role');
        $this->assertGreaterThan(5, $users->count(), 'The demo seed should cover several roles.');

        $mismatches = [];
        $checked = 0;
        foreach ($users as $user) {
            $navigation = Sidebar::build($user, Request::create('/'));
            $urls = [];
            foreach ($navigation['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $url = strtok($item['url'], '#');
                    // A shortcut (#fragment) re-uses a page already listed.
                    if (isset($urls[$url])) {
                        continue;
                    }
                    $urls[$url] = $item['locked'];
                }
            }

            foreach ($urls as $url => $locked) {
                $status = $this->actingAs($user)->get($url)->getStatusCode();
                $checked++;
                $refused = $status === 403;
                if ($locked !== $refused || $status >= 500) {
                    $mismatches[] = sprintf('%s: %s is %s but returned %d', $user->role, parse_url($url, PHP_URL_PATH).(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : ''), $locked ? 'locked' : 'unlocked', $status);
                }
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $mismatches, "Sidebar lock state disagrees with the destination:\n".implode("\n", $mismatches));
    }

    /** Locks reflect permissions only; a licence block is explained on the 403 page instead of a generic "no access". */
    public function test_an_unlicensed_organisation_gets_a_licence_explanation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $licensed = DB::table('organisations as o')->join('organisation_licenses as l', 'l.organisation_id', '=', 'o.id')->pluck('o.taxpayer_id')->all();
        $owner = User::query()->where('role', 'TAXPAYER_OWNER')->where('status', 'ACTIVE')->whereNotNull('taxpayer_id')
            ->whereNotIn('taxpayer_id', $licensed)->firstOrFail();

        $response = $this->actingAs($owner)->get('/administration');

        $response->assertForbidden();
        $response->assertSeeText("This area isn't included in your licence");
        $response->assertSee('Reference: The organisation has no configured licence.');
    }
}
