<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('the composer reconnects every network through its connect route, a linkedin page through linkedin', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());
    $process = (new Process([$node, base_path('tests/fixtures/connect-url-harness.ts'), resource_path('js'), ...$platforms]))->mustRun();
    $urls = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $reconnect = ['return_to' => '/schedule', 'reconnect' => 'channel-1'];

    expect($urls[Platform::LinkedInPage->value])->toBe(route('app.social.linkedin.connect', $reconnect, false))
        ->and($urls[Platform::LinkedIn->value])->toBe(route('app.social.linkedin.connect', $reconnect, false))
        ->and($urls[Platform::Mastodon->value])->toBe(route('app.social.mastodon.connect', $reconnect, false))
        ->and($urls[Platform::Telegram->value])->toBeNull()
        ->and(collect($urls)->except(Platform::Telegram->value)->filter()->keys()->all())
        ->toBe(collect($platforms)->reject(fn (string $platform): bool => $platform === Platform::Telegram->value)->values()->all());
});
