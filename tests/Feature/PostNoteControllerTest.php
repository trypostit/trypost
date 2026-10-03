<?php

declare(strict_types=1);

use App\Events\PostNoteChanged;
use App\Jobs\SendNotification;
use App\Mail\PostNoteAdded;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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

test('index returns every note of the post, newest first', function () {
    $older = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
        'created_at' => now()->subHour(),
    ]);
    $newer = PostNote::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.posts.notes.index', $this->post));

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.id', $newer->id);
    $response->assertJsonPath('data.1.id', $older->id);
    $response->assertJsonPath('data.0.user.id', $this->user->id);
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

test('note changes broadcast to the post and its workspace', function () {
    $event = new PostNoteChanged($this->post->id, $this->workspace->id, 'updated');

    expect($event->broadcastAs())->toBe('post.note.changed')
        ->and($event->broadcastWith())->toBe([
            'post_id' => $this->post->id,
            'change' => 'updated',
        ])
        ->and(array_map(strval(...), $event->broadcastOn()))->toBe([
            "private-post.{$this->post->id}",
            "private-workspace.{$this->workspace->id}",
        ]);
});

test('store emails every workspace member except the author', function () {
    Mail::fake();

    $admin = User::factory()->create();
    $member = User::factory()->create();
    $this->workspace->members()->attach($admin->id, membershipPivot('admin'));
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $outsider = User::factory()->create();
    Workspace::factory()->create(['user_id' => $outsider->id])->members()->attach($outsider->id, membershipPivot('admin'));

    $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => "Can someone review?\nThanks"])
        ->assertCreated();

    $note = PostNote::query()->sole();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($admin->email)
        && $mail->note->is($note)
        && $mail->author->is($this->user));
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($member->email));
    Mail::assertNotQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email));
    Mail::assertNotQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($outsider->email));
});

test('store emails the workspace owner when a member adds a note', function () {
    Mail::fake();

    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $this->actingAs($member)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Looks good'])
        ->assertCreated();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($this->user->email));
});

test('store respects a member who turned note emails off', function () {
    Mail::fake();

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->notificationPreference()->create(['post_note_added' => false]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Hello'])
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

    $response->assertForbidden();
});
