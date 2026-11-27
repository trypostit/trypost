<?php

declare(strict_types=1);

use App\Enums\User\TimeFormat;
use App\Mail\PostAtRisk;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

function postAtRiskRecipient(array $attributes = []): User
{
    return User::factory()->create(['timezone' => 'UTC', 'time_format' => TimeFormat::TwentyFourHour, ...$attributes]);
}

test('renders subject and body listing the at-risk account and its post times', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->setTime(14, 30),
    ]);

    $mailable = new PostAtRisk($workspace, [$post->id], 1, postAtRiskRecipient());

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $mailable->assertSeeInHtml('Posts May Fail to Publish');
    $mailable->assertSeeInHtml('Acme Co');
    $mailable->assertSeeInHtml('1 post scheduled: 14:30 (UTC)');
});

test('renders plural subject and postsLabel when multiple posts are at risk', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);

    $postIds = collect([
        ['time' => [14, 30]],
        ['time' => [15, 0]],
    ])->map(function (array $data) use ($account) {
        return Post::factory()->forAccount($account)->scheduled()->create([
            'scheduled_at' => now()->setTime(...$data['time']),
        ])->id;
    })->all();

    $mailable = new PostAtRisk($workspace, $postIds, 2, postAtRiskRecipient());

    $mailable->assertHasSubject('2 posts are at risk in Acme Co');
    $mailable->assertSeeInHtml('2 posts scheduled: 14:30, 15:00 (UTC)');
});

test('groups posts by account when rehydrating for send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $threadsAccount = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $facebookAccount = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);

    $threadsPost = Post::factory()->forAccount($threadsAccount)->scheduled()->create([
        'scheduled_at' => now()->setTime(9, 0),
    ]);

    $facebookPost = Post::factory()->forAccount($facebookAccount)->scheduled()->create([
        'scheduled_at' => now()->setTime(10, 0),
    ]);

    $mailable = new PostAtRisk($workspace, [$threadsPost->id, $facebookPost->id], 2, postAtRiskRecipient());

    $mailable->assertHasSubject('2 posts are at risk in Acme Co');
    $mailable->assertSeeInHtml('1 post scheduled: 09:00 (UTC)');
    $mailable->assertSeeInHtml('1 post scheduled: 10:00 (UTC)');
});

test('only carries the workspace, post IDs, and count on the queue payload, not full model graphs', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'access_token' => 'super-secret-token-value',
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->setTime(14, 30),
    ]);

    $mailable = new PostAtRisk($workspace, [$post->id], 1, postAtRiskRecipient());

    $serialized = serialize($mailable);

    expect($serialized)->not->toContain('super-secret-token-value')
        ->and($serialized)->not->toContain(SocialAccount::class)
        ->and($serialized)->not->toContain(Post::class)
        ->and(strlen($serialized))->toBeLessThan(2000);
});

test('footer links to notification preferences instead of an unsubscribe link', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->setTime(14, 30),
    ]);

    $mailable = new PostAtRisk($workspace, [$post->id], 1, postAtRiskRecipient());

    $mailable->assertSeeInHtml('Manage notifications');
    $mailable->assertSeeInHtml(route('app.notifications.preferences'));
    $mailable->assertDontSeeInHtml('Unsubscribe');
});

test('subject and previewText stay locked to the dispatch-time count even if a row disappears before send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->setTime(14, 30),
    ]);

    $mailable = new PostAtRisk($workspace, [$post->id], 1, postAtRiskRecipient());

    // Simulate the row disappearing between when this mailable was
    // dispatched and when it's actually rendered for send — the count
    // passed at construction (matching the in-app notification's title)
    // must not be recomputed from the now-stale rehydrated rows.
    $post->delete();

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $content = $mailable->content();
    expect($content->with['previewText'])->toBe('1 post is at risk in Acme Co');
});

test('renders without crashing when none of the post ids resolve', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);

    $mailable = new PostAtRisk($workspace, ['00000000-0000-0000-0000-000000000000'], 0, postAtRiskRecipient());

    $mailable->assertHasSubject('0 posts are at risk in Acme Co');
});

test('renders without crashing when the account is deleted before send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->setTime(14, 30),
    ]);

    $mailable = new PostAtRisk($workspace, [$post->id], 1, postAtRiskRecipient());

    // Deleting the account (not the post) between dispatch and
    // send: the FK's nullOnDelete sets posts.social_account_id to
    // null, so the rehydrated group's account resolves to null. There's
    // nothing meaningful to render for it (no platform, no handle), so it
    // must be dropped from the body rather than crash the render.
    $account->delete();

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $mailable->assertDontSeeInHtml('scheduled: 14:30 (UTC)');
});

test('lists the times in the recipient zone and clock and names the zone', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 05:30', 'UTC'),
    ]);
    $owner = postAtRiskRecipient(['timezone' => 'Asia/Tokyo', 'time_format' => TimeFormat::TwelveHour]);

    (new PostAtRisk($workspace, [$post->id], 1, $owner))->assertSeeInHtml('1 post scheduled: 2:30 PM (Asia/Tokyo)');
});

test('a payload queued without a recipient renders for the workspace owner', function () {
    $owner = postAtRiskRecipient(['timezone' => 'Asia/Tokyo', 'time_format' => TimeFormat::TwelveHour]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => CarbonImmutable::parse('2037-01-05 05:30', 'UTC'),
    ]);

    $mailable = unserialize(serialize(new PostAtRisk($workspace, [$post->id], 1)));

    $mailable->assertSeeInHtml('1 post scheduled: 2:30 PM (Asia/Tokyo)');
});
