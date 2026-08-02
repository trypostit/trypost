<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
});

test('index returns templates from the registry', function () {
    $this->actingAs($this->user)
        ->get(route('app.post-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/templates/Index')
            ->has('templates.data')
        );
});

test('index requires authentication', function () {
    $this->get(route('app.post-templates.index'))
        ->assertRedirect(route('login'));
});

test('index filters by platform', function () {
    $this->actingAs($this->user)
        ->get(route('app.post-templates.index', ['platform' => 'instagram_carousel']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/templates/Index')
            ->where('templates.data', fn ($templates) => collect($templates)->every(
                fn ($t) => $t['platform'] === 'instagram_carousel'
            ))
        );
});

test('apply creates post and returns post_id and redirect_url', function () {
    Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'))
        ->assertOk();

    expect($response->json('post_id'))->toBeString();
    expect($response->json('redirect_url'))->toContain('edit');

    $post = $this->workspace->posts()->find($response->json('post_id'));
    expect($post->created_via)->toBe(CreatedVia::Web);
});

test('apply interpolates brand_name in content', function () {
    Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

    $this->workspace->update(['name' => 'Acme Corp']);

    // success_story template content references {{brand_name}}
    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'))
        ->assertOk();

    $this->assertDatabaseHas('posts', [
        'workspace_id' => $this->workspace->id,
    ]);

    $post = $this->workspace->posts()->latest()->first();
    expect($post->content)->toContain('Acme Corp');
});

test('apply rejects cross-workspace social_account_id', function () {
    $otherAccount = Account::factory()->create();
    $otherUser = User::factory()->create(['account_id' => $otherAccount->id]);
    $otherAccount->update(['owner_id' => $otherUser->id]);
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
    ]);

    $foreignSocialAccount = SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'), [
            'social_account_id' => $foreignSocialAccount->id,
        ])
        ->assertForbidden();
});

test('apply requires authentication', function () {
    $this->postJson(route('app.post-templates.apply', 'success_story'))
        ->assertUnauthorized();
});

test('apply returns 404 for unknown slug', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'this-slug-does-not-exist'))
        ->assertNotFound();
});

test('apply leaves scheduled_at null when no date is provided', function () {
    Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'))
        ->assertOk();

    $post = $this->workspace->posts()->latest()->first();
    expect($post->scheduled_at)->toBeNull();
});

test('apply schedules the post on the date param when provided', function () {
    Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'), [
            'date' => '2026-06-15',
        ])
        ->assertOk();

    $post = $this->workspace->posts()->latest()->first();
    expect($post->scheduled_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-15 09:00:00');
});

test('apply rejects invalid date format', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'success_story'), [
            'date' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('apply with slides creates post even when image rendering fails', function () {
    // Unsplash returns no results → generator returns null → no media attached, post still created.
    Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.post-templates.apply', 'feature_launch_carousel'), [
            'social_account_id' => $socialAccount->id,
        ])
        ->assertOk();

    expect($response->json('post_id'))->toBeString();
    expect($response->json('redirect_url'))->toContain('edit');
});
