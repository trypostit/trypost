<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Events\PostNoteChanged;
use App\Mail\PostNoteAdded;
use App\Mail\PostNoteMentioned;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AddPostNoteReactionTool;
use App\Mcp\Tools\Post\CreatePostNoteTool;
use App\Mcp\Tools\Post\DeletePostNoteTool;
use App\Mcp\Tools\Post\ListPostNoteMentionablesTool;
use App\Mcp\Tools\Post\ListPostNotesTool;
use App\Mcp\Tools\Post\RemovePostNoteReactionTool;
use App\Mcp\Tools\Post\UpdatePostNoteTool;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostNoteReaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->post = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $this->unauthorized = (new AuthorizationException)->getMessage();
});

test('a note added on the web, the api and mcp is stored the same way with the author', function () {
    $this->actingAs($this->user)->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Web note'])->assertCreated();
    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.notes.store', $this->post), ['body' => 'Api note'])
        ->assertCreated()
        ->assertJsonPath('body', 'Api note')
        ->assertJsonPath('post_id', $this->post->id)
        ->assertJsonPath('author.id', $this->user->id);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => $this->post->id, 'body' => 'Mcp note'])
        ->assertOk()
        ->assertSee('Mcp note');

    expect(PostNote::query()->where('post_id', $this->post->id)->orderBy('body')->get(['body', 'user_id'])->toArray())->toBe([
        ['body' => 'Api note', 'user_id' => $this->user->id],
        ['body' => 'Mcp note', 'user_id' => $this->user->id],
        ['body' => 'Web note', 'user_id' => $this->user->id],
    ]);
});

test('the api and mcp store a mention by its id in lower case, drop one of someone outside and return the names', function () {
    $teammate = workspaceMember($this->workspace, 'member', ['name' => 'Bia Lima']);
    $outsider = workspaceOutsider($this->workspace);
    $upperId = Str::upper($teammate->id);
    $body = "@[user:{$upperId}] and @[user:{$outsider->id}]";
    $mentions = [['id' => $teammate->id, 'name' => 'Bia Lima']];

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.notes.store', $this->post), ['body' => $body])
        ->assertCreated()
        ->assertJsonPath('body', "@[user:{$upperId}] and ")
        ->assertJsonPath('mentions', $mentions);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => $this->post->id, 'body' => $body])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('body', "@[user:{$upperId}] and ")->where('mentions', $mentions)->etc());

    $notes = PostNote::query()->where('post_id', $this->post->id)->get();

    expect($notes)->toHaveCount(2)
        ->and($notes->map(fn (PostNote $note): array => $note->mentions()->pluck('users.id')->all())->all())->toBe([[$teammate->id], [$teammate->id]]);
});

test('the api and mcp note lists paginate newest first with the app page size and expose only safe author fields', function () {
    config(['app.pagination.default' => 2]);
    $notes = collect(range(1, 3))->map(fn (int $minutes): PostNote => PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'created_at' => now()->subMinutes($minutes),
    ]));

    $first = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.notes.index', $this->post))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.notes.index', [$this->post, 'page' => 2, 'per_page' => 50]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListPostNotesTool::class, ['post_id' => $this->post->id])->assertOk()->assertStructuredContent(parityMcpPage('notes', $first));
    TryPostServer::actingAs($this->user)->tool(ListPostNotesTool::class, ['post_id' => $this->post->id, 'page' => 2])->assertOk()->assertStructuredContent(parityMcpPage('notes', $second));

    expect([...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')])->toBe($notes->pluck('id')->all())
        ->and(array_keys($first->json('data.0')))->toBe(['id', 'post_id', 'body', 'mentions', 'reactions', 'author', 'created_at', 'updated_at'])
        ->and(array_keys($first->json('data.0.author')))->toBe(['id', 'name', 'photo_url']);
});

test('the author edits and deletes their own note through the api and mcp', function () {
    $apiNote = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);
    $mcpNote = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.update', [$this->post, $apiNote]), ['body' => 'Edited'])
        ->assertOk()
        ->assertJsonPath('body', 'Edited');
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $mcpNote->id, 'body' => 'Edited'])->assertOk();

    expect([$apiNote->fresh()->body, $mcpNote->fresh()->body])->toBe(['Edited', 'Edited']);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.notes.destroy', [$this->post, $apiNote]))->assertNoContent();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(DeletePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $mcpNote->id])->assertOk();

    expect(PostNote::query()->count())->toBe(0);
});

