<?php

declare(strict_types=1);

use App\Actions\PostNote\BuildPostNotesProps;
use App\Enums\Post\Status as PostStatus;
use App\Events\PostNoteChanged;
use App\Jobs\SendNotification;
use App\Mail\PostNoteAdded;
use App\Mail\PostNoteMentioned;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostNoteReaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('the publish page sends the notes of the post it has open, newest first and a page at a time by cursor', function () {
    [$oldest, $middle, $newest] = collect([3, 2, 1])->map(fn (int $hours): PostNote => PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'created_at' => now()->subHours($hours),
    ]))->all();

    $notes = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $this->post->id]))
        ->assertOk()
        ->inertiaProps('postNotes');

    expect(collect(data_get($notes, 'data'))->pluck('id')->all())->toBe([$newest->id, $middle->id, $oldest->id])
        ->and(data_get($notes, 'data.0.user.id'))->toBe($this->user->id)
        ->and(data_get($notes, 'meta.total'))->toBe(3)
        ->and($this->actingAs($this->user)->get(route('app.posts.index'))->inertiaProps('postNotes'))->toBeNull();

    config(['app.pagination.default' => 1]);

    $firstPage = $this->actingAs($this->user)->get(route('app.posts.index', ['notes' => $this->post->id]));
    $scroll = data_get($firstPage->viewData('page'), 'scrollProps.postNotes');
    $newest->delete();

    $olderPage = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $this->post->id, data_get($scroll, 'pageName') => data_get($scroll, 'nextPage')]))
        ->inertiaProps('postNotes');

    expect(collect(data_get($firstPage->inertiaProps('postNotes'), 'data'))->pluck('id')->all())->toBe([$newest->id])
        ->and(data_get($scroll, 'pageName'))->toBe(BuildPostNotesProps::CURSOR_NAME)
        ->and(collect(data_get($olderPage, 'data'))->pluck('id')->all())->toBe([$middle->id])
        ->and(data_get($olderPage, 'meta.total'))->toBe(2);
});

test('store creates a comment', function () {
    Event::fake([PostNoteChanged::class]);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), [
            'body' => 'This is a comment.',
        ]);

    $response->assertCreated();
    $response->assertJsonPath('body', 'This is a comment.');

    $this->assertDatabaseHas('post_notes', [
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'body' => 'This is a comment.',
    ]);

    Event::assertDispatched(PostNoteChanged::class, fn (PostNoteChanged $event) => $event->postId === $this->post->id
        && $event->workspaceId === $this->workspace->id
        && $event->change === 'created'
    );
});

test('every note change broadcasts to the post and its workspace, naming the note', function () {
    Event::fake([PostNoteChanged::class]);
    $this->actingAs($this->user);

    $note = PostNote::query()->findOrFail($this->postJson(route('app.posts.notes.store', $this->post), ['body' => 'First'])->json('id'));
    $this->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => 'Edited'])->assertOk();
    $this->putJson(route('app.posts.notes.reactions.update', [$this->post, $note]), ['emoji' => '👍'])->assertOk();
    $this->deleteJson(route('app.posts.notes.reactions.destroy', [$this->post, $note]), ['emoji' => '👍'])->assertOk();
    $this->deleteJson(route('app.posts.notes.destroy', [$this->post, $note]))->assertNoContent();

    $changes = [];
    Event::assertDispatched(PostNoteChanged::class, function (PostNoteChanged $event) use (&$changes, $note): bool {
        $changes[] = $event->broadcastWith();

        return $event->noteId === $note->id;
    });

    expect($changes)->toBe(array_map(fn (string $change): array => ['post_id' => $this->post->id, 'note_id' => $note->id, 'change' => $change], ['created', 'updated', 'updated', 'updated', 'deleted']))
        ->and(array_map(strval(...), (new PostNoteChanged($this->post->id, $this->workspace->id, 'updated', $note->id))->broadcastOn()))->toBe([
            "private-post.{$this->post->id}",
            "private-workspace.{$this->workspace->id}",
        ]);
});

test('store emails the post participants, not the rest of the workspace', function () {
    Mail::fake();

    $writer = workspaceMember($this->workspace);
    $earlierNoteAuthor = workspaceMember($this->workspace);
    $bystander = workspaceMember($this->workspace);
    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $earlierNoteAuthor->id]);

    $this->actingAs($writer)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => "Can someone review?\nThanks"])
        ->assertCreated();

    $note = PostNote::query()->where('user_id', $writer->id)->sole();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email)
        && $mail->note->is($note)
        && $mail->author->is($writer));
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($earlierNoteAuthor->email));
    Mail::assertNotQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($bystander->email));
});

