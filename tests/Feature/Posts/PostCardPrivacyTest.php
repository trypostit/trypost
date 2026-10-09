<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    $this->owner = User::factory()->create([
        'timezone' => 'UTC',
        'registration_ip' => '203.0.113.7',
        'google_id' => 'google-owner',
        'github_id' => 'github-owner',
        'utm_source' => 'newsletter',
    ]);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval', [
        'timezone' => 'UTC',
        'registration_ip' => '198.51.100.9',
        'google_id' => 'google-requester',
    ]);
    $this->viewer = workspaceMember($this->workspace, 'member', ['timezone' => 'UTC']);
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
});

function privacyPost(SocialAccount $channel, User $author, array $attributes = []): Post
{
    return Post::factory()->forAccount($channel)->create([
        'user_id' => $author->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
        ...$attributes,
    ]);
}

function deferredPublishPayload(object $test, User $viewer, array $query): array
{
    $url = route('app.posts.index', $query);
    $page = $test->actingAs($viewer)->get($url)->assertOk()->viewData('page');

    $response = $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $page['version'],
        'X-Inertia-Partial-Component' => 'publish/Index',
        'X-Inertia-Partial-Data' => implode(',', data_get($page, 'deferredProps.default', ['posts'])),
    ])->get($url)->assertOk();

    $test->flushHeaders();

    return $response->json('props');
}

function assertNoUserPii(mixed $payload): void
{
    $keys = collect(Arr::dot(is_array($payload) ? $payload : []))->keys();

    foreach (['email', 'registration_ip', 'google_id', 'github_id', 'utm_source', 'account_id', 'current_workspace_id'] as $forbidden) {
        expect($keys->filter(fn (string $key): bool => str_ends_with($key, ".{$forbidden}") && str_contains($key, 'user'))->values()->all())->toBe([]);
    }

    expect(json_encode($payload))
        ->not->toContain('203.0.113.7')
        ->not->toContain('198.51.100.9')
        ->not->toContain('google-owner')
        ->not->toContain('google-requester');
}

test('publish page cards expose only the author name, id and photo', function () {
    privacyPost($this->channel, $this->owner);

    $props = deferredPublishPayload($this, $this->viewer, ['tab' => 'queue']);

    expect(array_keys(data_get($props, 'posts.data.0.user')))->toEqualCanonicalizing(['id', 'name', 'photo_url']);
    assertNoUserPii($props);
});

test('pending cards keep approval_requested_by as an id and leak no requester data', function () {
    privacyPost($this->channel, $this->owner, [
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $this->requester->id,
        'approval_requested_at' => now(),
    ]);

    $props = deferredPublishPayload($this, $this->requester, ['tab' => 'approvals']);

    expect(data_get($props, 'posts.data.0.approval_requested_by'))->toBe($this->requester->id);
    assertNoUserPii($props);
});

test('queue pending holders expose no requester data', function () {
    privacyPost($this->channel, $this->requester, [
        'status' => PostStatus::PendingApproval,
        'schedule_mode' => ScheduleMode::Queue,
        'approval_requested_by' => $this->requester->id,
        'approval_requested_at' => now(),
    ]);

    $props = deferredPublishPayload($this, $this->owner, ['tab' => 'queue', 'channels' => [$this->channel->id]]);

    expect(data_get($props, 'queue.pending.0.approval_requested_by'))->toBe($this->requester->id);
    expect(array_keys(data_get($props, 'queue.pending.0.user')))->toEqualCanonicalizing(['id', 'name', 'photo_url']);
    assertNoUserPii($props);
});

test('calendar cards expose only the author name, id and photo', function () {
    privacyPost($this->channel, $this->owner);
    privacyPost($this->channel, $this->owner, [
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $this->requester->id,
        'approval_requested_at' => now(),
    ]);

    $props = $this->actingAs($this->requester)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertOk()
        ->viewData('page')['props'];

    $card = collect(data_get($props, 'posts'))->flatten(1)->first();

    expect(array_keys(data_get($card, 'user')))->toEqualCanonicalizing(['id', 'name', 'photo_url']);
    assertNoUserPii($props['posts']);
});

test('the post group endpoint exposes only the author name, id and photo', function () {
    $post = privacyPost($this->channel, $this->owner);

    $response = $this->actingAs($this->viewer)->getJson(route('app.posts.group.show', $post))->assertOk();

    expect(array_keys($response->json('0.user')))->toEqualCanonicalizing(['id', 'name', 'photo_url']);
    assertNoUserPii($response->json());
});

test('editing a pending post keeps approval_requested_by as an id', function () {
    $post = privacyPost($this->channel, $this->requester, [
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $this->requester->id,
        'approval_requested_at' => now(),
    ]);

    $props = $this->actingAs($this->requester)
        ->get(route('app.posts.index', ['tab' => 'approvals', 'edit' => $post->id]))
        ->assertOk()
        ->viewData('page')['props'];

    expect(data_get($props, 'editPost.approval_requested_by'))->toBe($this->requester->id);
    assertNoUserPii($props['editPost']);
});

test('the calendar query count does not grow with the number of posts', function () {
    $count = function (int $posts): int {
        foreach (range(1, $posts) as $index) {
            privacyPost($this->channel, $index % 2 === 0 ? $this->owner : $this->requester, ['scheduled_at' => now()->addDay()->addMinutes($index)]);
            privacyPost($this->channel, $this->owner, [
                'status' => PostStatus::Published,
                'scheduled_at' => now()->subHours(2)->addMinutes($index),
                'published_at' => now()->subHours(2)->addMinutes($index),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        /** @var TestResponse $response */
        $response = $this->actingAs($this->owner)->get(route('app.calendar', ['view' => 'week']));
        $response->assertOk();
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    $count(1);
    $small = $count(1);
    $large = $count(6);

    expect($large)->toBe($small);
});
