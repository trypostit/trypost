<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Mail\WebhookPausedMail;
use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\DomCrawler\Crawler;

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

test('webhook paused mail separates the endpoint from the explanation in every locale', function (string $locale) {
    app()->setLocale($locale);

    $webhook = Webhook::factory()->create([
        'endpoint' => 'https://example.com/hooks',
    ]);

    $mail = new WebhookPausedMail($webhook);

    $mail->assertSeeInHtml(__('mail.webhook_paused.title'));
    $mail->assertSeeInOrderInHtml([
        __('mail.webhook_paused.body'),
        __('webhooks.create.endpoint'),
        'https://example.com/hooks',
        __('mail.webhook_paused.next_steps'),
    ]);
    $mail->assertSeeInHtml(__('mail.webhook_paused.button'));
    $mail->assertSeeInHtml(route('app.webhooks.show', $webhook));
    $mail->assertSeeInHtml(__('mail.layout.manage_notifications'));
    $mail->assertSeeInHtml(route('app.notifications.preferences'));

    $document = new Crawler($mail->render());

    expect($document->filter('p[dir="ltr"]')->text())->toBe($webhook->endpoint);
})->with(Locale::values());

test('the highlighted webhook endpoint is escaped as text', function () {
    $endpoint = 'https://example.com/hooks?label=<script>alert(1)</script>&topic=posts';
    $webhook = Webhook::factory()->create(['endpoint' => $endpoint]);
    $html = (new WebhookPausedMail($webhook))->render();
    $document = new Crawler($html);

    expect($document->filter('p[dir="ltr"]')->text())->toBe($endpoint)
        ->and($document->filter('script')->count())->toBe(0);
});