test('a mentioned member gets the mention email instead of the note email', function () {
    Mail::fake();

    $writer = workspaceMember($this->workspace);
    $mentioned = workspaceMember($this->workspace);
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($writer)
        ->postJson(route('app.posts.notes.store', $this->post), [
            'body' => "@[user:{$mentioned->id}] and @[user:{$outsider->id}] and @[user:{$writer->id}] take a look",
        ])
        ->assertCreated();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($mentioned->email) && $mail->author->is($writer));
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email));
    Mail::assertNotQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($mentioned->email));
});

test('editing a note emails only the people it mentions for the first time', function () {
    Mail::fake();

    $alreadyMentioned = workspaceMember($this->workspace);
    $newlyMentioned = workspaceMember($this->workspace);
    $note = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'body' => "@[user:{$alreadyMentioned->id}] ping",
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), [
            'body' => "@[user:{$alreadyMentioned->id}] and @[user:{$newlyMentioned->id}] ping",
        ])
        ->assertOk();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($newlyMentioned->email));
});

test('an edit that mentions yourself or someone outside the workspace emails nobody', function () {
    Mail::fake();

    $outsider = workspaceOutsider($this->workspace);
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => 'ping']);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), [
            'body' => "@[user:{$this->user->id}] @[user:{$outsider->id}] ping",
        ])
        ->assertOk();

    Mail::assertNothingQueued();
});

test('the member who asked for approval of someone else post is a participant of its notes', function () {
    Mail::fake();

    $requester = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    $this->actingAs($approver)
        ->postJson(route('app.posts.notes.store', $pending), ['body' => 'Swap the image first'])
        ->assertCreated();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($requester->email));
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email));
});

test('everyone in the workspace is listed on a draft, the viewer included, sorted by name ignoring case and accents (names without a Latin form first, by their own text), with their time zone', function () {
    $this->user->update(['name' => 'Diego', 'timezone' => 'Europe/Lisbon']);
    $carla = workspaceMember($this->workspace, 'approval', ['name' => 'Carla']);
    $bruno = workspaceMember($this->workspace, 'member', ['name' => 'bruno', 'timezone' => 'Asia/Tokyo']);
    $alvaro = workspaceMember($this->workspace, 'admin', ['name' => 'Álvaro', 'timezone' => 'America/Sao_Paulo']);
    $yamada = workspaceMember($this->workspace, 'member', ['name' => '山田']);
    $zenin = workspaceMember($this->workspace, 'member', ['name' => '全員']);
    workspaceOutsider($this->workspace);

    $this->actingAs($carla)
        ->getJson(route('app.posts.notes.mentionables.index', $this->post))
        ->assertOk()
        ->assertExactJson([
            ['id' => $zenin->id, 'name' => '全員', 'photo_url' => $zenin->photo_url, 'timezone' => $zenin->timezone],
            ['id' => $yamada->id, 'name' => '山田', 'photo_url' => $yamada->photo_url, 'timezone' => $yamada->timezone],
            ['id' => $alvaro->id, 'name' => 'Álvaro', 'photo_url' => $alvaro->photo_url, 'timezone' => 'America/Sao_Paulo'],
            ['id' => $bruno->id, 'name' => 'bruno', 'photo_url' => $bruno->photo_url, 'timezone' => 'Asia/Tokyo'],
            ['id' => $carla->id, 'name' => 'Carla', 'photo_url' => $carla->photo_url, 'timezone' => $carla->timezone],
            ['id' => $this->user->id, 'name' => 'Diego', 'photo_url' => $this->user->photo_url, 'timezone' => 'Europe/Lisbon'],
        ]);
});

test('reactions go with their note and with the member who reacted', function () {
    $teammate = workspaceMember($this->workspace);
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);
    PostNoteReaction::factory()->create(['post_note_id' => $note->id, 'user_id' => $this->user->id]);
    PostNoteReaction::factory()->create(['post_note_id' => $note->id, 'user_id' => $teammate->id]);

    $teammate->delete();

    expect($this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $this->post->id]))
        ->inertiaProps('postNotes.data.0.reactions.0.count'))->toBe(1);

    $this->actingAs($this->user)
        ->deleteJson(route('app.posts.notes.destroy', [$this->post, $note]))
        ->assertNoContent();

    expect(PostNoteReaction::query()->count())->toBe(0);
});

test('@all emails a mention to everyone who sees the post but the author, and no note email', function () {
    Mail::fake();

    $writer = workspaceMember($this->workspace);
    $teammate = workspaceMember($this->workspace);
    workspaceOutsider($this->workspace);

    $this->actingAs($writer)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => '@[everyone] take a look'])
        ->assertCreated();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($this->user->email));
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($teammate->email));
    Mail::assertNotQueued(PostNoteAdded::class);
});

