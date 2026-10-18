<?php

declare(strict_types=1);

use App\Actions\Inbox\NotifyInboxActivity;
use App\Actions\Inbox\RecountInboxConversation;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Notification\Type;
use App\Jobs\Inbox\SendInboxActivityEmails;
use App\Jobs\SendNotification;
use App\Mail\InboxActivity;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    Queue::fake([SendNotification::class]);

    $this->post = Post::factory()->forAccount(SocialAccount::factory()->bluesky()->create())->published()->create(['published_at' => now()->subHours(3)]);
    $this->conversation = InboxConversation::factory()->linkedTo($this->post)->create();
    $this->workspace = $this->conversation->workspace;
    $this->owner = $this->workspace->account->owner;
});

function inboxActivityComment(InboxConversation $conversation, array $attributes = []): InboxMessage
{
    $message = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_created_at' => now()->subMinutes(30),
        ...$attributes,
    ]);

    RecountInboxConversation::execute($conversation);

    return $message;
}

test('new comments on a recent post email the people who publish directly, once', function () {
    $directMember = workspaceMember($this->workspace, 'member');
    $approvalMember = workspaceMember($this->workspace, 'approval');
    inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:ana']);
    inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:ana']);
    inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:bia']);

    expect(NotifyInboxActivity::execute())->toBe(1);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner)
        && $job->type === Type::InboxActivity
        && $job->mailable instanceof InboxActivity
        && $job->mailable->conversationId === $this->conversation->id
        && $job->mailable->peopleCount === 2
        && $job->mailable->recipient->is($this->owner));
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($directMember));
    Queue::assertNotPushed(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($approvalMember));
    expect($this->conversation->fresh()->notified_at?->toDateTimeString())->toBe('2026-10-16 12:00:00');

    expect(NotifyInboxActivity::execute())->toBe(0);
    Queue::assertPushed(SendNotification::class, 2);
});

test('a comment synced after the email triggers the next one, even when it was written before', function () {
    inboxActivityComment($this->conversation);
    NotifyInboxActivity::execute();

    $this->travel(1)->hours();
    inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:late', 'platform_created_at' => now()->subMinutes(70)]);

    expect(NotifyInboxActivity::execute())->toBe(1);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable->peopleCount === 1
        && $job->mailable->conversationId === $this->conversation->id);
    Queue::assertPushed(SendNotification::class, 2);
});

test('a post published more than a day ago sends nothing', function () {
    $this->post->update(['published_at' => now()->subHours(25)]);
    inboxActivityComment($this->conversation);

    expect(NotifyInboxActivity::execute())->toBe(0);
    Queue::assertNothingPushed();
});

test('a comment conversation whose post date is unknown sends nothing', function () {
    $this->post->forceFill(['published_at' => null])->save();
    inboxActivityComment($this->conversation);

    expect(NotifyInboxActivity::execute())->toBe(0);
});

test('a new review is emailed whatever the age of the post', function () {
    $review = InboxConversation::factory()->create([
        'social_account_id' => $this->conversation->social_account_id,
        'kind' => ConversationKind::Review,
        'rating' => 4,
        'subject_published_at' => null,
    ]);
    inboxActivityComment($review);

    expect(NotifyInboxActivity::execute())->toBe(1);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable->conversationId === $review->id);
});

test('backfilled activity older than a day sends nothing', function () {
    $review = InboxConversation::factory()->create([
        'social_account_id' => $this->conversation->social_account_id,
        'kind' => ConversationKind::Review,
        'subject_published_at' => null,
    ]);
    inboxActivityComment($review, ['platform_created_at' => now()->subDays(6)]);

    expect(NotifyInboxActivity::execute())->toBe(0);
    expect($review->fresh()->notified_at)->toBeNull();
});

test('answered, resolved and removed comments do not count', function () {
    inboxActivityComment($this->conversation, ['answered_at' => now()]);
    inboxActivityComment($this->conversation, ['resolved_at' => now()]);
    inboxActivityComment($this->conversation, ['removed_at' => now()]);

    expect(NotifyInboxActivity::execute())->toBe(0);
});

test('mentions and direct messages are not emailed', function (ConversationKind $kind) {
    $other = InboxConversation::factory()->create([
        'social_account_id' => $this->conversation->social_account_id,
        'kind' => $kind,
    ]);
    inboxActivityComment($other);

    expect(NotifyInboxActivity::execute())->toBe(0);
})->with([ConversationKind::Mention, ConversationKind::DirectMessage]);

