<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mail\AccountDisconnected;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;

test('account disconnected mail has correct subject', function () {
    $workspace = Workspace::factory()->create(['name' => 'My Workspace']);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $mail = new AccountDisconnected($account);

    expect($mail->envelope()->subject)->toBe('Your Instagram account in My Workspace needs to be reconnected');
});

test('account disconnected mail has correct content', function () {
    $workspace = Workspace::factory()->create(['name' => 'Test Team']);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'display_name' => 'John Doe',
        'username' => 'johndoe',
    ]);

    $mail = new AccountDisconnected($account);
    $content = $mail->content();

    expect($content->view)->toBe('mail.account-disconnected');
    expect($content->with['title'])->toBe('Your LinkedIn account needs to be reconnected');
    expect($content->with['previewText'])->toContain('LinkedIn');
    expect($content->with['previewText'])->toContain('Test Team');
    expect($content->with['platformName'])->toBe('LinkedIn');
    expect($content->with['accountName'])->toBe('John Doe');
    expect($content->with['workspaceName'])->toBe('Test Team');
    expect($content->with['url'])->toBe(route('app.accounts'));
});

test('account disconnected mail uses username when display name is null', function () {
    $account = SocialAccount::factory()->create([
        'display_name' => null,
        'username' => 'testuser',
    ]);

    $mail = new AccountDisconnected($account);
    $content = $mail->content();

    expect($content->with['accountName'])->toBe('testuser');
});

test('account disconnected mail has no attachments', function () {
    $account = SocialAccount::factory()->create();

    $mail = new AccountDisconnected($account);

    expect($mail->attachments())->toBeEmpty();
});

test('account disconnected mail is queueable', function () {
    $account = SocialAccount::factory()->create();

    $mail = new AccountDisconnected($account);

    expect($mail)->toBeInstanceOf(ShouldQueue::class);
});
