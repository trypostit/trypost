<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->owner->account_id,
        'user_id' => $this->owner->id,
    ]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->requester = User::factory()->create([
        'account_id' => $this->owner->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($this->requester->id, membershipPivot('approval'));

    $this->member = User::factory()->create([
        'account_id' => $this->owner->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($this->member->id, membershipPivot('member'));

    $this->admin = User::factory()->create([
        'account_id' => $this->owner->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($this->admin->id, membershipPivot('admin'));

    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('a user outside the workspace cannot delete a post', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)
        ->delete(route('app.posts.destroy', $this->post))
        ->assertForbidden();

    $this->assertDatabaseHas('posts', ['id' => $this->post->id]);
});

test('a member who needs approval can comment on a post', function () {
    $this->actingAs($this->requester)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Looks good!'])
        ->assertSuccessful();

    $this->assertDatabaseHas('post_notes', [
        'post_id' => $this->post->id,
        'user_id' => $this->requester->id,
    ]);
});

test('every workspace member can open the details of a draft post', function (string $actor) {
    $this->actingAs($this->{$actor})
        ->get(route('app.posts.index', ['post' => $this->post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'drafts')
            ->where('openPostDetailsId', $this->post->id)
            ->where('posts.data.0.id', $this->post->id)
        );
})->with(['admin', 'member', 'requester']);

test('a member who needs approval can open the draft dialog route to review the post', function () {
    $this->actingAs($this->requester)
        ->get(route('app.posts.edit', $this->post))
        ->assertRedirect(route('app.posts.index', ['edit' => $this->post->id]));
});

test('a user outside the workspace cannot save changes to a post', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)
        ->put(route('app.posts.update', $this->post), ['status' => Status::Draft->value])
        ->assertForbidden();
});

test('only admins and above can open the connections screen', function (string $actor, bool $allowed) {
    $response = $this->actingAs($this->{$actor})->get(route('app.workspace.channels'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'admin' => ['admin', true],
    'member' => ['member', false],
    'requester' => ['requester', false],
]);

test('opening the dialog does not create platform rows for a member who needs approval', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->requester)
        ->get(route('app.posts.edit', $this->post))
        ->assertRedirect(route('app.posts.index', ['edit' => $this->post->id]));

    expect($this->post->postPlatforms()->count())->toBe(0);
});

test('opening the dialog does not create platform rows for a member', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->member)
        ->get(route('app.posts.edit', $this->post))
        ->assertRedirect(route('app.posts.index', ['edit' => $this->post->id]));

    expect($this->post->postPlatforms()->count())->toBe(0);
});

test('a member without connected accounts is redirected away from the admin-only accounts screen when creating a post', function () {
    $this->actingAs($this->member)
        ->post(route('app.posts.store'), [
            'status' => 'draft',
            'destinations' => [['social_account_id' => (string) Str::uuid(), 'content_type' => 'linkedin_post']],
        ])
        ->assertRedirect(route('app.calendar'));
});

test('an admin without connected accounts is sent to the accounts screen when creating a post', function () {
    $this->actingAs($this->admin)
        ->post(route('app.posts.store'), [
            'status' => 'draft',
            'destinations' => [['social_account_id' => (string) Str::uuid(), 'content_type' => 'linkedin_post']],
        ])
        ->assertRedirect(route('app.workspace.channels'));
});

test('a member cannot open the create workspace form', function () {
    $this->actingAs($this->member)
        ->get(route('app.workspaces.create'))
        ->assertForbidden();
});

test('a member cannot store a workspace on the shared account', function () {
    $this->actingAs($this->member)
        ->post(route('app.workspaces.store'), [
            'name' => 'Sneaky Workspace',
        ])
        ->assertForbidden();

    expect(Workspace::where('name', 'Sneaky Workspace')->exists())->toBeFalse();
});

test('a workspace admin cannot store a workspace on the shared account', function () {
    $this->actingAs($this->admin)
        ->post(route('app.workspaces.store'), [
            'name' => 'Admin Workspace',
        ])
        ->assertForbidden();

    expect(Workspace::where('name', 'Admin Workspace')->exists())->toBeFalse();
});

test('a member who needs approval can save a draft and delete it', function () {
    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $this->post), ['status' => Status::Draft->value])
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->delete(route('app.posts.destroy', $this->post))
        ->assertRedirect();

    expect(Post::query()->find($this->post->id))->toBeNull();
});
