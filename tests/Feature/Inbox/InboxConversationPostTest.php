<?php

declare(strict_types=1);

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\SendInboxDirectMessage;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Post\Origin;
use App\Http\Resources\Api\InboxConversationResource;
use App\Http\Resources\Api\InboxMessageResource;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me']);
    $this->user = $this->account->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->account->workspace_id]);
});

function inboxCommentOn(string $subjectId, ?string $remoteId, string $id = 'c1'): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject($subjectId, 'My post', [], null, CarbonImmutable::parse('2026-10-15'), $remoteId),
        platformMessageId: $id,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: 'fan',
        authorName: 'Fan',
        authorUsername: 'fan',
        authorAvatarUrl: null,
        body: 'Nice post',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 10:00:00'),
    );
}

test('a comment is linked to the post of its channel, published through TryPost or imported', function (string $state, Origin $origin) {
    $post = Post::factory()->forAccount($this->account)->{$state}()->create(['platform_post_id' => 'root1']);

    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

    expect(InboxConversation::query()->sole()->post_id)->toBe($post->id)
        ->and($post->fresh()->origin)->toBe($origin);
})->with([
    'published through TryPost' => ['published', Origin::TryPost],
    'imported from the network' => ['imported', Origin::Network],
]);

test('each network links by the id its posts store', function (string $platform, string $subjectId, ?string $remoteId, string $platformPostId) {
    $account = SocialAccount::factory()->{$platform}()->create(['platform_user_id' => 'did:plc:me']);
    $post = Post::factory()->forAccount($account)->published()->create(['platform_post_id' => $platformPostId]);

    UpsertInboxMessage::execute($account, inboxCommentOn($subjectId, $remoteId));

    expect(InboxConversation::query()->sole()->post_id)->toBe($post->id);
})->with([
    'bluesky by record key' => ['bluesky', 'at://did:plc:me/app.bsky.feed.post/3kabc', null, '3kabc'],
    'facebook page post' => ['facebook', '1234_5678', '1234_5678', '1234_5678'],
    'facebook without a remote id' => ['facebook', '1234_5678', null, '1234_5678'],
    'instagram media' => ['instagram', '17890000000000001', '17890000000000001', '17890000000000001'],
    'threads media' => ['threads', '18000000000000001', '18000000000000001', '18000000000000001'],
    'youtube video' => ['youtube', 'dQw4w9WgXcQ', 'dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'mastodon status' => ['mastodon', '113000000000000001', '113000000000000001', '113000000000000001'],
]);

test("another channel's post with the same id is never linked", function () {
    Post::factory()->forAccount(SocialAccount::factory()->bluesky()->create())->published()->create(['platform_post_id' => 'root1']);

    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

    expect(InboxConversation::query()->sole()->post_id)->toBeNull();
});

test('a post published or imported after its comments links them', function (string $state) {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    expect(InboxConversation::query()->sole()->post_id)->toBeNull();

    $post = Post::factory()->forAccount($this->account)->{$state}()->create(['platform_post_id' => 'root1']);

    expect(InboxConversation::query()->sole()->post_id)->toBe($post->id);
})->with(['published', 'imported']);

test('a post that learns its id when it is published links its comments', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $post = Post::factory()->forAccount($this->account)->publishing()->create();

    $post->markPublicationPublished('root1', 'https://bsky.app/profile/me/post/root1');

    expect(InboxConversation::query()->sole()->post_id)->toBe($post->id);
});

test('linking a later post never moves a linked conversation', function () {
    $first = Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'root1']);
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

    Post::factory()->forAccount($this->account)->imported()->create(['platform_post_id' => 'root1']);

    expect(InboxConversation::query()->sole()->post_id)->toBe($first->id);
});

test('deleting the post unlinks its conversation', function () {
    $post = Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'root1']);
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

    $post->delete();

    expect(InboxConversation::query()->sole()->post_id)->toBeNull();
});

test('a direct message conversation is never linked to a post', function () {
    $account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
    fakeDirectMessageDriver();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'igsid-1']);

    UpsertInboxMessage::execute($account, inboxDirectIncoming('d1'));

    expect(InboxConversation::query()->sole()->post_id)->toBeNull();
});

