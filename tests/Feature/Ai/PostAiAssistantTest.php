<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('legacy creation and progress endpoints are retired', function () {
    expect(Route::has('app.posts.ai.create'))->toBeFalse();
    expect(Route::has('app.posts.ai.loading'))->toBeFalse();
});

test('legacy AI creation link opens the assistant in the composer', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.create', ['ai' => 1, 'templates' => 1]))
        ->assertRedirect(route('app.posts.index', ['compose' => 1, 'assistant' => 1]));

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['compose' => 1, 'assistant' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Index')
            ->where('openComposer', true)
            ->where('openComposerAssistant', true));
});

test('assistant requires authentication', function () {
    $this->postJson(route('app.posts.ai.assist'), [
        'mode' => 'write_more',
        'prompt' => 'A post about our new product',
    ])->assertStatus(Response::HTTP_UNAUTHORIZED);
});

test('assistant validates mode and required content', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'rephrase'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_content']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'write_more'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt']);
});

test('web assistant suggests a caption without creating a post', function () {
    PostWritingAssistant::fake(['A fresh caption']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'write_more',
            'prompt' => 'Introduce our new product',
        ])
        ->assertOk()
        ->assertJsonPath('content', 'A fresh caption');

    PostWritingAssistant::assertPrompted('Introduce our new product');
    $this->assertDatabaseCount('posts', 0);
});

test('API assistant uses the same action as the web composer', function () {
    PostWritingAssistant::fake(['A shorter caption']);
    $plainToken = passportToken($this->user, $this->workspace);

    $this->withHeaders(['Authorization' => 'Bearer '.$plainToken])
        ->postJson(route('api.posts.ai.assist'), [
            'mode' => 'shorten',
            'current_content' => 'A long social media caption to be shortened.',
        ])
        ->assertOk()
        ->assertJsonPath('content', 'A shorter caption');

    $this->assertDatabaseCount('posts', 0);
});
