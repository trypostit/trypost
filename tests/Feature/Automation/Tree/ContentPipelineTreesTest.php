<?php

declare(strict_types=1);

use App\Actions\Automation\TriggerItem\EnrollTriggerItem;
use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Models\Automation;
use App\Models\AutomationNodeState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Image\PostImagePipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());

/**
 * Bind a no-op image pipeline so Generate never touches real image generation.
 */
$fakeImagePipeline = function (): void {
    $pipeline = Mockery::mock(PostImagePipeline::class);
    $pipeline->shouldReceive('forSingle')->andReturn([]);
    $pipeline->shouldReceive('forCarousel')->andReturn([]);
    app()->instance(PostImagePipeline::class, $pipeline);
};

$fakeContentAgents = function (string $content = 'Generated post body'): void {
    PostContentGenerator::fake([
        ['content' => $content, 'image_title' => 'Title', 'image_body' => 'Body', 'image_keywords' => ['kw']],
    ]);

    PostContentHumanizer::fake([
        ['content' => $content, 'image_title' => 'Title', 'image_body' => 'Body'],
    ]);
};

/**
 * Build an RSS feed body with a single item published at the given date.
 */
$rssFeed = function (string $title, string $guid, string $pubDate): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
  <item><title>{$title}</title><link>https://1.1.1.1/{$guid}</link><guid>{$guid}</guid><pubDate>{$pubDate}</pubDate></item>
</channel></rss>
XML;
};

it('runs cron → generate → publish to completion and creates a draft post', function () use ($fakeImagePipeline, $fakeContentAgents) {
    $fakeImagePipeline();
    $fakeContentAgents('Post about cron trigger');

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->for($workspace)->create();

    $automation = Automation::factory()->for($workspace)->active()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule', 'cron' => '0 9 * * *']],
            ['id' => 'g', 'type' => 'generate', 'position' => ['x' => 1, 'y' => 0], 'data' => [
                'accounts' => [
                    ['social_account_id' => (string) $account->id, 'content_type' => 'instagram_feed', 'meta' => []],
                ],
                'prompt_template' => 'Write something',
                'target_slide_count' => 0,
            ]],
            ['id' => 'p', 'type' => 'publish', 'position' => ['x' => 2, 'y' => 0], 'data' => ['mode' => 'draft']],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'g'],
            ['id' => 'e2', 'source' => 'g', 'target' => 'p'],
        ],
    ]);

    app(EnrollTriggerItem::class)($automation, 'cron-item-1', ['topic' => 'launch']);

    $run = $automation->runs()->latest()->first();

    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->generated_post_id)->not->toBeNull();

    $post = Post::find($run->generated_post_id);
    expect($post)->not->toBeNull();
    expect($post->content)->toBe('Post about cron trigger');
});

it('runs fetch_rss → generate → delay → publish, pausing at the delay then resuming via the command', function () use ($fakeImagePipeline, $fakeContentAgents, $rssFeed) {
    Carbon::setTestNow('2026-01-15 10:00:00');
    $fakeImagePipeline();
    $fakeContentAgents('Post from RSS item');

    Http::fake([
        '1.1.1.1/*' => Http::response($rssFeed('Fresh News', 'fresh-1', 'Wed, 14 Jan 2026 12:00:00 +0000'), 200),
    ]);

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->for($workspace)->create();

    $automation = Automation::factory()->for($workspace)->active()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'f', 'type' => 'fetch_rss', 'position' => ['x' => 1, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'g', 'type' => 'generate', 'position' => ['x' => 2, 'y' => 0], 'data' => [
                'accounts' => [
                    ['social_account_id' => (string) $account->id, 'content_type' => 'instagram_feed', 'meta' => []],
                ],
                'prompt_template' => 'Write about {{ fetched.title }}',
                'target_slide_count' => 0,
            ]],
            ['id' => 'd', 'type' => 'delay', 'position' => ['x' => 3, 'y' => 0], 'data' => ['duration' => 2, 'unit' => 'hours']],
            ['id' => 'p', 'type' => 'publish', 'position' => ['x' => 4, 'y' => 0], 'data' => ['mode' => 'draft']],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'f'],
            ['id' => 'e2', 'source' => 'f', 'source_handle' => 'default', 'target' => 'g'],
            ['id' => 'e3', 'source' => 'g', 'target' => 'd'],
            ['id' => 'e4', 'source' => 'd', 'target' => 'p'],
        ],
    ]);

    // Watermark before the feed item so it counts as new.
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'f',
        'data' => ['last_item_date' => '2026-01-01T00:00:00+00:00'],
    ]);

    app(EnrollTriggerItem::class)($automation, 'rss-item-1', []);

    $run = $automation->runs()->latest()->first();

    // The run generated a post then hit the delay and is now waiting.
    expect($run->status)->toBe(RunStatus::Waiting);
    expect($run->current_node_id)->toBe('d');
    expect($run->generated_post_id)->not->toBeNull();

    // Move past the delay window and resume.
    Carbon::setTestNow('2026-01-15 13:00:00');
    $this->artisan('automation:process-delays')->assertSuccessful();

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Completed);

    $post = Post::find($run->generated_post_id);
    expect($post)->not->toBeNull();
    expect($post->content)->toBe('Post from RSS item');
});