test('only the author may edit or delete a note, on the web, the api and mcp', function () {
    $admin = workspaceMember($this->workspace, 'admin');
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => 'Original']);
    $adminToken = passportToken($admin, $this->workspace);

    $this->actingAs($admin)->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => 'Hijacked'])->assertForbidden();
    $this->actingAs($admin)->deleteJson(route('app.posts.notes.destroy', [$this->post, $note]))->assertForbidden();
    auth()->forgetGuards();

    $this->withHeaders(parityApi($adminToken))->putJson(route('api.posts.notes.update', [$this->post, $note]), ['body' => 'Hijacked'])->assertForbidden();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($adminToken))->deleteJson(route('api.posts.notes.destroy', [$this->post, $note]))->assertForbidden();
    auth()->forgetGuards();

    TryPostServer::actingAs($admin)->tool(UpdatePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'body' => 'Hijacked'])->assertHasErrors([$this->unauthorized]);
    TryPostServer::actingAs($admin)->tool(DeletePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id])->assertHasErrors([$this->unauthorized]);

    expect($note->fresh()->body)->toBe('Original');
});

test('a note of another post is not found on the web, the api and mcp', function () {
    $otherPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $note = PostNote::factory()->create(['post_id' => $otherPost->id, 'user_id' => $this->user->id, 'body' => 'Original']);

    $this->actingAs($this->user)->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => 'Moved'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.update', [$this->post, $note]), ['body' => 'Moved'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.notes.destroy', [$this->post, $note]))->assertNotFound();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'body' => 'Moved'])->assertHasErrors(['Note not found.']);
    TryPostServer::actingAs($this->user)->tool(DeletePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id])->assertHasErrors(['Note not found.']);

    expect($note->fresh()->body)->toBe('Original');
});

test('a post of another workspace is not found on the web, the api and mcp', function () {
    $other = parityContext();
    $foreignPost = Post::factory()->draft()->create(['workspace_id' => $other['workspace']->id, 'user_id' => $other['user']->id]);
    $foreignNote = PostNote::factory()->create(['post_id' => $foreignPost->id, 'user_id' => $other['user']->id, 'body' => 'Original']);

    expect($this->actingAs($this->user)->get(route('app.posts.index', ['notes' => $foreignPost->id]))->inertiaProps('postNotes'))->toBeNull();
    $this->actingAs($this->user)->postJson(route('app.posts.notes.store', $foreignPost), ['body' => 'Hijacked'])->assertNotFound();
    auth()->forgetGuards();

    $calls = [
        ['GET', route('api.posts.notes.index', $foreignPost), []],
        ['POST', route('api.posts.notes.store', $foreignPost), ['body' => 'Hijacked']],
        ['PUT', route('api.posts.notes.update', [$foreignPost, $foreignNote]), ['body' => 'Hijacked']],
        ['DELETE', route('api.posts.notes.destroy', [$foreignPost, $foreignNote]), []],
        ['GET', route('api.posts.notes.mentionables.index', $foreignPost), []],
        ['PUT', route('api.posts.notes.reactions.update', [$foreignPost, $foreignNote]), ['emoji' => '👍']],
        ['DELETE', route('api.posts.notes.reactions.destroy', [$foreignPost, $foreignNote]), ['emoji' => '👍']],
    ];

    foreach ($calls as [$method, $url, $body]) {
        $this->withHeaders(parityApi($this->token))->json($method, $url, $body)->assertNotFound();
        auth()->forgetGuards();
    }

    TryPostServer::actingAs($this->user)->tool(ListPostNotesTool::class, ['post_id' => $foreignPost->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => $foreignPost->id, 'body' => 'Hijacked'])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, ['post_id' => $foreignPost->id, 'note_id' => $foreignNote->id, 'body' => 'Hijacked'])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->user)->tool(DeletePostNoteTool::class, ['post_id' => $foreignPost->id, 'note_id' => $foreignNote->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->user)->tool(ListPostNoteMentionablesTool::class, ['post_id' => $foreignPost->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($this->user)->tool(AddPostNoteReactionTool::class, ['post_id' => $foreignPost->id, 'note_id' => $foreignNote->id, 'emoji' => '👍'])->assertHasErrors(['Post not found.']);

    expect(PostNote::query()->where('post_id', $foreignPost->id)->pluck('body')->all())->toBe(['Original'])
        ->and(PostNoteReaction::query()->count())->toBe(0);
});

test('a member who cannot see another member pending request gets not found on the web and mcp, while the requester reads it', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);
    PostNote::factory()->create(['post_id' => $pending->id, 'user_id' => $this->user->id]);

    expect($this->actingAs($bystander)->get(route('app.posts.index', ['notes' => $pending->id]))->inertiaProps('postNotes'))->toBeNull();
    $this->actingAs($bystander)->postJson(route('app.posts.notes.store', $pending), ['body' => 'Peek'])->assertNotFound();
    auth()->forgetGuards();

    TryPostServer::actingAs($bystander)->tool(ListPostNotesTool::class, ['post_id' => $pending->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($bystander)->tool(CreatePostNoteTool::class, ['post_id' => $pending->id, 'body' => 'Peek'])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($requester)->tool(ListPostNotesTool::class, ['post_id' => $pending->id])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->etc());

    expect(PostNote::query()->where('post_id', $pending->id)->count())->toBe(1);
});