test('a reply sent from the inbox is a TryPost message and an imported reply is a network one', function () {
    Queue::fake([SendInboxReply::class]);
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $imported = UpsertInboxMessage::execute($this->account, inboxIncoming('r1', 'c1', MessageDirection::Outbound));

    $reply = ReplyToInboxMessage::execute($comment, $this->user, 'Thanks!');

    expect($reply->fresh()->origin)->toBe(Origin::TryPost)
        ->and($imported->fresh()->origin)->toBe(Origin::Network)
        ->and($comment->fresh()->origin)->toBe(Origin::Network);
});

test('an imported echo of a reply sent from the inbox keeps the TryPost origin', function () {
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $reply = InboxMessage::factory()->sentFromTryPost()->for($comment->conversation, 'conversation')->create([
        'parent_id' => $comment->id,
        'root_id' => $comment->id,
        'platform_message_id' => 'r1',
        'platform_message_hash' => InboxConversation::hashOf('r1'),
        'sent_by_user_id' => $this->user->id,
    ]);

    UpsertInboxMessage::execute($this->account, inboxIncoming('r1', 'c1', MessageDirection::Outbound));

    expect($reply->fresh()->origin)->toBe(Origin::TryPost);
});

test('a direct message sent from the inbox is a TryPost message', function () {
    Queue::fake([SendInboxReply::class]);
    fakeDirectMessageDriver();
    $account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
    UpsertInboxMessage::execute($account, inboxDirectIncoming('d1', at: now()->subHour()->toDateTimeString()));

    $message = SendInboxDirectMessage::execute(InboxConversation::query()->sole(), $account->workspace->owner, 'Hello');

    expect($message->fresh()->origin)->toBe(Origin::TryPost);
});

test('the resources expose the linked post and the message origin', function () {
    $author = User::factory()->create(['name' => 'Paula Writer']);
    $post = Post::factory()->forAccount($this->account)->published()->create([
        'user_id' => $author->id,
        'media' => [['id' => 'm1', 'url' => 'https://cdn.test/a.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'path' => 'secret/a.mp4']],
    ]);
    $conversation = InboxConversation::factory()->linkedTo($post)->create();
    $message = InboxMessage::factory()->sentFromTryPost()->for($conversation, 'conversation')->create();

    $resource = InboxConversationResource::make($conversation->load('socialAccount', 'post.user'))->resolve();

    expect(data_get($resource, 'post_id'))->toBe($post->id)
        ->and(data_get($resource, 'post.origin'))->toBe('trypost')
        ->and(data_get($resource, 'post.created_by'))->toBe('Paula Writer')
        ->and(data_get($resource, 'post.created_at'))->toBe($post->created_at->toIso8601String())
        ->and(data_get($resource, 'post.media'))->toBe([['id' => 'm1', 'url' => 'https://cdn.test/a.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'meta' => ['cover_offset_ms' => null]]])
        ->and(data_get(InboxMessageResource::make($message)->resolve(), 'origin'))->toBe('trypost');
});

test('an imported post names no author', function () {
    $post = Post::factory()->forAccount($this->account)->imported()->create();
    $conversation = InboxConversation::factory()->linkedTo($post)->create();

    $resource = InboxConversationResource::make($conversation->load('socialAccount', 'post.user'))->resolve();

    expect(data_get($resource, 'post.origin'))->toBe('network')
        ->and(data_get($resource, 'post.created_by'))->toBeNull();
});

test('the inbox pages carry the linked post without a query per conversation', function () {
    $author = User::factory()->create(['name' => 'Paula Writer']);
    $post = Post::factory()->forAccount($this->account)->published()->create(['user_id' => $author->id]);
    InboxMessage::factory()->for(InboxConversation::factory()->linkedTo($post)->create(), 'conversation')->create();

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.data.0.post.created_by', 'Paula Writer')
            ->where('conversation.post.created_by', 'Paula Writer'));
    $this->actingAs($this->user)->get(route('app.inbox.messages.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.data.0.conversation.post_id', $post->id)
            ->where('messages.data.0.conversation.post.created_by', 'Paula Writer'));

    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'posts')) {
                $queries++;
            }
        });
        $this->actingAs($this->user)->get(route('app.inbox.index'))->assertOk();

        return $queries;
    };

    $baseline = $countQueries();

    foreach (range(1, 3) as $index) {
        $other = Post::factory()->forAccount($this->account)->published()->create(['user_id' => $author->id]);
        InboxMessage::factory()->for(InboxConversation::factory()->linkedTo($other)->create(), 'conversation')->create();
    }

    expect($countQueries())->toBe($baseline);
});