test('@all on a pending request reaches only the approvers and the member who asked', function () {
    Mail::fake();

    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    $this->actingAs($approver)
        ->postJson(route('app.posts.notes.store', $pending), ['body' => '@[everyone] ready?'])
        ->assertCreated();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($this->user->email));
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($requester->email));
    Mail::assertNotQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($bystander->email));
});

test('an edit that adds @all emails only the people it had not mentioned yet', function () {
    Mail::fake();

    $alreadyMentioned = workspaceMember($this->workspace);
    $newlyMentioned = workspaceMember($this->workspace);
    $note = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'body' => "@[user:{$alreadyMentioned->id}] ping",
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => '@[everyone] ping'])
        ->assertOk();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($newlyMentioned->email));
});

test('a note keeps only the mentions of people who see the post, and shows them with their current name', function () {
    $teammate = workspaceMember($this->workspace, 'member', ['name' => 'Bia Lima']);
    $outsider = workspaceOutsider($this->workspace);

    $created = $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), [
            'body' => "@[user:{$teammate->id}] and @[user:{$outsider->id}] @[everyone]",
        ])
        ->assertCreated()
        ->assertJsonPath('mentions', [['id' => $teammate->id, 'name' => 'Bia Lima']]);

    $note = PostNote::query()->sole();
    expect($note->body)->toBe("@[user:{$teammate->id}] and  @[everyone]")
        ->and($note->mentions()->pluck('users.id')->all())->toBe([$teammate->id]);

    $teammate->update(['name' => 'Bia Souza']);

    expect($this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $this->post->id]))
        ->inertiaProps('postNotes.data.0.mentions'))->toBe([['id' => $teammate->id, 'name' => 'Bia Souza']]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $created->json('id')]), ['body' => 'no mention now'])
        ->assertOk()
        ->assertJsonPath('mentions', []);

    expect($note->mentions()->count())->toBe(0);
});

test('store emails the workspace owner when a member adds a note', function () {
    Mail::fake();

    $member = workspaceMember($this->workspace);

    $this->actingAs($member)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Looks good'])
        ->assertCreated();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email));
});

test('a note on a pending request emails and mentions only the approvers and the requester', function () {
    Mail::fake();

    $requester = workspaceMember($this->workspace, 'approval');
    $bystander = workspaceMember($this->workspace, 'approval');
    $approver = workspaceMember($this->workspace);
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);

    $this->actingAs($approver)
        ->postJson(route('app.posts.notes.store', $pending), [
            'body' => "Pricing changes Friday, @[user:{$bystander->id}] @[user:{$this->user->id}]",
        ])
        ->assertCreated();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($this->user->email));
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($requester->email));
    Mail::assertNotQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($bystander->email));
});

test('store respects a member who turned note emails off', function () {
    Mail::fake();

    $member = workspaceMember($this->workspace);
    $member->notificationPreference()->create(['post_note_added' => false]);
    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $member->id]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => "Hello @[user:{$member->id}]"])
        ->assertCreated();

    Mail::assertNothingQueued();
});

test('store sends nothing when the author is the only member', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Just me'])
        ->assertCreated();

    Queue::assertNotPushed(SendNotification::class);
});

test('update and delete send no email', function () {
    Queue::fake();

    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $note = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => 'Edited'])
        ->assertOk();

    $this->actingAs($this->user)
        ->deleteJson(route('app.posts.notes.destroy', [$this->post, $note]))
        ->assertNoContent();

    Queue::assertNotPushed(SendNotification::class);
});

test('update own comment', function () {
    $comment = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'body' => 'Original body.',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $comment]), [
            'body' => 'Updated body.',
        ]);

    $response->assertOk();
    $response->assertJsonPath('body', 'Updated body.');

    $this->assertDatabaseHas('post_notes', [
        'id' => $comment->id,
        'body' => 'Updated body.',
    ]);
});

test('cannot update other user comment', function () {
    $otherUser = User::factory()->create([]);
    $this->workspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $comment = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'body' => 'Original body.',
    ]);

    $response = $this->actingAs($otherUser)
        ->putJson(route('app.posts.notes.update', [$this->post, $comment]), [
            'body' => 'Hacked body.',
        ]);

    $response->assertForbidden();
});

test('an email about a note deleted before it goes out is dropped instead of failing', function (int $jobsBeforeDeleting) {
    config()->set('queue.default', 'database');
    Queue::swap(Queue::getFacadeRoot()->queue);
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    $failed = [];
    Queue::failing(function (JobFailed $event) use (&$failed): void {
        $failed[] = $event->job->resolveName();
    });

    $this->actingAs(workspaceMember($this->workspace))
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Tpyo'])
        ->assertCreated();

    for ($job = 0; $job < $jobsBeforeDeleting; $job++) {
        $worker->runNextJob('database', 'default', $options);
    }

    PostNote::query()->sole()->delete();
    $worker->runNextJob('database', 'default', $options);

    expect(Queue::connection('database')->size('default'))->toBe(0)
        ->and($failed)->toBe([]);
})->with([
    'before the notification job runs' => [0],
    'between the notification job and the email it queues' => [1],
]);