test('nothing is sent while the inbox is off', function () {
    inboxActivityComment($this->conversation);
    config()->set('trypost.inbox.enabled', false);

    expect(NotifyInboxActivity::execute())->toBe(0);
    expect($this->conversation->fresh()->notified_at)->toBeNull();
});

test('a workspace without app access is skipped', function () {
    inboxActivityComment($this->conversation);
    config()->set('trypost.self_hosted', false);
    $this->workspace->account->subscriptions()->delete();

    expect(NotifyInboxActivity::execute())->toBe(0);
    expect($this->conversation->fresh()->notified_at)->toBeNull();
});

test('the hourly job runs the action on the inbox queue', function () {
    inboxActivityComment($this->conversation);

    $job = new SendInboxActivityEmails;
    $job->handle();

    expect($job->queue)->toBe('inbox');
    Queue::assertPushed(SendNotification::class);
});

test('the activity emails are scheduled hourly on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'inbox:send-activity-emails');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('an activity email is dropped once its conversation has nothing open', function () {
    Mail::fake();
    $comment = inboxActivityComment($this->conversation);
    $comment->update(['resolved_at' => now()]);
    RecountInboxConversation::execute($this->conversation);

    (new SendNotification($this->owner, Type::InboxActivity, new InboxActivity($this->conversation->id, 1, $this->owner)))->handle();

    Mail::assertNothingQueued();
});

test('an activity email is sent while something is open, unless the user turned it off', function () {
    Mail::fake();
    inboxActivityComment($this->conversation);

    (new SendNotification($this->owner, Type::InboxActivity, new InboxActivity($this->conversation->id, 1, $this->owner)))->handle();
    Mail::assertQueued(InboxActivity::class, fn (InboxActivity $mail): bool => $mail->hasTo($this->owner->email));

    NotificationPreference::factory()->create(['user_id' => $this->owner->id, 'inbox_activity' => false]);
    Mail::fake();

    (new SendNotification($this->owner->fresh(), Type::InboxActivity, new InboxActivity($this->conversation->id, 1, $this->owner)))->handle();
    Mail::assertNothingQueued();
});

test('notified_at marks the newest message counted, so one stored meanwhile is emailed next', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 11:50:00', 'UTC'));
    inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:ana']);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));

    expect(NotifyInboxActivity::execute())->toBe(1)
        ->and($this->conversation->fresh()->notified_at?->toDateTimeString())->toBe('2026-10-16 11:50:00');

    $late = inboxActivityComment($this->conversation, ['author_platform_id' => 'did:plc:bia']);
    $late->forceFill(['created_at' => CarbonImmutable::parse('2026-10-16 11:55:00', 'UTC')])->save();

    expect(NotifyInboxActivity::execute())->toBe(1);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable->peopleCount === 1);
    expect($this->conversation->fresh()->notified_at?->toDateTimeString())->toBe('2026-10-16 11:55:00');
});

test('an activity email is dropped once the inbox is turned off', function () {
    Mail::fake();
    inboxActivityComment($this->conversation);
    config()->set('trypost.inbox.enabled', false);

    (new SendNotification($this->owner, Type::InboxActivity, new InboxActivity($this->conversation->id, 1, $this->owner)))->handle();

    Mail::assertNothingQueued();
});

test('a queued activity email whose conversation was deleted is dropped without an error', function () {
    config()->set('mail.default', 'array');
    $mailable = new InboxActivity($this->conversation->id, 1, $this->owner);
    $this->conversation->messages()->delete();
    $this->conversation->delete();

    expect($mailable->send(app('mailer')))->toBeNull()
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($mailable->render())->toContain(e(__('mail.inbox_activity.title')));
});

test('a queued activity email is dropped once its recipient no longer publishes directly', function () {
    Mail::fake();
    inboxActivityComment($this->conversation);
    $member = workspaceMember($this->workspace, 'member');
    $job = new SendNotification($member, Type::InboxActivity, new InboxActivity($this->conversation->id, 1, $member));
    $this->workspace->members()->updateExistingPivot($member->id, membershipPivot('approval'));

    $job->handle();

    Mail::assertNothingQueued();
});
