<?php

declare(strict_types=1);

use App\Mail\WebhookPausedMail;
use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;

test('webhook paused mail has the translated subject', function () {
    $webhook = Webhook::factory()->create([
        'endpoint' => 'https://example.com/hooks',
    ]);

    $mail = new WebhookPausedMail($webhook);

    expect($mail->envelope()->subject)->toBe(__('webhooks.mail.paused_subject', [
        'endpoint' => 'https://example.com/hooks',
    ]));
});

test('webhook paused mail has the translated content', function () {
    $webhook = Webhook::factory()->create([
        'endpoint' => 'https://example.com/hooks',
    ]);

    $mail = new WebhookPausedMail($webhook);
    $content = $mail->content();

    expect($content->view)->toBe('mail.webhook-paused')
        ->and($content->with['title'])->toBe(__('webhooks.mail.paused_title'))
        ->and($content->with['previewText'])->toBe(__('webhooks.mail.paused_preview'))
        ->and($content->with['body'])->toBe(__('webhooks.mail.paused_body', [
            'endpoint' => 'https://example.com/hooks',
        ]))
        ->and($content->with['buttonText'])->toBe(__('webhooks.mail.paused_cta'))
        ->and($content->with['url'])->toBe(route('app.webhooks.show', $webhook));
});

test('webhook paused mail is queueable', function () {
    $webhook = Webhook::factory()->create();

    expect(new WebhookPausedMail($webhook))->toBeInstanceOf(ShouldQueue::class);
});

test('webhook paused mail renders the maizzle layout', function () {
    $webhook = Webhook::factory()->create([
        'endpoint' => 'https://example.com/hooks',
    ]);

    $mail = new WebhookPausedMail($webhook);

    $mail->assertSeeInHtml(__('webhooks.mail.paused_title'));
    $mail->assertSeeInHtml(__('webhooks.mail.paused_body', [
        'endpoint' => 'https://example.com/hooks',
    ]));
    $mail->assertSeeInHtml(__('webhooks.mail.paused_cta'));
    $mail->assertSeeInHtml(route('app.webhooks.show', $webhook));
    $mail->assertSeeInHtml('Manage notifications');
    $mail->assertSeeInHtml(route('app.notifications.preferences'));
});
