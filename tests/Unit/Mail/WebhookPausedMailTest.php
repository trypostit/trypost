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

    expect($mail->envelope()->subject)->toBe(__('mail.webhook_paused.subject', [
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
        ->and($content->with['title'])->toBe(__('mail.webhook_paused.title'))
        ->and($content->with['previewText'])->toBe(__('mail.webhook_paused.preview'))
        ->and($content->with['endpoint'])->toBe('https://example.com/hooks')
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

    $mail->assertSeeInHtml(__('mail.webhook_paused.title'));
    $mail->assertSeeInHtml(__('mail.webhook_paused.body', [
        'endpoint' => 'https://example.com/hooks',
    ]));
    $mail->assertSeeInHtml(__('mail.webhook_paused.button'));
    $mail->assertSeeInHtml(route('app.webhooks.show', $webhook));
    $mail->assertSeeInHtml('Manage notifications');
    $mail->assertSeeInHtml(route('app.notifications.preferences'));
});