test('the web, the api and mcp refuse the same note bodies', function () {
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => 'Original']);

    foreach ([[], ['body' => ''], ['body' => str_repeat('a', 2001)], ['body' => ['array']]] as $payload) {
        $this->actingAs($this->user)->postJson(route('app.posts.notes.store', $this->post), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($this->user)->putJson(route('app.posts.notes.update', [$this->post, $note]), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.notes.store', $this->post), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.update', [$this->post, $note]), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        auth()->forgetGuards();
        TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => $this->post->id, ...$payload])->assertHasErrors();
        TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, ...$payload])->assertHasErrors();
    }

    expect(PostNote::query()->pluck('body')->all())->toBe(['Original']);
});

test('the mcp note tools refuse ids that are not uuids with a validation error', function () {
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);

    TryPostServer::actingAs($this->user)->tool(ListPostNotesTool::class, ['post_id' => 'not-a-uuid'])->assertHasErrors()->assertDontSee('SQLSTATE');
    TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => 'not-a-uuid', 'body' => 'Hi'])->assertHasErrors()->assertDontSee('SQLSTATE');
    TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => 'not-a-uuid', 'body' => 'Hi'])->assertHasErrors()->assertDontSee('SQLSTATE');
    TryPostServer::actingAs($this->user)->tool(DeletePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => 'not-a-uuid'])->assertHasErrors()->assertDontSee('SQLSTATE');
    TryPostServer::actingAs($this->user)->tool(DeletePostNoteTool::class, ['post_id' => $this->post->id, 'note_id' => (string) Str::uuid()])->assertHasErrors(['Note not found.']);

    expect($note->fresh())->not->toBeNull();
});

test('a user outside the workspace cannot read or add notes on the web or mcp', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->get(route('app.posts.index', ['notes' => $this->post->id]))->assertForbidden();
    auth()->forgetGuards();

    TryPostServer::actingAs($outsider)->tool(ListPostNotesTool::class, ['post_id' => $this->post->id])->assertHasErrors([$this->unauthorized]);
    TryPostServer::actingAs($outsider)->tool(CreatePostNoteTool::class, ['post_id' => $this->post->id, 'body' => 'Hi'])->assertHasErrors([$this->unauthorized]);

    expect(PostNote::query()->count())->toBe(0);
});

test('a note added through the api on a pending request emails the requester and mentions only those who see it', function () {
    Mail::fake();
    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    $this->withHeaders(parityApi($this->token))->postJson(route('api.posts.notes.store', $pending), [
        'body' => "Pricing changes Friday @[user:{$approver->id}] @[user:{$bystander->id}]",
    ])->assertCreated();

    expect(pendingNoteRecipients())->toBe([$requester->email])
        ->and(noteRecipients(PostNoteMentioned::class))->toBe([$approver->email]);
});

test('a note added through mcp by the requester on their pending request mentions only the approvers', function () {
    Mail::fake();
    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    TryPostServer::actingAs($requester)->tool(CreatePostNoteTool::class, [
        'post_id' => $pending->id,
        'body' => "Ready for review @[user:{$approver->id}] @[user:{$bystander->id}]",
    ])->assertOk();

    expect(pendingNoteRecipients())->toBe([])
        ->and(noteRecipients(PostNoteMentioned::class))->toBe([$approver->email]);
});

test('a note added through mcp on a draft emails its participants', function () {
    Mail::fake();
    $member = workspaceMember($this->workspace, 'approval');
    workspaceMember($this->workspace);
    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $member->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostNoteTool::class, ['post_id' => $this->post->id, 'body' => 'Heads up'])->assertOk();

    expect(pendingNoteRecipients())->toBe([$member->email]);
});

