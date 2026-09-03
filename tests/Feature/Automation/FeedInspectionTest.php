<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Automation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->refresh();
});

it('returns the discovered fields for a feed', function () {
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('youtube_atom'), 200)]);

    $automation = Automation::factory()->for($this->workspace)->create();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.automations.feed.inspect', $automation->id), [
            'feed_url' => 'https://1.1.1.1/feed.xml',
        ]);

    $response->assertOk();

    $paths = collect($response->json('fields'))->pluck('path');
    expect($paths)->toContain('fetched.title')
        ->toContain('fetched.link')
        ->toContain('fetched.yt_videoId')
        ->toContain('fetched.media_group.media_thumbnail.url');
});

it('resolves workspace variables in the feed url before fetching', function () {
    Http::fake(['1.1.1.1/*' => Http::response(feedFixture('youtube_atom'), 200)]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'HOST', 'value' => '1.1.1.1']],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.automations.feed.inspect', $automation->id), [
            'feed_url' => 'https://{{ variables.HOST }}/feed.xml',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '1.1.1.1/feed.xml'));
});

it('returns 422 for a malformed feed', function () {
    Http::fake(['1.1.1.1/*' => Http::response('not a feed', 200)]);

    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->postJson(route('app.automations.feed.inspect', $automation->id), [
            'feed_url' => 'https://1.1.1.1/feed.xml',
        ])
        ->assertStatus(422);
});

it('validates that feed_url is required', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->postJson(route('app.automations.feed.inspect', $automation->id), [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('feed_url');
});

it('persists discovered_fields stored on a fetch_rss node through an update', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'fetch_1',
                'type' => 'fetch_rss',
                'position' => ['x' => 0, 'y' => 0],
                'data' => [
                    'feed_url' => 'https://example.com/feed.xml',
                    'discovered_fields' => [
                        ['path' => 'fetched.yt_videoId', 'sample' => 'abc123'],
                    ],
                ],
            ]],
        ])
        ->assertRedirect();

    $node = collect($automation->fresh()->nodes)->firstWhere('id', 'fetch_1');
    expect(data_get($node, 'data.discovered_fields.0.path'))->toBe('fetched.yt_videoId');
});

it('accepts a feed_url containing a workflow-variable expression', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'fetch_1',
                'type' => 'fetch_rss',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['feed_url' => 'https://www.youtube.com/feeds/videos.xml?channel_id={{ variables.CHANNEL_ID }}'],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('rejects a feed_url that is not a URL even after stripping expressions', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'fetch_1',
                'type' => 'fetch_rss',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['feed_url' => 'not a url {{ variables.X }}'],
            ]],
        ])
        ->assertSessionHasErrors('nodes.0.data.feed_url');
});

it('rejects a non-http(s) scheme in feed_url', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'fetch_1',
                'type' => 'fetch_rss',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['feed_url' => 'file:///etc/passwd'],
            ]],
        ])
        ->assertSessionHasErrors('nodes.0.data.feed_url');
});

it('accepts a templated url on an http_request node', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'http_1',
                'type' => 'http_request',
                'position' => ['x' => 0, 'y' => 0],
                'data' => [
                    'url' => 'https://api.example.com/{{ variables.PATH }}/feed',
                    'method' => 'GET',
                    'auth_type' => 'none',
                ],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('accepts a templated url on an HTTP request node', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'nodes' => [[
                'id' => 'http_1',
                'type' => 'http_request',
                'position' => ['x' => 0, 'y' => 0],
                'data' => [
                    'url' => 'https://hooks.example.com/{{ variables.TOKEN }}',
                    'method' => 'POST',
                    'auth_type' => 'none',
                ],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('forbids inspecting a feed for an automation in another workspace', function () {
    Http::fake(['1.1.1.1/*' => Http::response('<rss version="2.0"><channel></channel></rss>', 200)]);

    $automation = Automation::factory()->for(Workspace::factory()->create())->create();

    $this->actingAs($this->user)
        ->postJson(route('app.automations.feed.inspect', $automation->id), [
            'feed_url' => 'https://1.1.1.1/feed.xml',
        ])
        ->assertForbidden();
});
