<?php

declare(strict_types=1);

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\MetaGraphRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->graph = config('trypost.platforms.facebook.graph_api');
    $this->account = SocialAccount::factory()->facebook()->create(['token_expires_at' => null]);
    $this->client = new class
    {
        use MetaGraphRequests;

        /** @param array<string, mixed> $query */
        public function read(SocialAccount $account, string $url, array $query = []): array
        {
            return $this->graphGet($account, $url, $query);
        }

        /** @param array<string, mixed> $form */
        public function write(SocialAccount $account, string $url, array $form = []): array
        {
            return $this->graphPost($account, $url, $form);
        }

        protected function graphException(Response $response): SocialPublishException
        {
            return FacebookPublishException::fromApiResponse($response);
        }
    };
});

test('code 190 is an expired token', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 400)]);

    $this->client->read($this->account, "{$this->graph}/x");
})->throws(TokenExpiredException::class);

test('a rate limit is transient', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['error' => ['code' => 4]], 400)]);

    $this->client->read($this->account, "{$this->graph}/x");
})->throws(PlatformUnavailableException::class);

test('a server error is transient', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['error' => ['code' => 2]], 500)]);

    $this->client->read($this->account, "{$this->graph}/x");
})->throws(PlatformUnavailableException::class);

test('another rejection is the network exception', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['error' => ['code' => 100, 'message' => 'bad']], 400)]);

    $this->client->read($this->account, "{$this->graph}/x");
})->throws(FacebookPublishException::class);

test('the access token goes in the query and empty parameters are dropped', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['data' => []])]);

    $this->client->read($this->account, "{$this->graph}/x", ['fields' => 'id', 'after' => null]);

    Http::assertSent(fn (Request $request): bool => $request['access_token'] === $this->account->access_token
        && $request['fields'] === 'id'
        && ! str_contains($request->url(), 'after='));
});

test('a post is sent as a form with the access token', function () {
    Http::fake(["{$this->graph}/x*" => Http::response(['id' => 'c9'])]);

    expect($this->client->write($this->account, "{$this->graph}/x", ['message' => 'hi']))->toBe(['id' => 'c9']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->isForm()
        && $request['message'] === 'hi'
        && $request['access_token'] === $this->account->access_token);
});

test('an unreachable graph is transient and never carries the token', function () {
    Http::fake(fn () => throw new ConnectionException("cURL error 28: timed out for {$this->graph}/x?access_token=SECRET"));

    try {
        $this->client->read($this->account, "{$this->graph}/x");
        $this->fail('Expected the request to be reported unavailable.');
    } catch (PlatformUnavailableException $exception) {
        expect($exception->getMessage())->not->toContain('SECRET')
            ->and($exception->getMessage())->not->toContain('http');
    }
});