test('reactions added and removed on the web, the api and mcp are stored once per member and emoji', function () {
    Event::fake([PostNoteChanged::class]);
    $teammate = workspaceMember($this->workspace, 'admin');
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $teammate->id]);

    $this->actingAs($this->user)->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])->assertOk();
    $this->actingAs($this->user)->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])->assertOk();
    auth()->forgetGuards();

    $this->withHeaders(parityApi(passportToken($teammate, $this->workspace)))
        ->putJson(route('api.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('reactions', [['emoji' => '👍', 'count' => 2, 'users' => [
            ['id' => $this->user->id, 'name' => $this->user->name],
            ['id' => $teammate->id, 'name' => $teammate->name],
        ]]]);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(AddPostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => '🎉'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('reactions.0.count', 2)->where('reactions.1.emoji', '🎉')->etc());

    expect(PostNoteReaction::query()->count())->toBe(3);
    Event::assertDispatchedTimes(PostNoteChanged::class, 3);

    $this->actingAs($this->user)->deleteJson(route('app.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('reactions.0.count', 1);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '🎉'])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($teammate)->tool(RemovePostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => '👍'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('reactions', [])->etc());

    expect(PostNoteReaction::query()->count())->toBe(0);
});

test('the web, the api and mcp refuse a reaction that is not exactly one emoji', function () {
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);

    $invalid = [
        [],
        ['emoji' => ''],
        ['emoji' => 'a'],
        ['emoji' => '👍👍'],
        ['emoji' => ['👍']],
        ['emoji' => "a\u{20E3}"],
        ['emoji' => "\u{1F1E7}"],
        ['emoji' => '👍'.str_repeat("\u{0301}", 80)],
    ];

    foreach ($invalid as $payload) {
        $this->actingAs($this->user)->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), $payload)->assertUnprocessable()->assertJsonValidationErrors('emoji');
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.reactions.update', [$this->post, $note]), $payload)->assertUnprocessable()->assertJsonValidationErrors('emoji');
        auth()->forgetGuards();
        TryPostServer::actingAs($this->user)->tool(AddPostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, ...$payload])->assertHasErrors();
    }

    expect(PostNoteReaction::query()->count())->toBe(0);
});

test('every kind of emoji is a reaction on the web, the api and mcp', function (string $emoji) {
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);
    $admin = workspaceMember($this->workspace, 'admin');
    $member = workspaceMember($this->workspace);

    $this->actingAs($this->user)->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => $emoji])->assertOk();
    auth()->forgetGuards();
    $this->withHeaders(parityApi(passportToken($admin, $this->workspace)))->putJson(route('api.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => $emoji])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($member)->tool(AddPostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => $emoji])->assertOk();

    expect(PostNoteReaction::query()->pluck('emoji')->all())->toBe([$emoji, $emoji, $emoji]);
})->with([
    'pictograph' => '👍',
    'variation selector' => '❤️',
    'skin tone' => '👍🏽',
    'joined pictographs' => '👨‍👩‍👧‍👦',
    'flag' => '🇧🇷',
    'subdivision flag' => '🏴󠁧󠁢󠁳󠁣󠁴󠁿',
    'keycap' => '1️⃣',
]);

test('taking back a reaction you do not have changes nothing on the web, the api and mcp', function () {
    Event::fake([PostNoteChanged::class]);
    $teammate = workspaceMember($this->workspace);
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $teammate->id]);
    PostNoteReaction::factory()->create(['post_note_id' => $note->id, 'user_id' => $teammate->id, 'emoji' => '👍']);

    $this->actingAs($this->user)->deleteJson(route('app.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('reactions.0.count', 1);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(RemovePostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => '👍'])->assertOk();

    expect(PostNoteReaction::query()->sole()->user_id)->toBe($teammate->id);
    Event::assertNotDispatched(PostNoteChanged::class);
});

