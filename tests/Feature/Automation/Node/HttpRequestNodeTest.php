<?php

declare(strict_types=1);

use App\Actions\Automation\Node\RunHttpRequestNode;
use App\Enums\Automation\NodeRun\Status as NodeRunStatus;
use App\Jobs\Automation\ProcessAutomationNode;
use App\Models\Automation;
use App\Models\AutomationNodeState;
use App\Models\AutomationRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());
beforeEach(fn () => Bus::fake());

it('runs in single-response mode when items_path is empty', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response(['title' => 'Hello', 'id' => 42], 200),
    ]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/lookup',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    expect($result->output['fetched'])->toBe(['title' => 'Hello', 'id' => 42]);
    expect($result->output['fetch']['spawned'])->toBe(0);
});

it('blocks a request to a private or reserved address', function () {
    Http::fake();

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'http://127.0.0.1/internal',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    expect($result->error['reason'])->toBe('url_not_allowed');
    Http::assertNothingSent();
});

it('never follows a redirect that targets a private or internal host', function () {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('internal secret', 200),
    ]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://93.184.216.34/start',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('still follows a legitimate public-to-public redirect', function () {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 301, ['Location' => 'https://1.1.1.1/final']),
        'https://1.1.1.1/final' => Http::response(['ok' => true], 200),
    ]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://93.184.216.34/start',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), '93.184.216.34'),
        fn ($request) => str_contains($request->url(), '1.1.1.1'),
    ]);
});

it('processes first new item and spawns siblings when items_path is set', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake([
        '1.1.1.1/*' => Http::response([
            'items' => [
                ['id' => 'a1', 'published_at' => '2025-12-31T00:00:00Z'],
                ['id' => 'a2', 'published_at' => '2026-01-05T00:00:00Z'],
                ['id' => 'a3', 'published_at' => '2026-01-10T00:00:00Z'],
            ],
        ], 200),
    ]);

    $automation = Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'http_1', 'type' => 'http_request', 'position' => ['x' => 200, 'y' => 0], 'data' => []],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'http_1'],
            ['id' => 'e2', 'source' => 'http_1', 'target' => 'end_1'],
        ],
    ]);

    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'http_1',
        'data' => ['last_item_date' => '2025-12-30T00:00:00+00:00'],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/items',
        'method' => 'GET',
        'auth_type' => 'none',
        'items_path' => 'items',
        'item_date_path' => 'published_at',
    ]);

    expect($result->output['fetched']['id'])->toBe('a1');
    expect($result->output['fetch']['count'])->toBe(3);
    expect($result->output['fetch']['spawned'])->toBe(2);
    // Parent run + one sibling per remaining item.
    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(3);
    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 2);
});

it('records the baseline on the first date-dedup poll and emits nothing', function () {
    Carbon::setTestNow('2026-01-15 10:00:00');
    Http::fake([
        '1.1.1.1/*' => Http::response([
            'items' => [
                ['id' => 'a1', 'published_at' => '2026-01-05T00:00:00Z'],
                // Dated ahead of the server clock — must NOT leak on the first poll.
                ['id' => 'a2', 'published_at' => '2026-02-01T00:00:00Z'],
            ],
        ], 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/items',
        'method' => 'GET',
        'auth_type' => 'none',
        'items_path' => 'items',
        'item_date_path' => 'published_at',
    ]);

    expect($result->output['fetch']['count'])->toBe(0);
    Bus::assertNotDispatched(ProcessAutomationNode::class);

    $state = AutomationNodeState::for($automation->id, 'http_1');
    expect($state->data['last_item_date'])->toStartWith('2026-02-01');
});

it('sends bearer token header decrypting it on the fly', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/me',
        'method' => 'GET',
        'auth_type' => 'bearer',
        'auth_token' => Crypt::encryptString('secret-token-123'),
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token-123'));
});

it('sends api_key header with custom header name', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/me',
        'method' => 'GET',
        'auth_type' => 'api_key',
        'auth_header_name' => 'X-Custom-Key',
        'auth_token' => 'plain-text-key',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Custom-Key', 'plain-text-key'));
});

it('sends basic auth with a decrypted password', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/me',
        'method' => 'GET',
        'auth_type' => 'basic',
        'auth_username' => 'user',
        'auth_password' => Crypt::encryptString('pass'),
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('user:pass')));
});

it('supports PUT, PATCH and DELETE methods', function (string $method) {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/resource',
        'method' => $method,
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Completed);
    Http::assertSent(fn ($request) => $request->method() === $method);
})->with(['PUT', 'PATCH', 'DELETE']);

it('fails on a non-2xx response', function (int $status) {
    Http::fake(['1.1.1.1/*' => Http::response('nope', $status)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/resource',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    expect($result->error['status'])->toBe($status);
})->with([404, 500]);

it('fails when the request throws a connection exception', function () {
    Http::fake(fn () => throw new ConnectionException('connection timed out'));

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/resource',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Failed);
    expect($result->error['message'])->toContain('connection timed out');
});

it('fails when items_path does not resolve to an array', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['data' => 'not-a-list'], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/resource',
        'method' => 'GET',
        'auth_type' => 'none',
        'items_path' => 'data',
    ]);

    expect($result->status)->toBe(NodeRunStatus::Failed);
});

