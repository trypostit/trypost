<?php

declare(strict_types=1);

use App\Actions\Automation\Node\RunFetchRssNode;
use App\Enums\Automation\NodeRun\Status as NodeRunStatus;
use App\Jobs\Automation\ProcessAutomationNode;
use App\Models\Automation;
use App\Models\AutomationNodeState;
use App\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());

beforeEach(fn () => Bus::fake());

it('first execution dispatches nothing and stores watermark from newest item', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_old'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'target' => 'end_1'],
        ],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->nextHandle)->toBe('no_items');

    $state = AutomationNodeState::where('automation_id', $automation->id)->where('node_id', 'fetch_1')->first();
    expect($state)->not->toBeNull();
    expect(CarbonImmutable::parse($state->data['last_item_date'])->toIso8601String())
        ->toBe(CarbonImmutable::parse('2025-02-01 12:00:00')->toIso8601String());
});

it('processes the first new item on the current run and spawns siblings for the rest', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_mixed'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'target' => 'end_1'],
        ],
    ]);

    // Pre-seed watermark so 'Older' is below it; 'Newer1' and 'Newer2' should pass.
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-02-01T12:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->output['fetched']['key'])->toBe('b'); // oldest of the new items
    expect($result->output['fetch']['count'])->toBe(2);
    expect($result->output['fetch']['spawned'])->toBe(1);

    // One sibling run created + dispatched at end_1
    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(2);
    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 1);
});

it('spawns sibling runs down the item edge for each remaining new item', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_three_new'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'generate_1', 'type' => 'generate', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'source_handle' => 'default', 'target' => 'generate_1'],
        ],
    ]);

    // Watermark predates all three items, so every item is "new".
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-01-01T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    // Current run handles item #1 (oldest); siblings handle #2 and #3.
    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->output['fetched']['key'])->toBe('x');
    expect($result->output['fetch']['count'])->toBe(3);
    expect($result->output['fetch']['spawned'])->toBe(2);

    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 2);
    Bus::assertDispatched(
        ProcessAutomationNode::class,
        fn (ProcessAutomationNode $job) => $job->nodeId === 'generate_1',
    );
});

it('fans every spawned item out across all branches wired to the fetch node', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_three_new'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'generate_1', 'type' => 'generate', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
            ['id' => 'webhook_1', 'type' => 'webhook', 'position' => ['x' => 400, 'y' => 200], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'source_handle' => 'default', 'target' => 'generate_1'],
            ['id' => 'e3', 'source' => 'fetch_1', 'source_handle' => 'default', 'target' => 'webhook_1'],
        ],
    ]);

    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-01-01T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    // 2 spawned items × 2 branches = 4 dispatches; both branches reached.
    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 4);
    Bus::assertDispatched(ProcessAutomationNode::class, fn ($job) => $job->nodeId === 'generate_1');
    Bus::assertDispatched(ProcessAutomationNode::class, fn ($job) => $job->nodeId === 'webhook_1');
});

it('does not persist the production watermark on a manual real-data test', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_mixed'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'target' => 'end_1'],
        ],
    ]);

    // Watermark in the FUTURE: a production run would see zero new items here.
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2030-01-01T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create([
        'current_node_id' => 'fetch_1',
        'is_manual' => true,
        'is_dry_run' => false,
    ]);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    // A manual test IGNORES the watermark, so it always surfaces real data even
    // when production would have already consumed every item.
    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->output['fetch']['count'])->toBeGreaterThan(0);

    // ...surfaces the NEWEST item (key 'c', 15 Jun) — not the oldest — so the
    // test reflects what the user just published, and spawns no siblings.
    expect($result->output['fetched']['key'])->toBe('c');
    expect($result->output['fetch']['spawned'])->toBe(0);

    // ...and never advances the persisted watermark.
    $state = AutomationNodeState::for($automation->id, 'fetch_1');
    expect($state->data['last_item_date'])->toBe('2030-01-01T00:00:00+00:00');
});

it('advances the production watermark on a non-manual real-data run', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_mixed'), 200)]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'fetch_1', 'type' => 'fetch_rss', 'position' => ['x' => 200, 'y' => 0], 'data' => ['feed_url' => 'https://1.1.1.1/feed']],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'fetch_1'],
            ['id' => 'e2', 'source' => 'fetch_1', 'target' => 'end_1'],
        ],
    ]);

    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-01-01T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create([
        'current_node_id' => 'fetch_1',
        'is_manual' => false,
        'is_dry_run' => false,
    ]);

    app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    $state = AutomationNodeState::for($automation->id, 'fetch_1');
    expect(CarbonImmutable::parse($state->data['last_item_date'])->toIso8601String())
        ->toBe(CarbonImmutable::parse('Sun, 15 Jun 2025 12:00:00 +0000')->toIso8601String());
});

it('processes an Atom feed (YouTube) that the old RSS-only parser dropped', function () {
    Carbon::setTestNow('2026-06-16 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('youtube_atom'), 200)]);

    $automation = Automation::factory()->active()->create();

    // Watermark predates the single entry, so it counts as new.
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2026-01-01T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->output['fetch']['count'])->toBe(1);
    expect($result->output['fetched']['title'])->toBe('Ninguém aguenta o TikTok Shop');
    expect($result->output['fetched']['link'])->toBe('https://www.youtube.com/watch?v=bIQVeW4sTcE');
    expect($result->output['fetched']['yt_videoId'])->toBe('bIQVeW4sTcE');
});

it('fails when feed_url is missing', function () {
    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, []);

    expect($result->status)->toBe(NodeRunStatus::Failed);
});

it('fails when the feed request returns a non-2xx status', function () {
    Http::fake(['1.1.1.1/*' => Http::response('upstream down', 500)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed.xml']);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    expect($result->error['message'])->toContain('500');
});

it('fails on a malformed RSS feed', function () {
    Http::fake(['1.1.1.1/*' => Http::response('this is not xml at all', 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed.xml']);

    expect($result->status)->toBe(NodeRunStatus::Failed);
});

it('skips items without a publish date', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_dateless'), 200)]);

    $automation = Automation::factory()->active()->create();
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-02-01T12:00:00+00:00'],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    // Only the dated item passes; the dateless one is ignored.
    expect($result->output['fetch']['count'])->toBe(1);
    expect($result->output['fetched']['key'])->toBe('d');
});

it('never follows a feed redirect that targets a private or internal host', function () {
    Http::fake([
        'https://93.184.216.34/feed' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response(feedFixture('rss_old'), 200),
    ]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://93.184.216.34/feed']);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('still follows a legitimate public-to-public feed redirect', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake([
        'https://93.184.216.34/feed' => Http::response('', 301, ['Location' => 'https://1.1.1.1/feed']),
        'https://1.1.1.1/feed' => Http::response(feedFixture('rss_old'), 200),
    ]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://93.184.216.34/feed']);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), '93.184.216.34'),
        fn ($request) => str_contains($request->url(), '1.1.1.1'),
    ]);
});

it('falls back to the link as the dedup key when an item has no guid', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('rss_no_guid'), 200)]);

    $automation = Automation::factory()->active()->create();
    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'fetch_1',
        'data' => ['last_item_date' => '2025-02-01T12:00:00+00:00'],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'fetch_1']);

    $result = app(RunFetchRssNode::class)($run, ['feed_url' => 'https://1.1.1.1/feed']);

    expect($result->output['fetched']['key'])->toBe('https://1.1.1.1/only-link');
});
