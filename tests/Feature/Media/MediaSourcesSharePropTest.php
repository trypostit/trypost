<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Services\UnsplashService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
});

function mediaSourcesEnableAll(): void
{
    config()->set([
        'services.unsplash.access_key' => 'SENTINEL-UNSPLASH-KEY',
        'services.unsplash.secret_key' => 'SENTINEL-UNSPLASH-SECRET',
        'services.google_media.client_id' => 'google-client-id',
        'services.google_media.client_secret' => 'SENTINEL-GOOGLE-SECRET',
        'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback',
        'services.google_media.api_key' => 'google-api-key',
        'services.google_media.app_id' => 'google-app-id',
        'services.canva.client_id' => 'SENTINEL-CANVA-ID',
        'services.canva.client_secret' => 'SENTINEL-CANVA-SECRET',
        'trypost.media_sources.google_drive.enabled' => true,
        'trypost.media_sources.google_photos.enabled' => true,
        'trypost.media_sources.canva.enabled' => true,
    ]);
}

test('the prop lists only enabled sources in menu order', function () {
    mediaSourcesEnableAll();
    config()->set('trypost.media_sources.google_photos.enabled', false);
    config()->set('services.unsplash.access_key', null);

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->has('mediaSources.menu', 2)
            ->where('mediaSources.menu.0.source', 'canva')
            ->where('mediaSources.menu.0.label', 'Canva')
            ->where('mediaSources.menu.1.source', 'google_drive')
            ->where('mediaSources.menu.1.config.app_id', 'google-app-id')
            ->missing('mediaSources.menu.1.config.client_id')
        );
});

test('the prop is null for a guest', function () {
    mediaSourcesEnableAll();

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('mediaSources', null));
});

test('secrets never reach the shared prop', function () {
    mediaSourcesEnableAll();

    $response = $this->actingAs($this->user)->get(route('app.workspace.channels'));
    $menu = $response->viewData('page')['props']['mediaSources'];
    $json = json_encode($menu);

    expect($menu['menu'])->toHaveCount(4)
        ->and($json)->not->toContain('SENTINEL')
        ->and($json)->not->toContain('google-client-id')
        ->and($json)->toContain('google-app-id');
});

test('unsplash calls the configured api host', function () {
    config()->set('services.unsplash.access_key', 'key');
    config()->set('trypost.media_sources.unsplash.api', 'https://unsplash.example.test');
    Http::fake(['unsplash.example.test/*' => Http::response(['results' => [], 'total' => 0, 'total_pages' => 0])]);

    (new UnsplashService)->search('cats');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://unsplash.example.test/search/photos'));
});
