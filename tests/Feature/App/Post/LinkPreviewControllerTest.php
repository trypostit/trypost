<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $workspace->id]);
});

test('returns a link card for a valid url', function () {
    Http::fake([
        'https://example.com' => Http::response(
            '<html><head><meta property="og:title" content="Example">'
            .'<meta property="og:description" content="Desc">'
            .'<meta property="og:image" content="https://example.com/card.png"></head></html>',
            200,
        ),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview'), ['url' => 'https://example.com'])
        ->assertOk()
        ->assertJson([
            'uri' => 'https://example.com',
            'domain' => 'example.com',
            'title' => 'Example',
            'description' => 'Desc',
            'image' => 'https://example.com/card.png',
        ]);
});

test('returns no content when there is no card', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview'), ['url' => 'http://127.0.0.1/private'])
        ->assertNoContent();
});

test('validates that a url is required', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview'), [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('requires authentication', function () {
    $this->postJson(route('app.posts.link-preview'), ['url' => 'https://example.com'])
        ->assertUnauthorized();
});
