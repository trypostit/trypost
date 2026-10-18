<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessages;
use App\Events\InboxUpdated;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me']);
    Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'root1']);
});

test('a batch broadcasts once per touched conversation, in time order', function () {
    $account = $this->account;
    Event::fake([InboxUpdated::class]);

    $touched = UpsertInboxMessages::execute($account, [
        inboxIncoming('c2', 'c1', at: '2026-10-16 11:00:00'),
        inboxIncoming('c1', at: '2026-10-16 10:00:00'),
    ]);

    expect($touched)->toHaveCount(1)
        ->and(InboxMessage::query()->where('platform_message_id', 'c2')->value('parent_id'))
        ->toBe(InboxMessage::query()->where('platform_message_id', 'c1')->value('id'));
    Event::assertDispatchedTimes(InboxUpdated::class, 1);
});

test('replaying the same batch broadcasts nothing', function () {
    $account = $this->account;
    UpsertInboxMessages::execute($account, [inboxIncoming('c1')]);
    Event::fake([InboxUpdated::class]);

    expect(UpsertInboxMessages::execute($account, [inboxIncoming('c1')]))->toBeEmpty();

    Event::assertNotDispatched(InboxUpdated::class);
});

test('an empty batch touches nothing', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    Event::fake([InboxUpdated::class]);

    expect(UpsertInboxMessages::execute($account, []))->toBeEmpty();

    Event::assertNotDispatched(InboxUpdated::class);
});

test('a message read without an id is skipped instead of merged into another', function () {
    $account = $this->account;

    UpsertInboxMessages::execute($account, [inboxIncoming(''), inboxIncoming(''), inboxIncoming('c1')]);

    expect(InboxMessage::query()->pluck('platform_message_id')->all())->toBe(['c1']);
});
