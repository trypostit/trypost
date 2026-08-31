<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Automation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * A rehearsal rather than a scenario test: build a deliberately messy database
 * the way a long-lived self-hosted install would look, run the real migration,
 * and assert the invariants that must hold afterwards. Scenario tests only cover
 * the cases someone thought to write; this covers the combinations nobody did.
 */
beforeEach(function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $this->migration = require database_path(
        'migrations/2026_08_21_130941_add_workspace_platform_identity_unique_to_social_accounts_table.php',
    );

    $this->migration->down();
});

/**
 * Deterministic so a failure is reproducible: no fake() randomness decides the
 * shape, only the seeded sequence below.
 */
function rehearsalDatabase(): array
{
    $statuses = [PostPlatformStatus::Published, PostPlatformStatus::Pending, PostPlatformStatus::Failed];
    $platforms = [Platform::Pinterest, Platform::X, Platform::LinkedIn, Platform::Instagram];
    $seed = 0;
    $next = function (int $modulo) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;

        return intdiv($seed, 65536) % $modulo;
    };

    $published = [];
    $accountIds = [];

    foreach (range(1, 3) as $w) {
        $workspace = Workspace::factory()->create();

        foreach ($platforms as $p => $platform) {
            $copies = 1 + $next(3);
            $identity = "identity-{$w}-{$p}";

            $accounts = collect(range(1, $copies))->map(fn (int $c) => SocialAccount::factory()->create([
                'workspace_id' => $workspace->id,
                'platform' => $platform,
                'platform_user_id' => $identity,
                'created_at' => now()->subDays($copies - $c),
            ]));

            $accountIds = array_merge($accountIds, $accounts->pluck('id')->all());

            foreach (range(1, 2) as $n) {
                $post = Post::factory()->create(['workspace_id' => $workspace->id]);

                foreach ($accounts as $account) {
                    $status = $statuses[$next(3)];

                    $row = PostPlatform::factory()->create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform' => $platform,
                        'status' => $status,
                        'enabled' => $next(2) === 0,
                    ]);

                    if ($status === PostPlatformStatus::Published) {
                        $published[] = $row->id;
                    }
                }
            }

            Automation::factory()->for($workspace)->create([
                'nodes' => [
                    [
                        'id' => "node-{$w}-{$p}",
                        'type' => 'generate',
                        'config' => $next(2) === 0
                            ? ['accounts' => $accounts->map(fn (SocialAccount $a) => [
                                'social_account_id' => $a->id,
                                'content_type' => 'pinterest_pin',
                            ])->all()]
                            : ['social_account_ids' => $accounts->pluck('id')->all()],
                    ],
                ],
            ]);
        }
    }

    $duplicateGroups = DB::table('social_accounts')
        ->select('workspace_id', 'platform', 'platform_user_id')
        ->groupBy('workspace_id', 'platform', 'platform_user_id')
        ->havingRaw('count(*) > 1')
        ->get()
        ->count();

    // Guard the guard: if the generator ever stops producing duplicates and
    // published rows, every invariant below would pass on an empty problem.
    expect($duplicateGroups)->toBeGreaterThan(5)
        ->and($published)->not->toBeEmpty();

    return ['published' => $published, 'accountIds' => $accountIds];
}

test('the rehearsal leaves no duplicate identity behind', function () {
    rehearsalDatabase();

    $this->migration->up();

    $duplicates = DB::table('social_accounts')
        ->select('workspace_id', 'platform', 'platform_user_id')
        ->groupBy('workspace_id', 'platform', 'platform_user_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($duplicates)->toBeEmpty();
});

test('the rehearsal never destroys a published row', function () {
    ['published' => $published] = rehearsalDatabase();

    $this->migration->up();

    expect(PostPlatform::whereIn('id', $published)->count())->toBe(count($published));
});

test('the rehearsal leaves no post publishing twice to one account', function () {
    rehearsalDatabase();

    $this->migration->up();

    $doubled = DB::table('post_platforms')
        ->select('post_id', 'social_account_id')
        ->where('enabled', true)
        ->whereNotNull('social_account_id')
        ->groupBy('post_id', 'social_account_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($doubled)->toBeEmpty();
});

test('the rehearsal leaves no post platform pointing at a deleted account', function () {
    rehearsalDatabase();

    $this->migration->up();

    $orphans = DB::table('post_platforms')
        ->whereNotNull('social_account_id')
        ->whereNotIn('social_account_id', DB::table('social_accounts')->select('id'))
        ->count();

    expect($orphans)->toBe(0);
});

test('the rehearsal leaves no automation pointing at a deleted account', function () {
    ['accountIds' => $accountIds] = rehearsalDatabase();

    $this->migration->up();

    $surviving = DB::table('social_accounts')->pluck('id')->all();
    $gone = array_values(array_diff($accountIds, $surviving));

    expect($gone)->not->toBeEmpty();

    foreach (Automation::all() as $automation) {
        $json = json_encode($automation->nodes);

        foreach ($gone as $id) {
            expect($json)->not->toContain($id);
        }
    }
});

test('the rehearsal keeps the newest row of each identity', function () {
    rehearsalDatabase();

    $expected = DB::table('social_accounts')
        ->select('workspace_id', 'platform', 'platform_user_id', DB::raw('max(created_at) as newest'))
        ->groupBy('workspace_id', 'platform', 'platform_user_id')
        ->get();

    $this->migration->up();

    foreach ($expected as $identity) {
        $survivor = DB::table('social_accounts')
            ->where('workspace_id', $identity->workspace_id)
            ->where('platform', $identity->platform)
            ->where('platform_user_id', $identity->platform_user_id)
            ->first();

        expect($survivor)->not->toBeNull()
            ->and((string) $survivor->created_at)->toBe((string) $identity->newest);
    }
});
