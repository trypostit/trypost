<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use Illuminate\Support\Facades\Mail;

/** The HTML body of every message the array mailer captured, keyed by subject. */
function capturedPreviewEmails(): array
{
    return collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->mapWithKeys(fn ($sent) => [
            (string) $sent->getOriginalMessage()->getSubject() => (string) $sent->getOriginalMessage()->getHtmlBody(),
        ])
        ->all();
}

test('the preview sends one of every email', function () {
    $this->artisan('mail:preview', ['email' => 'preview@example.com'])
        ->assertSuccessful();

    expect(capturedPreviewEmails())->toHaveCount(10);
});

test('the preview leaves no sample records behind', function () {
    $this->artisan('mail:preview', ['email' => 'preview@example.com'])->assertSuccessful();

    $this->assertDatabaseMissing('workspaces', ['name' => 'Preview Workspace']);
    $this->assertDatabaseMissing('accounts', ['name' => 'Preview Account']);
    $this->assertDatabaseMissing('invites', ['email' => 'preview@example.com']);
});

/**
 * Verification and password reset are notifications rather than Mailables, so
 * the command builds and renders them by hand. Rendering outside the locale
 * scope used to leave the subject translated and the body in English.
 */
test('the preview renders the notification bodies in the requested locale', function (string $key) {
    $this->artisan('mail:preview', [
        'email' => 'preview@example.com',
        '--locale' => Locale::PortugueseBrazil->value,
    ])->assertSuccessful();

    $subject = __("mail.{$key}.subject", [], 'pt-BR');
    $body = __("mail.{$key}.body", [], 'pt-BR');

    $emails = capturedPreviewEmails();

    expect($emails)->toHaveKey($subject);
    expect($emails[$subject])
        ->toContain($body)
        ->not->toContain(__("mail.{$key}.body", [], 'en'));
})->with(['password_reset', 'email_verification']);

test('the preview renders every mailable in the requested locale', function () {
    $this->artisan('mail:preview', [
        'email' => 'preview@example.com',
        '--locale' => Locale::Japanese->value,
    ])->assertSuccessful();

    $bodies = implode("\n", capturedPreviewEmails());

    expect($bodies)
        ->toContain(__('mail.layout.tagline', [], 'ja'))
        ->not->toContain(__('mail.layout.tagline', [], 'en'));
});
