<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('analytics degrades to empty when a refresh is already in flight', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
        'platform_user_id' => '4242',
        // Expired, so the analytics service tries to refresh before reading.
        'token_expires_at' => now()->subMinutes(5),
    ]);

    Http::fake(['*' => Http::response(['data' => [], 'meta' => []], 200)]);

    // The scheduled RefreshSocialToken is mid-refresh for this account.
    Cache::lock("token_refresh:{$account->id}", 120)->get();

    $response = $this->actingAs($user)->getJson(route('app.analytics.show', $account));

    // A transient collision is not a server error. Before the lock started
    // reporting itself as transient this returned empty metrics, and a 500 on
    // a page the user just opened is a worse answer than no numbers.
    $response->assertOk();
    expect($response->json('metrics'))->toBe([]);
});