it('posts a body rendered from the template with run context', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create([
        'current_node_id' => 'http_1',
        'context' => ['trigger' => ['post' => ['id' => 'post-42']]],
    ]);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/notify',
        'method' => 'POST',
        'auth_type' => 'none',
        'body_template' => '{"post_id":"{{ trigger.post.id }}"}',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['post_id'] === 'post-42');
});

it('sends the branded user-agent header', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/me',
        'method' => 'GET',
        'auth_type' => 'none',
        'headers' => ['User-Agent' => 'user-supplied-agent'],
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', config('trypost.user_agent')));
});

it('sends custom headers configured in the editor', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/me',
        'method' => 'GET',
        'auth_type' => 'none',
        'headers' => ['X-Custom' => 'v'],
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Custom', 'v'));
});

it('fails when url is missing', function () {
    $automation = Automation::factory()->active()->create();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, []);

    expect($result->status)->toBe(NodeRunStatus::Failed);
});

it('iterates a top-level array with no items_path', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response([
            ['id' => 1, 'title' => 'a'],
            ['id' => 2, 'title' => 'b'],
            ['id' => 3, 'title' => 'c'],
        ], 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/posts',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->output['fetched'])->toBe(['id' => 1, 'title' => 'a']);
    expect($result->output['fetch']['count'])->toBe(3);
    expect($result->output['fetch']['spawned'])->toBe(2);
    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 2);
});

it('iterates a top-level object map via the wildcard items_path', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response([
            '16092' => ['id' => 16092, 'title' => 'a'],
            '14920' => ['id' => 14920, 'title' => 'b'],
        ], 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/posts',
        'method' => 'GET',
        'auth_type' => 'none',
        'items_path' => '*',
    ]);

    expect($result->output['fetched'])->toBe(['id' => 16092, 'title' => 'a']);
    expect($result->output['fetch']['spawned'])->toBe(1);
});

it('iterates a top-level array of primitives, passing each scalar as fetched', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response(['https://x/1', 'https://x/2', 'https://x/3'], 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/urls',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->output['fetched'])->toBe('https://x/1');
    expect($result->output['fetch']['spawned'])->toBe(2);
});

it('parses an NDJSON body into items', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response("{\"id\":1}\n{\"id\":2}\n{\"id\":3}", 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/stream',
        'method' => 'GET',
        'auth_type' => 'none',
    ]);

    expect($result->output['fetched'])->toBe(['id' => 1]);
    expect($result->output['fetch']['count'])->toBe(3);
});

it('records the baseline on the first key-dedup poll and emits nothing', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response([
            ['id' => 'p1'],
            ['id' => 'p2'],
            ['id' => 'p3'],
        ], 200),
    ]);

    $automation = httpAutomation();
    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/posts',
        'method' => 'GET',
        'auth_type' => 'none',
        'item_key_path' => 'id',
    ]);

    expect($result->output['fetch']['count'])->toBe(0);
    Bus::assertNotDispatched(ProcessAutomationNode::class);

    $state = AutomationNodeState::for($automation->id, 'http_1');
    expect($state->data['seen_keys'])->toHaveCount(3);
});

it('emits only unseen items on a later key-dedup poll', function () {
    $automation = httpAutomation();

    AutomationNodeState::create([
        'automation_id' => $automation->id,
        'node_id' => 'http_1',
        'data' => ['seen_keys' => [md5('p1'), md5('p2')]],
    ]);

    Http::fake([
        '1.1.1.1/*' => Http::response([
            ['id' => 'p1'],
            ['id' => 'p2'],
            ['id' => 'p3'],
        ], 200),
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['current_node_id' => 'http_1']);

    $result = app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/posts',
        'method' => 'GET',
        'auth_type' => 'none',
        'item_key_path' => 'id',
    ]);

    expect($result->output['fetched'])->toBe(['id' => 'p3']);
    expect($result->output['fetch']['count'])->toBe(1);
    expect($result->output['fetch']['spawned'])->toBe(0);

    $state = AutomationNodeState::for($automation->id, 'http_1');
    expect($state->data['seen_keys'])->toHaveCount(3);
});

function httpAutomation(): Automation
{
    return Automation::factory()->active()->create([
        'nodes' => [
            ['id' => 'trigger_1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'http_1', 'type' => 'http_request', 'position' => ['x' => 200, 'y' => 0], 'data' => []],
            ['id' => 'end_1', 'type' => 'end', 'position' => ['x' => 400, 'y' => 0], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'trigger_1', 'target' => 'http_1'],
            ['id' => 'e2', 'source' => 'http_1', 'target' => 'end_1'],
        ],
    ]);
}
