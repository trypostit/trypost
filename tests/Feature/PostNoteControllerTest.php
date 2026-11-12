<?php

declare(strict_types=1);

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
            'body' => "@[{$mentioned->name}](user:{$mentioned->id}) and @[{$outsider->name}](user:{$outsider->id}) and @[Me](user:{$writer->id}) take a look",
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
        'body' => "@[{$alreadyMentioned->name}](user:{$alreadyMentioned->id}) ping",
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), [
            'body' => "@[{$alreadyMentioned->name}](user:{$alreadyMentioned->id}) and @[{$newlyMentioned->name}](user:{$newlyMentioned->id}) ping",
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
            'body' => "@[Me](user:{$this->user->id}) @[{$outsider->name}](user:{$outsider->id}) ping",
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

    $this->actingAs($this->user)
        ->getJson(route('app.posts.notes.index', $this->post))
        ->assertOk()
        ->assertJsonPath('data.0.reactions.0.count', 1);

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
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => '@[todos](everyone) take a look'])
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
        ->postJson(route('app.posts.notes.store', $pending), ['body' => '@[all](everyone) ready?'])
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
        'body' => "@[{$alreadyMentioned->name}](user:{$alreadyMentioned->id}) ping",
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $note]), ['body' => '@[all](everyone) ping'])
        ->assertOk();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($newlyMentioned->email));
});

test('a saved mention carries the person\'s real name, and a mention of someone outside the post becomes plain text', function () {
    $teammate = workspaceMember($this->workspace, 'member', ['name' => 'Bia Lima']);
    $outsider = workspaceOutsider($this->workspace);

    $created = $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), [
            'body' => "@[The boss](user:{$teammate->id}) and @[Someone](user:{$outsider->id})",
        ])
        ->assertCreated();

    expect(PostNote::query()->sole()->body)->toBe("@[Bia Lima](user:{$teammate->id}) and @Someone");

    $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $created->json('id')]), [
            'body' => "@[Fake](user:{$teammate->id}) again",
        ])
        ->assertOk()
        ->assertJsonPath('body', "@[Bia Lima](user:{$teammate->id}) again");

    $teammate->update(['name' => '[] ']);
    $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => "@[Bia](user:{$teammate->id}) hi"])
        ->assertCreated()
        ->assertJsonPath('body', "@[?](user:{$teammate->id}) hi");
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
            'body' => "Pricing changes Friday, @[{$bystander->name}](user:{$bystander->id}) @[{$this->user->name}](user:{$this->user->id})",
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
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => "Hello @[{$member->name}](user:{$member->id})"])
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

    $response->assertNotFound();
});

test('notes expose only the author id, name and photo', function () {
    $this->user->update([
        'registration_ip' => '203.0.113.7',
        'google_id' => 'google-123',
        'utm_source' => 'newsletter',
    ]);
    PostNote::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->user->id]);

    $index = $this->actingAs($this->user)
        ->getJson(route('app.posts.notes.index', $this->post))
        ->assertOk();
    $store = $this->actingAs($this->user)
        ->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Hi'])
        ->assertCreated();
    $update = $this->actingAs($this->user)
        ->putJson(route('app.posts.notes.update', [$this->post, $store->json('id')]), ['body' => 'Edited'])
        ->assertOk();

    foreach ([$index->json('data.0.user'), $store->json('user'), $update->json('user')] as $author) {
        expect(array_keys($author))->toEqualCanonicalizing(['id', 'name', 'photo_url'])
            ->and($author['id'])->toBe($this->user->id);
    }

    foreach ([$index, $store, $update] as $response) {
        foreach (['email', 'registration_ip', 'google_id', 'github_id', 'utm_source', 'persona', '203.0.113.7', $this->user->email] as $leak) {
            expect($response->getContent())->not->toContain($leak);
        }
    }

    expect($index->json('meta.current_page'))->toBe(1)
        ->and($index->json('meta.last_page'))->toBe(1);
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

    $this->actingAs($other)->getJson(route('app.posts.notes.index', $pending))->assertNotFound();
    $this->actingAs($other)->postJson(route('app.posts.notes.store', $pending), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($other)->putJson(route('app.posts.notes.update', [$pending, $note]), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($other)->deleteJson(route('app.posts.notes.destroy', [$pending, $note]))->assertNotFound();

    expect(PostNote::query()->count())->toBe(1);
});

test('a user outside the workspace cannot read or add notes', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->getJson(route('app.posts.notes.index', $this->post))->assertForbidden();
    $this->actingAs($outsider)->postJson(route('app.posts.notes.store', $this->post), ['body' => 'Hi'])->assertForbidden();
});
