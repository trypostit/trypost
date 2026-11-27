<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function socialAccountPositionMigration(): object
{
    return require database_path('migrations/2026_09_30_185403_add_position_to_social_accounts_table.php');
}

test('the backfill numbers each workspace channels by created_at then id', function () {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    $newest = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'created_at' => now()->subDay()]);
    $oldest = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'created_at' => now()->subDays(5)]);
    $middle = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id, 'created_at' => now()->subDays(3)]);
    $alone = SocialAccount::factory()->linkedin()->create(['workspace_id' => $other->id]);

    DB::table('social_accounts')->update(['position' => 0]);

    socialAccountPositionMigration()->backfillPositions();

    expect($oldest->fresh()->position)->toBe(0)
        ->and($middle->fresh()->position)->toBe(1)
        ->and($newest->fresh()->position)->toBe(2)
        ->and($alone->fresh()->position)->toBe(0)
        ->and($workspace->socialAccounts()->pluck('id')->all())->toBe([$oldest->id, $middle->id, $newest->id]);
});
