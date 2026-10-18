<?php

declare(strict_types=1);

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxMessage;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

test('characters count code points and bytes count utf-8 bytes', function () {
    expect(ReplyLengthUnit::Characters->measure('olá 😀'))->toBe(5)
        ->and(ReplyLengthUnit::Bytes->measure('olá 😀'))->toBe(9);
});

test('a reply is measured in the unit of its network', function () {
    Queue::fake();
    $parent = InboxMessage::factory()->create();
    $driver = Mockery::mock(InboxDriver::class);
    $driver->shouldReceive('replyLength')->andReturn(8);
    $driver->shouldReceive('replyLengthUnit')->andReturn(ReplyLengthUnit::Bytes);
    $drivers = Mockery::mock(InboxDrivers::class);
    $drivers->shouldReceive('for')->andReturn($driver);
    app()->instance(InboxDrivers::class, $drivers);
    $user = $parent->conversation->workspace->owner;

    expect(fn () => ReplyToInboxMessage::execute($parent, $user, '😀😀😀'))->toThrow(ValidationException::class);
    expect(ReplyToInboxMessage::execute($parent, $user, '😀😀')->body)->toBe('😀😀');
});

test('every inbox driver measures its replies, in bytes only on Google Business Profile', function (Platform $platform, ReplyLengthUnit $unit) {
    expect(app(InboxDrivers::class)->for($platform)->replyLengthUnit())->toBe($unit);
})->with([
    [Platform::Bluesky, ReplyLengthUnit::Characters],
    [Platform::Instagram, ReplyLengthUnit::Characters],
    [Platform::InstagramFacebook, ReplyLengthUnit::Characters],
    [Platform::Facebook, ReplyLengthUnit::Characters],
    [Platform::Threads, ReplyLengthUnit::Characters],
    [Platform::YouTube, ReplyLengthUnit::Characters],
    [Platform::Mastodon, ReplyLengthUnit::Characters],
    [Platform::GoogleBusiness, ReplyLengthUnit::Bytes],
]);

test('a reply over a byte limit says bytes, one over a character limit says characters', function (ReplyLengthUnit $unit, string $word) {
    Queue::fake();
    $parent = InboxMessage::factory()->create();
    $driver = Mockery::mock(InboxDriver::class);
    $driver->shouldReceive('replyLength')->andReturn(2);
    $driver->shouldReceive('replyLengthUnit')->andReturn($unit);
    $drivers = Mockery::mock(InboxDrivers::class);
    $drivers->shouldReceive('for')->andReturn($driver);
    app()->instance(InboxDrivers::class, $drivers);

    try {
        ReplyToInboxMessage::execute($parent, $parent->conversation->workspace->owner, 'abc');
        $this->fail('The reply was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['text'][0])->toContain($word);
    }
})->with([
    'bytes' => [ReplyLengthUnit::Bytes, 'bytes'],
    'characters' => [ReplyLengthUnit::Characters, 'characters'],
]);

test('russian, ukrainian and polish counts follow their plural rules', function (string $locale, string $key, int $count, string $expected) {
    app()->setLocale($locale);

    expect(trans_choice($key, $count, ['count' => $count]))->toBe($expected);
})->with([
    ['ru', 'inbox.direct.open_count', 21, '21 сообщение ждёт ответа'],
    ['ru', 'inbox.direct.open_count', 22, '22 сообщения ждут ответа'],
    ['ru', 'inbox.direct.open_count', 25, '25 сообщений ждут ответа'],
    ['ru', 'inbox.direct.open_count', 0, '0 сообщений ждут ответа'],
    ['ru', 'inbox.errors.window_hours', 21, '21 часа'],
    ['uk', 'inbox.direct.open_count', 23, '23 повідомлення чекають відповіді'],
    ['pl', 'inbox.direct.open_count', 22, '22 wiadomości do odpowiedzi'],
    ['pl', 'inbox.errors.window_hours', 1, '1 godziny'],
]);
