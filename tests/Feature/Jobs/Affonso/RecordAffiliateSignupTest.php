<?php

declare(strict_types=1);

use App\Jobs\Affonso\RecordAffiliateSignup;
use App\Models\User;
use App\Services\Affonso\AffonsoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.affonso.api_key' => 'sk_live_test']);
    $this->user = User::factory()->create(['email' => 'referred@example.com']);
});

test('the lead is sent to affonso with the click, the email and the user id', function () {
    Http::fake([config('services.affonso.api').'/signups' => Http::response(['success' => true], 201)]);

    $job = (new RecordAffiliateSignup($this->user->id, 'clk_123'))->withFakeQueueInteractions();
    $job->handle(app(AffonsoClient::class));

    $job->assertNotFailed();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === config('services.affonso.api').'/signups'
        && $request->hasHeader('Authorization', 'Bearer sk_live_test')
        && $request->data() === [
            'click_id' => 'clk_123',
            'email' => 'referred@example.com',
            'external_user_id' => $this->user->id,
        ]);
});

test('a user deleted before the job runs sends nothing', function () {
    Http::fake();
    $userId = $this->user->id;
    $this->user->delete();

    (new RecordAffiliateSignup($userId, 'clk_123'))->handle(app(AffonsoClient::class));

    Http::assertNothingSent();
});

test('a rate limit or a server error is thrown so the queue tries again', function (int $status) {
    Http::fake([config('services.affonso.api').'/signups' => Http::response(['success' => false], $status)]);

    $job = (new RecordAffiliateSignup($this->user->id, 'clk_123'))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(AffonsoClient::class)))->toThrow(RequestException::class);
    $job->assertNotFailed();
})->with([
    'rate limit' => [429],
    'server error' => [500],
    'unavailable' => [503],
]);

test('a refusal fails at once, since sending it again gets the same answer', function (int $status) {
    Http::fake([config('services.affonso.api').'/signups' => Http::response(['success' => false], $status)]);

    $job = (new RecordAffiliateSignup($this->user->id, 'clk_123'))->withFakeQueueInteractions();
    $job->handle(app(AffonsoClient::class));

    $job->assertFailed();
})->with([
    'validation' => [400],
    'bad key' => [401],
    'unknown click' => [404],
]);