test('a link with a malformed note or post id opens the page without a thread', function (mixed $notes, mixed $note) {
    $page = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $notes, 'note' => $note]))
        ->assertOk();

    expect($page->inertiaProps('openPostNotesId'))->toBeNull()
        ->and($page->inertiaProps('highlightNoteId'))->toBeNull()
        ->and($page->inertiaProps('postNotes'))->toBeNull();
})->with([
    'not a uuid' => ['abc', 'abc'],
    'an array' => [['x'], ['y']],
    'a uuid of nothing' => [fn (): string => Str::uuid()->toString(), null],
]);

test('an edit drops the mention of someone who no longer sees the post', function () {
    $teammate = workspaceMember($this->workspace);
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => "Ask @[user:{$teammate->id}]"]);
    $note->mentions()->attach($teammate);
    $this->workspace->members()->detach($teammate);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => "Ask @[user:{$teammate->id}] again"])
        ->assertOk()
        ->assertJsonPath('mentions', []);

    expect($note->fresh()->body)->toBe('Ask  again')
        ->and($note->mentions()->count())->toBe(0);
});

test('deleting a note takes its reactions and mentions with it', function () {
    $teammate = workspaceMember($this->workspace);
    $note = PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id, 'body' => "Ask @[user:{$teammate->id}]"]);
    $note->mentions()->attach($teammate);
    PostNoteReaction::factory()->create(['post_note_id' => $note->id, 'user_id' => $teammate->id, 'emoji' => '👍']);

    $this->actingAs($this->user)->deleteJson(route('app.posts.notes.destroy', [$this->post, $note]))->assertNoContent();

    expect(PostNoteReaction::query()->count())->toBe(0);
    $this->assertDatabaseCount('post_note_mentions', 0);
});

test('delete own comment', function () {
    $comment = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('app.posts.notes.destroy', [$this->post, $comment]));

    $response->assertNoContent();

    $this->assertDatabaseMissing('post_notes', [
        'id' => $comment->id,
    ]);
});

test('cannot delete other user comment', function () {
    $otherUser = User::factory()->create([]);
    $this->workspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $comment = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($otherUser)
        ->deleteJson(route('app.posts.notes.destroy', [$this->post, $comment]));

    $response->assertForbidden();
});

test('cannot comment on post from other workspace', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($otherUser)
        ->postJson(route('app.posts.notes.store', $this->post), [
            'body' => 'Cross-workspace comment.',
        ]);

    $response->assertNotFound();
});

test('notes expose only the author id, name and photo', function () {
    $this->user->update([
        'registration_ip' => '203.0.113.7',
        'google_id' => 'google-123',
        'utm_source' => 'newsletter',
    ]);
    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);

    $listed = $this->actingAs($this->user)
        ->get(route('app.posts.index', ['notes' => $this->post->id]))
        ->inertiaProps('postNotes.data');
    $store = $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Hi'])
        ->assertCreated();
    $update = $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $store->json('id')]), ['body' => 'Edited'])
        ->assertOk();

    foreach ([data_get($listed, '0.user'), $store->json('user'), $update->json('user')] as $author) {
        expect(array_keys($author))->toEqualCanonicalizing(['id', 'name', 'photo_url'])
            ->and($author['id'])->toBe($this->user->id);
    }

    foreach ([json_encode($listed), $store->getContent(), $update->getContent()] as $content) {
        foreach (['email', 'registration_ip', 'google_id', 'github_id', 'utm_source', 'persona', '203.0.113.7', $this->user->email] as $leak) {
            expect($content)->not->toContain($leak);
        }
    }

});

test('a member who needs approval cannot see notes of another member pending request', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $other = workspaceMember($this->workspace, 'approval');
    $pending = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $requester->id,
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $requester->id,
    ]);
    $note = PostNote::factory()->create(['post_id' => $pending->id, 'user_id' => $other->id]);

    expect($this->actingAs($other)->get(route('app.posts.index', ['notes' => $pending->id]))->inertiaProps('postNotes'))->toBeNull();
    $this->actingAs($other)->postJson(route('app.posts.notes.store', $pending), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($other)->putJson(route('app.posts.notes.update', [$pending, $note]), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($other)->deleteJson(route('app.posts.notes.destroy', [$pending, $note]))->assertNotFound();

    expect(PostNote::query()->count())->toBe(1);
});

test('a user outside the workspace cannot read or add notes', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->get(route('app.posts.index', ['notes' => $this->post->id]))->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Hi'])->assertForbidden();
});
