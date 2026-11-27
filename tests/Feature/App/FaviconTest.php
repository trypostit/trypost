<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function faviconProviderUrl(string $domain): string
{
    return config('trypost.favicon.api')."/ip3/{$domain}.ico";
}

function faviconBytes(): string
{
    return str_repeat('A', 200);
}

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('a valid domain is fetched once, then served from the cache with the safety headers', function () {
    Http::fake([faviconProviderUrl('example.com') => Http::response(faviconBytes(), 200, ['Content-Type' => 'image/png'])]);

    $first = $this->actingAs($this->user)->get(route('app.favicon', 'example.com'));
    $second = $this->actingAs($this->user)->get(route('app.favicon', 'example.com'));

    $first->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Security-Policy', "script-src 'none'")
        ->assertHeader('Content-Disposition', 'attachment')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($first->headers->get('Cache-Control'))->toContain('max-age=2592000')
        ->and($second->getContent())->toBe(faviconBytes());
    Http::assertSentCount(1);
});

test('a definitive miss serves the default globe and is cached', function () {
    Http::fake([faviconProviderUrl('nothing.test') => Http::response('x', 200)]);

    $first = $this->actingAs($this->user)->get(route('app.favicon', 'nothing.test'));
    $this->actingAs($this->user)->get(route('app.favicon', 'nothing.test'));

    $first->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($first->getContent())->toContain('<circle');
    Http::assertSentCount(1);
});

test('an invalid domain gets the default without any request', function () {
    Http::fake();

    $response = $this->actingAs($this->user)->get(route('app.favicon', 'bad_host$!'));

    $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    Http::assertNothingSent();
});

test('a transient failure is served as the default but not cached', function () {
    Http::fake([faviconProviderUrl('flaky.test') => Http::response('', 503)]);

    $this->actingAs($this->user)->get(route('app.favicon', 'flaky.test'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->actingAs($this->user)->get(route('app.favicon', 'flaky.test'));

    Http::assertSentCount(2);
});

test('an svg mislabelled as an icon is served as svg', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat(' ', 150).'</svg>';
    Http::fake([faviconProviderUrl('vector.test') => Http::response($svg, 200, ['Content-Type' => 'image/x-icon'])]);

    $this->actingAs($this->user)->get(route('app.favicon', 'vector.test'))->assertHeader('Content-Type', 'image/svg+xml');
});

test('guests are redirected', function () {
    $this->get(route('app.favicon', 'example.com'))->assertRedirect();
});
