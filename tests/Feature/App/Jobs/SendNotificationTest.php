<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Mail\PostAtRisk;
use App\Mail\PostNoteAdded;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\HasDatabaseNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
});

/**
 * @return array<string, array{0: Type, 1: Closure(User, Workspace): Mailable, 2: string}>
 */
dataset('email notifications', fn (): array => [
    'post published' => [Type::PostPublished, fn (User $user, Workspace $workspace): Mailable => new PostPublished(
        Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]),
    ), 'post_published'],
    'post failed' => [Type::PostFailed, fn (User $user, Workspace $workspace): Mailable => new PostPublishFailed(
        Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]),
    ), 'post_failed'],
    'account disconnected' => [Type::AccountDisconnected, fn (User $user, Workspace $workspace): Mailable => new AccountDisconnected(
        SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]),
    ), 'account_disconnected'],
    'workspace connections disconnected' => [Type::AccountDisconnected, fn (User $user, Workspace $workspace): Mailable => new WorkspaceConnectionsDisconnected(
        $workspace,
        collect([SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id])]),
    ), 'account_disconnected'],
    'post at risk' => [Type::PostAtRisk, fn (User $user, Workspace $workspace): Mailable => new PostAtRisk($workspace, [], 1, $user), 'account_disconnected'],
    'post note added' => [Type::PostNoteAdded, fn (User $user, Workspace $workspace): Mailable => new PostNoteAdded(
        PostNote::factory()->create([
            'post_id' => Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id])->id,
            'user_id' => $user->id,
        ]),
        $user,
    ), 'post_note_added'],
]);

test('it emails the user', function (Type $type, Closure $makeMailable) {
    $mailable = $makeMailable($this->user, $this->workspace);

    (new SendNotification(user: $this->user, type: $type, mailable: $mailable))->handle();

    Mail::assertQueued($mailable::class, fn (Mailable $mail) => $mail->hasTo($this->user->email));
})->with('email notifications');

test('it skips the email when the user turned that preference off', function (Type $type, Closure $makeMailable, string $preference) {
    $this->user->notificationPreference()->create([$preference => false]);

    (new SendNotification(
        user: $this->user->fresh(),
        type: $type,
        mailable: $makeMailable($this->user, $this->workspace),
    ))->handle();

    Mail::assertNothingQueued();
})->with('email notifications');

test('notifications are email only: no notifications table and no database channel', function () {
    expect(Schema::hasTable('notifications'))->toBeFalse()
        ->and(class_uses_recursive(User::class))->not->toContain(HasDatabaseNotifications::class);
});
