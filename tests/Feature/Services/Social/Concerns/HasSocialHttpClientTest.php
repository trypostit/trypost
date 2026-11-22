<?php

declare(strict_types=1);

use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new class
    {
        use HasSocialHttpClient;

        public function makeRequest(string $url): Response
        {
            return $this->socialHttp()->get($url);
        }
    };
});

it('retries on 429 responses', function () {
    Http::fake([
        'https://example.com/api' => Http::sequence()
            ->push('Rate limited', 429)
            ->push(['success' => true], 200),
    ]);

    $response = $this->client->makeRequest('https://example.com/api');

    expect($response->status())->toBe(200);
    Http::assertSentCount(2);
});

it('does not retry on non-429 errors', function () {
    Http::fake([
        'https://example.com/api' => Http::response('Server error', 500),
    ]);

    $response = $this->client->makeRequest('https://example.com/api');

    expect($response->status())->toBe(500);
    Http::assertSentCount(1);
});

it('gives up after 3 retries', function () {
    Http::fake([
        'https://example.com/api' => Http::sequence()
            ->push('Rate limited', 429)
            ->push('Rate limited', 429)
            ->push('Rate limited', 429),
    ]);

    $response = $this->client->makeRequest('https://example.com/api');

    expect($response->status())->toBe(429);
    Http::assertSentCount(3);
});

it('returns successful response normally', function () {
    Http::fake([
        'https://example.com/api' => Http::response(['data' => 'ok'], 200),
    ]);

    $response = $this->client->makeRequest('https://example.com/api');

    expect($response->status())->toBe(200);
    Http::assertSentCount(1);
});
