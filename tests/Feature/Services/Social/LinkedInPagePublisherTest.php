<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPagePublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456',
        'username' => 'testcompany',
        'token_expires_at' => now()->addDays(60),
        'meta' => [
            'organization_id' => '123456',
            'admin_user_id' => 'user123',
            'admin_name' => 'John Doe',
        ],
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from our LinkedIn Page!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
    ]);

    $this->publisher = new LinkedInPagePublisher;
});

test('linkedin page publisher can publish text-only post', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('urn:li:share:1234567890');
    expect($result['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:1234567890');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/posts')
            && $request['author'] === 'urn:li:organization:123456'
            && $request['commentary'] === 'Hello from our LinkedIn Page!'
            && $request['visibility'] === 'PUBLIC';
    });
});

test('linkedin page publisher uses organization urn', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return $request['author'] === 'urn:li:organization:123456';
    });
});

test('linkedin page publisher throws exception when organization id missing', function () {
    Http::fake();
    $this->socialAccount->update(['meta' => []]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'LinkedIn Page organization ID not configured');

    // Fail-fast: the missing org id must abort before any LinkedIn request.
    Http::assertNothingSent();
});

test('linkedin page publisher uses correct headers', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && $request->hasHeader('X-Restli-Protocol-Version')
            && $request->hasHeader('LinkedIn-Version')
            && str_starts_with($request->header('Authorization')[0], 'Bearer ');
    });
});

test('linkedin page publisher throws exception on api error', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response([
            'message' => 'Invalid request',
            'status' => 400,
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('linkedin page publisher throws token expired exception on auth error after retry', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response([
            'code' => 'EXPIRED_ACCESS_TOKEN',
            'message' => 'The token used in the request has expired',
        ], 401),
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('linkedin page publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 5184000,
        ], 200),
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/v2/accessToken');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('linkedin page publisher throws exception when no refresh token available', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'No refresh token available for LinkedIn Page account');
});

test('linkedin page publisher throws TokenExpiredException when refresh_token is rejected', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'The refresh token is invalid');
});

test('linkedin page publisher handles empty content', function () {
    $this->post->update(['content' => '']);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:1234567890');

    Http::assertSent(function ($request) {
        return $request['commentary'] === '';
    });
});

test('linkedin page publisher builds feed url from the post id regardless of username', function (?string $username) {
    $this->socialAccount->update(['username' => $username]);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:1234567890');
})->with([
    'username present' => ['testcompany'],
    'username missing' => [null],
]);

test('linkedin page publisher returns a null url when the response has no post id', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts' => Http::response(null, 201),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBeNull();
});

test('linkedin page publisher can publish post with image using organization urn', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/OrgFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:OrgFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:9999999999']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:9999999999');
    expect($result['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:9999999999');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/images'));

    // Assert the post was created with organization URN as author
    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && ($request['author'] ?? '') === 'urn:li:organization:123456'
        && isset($request['content']['media']['id'])
    );
});

test('linkedin page publisher publishes a multi-image carousel with image ids under the organization', function () {
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/c1.jpg', 'url' => 'https://example.com/c1.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'c1.jpg'],
            ['id' => 'm2', 'path' => 'media/c2.jpg', 'url' => 'https://example.com/c2.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'c2.jpg'],
        ],
    ]);

    $imageUrns = ['urn:li:image:OrgCarousel1', 'urn:li:image:OrgCarousel2'];
    $uploadUrls = ['https://www.linkedin.com/dms/upload/org/1', 'https://www.linkedin.com/dms/upload/org/2'];
    $initCount = 0;

    Http::fake(function ($request) use ($imageUrns, $uploadUrls, &$initCount) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            $idx = $initCount % 2;
            $initCount++;

            return Http::response(['value' => ['uploadUrl' => $uploadUrls[$idx], 'image' => $imageUrns[$idx]]], 200);
        }

        foreach ($uploadUrls as $uploadUrl) {
            if ($url === $uploadUrl) {
                return Http::response(null, 201);
            }
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:orgcarousel']);
        }

        return Http::response('fake-image-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:orgcarousel');
    expect($result['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:orgcarousel');

    // Org carousel must author as the organization and send image URNs under `id` (not `media`).
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }
        $data = $request->data();
        $images = data_get($data, 'content.multiImage.images');

        return data_get($data, 'author') === 'urn:li:organization:123456'
            && is_array($images)
            && count($images) === 2
            && data_get($images, '0.id') === 'urn:li:image:OrgCarousel1'
            && ! array_key_exists('media', $images[0]);
    });
});

test('linkedin page publisher can publish a document (pdf carousel) using organization urn', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::LinkedInPagePost,
        'meta' => ['document_title' => 'Company Deck'],
    ]);
    $this->post->update([
        'media' => [
            [
                'id' => 'doc-media-1',
                'path' => 'media/2026-01/company-deck.pdf',
                'url' => 'https://example.com/media/2026-01/company-deck.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'company-deck.pdf',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/org/0';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'document' => 'urn:li:document:OrgDocUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:orgdoc999']);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:orgdoc999');
    expect($result['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:orgdoc999');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/documents') && str_contains($request->url(), 'initializeUpload'));
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return ($request['author'] ?? '') === 'urn:li:organization:123456'
            && data_get($request->data(), 'content.media.id') === 'urn:li:document:OrgDocUrn'
            && data_get($request->data(), 'content.media.title') === 'Company Deck';
    });
});

test('linkedin page publisher throws and does not post when document processing fails', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPagePost]);
    $this->post->update([
        'media' => [[
            'id' => 'doc-media-1', 'path' => 'media/2026-01/company-deck.pdf',
            'url' => 'https://example.com/media/2026-01/company-deck.pdf',
            'mime_type' => 'application/pdf', 'original_filename' => 'company-deck.pdf',
        ]],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/org/fail';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:OrgFail']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'PROCESSING_FAILED'], 200);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'LinkedIn Page document processing failed');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});
