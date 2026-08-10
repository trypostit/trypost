<?php

declare(strict_types=1);

use App\Mail\WorkspaceInvite;
use App\Models\Account;
use App\Models\Invite;
use Illuminate\Contracts\Queue\ShouldQueue;

test('workspace invite mail has correct subject', function () {
    $account = Account::factory()->create(['name' => 'Test Account']);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
    ]);

    $mail = new WorkspaceInvite($invite);

    expect($mail->envelope()->subject)->toBe("You've been invited to join Test Account");
});

test('workspace invite mail has correct content', function () {
    $account = Account::factory()->create(['name' => 'My Team']);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
    ]);

    $mail = new WorkspaceInvite($invite);
    $content = $mail->content();

    expect($content->view)->toBe('mail.workspace-invite');
    expect($content->with['title'])->toBe("You've been invited to join My Team");
    expect($content->with['previewText'])->toBe("You've been invited to join My Team");
    expect($content->with['accountName'])->toBe('My Team');
    expect($content->with['roleLabel'])->toBeString();
    expect($content->with['url'])->toBe(route('app.invites.show', $invite->id));
});

test('workspace invite mail has no attachments', function () {
    $invite = Invite::factory()->create();

    $mail = new WorkspaceInvite($invite);

    expect($mail->attachments())->toBeEmpty();
});

test('workspace invite mail is queueable', function () {
    $invite = Invite::factory()->create();

    $mail = new WorkspaceInvite($invite);

    expect($mail)->toBeInstanceOf(ShouldQueue::class);
});

test('footer has no manage-notifications or unsubscribe link — this is a transactional email, not preference-driven', function () {
    $invite = Invite::factory()->create();

    $mail = new WorkspaceInvite($invite);

    $mail->assertDontSeeInHtml('Manage notifications');
    $mail->assertDontSeeInHtml('Unsubscribe');
});
