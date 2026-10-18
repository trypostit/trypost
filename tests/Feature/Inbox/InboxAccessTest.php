<?php

declare(strict_types=1);

use App\Actions\Inbox\DispatchInboxBackfill;
use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
});

test('an old mastodon channel shows the banner and is never synced', function () {
    $account = SocialAccount::factory()->mastodon()->create();
    $account->workspace->owner->update(['current_workspace_id' => $account->workspace_id]);

    expect($account->hasInboxAccess())->toBeFalse();

    Artisan::call('inbox:dispatch-sync');
    Queue::assertNotPushed(SyncInboxAccount::class);

    $this->actingAs($account->workspace->owner->fresh())
        ->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('inboxChannels.0.has_inbox_access', false));
});

test('read or read:notifications grants mastodon inbox access', function (array $scopes) {
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => $scopes]);

    expect($account->hasInboxAccess())->toBeTrue();
})->with([
    'granular' => [['read:accounts', 'read:statuses', 'write:statuses', 'write:media', 'read:notifications']],
    'umbrella' => [['read', 'write:statuses', 'write:media']],
]);

test('reconnecting with the scope backfills', function () {
    $account = SocialAccount::factory()->mastodon()->create();

    $account->update(['scopes' => [...$account->scopes, 'read:notifications']]);
    app(DispatchInboxBackfill::class)->handle($account->fresh());

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id
        && $job->kind === ConversationKind::Comment
        && $job->backfill);
});
