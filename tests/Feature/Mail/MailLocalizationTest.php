<?php

declare(strict_types=1);

use App\Actions\Invite\CreateInvite;
use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Mail\WebhookPausedMail;
use App\Mail\WorkspaceInvite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

function localizedOwner(Locale $locale): User
{
    $user = User::factory()->create(['locale' => $locale]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user;
}

test('an email is rendered in the recipient locale, not the app default', function () {
    Mail::fake();

    $user = localizedOwner(Locale::PortugueseBrazil);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform' => Platform::LinkedIn,
    ]);

    (new SendNotification(
        user: $user,
        workspaceId: $user->current_workspace_id,
        type: Type::AccountDisconnected,
        channel: Channel::Email,
        title: 'x',
        body: 'x',
        mailable: new AccountDisconnected($account),
    ))->handle();

    Mail::assertQueued(AccountDisconnected::class, function (AccountDisconnected $mail) {
        return $mail->locale === Locale::PortugueseBrazil->value;
    });
});

test('each recipient gets their own locale for the same mailable', function () {
    Mail::fake();

    foreach ([Locale::Japanese, Locale::German] as $locale) {
        $user = localizedOwner($locale);
        $account = SocialAccount::factory()->create([
            'workspace_id' => $user->current_workspace_id,
            'platform' => Platform::LinkedIn,
        ]);

        (new SendNotification(
            user: $user,
            workspaceId: $user->current_workspace_id,
            type: Type::AccountDisconnected,
            channel: Channel::Email,
            title: 'x',
            body: 'x',
            mailable: new AccountDisconnected($account),
        ))->handle();
    }

    foreach ([Locale::Japanese, Locale::German] as $locale) {
        Mail::assertQueued(
            AccountDisconnected::class,
            fn (AccountDisconnected $mail) => $mail->locale === $locale->value,
        );
    }
});

test('the subject and body of a rendered mailable are actually translated', function () {
    $user = localizedOwner(Locale::PortugueseBrazil);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform' => Platform::LinkedIn,
    ]);

    $mailable = (new AccountDisconnected($account))->locale(Locale::PortugueseBrazil->value);

    $mailable->assertHasSubject(__('mail.account_disconnected.subject', [
        'platform' => $account->platform->label(),
        'workspace' => $account->workspace->name,
    ], 'pt-BR'));

    $mailable->assertSeeInHtml(__('mail.account_disconnected.heading', [], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.account_disconnected.reason_expired', [], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.layout.tagline', [], 'pt-BR'));
    $mailable->assertDontSeeInHtml(__('mail.account_disconnected.heading', [], 'en'));
});

test('an invite is sent in the locale of whoever sent it', function () {
    Mail::fake();

    $inviter = localizedOwner(Locale::Spanish);
    $this->actingAs($inviter);

    CreateInvite::execute($inviter->currentWorkspace, [
        'email' => 'invitee@example.com',
        'role' => Role::Member->value,
    ]);

    Mail::assertQueued(
        WorkspaceInvite::class,
        fn (WorkspaceInvite $mail) => $mail->locale === Locale::Spanish->value,
    );
});

test('a paused webhook is reported in the account owner locale', function () {
    Mail::fake();

    $owner = localizedOwner(Locale::Turkish);
    $webhook = Webhook::factory()->create(['workspace_id' => $owner->current_workspace_id]);

    Mail::to($owner)->send(new WebhookPausedMail($webhook));

    Mail::assertQueued(
        WebhookPausedMail::class,
        fn (WebhookPausedMail $mail) => $mail->locale === Locale::Turkish->value,
    );
});
