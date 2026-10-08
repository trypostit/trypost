<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ContentLimitException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
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

        public function callValidateContentLength(PostPlatform $postPlatform): void
        {
            $this->validateContentLength($postPlatform);
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

test('validateContentLength passes when content is within limit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => str_repeat('a', 100)]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $socialAccount->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => $this->client->callValidateContentLength($postPlatform))->not->toThrow(Exception::class);
});

test('validateContentLength throws when content exceeds limit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => str_repeat('a', 4000)]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $socialAccount->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => $this->client->callValidateContentLength($postPlatform))
        ->toThrow(ContentLimitException::class, __('posts.errors.content_too_long', [
            'platform' => Platform::LinkedIn->label(),
            'max' => 3000,
            'provided' => 4000,
        ]));
});