test('reactions on a note of another post are not found on the web, the api and mcp', function () {
    $otherPost = Post::factory()->draft()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $note = PostNote::factory()->create(['post_id' => $otherPost->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user)->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])->assertNotFound();
    $this->actingAs($this->user)->deleteJson(route('app.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])->assertNotFound();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(AddPostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => '👍'])->assertHasErrors(['Note not found.']);
    TryPostServer::actingAs($this->user)->tool(RemovePostNoteReactionTool::class, ['post_id' => $this->post->id, 'note_id' => $note->id, 'emoji' => '👍'])->assertHasErrors(['Note not found.']);

    expect(PostNoteReaction::query()->count())->toBe(0);
});

test('a member who cannot see a pending request cannot react to its notes or list who to mention, on the web and mcp', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);
    $note = PostNote::factory()->create(['post_id' => $pending->id, 'user_id' => $this->user->id]);

    $this->actingAs($bystander)->getJson(route('app.posts.notes.mentionables.index', $pending))->assertNotFound();
    $this->actingAs($bystander)->putJson(route('app.posts.notes.reactions.update', [$pending, $note]), ['emoji' => '👍'])->assertNotFound();
    $this->actingAs($bystander)->deleteJson(route('app.posts.notes.reactions.destroy', [$pending, $note]), ['emoji' => '👍'])->assertNotFound();
    auth()->forgetGuards();

    TryPostServer::actingAs($bystander)->tool(ListPostNoteMentionablesTool::class, ['post_id' => $pending->id])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($bystander)->tool(AddPostNoteReactionTool::class, ['post_id' => $pending->id, 'note_id' => $note->id, 'emoji' => '👍'])->assertHasErrors(['Post not found.']);
    TryPostServer::actingAs($bystander)->tool(RemovePostNoteReactionTool::class, ['post_id' => $pending->id, 'note_id' => $note->id, 'emoji' => '👍'])->assertHasErrors(['Post not found.']);

    expect(PostNoteReaction::query()->count())->toBe(0);
});

test('an edit through the api and mcp emails only the teammates it mentions for the first time', function () {
    Mail::fake();
    $alreadyMentioned = workspaceMember($this->workspace);
    $apiMention = workspaceMember($this->workspace);
    $mcpMention = workspaceMember($this->workspace);
    $mention = fn (User $user): string => "@[user:{$user->id}]";
    $apiNote = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => "{$mention($alreadyMentioned)} ping"]);
    $mcpNote = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => "{$mention($alreadyMentioned)} ping"]);

    $this->withHeaders(parityApi($this->token))->putJson(route('api.posts.notes.update', [$this->post, $apiNote]), [
        'body' => "{$mention($alreadyMentioned)} {$mention($apiMention)} ping",
    ])->assertOk();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(UpdatePostNoteTool::class, [
        'post_id' => $this->post->id,
        'note_id' => $mcpNote->id,
        'body' => "{$mention($alreadyMentioned)} {$mention($mcpMention)} ping",
    ])->assertOk();

    expect(noteRecipients(PostNoteMentioned::class))->toBe([$apiMention->email, $mcpMention->email])
        ->and(pendingNoteRecipients())->toBe([]);
});

test('the web, the api and mcp list the same people to mention: who sees the post, the viewer included', function () {
    $this->user->update(['name' => 'Diego Owner']);
    $requester = workspaceMember($this->workspace, 'approval', ['name' => 'Bia Requester']);
    workspaceMember($this->workspace, 'approval', ['name' => 'Caio Bystander']);
    $approver = workspaceMember($this->workspace, 'member', ['name' => 'Ana Approver']);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => Status::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    $web = $this->actingAs($requester)->getJson(route('app.posts.notes.mentionables.index', $pending))->assertOk();
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.notes.mentionables.index', $pending))->assertOk();
    auth()->forgetGuards();

    expect(collect($web->json())->pluck('id')->all())->toBe([$approver->id, $requester->id, $this->user->id])
        ->and($api->json())->toBe($web->json())
        ->and(array_keys($web->json('0')))->toBe(['id', 'name', 'photo_url', 'timezone']);

    TryPostServer::actingAs($requester)->tool(ListPostNoteMentionablesTool::class, ['post_id' => $pending->id])
        ->assertOk()
        ->assertStructuredContent(['mentionables' => $web->json()]);
});

/**
 * Emails of every user a PostNoteAdded mail was queued to.
 *
 * @return list<string>
 */
function pendingNoteRecipients(): array
{
    return noteRecipients(PostNoteAdded::class);
}

/**
 * Emails of every user a mail of that class was queued to.
 *
 * @param  class-string<Mailable>  $mailable
 * @return list<string>
 */
function noteRecipients(string $mailable): array
{
    return Mail::queued($mailable)
        ->flatMap(fn (Mailable $mail): array => collect($mail->to)->pluck('address')->all())
        ->values()
        ->all();
}
