<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('channels are shared for the current workspace with their scheduled count', function () {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->x()->create();

    $scheduled = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id]);
    PostPlatform::factory()->create(['post_id' => $scheduled->id, 'social_account_id' => $channel->id]);

    $disabled = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id]);
    PostPlatform::factory()->disabled()->create(['post_id' => $disabled->id, 'social_account_id' => $channel->id]);

    $draft = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id]);
    PostPlatform::factory()->create(['post_id' => $draft->id, 'social_account_id' => $channel->id]);

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->has('channels', 1)
            ->where('channels.0.id', $channel->id)
            ->where('channels.0.platform', Platform::LinkedIn->value)
            ->where('channels.0.scheduled_posts_count', 1)
            ->has('channels.0.username')
            ->missing('channels.0.is_active')
        );
});

test('posts from another workspace never count toward the scheduled total', function () {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $otherWorkspace = Workspace::factory()->create();

    $foreign = Post::factory()->scheduled()->create(['workspace_id' => $otherWorkspace->id]);
    PostPlatform::factory()->create(['post_id' => $foreign->id, 'social_account_id' => $channel->id]);

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->where('channels.0.id', $channel->id)
            ->where('channels.0.scheduled_posts_count', 0)
        );
});

test('channels follow the workspace order, not their creation time', function () {
    $first = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDay(),
    ]);
    $second = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDays(5),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('channels', 2)
            ->where('channels.0.id', $first->id)
            ->where('channels.1.id', $second->id)
        );
});

test('channels are empty without a current workspace', function () {
    $user = User::factory()->create(['current_workspace_id' => null]);

    $this->actingAs($user)
        ->get(route('app.profile.edit'))
        ->assertInertia(fn ($page) => $page->where('channels', []));
});

test('connectable platforms are shared once', function () {
    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->where('connectablePlatforms', Platform::connectableOptions())
        );
});

test('shared channels carry their normalized time zone', function () {
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'Europe/Warsaw']);

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page->where('channels.0.timezone', 'Europe/Warsaw'));
});
