<?php

declare(strict_types=1);

use App\Exceptions\Social\DiscordPublishException;
use Illuminate\Support\Facades\Http;

test('only a Discord rejection the server owner can fix is marked as a network rejection', function (int $status, int $code, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response(['code' => $code, 'message' => 'Rejected'], $status)])
        ->post('https://discord.com/api/v10/channels/1/messages');

    expect(DiscordPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'bot missing permissions in the channel' => [403, 50013, true],
    'attachment too large' => [413, 40005, true],
    'a channel id we sent that does not exist' => [404, 10003, false],
    'our bot rate limit' => [429, 0, false],
]);
