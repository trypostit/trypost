<?php

declare(strict_types=1);

use App\Actions\Post\Queue\BuildQueueTimeline;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => buildTimelineWeekdaySchedule(),
    ]);
    $this->until = CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC');
});

function buildTimelineWeekdaySchedule(): PostingSchedule
{
    $schedule = PostingSchedule::empty();

    foreach (range(1, 5) as $day) {
        $schedule = $schedule->withTime($day, '12:00')->withTime($day, '18:00');
    }

    return $schedule;
}

function buildTimelinePost(SocialAccount $channel, string $at, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $channel->workspace->user_id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse($at, 'UTC'),
    ], $attributes));

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id]);

    return $post;
}

function buildTimeline(object $test, array $labelIds = [], ?SocialAccount $channel = null, string $timezone = 'UTC'): array
{
    return BuildQueueTimeline::handle(
        $test->workspace,
        collect([$channel ?? $test->channel]),
        $timezone,
        $test->until,
        $labelIds,
    );
}

function buildTimelineSummary(array $groups): array
{
    return array_map(
        fn (array $group): array => [
            $group['date'] => array_map(fn (array $item): string => "{$item['type']}@".substr($item['at'], 11, 5), $group['items']),
        ],
        $groups,
    );
}

test('an empty channel shows its slots grouped by day', function () {
    $groups = buildTimeline($this);

    expect(array_column($groups, 'date'))->toBe(['2026-10-05', '2026-10-06', '2026-10-07'])
        ->and(array_sum(array_map(fn (array $group): int => count($group['items']), $groups)))->toBe(6)
        ->and(buildTimelineSummary($groups))->toBe([
            ['2026-10-05' => ['slot@12:00', 'slot@18:00']],
            ['2026-10-06' => ['slot@12:00', 'slot@18:00']],
            ['2026-10-07' => ['slot@12:00', 'slot@18:00']],
        ]);
});

test('a queued post replaces the slot it occupies', function () {
    $post = buildTimelinePost($this->channel, '2026-10-05 12:00:00');

    $monday = buildTimeline($this)[0];

    expect(buildTimelineSummary([$monday])[0]['2026-10-05'])->toBe(['post@12:00', 'slot@18:00'])
        ->and($monday['items'][0]['post_id'])->toBe($post->id)
        ->and($monday['items'][0]['channel_id'])->toBe($this->channel->id);
});

test('a custom post on a slot instant occupies that slot', function () {
    buildTimelinePost($this->channel, '2026-10-05 12:00:00', ['schedule_mode' => ScheduleMode::Custom]);

    expect(buildTimelineSummary([buildTimeline($this)[0]])[0]['2026-10-05'])->toBe(['post@12:00', 'slot@18:00']);
});

test('a queued post off the slot grid keeps its own time and both slots remain', function () {
    buildTimelinePost($this->channel, '2026-10-05 13:37:00');

    expect(buildTimelineSummary([buildTimeline($this)[0]])[0]['2026-10-05'])->toBe(['slot@12:00', 'post@13:37', 'slot@18:00']);
});

test('days are grouped in the display time zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC'));
    $channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'Pacific/Honolulu',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '20:00'),
    ]);
    $this->until = CarbonImmutable::parse('2026-10-07 00:00:00', 'UTC');

    $groups = buildTimeline($this, [], $channel, 'Pacific/Auckland');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['date'])->toBe('2026-10-06')
        ->and($groups[0]['items'][0]['at'])->toBe('2026-10-06T06:00:00+00:00');
});

test('a channel without a schedule shows posts and no slots', function () {
    $this->channel->update(['posting_schedule' => null]);
    buildTimelinePost($this->channel, '2026-10-06 09:00:00');

    expect(buildTimelineSummary(buildTimeline($this)))->toBe([['2026-10-06' => ['post@09:00']]]);
});

test('a label filter returns only labelled posts and no slots', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $labelled = buildTimelinePost($this->channel, '2026-10-06 09:00:00');
    buildTimelinePost($this->channel, '2026-10-06 10:00:00');
    $labelled->labels()->attach($label->id);

    $groups = buildTimeline($this, [$label->id]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['items'])->toHaveCount(1)
        ->and($groups[0]['items'][0]['post_id'])->toBe($labelled->id);
});

test('drafts and published posts never appear', function () {
    $this->channel->update(['posting_schedule' => null]);
    buildTimelinePost($this->channel, '2026-10-06 09:00:00', ['status' => PostStatus::Draft]);
    buildTimelinePost($this->channel, '2026-10-06 10:00:00', ['status' => PostStatus::Published]);

    expect(buildTimeline($this))->toBe([]);
});

test('posts on other channels, other workspaces or after until are excluded', function () {
    $this->channel->update(['posting_schedule' => null]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $foreignChannel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'timezone' => 'UTC']);

    buildTimelinePost($otherChannel, '2026-10-06 09:00:00');
    buildTimelinePost($foreignChannel, '2026-10-06 09:00:00');
    buildTimelinePost($this->channel, '2026-10-09 09:00:00');

    expect(buildTimeline($this))->toBe([]);
});
