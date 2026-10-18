<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\GetInboxScoreTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 15:00:00', 'UTC'));
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->user->update(['timezone' => 'Europe/Lisbon']);
    $this->account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $this->other = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $elsewhere = InboxConversation::factory()->create(['social_account_id' => $this->other->id]);
    InboxMessage::factory()->for($conversation, 'conversation')->count(6)->create(['platform_created_at' => now()->subHours(5), 'answered_at' => now()->subHours(4)]);
    InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_created_at' => now()->subDays(3)]);
    InboxMessage::factory()->for($elsewhere, 'conversation')->create(['platform_created_at' => now()->subDay(), 'answered_at' => now()]);
});

test('the score is the same on web, api and mcp', function (bool $scoped) {
    $web = $this->actingAs($this->user)->get(route('app.inbox.messages.index', $scoped ? ['channels' => [$this->account->id]] : []));
    $webScore = null;
    $web->assertOk()->assertInertia(function (AssertableInertia $page) use (&$webScore) {
        $page->loadDeferredProps(function (AssertableInertia $reload) use (&$webScore) {
            $webScore = data_get($reload->toArray(), 'props.commentScore');
        });
    });
    auth()->forgetGuards();

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.inbox.score.show', $scoped ? ['channels' => [$this->account->id]] : []))
        ->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)
        ->tool(GetInboxScoreTool::class, $scoped ? ['channel_ids' => [$this->account->id]] : [])
        ->assertOk()
        ->assertStructuredContent($api->json());

    expect($webScore)->toEqual($api->json())
        ->and($api->json('comments_count'))->toBe($scoped ? 7 : 8)
        ->and($api->json('score'))->not->toBeNull();
})->with(['every channel' => false, 'one channel' => true]);

test('a channel of another workspace is refused on api and mcp', function () {
    $foreign = SocialAccount::factory()->bluesky()->create();

    $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.score.show', ['channels' => [$foreign->id]]))->assertUnprocessable();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(GetInboxScoreTool::class, ['channel_ids' => [$foreign->id]])->assertHasErrors();
});
