<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Mail\WorkspaceInvite;
use App\Models\Account;
use App\Models\Invite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Every template's copy moved out of PHP and into `__()` calls in the Blade
 * view, so a variable the Mailable stopped passing now only shows up when the
 * view is actually rendered. These render the four templates no other test does.
 */
test('the workspace invite renders in the requested locale', function () {
    $account = Account::factory()->create(['name' => 'Acme Co']);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'email' => 'invitee@example.com',
        'role' => Role::Member,
    ]);

    $mailable = (new WorkspaceInvite($invite))->locale(Locale::PortugueseBrazil->value);

    $mailable->assertHasSubject(__('mail.workspace_invite.subject', ['account' => 'Acme Co'], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.workspace_invite.heading', [], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.workspace_invite.expiry', [], 'pt-BR'));
    $mailable->assertSeeInHtml('Acme Co');
    $mailable->assertSeeInHtml(Role::Member->label());
});

test('the disconnected-connections digest renders every account and reason', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Acme Workspace',
    ]);

    $accounts = collect([Platform::LinkedIn, Platform::X])->map(
        fn (Platform $platform) => SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
        ]),
    );

    $mailable = (new WorkspaceConnectionsDisconnected($workspace, $accounts))
        ->locale(Locale::German->value);

    $mailable->assertHasSubject(trans_choice(
        'mail.workspace_connections_disconnected.subject',
        2,
        ['count' => 2, 'workspace' => 'Acme Workspace'],
        'de',
    ));

    $mailable->assertSeeInHtml(__('mail.workspace_connections_disconnected.heading', [], 'de'));
    $mailable->assertSeeInHtml(__('mail.workspace_connections_disconnected.reason_revoked', [], 'de'));
    $mailable->assertSeeInHtml('Acme Workspace');

    foreach ($accounts as $account) {
        $mailable->assertSeeInHtml($account->platform->label());
    }
});

/**
 * Send a notification for real (the `array` mailer swallows it) and return the
 * HTML that came out. Going through the notification sender is the point: it is
 * what reads `preferredLocale()` off the user, so rendering `toMail()` by hand
 * would pass even with no localization at all.
 */
function sentNotificationHtml(User $user, BaseNotification $notification): string
{
    Mail::mailer()->getSymfonyTransport()->messages()->take(0);

    $user->notify($notification);

    $message = Mail::mailer()->getSymfonyTransport()->messages()->last();

    return (string) $message->getOriginalMessage()->getHtmlBody();
}

test('the verification email is sent in the user locale', function () {
    $user = User::factory()->create(['locale' => Locale::Spanish]);

    expect(sentNotificationHtml($user, new VerifyEmail))
        ->toContain(__('mail.email_verification.body', [], 'es'))
        ->toContain(__('mail.email_verification.button', [], 'es'))
        ->toContain(__('mail.layout.team', [], 'es'))
        ->not->toContain(__('mail.email_verification.body', [], 'en'));
});

test('the password reset email is sent in the user locale', function () {
    $user = User::factory()->create(['locale' => Locale::Japanese]);

    expect(sentNotificationHtml($user, new ResetPassword('token-123')))
        ->toContain(__('mail.password_reset.body', [], 'ja'))
        ->toContain(__('mail.password_reset.expiry', [], 'ja'))
        ->not->toContain(__('mail.password_reset.body', [], 'en'));
});
