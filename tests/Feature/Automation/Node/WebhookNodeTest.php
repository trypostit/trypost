<?php

declare(strict_types=1);

use App\Actions\Automation\Node\RunWebhookNode;
use App\Enums\Automation\NodeRun\Status;
use App\Models\AutomationRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('posts interpolated payload to the configured url', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response(['ok' => true], 200),
    ]);

    $run = AutomationRun::factory()->create([
        'context' => ['trigger' => ['title' => 'Hello'], 'generated' => ['post_url' => 'https://t.it/p/1']],
    ]);

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'headers' => ['X-Source' => 'TryPost'],
        'payload_template' => '{"title":"{{ trigger.title }}","post_url":"{{ generated.post_url }}"}',
    ]);

    expect($result->status)->toBe(Status::Completed);
    Http::assertSent(fn ($request) => $request['title'] === 'Hello' && $request['post_url'] === 'https://t.it/p/1');
});

it('sends the branded user-agent header', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response(['ok' => true], 200),
    ]);

    $run = AutomationRun::factory()->create();

    app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'headers' => ['User-Agent' => 'user-supplied-agent'],
        'payload_template' => '{}',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', config('trypost.user_agent')));
});

it('escapes special characters in templated payload values so the JSON stays valid', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create([
        'context' => ['fetched' => ['title' => 'He said "hi" & bye']],
    ]);

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/hook',
        'method' => 'POST',
        'payload_template' => '{"title":"{{ fetched.title }}"}',
    ]);

    expect($result->status)->toBe(Status::Completed);
    Http::assertSent(fn ($request) => $request['title'] === 'He said "hi" & bye');
});

it('fails on 5xx response', function () {
    Http::fake(['1.1.1.1/*' => Http::response('err', 500)]);

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    expect($result->status)->toBe(Status::Failed);
});

it('fails on malformed payload json instead of silently sending an empty body', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'payload_template' => '{ "a": }',
    ]);

    expect($result->status)->toBe(Status::Failed);
    expect($result->error['reason'])->toBe('invalid_payload_json');
    Http::assertNothingSent();
});

it('does not fire a real request on a dry run', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create(['is_dry_run' => true]);

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'payload_template' => '{"a":1}',
    ]);

    expect($result->status)->toBe(Status::Completed);
    expect($result->output['webhook']['dry_run'])->toBeTrue();
    Http::assertNothingSent();
});

it('still validates payload json on a dry run', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create(['is_dry_run' => true]);

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'payload_template' => '{ "a": }',
    ]);

    expect($result->status)->toBe(Status::Failed);
    expect($result->error['reason'])->toBe('invalid_payload_json');
    Http::assertNothingSent();
});

it('fails with a clear error when the url is missing', function () {
    Http::fake();

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    expect($result->status)->toBe(Status::Failed);
    expect($result->error['reason'])->toBe('missing_url');
    Http::assertNothingSent();
});

it('blocks a request to a private or reserved address', function () {
    Http::fake();

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'http://169.254.169.254/latest/meta-data/',
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    expect($result->status)->toBe(Status::Failed);
    expect($result->error['reason'])->toBe('url_not_allowed');
    Http::assertNothingSent();
});

it('treats 4xx responses as completed (only 5xx fails)', function () {
    Http::fake(['1.1.1.1/*' => Http::response('not found', 404)]);

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/test',
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    expect($result->status->value)->toBe('completed');
    expect($result->output['webhook']['status'])->toBe(404);
});

it('sends the configured HTTP method', function (string $method) {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/hook',
        'method' => $method,
        'payload_template' => '{}',
    ]);

    expect($result->status)->toBe(Status::Completed);
    Http::assertSent(fn ($request) => $request->method() === $method);
})->with(['PUT', 'PATCH', 'DELETE', 'GET']);

it('resolves expressions in custom header values', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $run = AutomationRun::factory()->create(['context' => ['trigger' => ['title' => 'tok-123']]]);

    app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/hook',
        'method' => 'POST',
        'headers' => ['X-Token' => '{{ trigger.title }}'],
        'payload_template' => '{}',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'tok-123'));
});

it('never follows a redirect to a private or internal host', function () {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('internal secret', 200),
    ]);

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://93.184.216.34/hook',
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    // The 3xx is returned as-is (not followed), so the node completes with the
    // redirect status rather than the internal host's response.
    expect($result->status)->toBe(Status::Completed);
    expect($result->output['webhook']['status'])->toBe(302);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('fails cleanly when the request throws a connection exception', function () {
    Http::fake(fn () => throw new ConnectionException('connection timed out'));

    $run = AutomationRun::factory()->create();

    $result = app(RunWebhookNode::class)($run, [
        'url' => 'https://1.1.1.1/hook',
        'method' => 'POST',
        'payload_template' => '{}',
    ]);

    expect($result->status)->toBe(Status::Failed);
    expect($result->error['reason'])->toBe('request_failed');
    expect($result->error['message'])->toContain('connection timed out');
});
