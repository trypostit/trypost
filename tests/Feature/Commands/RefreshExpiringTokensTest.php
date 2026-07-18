<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\RefreshSocialToken;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('it dispatches refresh jobs for rotating tokens near expiry and extension tokens well ahead of expiry', function () {
    Queue::fake();

    $workspace = Workspace::factory()->create();

    // Rotating platform expiring in 15 minutes — inside the 30-minute window.
    $rotatingSoon = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'status' => Status::Connected,
        'token_expires_at' => now()->addMinutes(15),
    ]);

    // Rotating platform expiring in 1 hour — OUTSIDE the 30-minute window.
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHour(),
    ]);

    // Extension platform expiring in 1 hour — inside the wide 24-hour window.
    // (On the old shared 30-minute window this lapsed under queue backlog.)
    $extensionSoon = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHour(),
    ]);

    // Extension platform expiring in 12 hours — still inside the 24-hour window.
    $extensionLater = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Threads,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHours(12),
    ]);

    // Extension platform expiring in 2 days — OUTSIDE the 24-hour window.
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'token_expires_at' => now()->addDays(2),
    ]);

    // Rotating platform already expired — last-chance attempt before the
    // refresh_token also dies at the provider.
    $rotatingExpired = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::TikTok,
        'status' => Status::Connected,
        'token_expires_at' => now()->subHour(),
    ]);

    // Disconnected — never refreshed.
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Pinterest,
        'status' => Status::Disconnected,
        'token_expires_at' => now()->addHour(),
    ]);

    // Already token expired — daily verify handles these.
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::YouTube,
        'status' => Status::TokenExpired,
        'token_expires_at' => now()->subHour(),
    ]);

    $this->artisan('social:refresh-expiring-tokens')
        ->assertSuccessful();

    Queue::assertPushed(RefreshSocialToken::class, 4);
    Queue::assertPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $rotatingSoon->id);
    Queue::assertPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $extensionSoon->id);
    Queue::assertPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $extensionLater->id);
    Queue::assertPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $rotatingExpired->id);
});

test('extension-model platforms get a wider refresh window than rotating platforms', function () {
    Queue::fake();

    $workspace = Workspace::factory()->create();

    // Same expiry (1 hour out) for both — only the extension-model account
    // should be dispatched, because it can't be refreshed once expired.
    $extension = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHour(),
    ]);

    $rotating = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHour(),
    ]);

    $this->artisan('social:refresh-expiring-tokens')
        ->assertSuccessful();

    Queue::assertPushed(RefreshSocialToken::class, 1);
    Queue::assertPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $extension->id);
    Queue::assertNotPushed(RefreshSocialToken::class, fn ($job) => $job->account->id === $rotating->id);
});

test('it dispatches nothing when no tokens are expiring', function () {
    Queue::fake();

    $this->artisan('social:refresh-expiring-tokens')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
