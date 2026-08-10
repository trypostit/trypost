<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mail\MentionedInComment;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->author = User::factory()->create(['name' => 'Alice Author']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->author->id]);
    $this->workspace->members()->attach($this->author->id, ['role' => Role::Member->value]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->author->id,
    ]);

    $this->comment = PostComment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->author->id,
        'body' => 'Hey, please review this asap',
    ]);
});

test('subject is localized with author name', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'short excerpt');

    expect($mail->envelope()->subject)->toBe('Alice Author mentioned you on TryPost');
});

test('content view + payload + url include the comment context', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'short excerpt');
    $content = $mail->content();

    expect($content->view)->toBe('mail.mentioned-in-comment');
    expect($content->with['title'])->toBe('Alice Author mentioned you');
    expect($content->with['authorName'])->toBe('Alice Author');
    expect($content->with['excerpt'])->toBe('short excerpt');
    expect($content->with['url'])->toContain((string) $this->post->id);
    expect($content->with['url'])->toContain('tab=comments');
    expect($content->with['url'])->toContain('comment='.$this->comment->id);
});

test('mailable has no attachments', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'x');

    expect($mail->attachments())->toBeEmpty();
});

test('mailable is queueable', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'x');

    expect($mail)->toBeInstanceOf(ShouldQueue::class);
});

test('blade view renders without error', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'short excerpt');
    $rendered = $mail->render();

    expect($rendered)->toContain('Alice Author');
    expect($rendered)->toContain('short excerpt');
});

test('footer links to notification preferences instead of an unsubscribe link', function () {
    $mail = new MentionedInComment($this->comment, $this->author, 'short excerpt');
    $rendered = $mail->render();

    expect($rendered)->toContain('Manage notifications');
    expect($rendered)->toContain(route('app.notifications.preferences'));
    expect($rendered)->not->toContain('Unsubscribe');
});