it('runs http_request → condition (true) → notify down the yes handle', function () {
    Http::fake([
        '8.8.8.8/*' => Http::response(['status' => 'active'], 200),
        '9.9.9.9/*' => Http::response(['ok' => true], 200),
    ]);

    $workspace = Workspace::factory()->create();

    $automation = Automation::factory()->for($workspace)->active()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'h', 'type' => 'http_request', 'position' => ['x' => 1, 'y' => 0], 'data' => ['url' => 'https://8.8.8.8/status', 'method' => 'GET']],
            ['id' => 'c', 'type' => 'condition', 'position' => ['x' => 2, 'y' => 0], 'data' => ['field' => '{{ fetched.status }}', 'operator' => 'equals', 'value' => 'active']],
            ['id' => 'w', 'type' => 'http_request', 'position' => ['x' => 3, 'y' => 0], 'data' => ['url' => 'https://9.9.9.9/notify', 'method' => 'POST', 'auth_type' => 'none', 'body_template' => '{"ok":true}']],
            ['id' => 'e', 'type' => 'end', 'position' => ['x' => 3, 'y' => 1], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'h'],
            ['id' => 'e2', 'source' => 'h', 'target' => 'c'],
            ['id' => 'e3', 'source' => 'c', 'source_handle' => 'yes', 'target' => 'w'],
            ['id' => 'e4', 'source' => 'c', 'source_handle' => 'no', 'target' => 'e'],
        ],
    ]);

    app(EnrollTriggerItem::class)($automation, 'http-true-1', []);

    $run = $automation->runs()->latest()->first();

    expect($run->status)->toBe(RunStatus::Completed);
    Http::assertSent(fn ($request) => str_contains($request->url(), '9.9.9.9/notify'));
});

it('runs http_request → condition (false) → end down the no handle without hitting the notify request', function () {
    Http::fake([
        '8.8.8.8/*' => Http::response(['status' => 'inactive'], 200),
        '9.9.9.9/*' => Http::response(['ok' => true], 200),
    ]);

    $workspace = Workspace::factory()->create();

    $automation = Automation::factory()->for($workspace)->active()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'h', 'type' => 'http_request', 'position' => ['x' => 1, 'y' => 0], 'data' => ['url' => 'https://8.8.8.8/status', 'method' => 'GET']],
            ['id' => 'c', 'type' => 'condition', 'position' => ['x' => 2, 'y' => 0], 'data' => ['field' => '{{ fetched.status }}', 'operator' => 'equals', 'value' => 'active']],
            ['id' => 'w', 'type' => 'http_request', 'position' => ['x' => 3, 'y' => 0], 'data' => ['url' => 'https://9.9.9.9/notify', 'method' => 'POST', 'auth_type' => 'none', 'body_template' => '{"ok":true}']],
            ['id' => 'e', 'type' => 'end', 'position' => ['x' => 3, 'y' => 1], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'h'],
            ['id' => 'e2', 'source' => 'h', 'target' => 'c'],
            ['id' => 'e3', 'source' => 'c', 'source_handle' => 'yes', 'target' => 'w'],
            ['id' => 'e4', 'source' => 'c', 'source_handle' => 'no', 'target' => 'e'],
        ],
    ]);

    app(EnrollTriggerItem::class)($automation, 'http-false-1', []);

    $run = $automation->runs()->latest()->first();

    expect($run->status)->toBe(RunStatus::Completed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '9.9.9.9/notify'));
});

it('runs fetch_rss → (no_items) → end when the feed yields no new items and creates no post', function () use ($rssFeed) {
    Carbon::setTestNow('2026-01-15 10:00:00');

    Http::fake([
        '1.1.1.1/*' => Http::response($rssFeed('Stale', 'stale-1', 'Mon, 01 Jan 2024 12:00:00 +0000'), 200),
    ]);

    $workspace = Workspace::factory()->create();

    $automation = Automation::factory()->for($workspace)->active()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'f', 'type' => 'fetch_rss', 'position' => ['x' => 1, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'g', 'type' => 'generate', 'position' => ['x' => 2, 'y' => 0], 'data' => ['prompt_template' => 'x', 'target_slide_count' => 0]],
            ['id' => 'e', 'type' => 'end', 'position' => ['x' => 2, 'y' => 1], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'f'],
            ['id' => 'e2', 'source' => 'f', 'source_handle' => 'default', 'target' => 'g'],
            ['id' => 'e3', 'source' => 'f', 'source_handle' => 'no_items', 'target' => 'e'],
        ],
    ]);

    // Watermark newer than the feed's only item so zero items are new.
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'f',
        'data' => ['last_item_date' => '2025-12-01T00:00:00+00:00'],
    ]);

    app(EnrollTriggerItem::class)($automation, 'rss-empty-1', []);

    $run = $automation->runs()->latest()->first();

    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->generated_post_id)->toBeNull();
    expect(Post::count())->toBe(0);
});
