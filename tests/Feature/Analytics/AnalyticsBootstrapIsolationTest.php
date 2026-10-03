<?php

declare(strict_types=1);

use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('connecting an account in a test queues the analytics bootstrap instead of importing remote posts', function () {
    Http::fake();

    $account = SocialAccount::factory()->mastodon()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'platform_user_id' => '297'.'-numeric-prefix',
    ]);

    Queue::assertPushed(BootstrapAccountAnalytics::class, fn (BootstrapAccountAnalytics $job): bool => $job->socialAccountId === $account->id);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/statuses'));
});
