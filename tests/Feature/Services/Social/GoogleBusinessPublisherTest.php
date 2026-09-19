<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'content_language' => 'en']);

    $this->socialAccount = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'accounts/123456789/locations/987654321',
        'token_expires_at' => now()->addHour(),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out our new arrivals!',
    ]);

    $this->postPlatform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::GoogleBusiness,
        'content_type' => ContentType::GoogleBusinessPost,
        'meta' => ['topic_type' => 'STANDARD'],
    ]);

    $this->publisher = new GoogleBusinessPublisher;
});

test('publish hands Google a JPEG derivative rather than the raw upload', function () {
    Storage::fake();
    Storage::put('uploads/promo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    $this->post->update(['media' => [[
        'path' => 'uploads/promo.png',
        'url' => Storage::url('uploads/promo.png'),
        'mime_type' => 'image/png',
        'type' => 'image',
    ]]]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'LIVE',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request): bool {
        $sourceUrl = (string) data_get($request->data(), 'media.0.sourceUrl');

        return data_get($request->data(), 'media.0.mediaFormat') === 'PHOTO'
            && str_ends_with($sourceUrl, '.jpg')
            && ! str_contains($sourceUrl, 'promo.png');
    });
});

test('publish reports the review state Google returned and the real post URL', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'LIVE',
            'searchUrl' => 'https://posts.google.com/999',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['state'])->toBe('LIVE')
        ->and($result['url'])->toBe('https://posts.google.com/999');
});

test('publishes a standard post with the workspace content language', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('accounts/123456789/locations/987654321/localPosts/999');
    expect($result['url'])->toBe('https://business.google.com/locations/987654321');

    Http::assertSent(function ($request) {
        return $request->url() === config('trypost.platforms.google_business.local_posts_api').'/accounts/123456789/locations/987654321/localPosts'
            && $request['languageCode'] === 'en'
            && $request['summary'] === 'Check out our new arrivals!'
            && $request['topicType'] === 'STANDARD'
            && ! isset($request['media']);
    });
});

test('an explicitly null topic_type publishes as STANDARD', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update(['meta' => ['topic_type' => null]]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(fn ($request) => data_get($request->data(), 'topicType') === 'STANDARD'
        && ! isset($request['event']));
});

test('a blank event title throws instead of publishing an untitled event', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => [
            'topic_type' => 'EVENT',
            'event' => ['title' => '', 'start_date' => '2026-09-01', 'end_date' => '2026-09-02'],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(GoogleBusinessPublishException::class);

    Http::assertNothingSent();
});

test('a blank event start date throws instead of silently publishing today', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => [
            'topic_type' => 'EVENT',
            'event' => ['title' => 'Grand Opening', 'start_date' => '', 'end_date' => '2026-09-02'],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(GoogleBusinessPublishException::class);

    Http::assertNothingSent();
});

test('a missing event end date throws instead of silently publishing today', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => [
            'topic_type' => 'OFFER',
            'event' => ['title' => 'Summer Sale', 'start_date' => '2026-09-01', 'end_date' => null],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(GoogleBusinessPublishException::class);

    Http::assertNothingSent();
});

test('includes a call to action when configured', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => ['topic_type' => 'STANDARD', 'call_to_action' => ['action_type' => 'BOOK', 'url' => 'https://example.com/book']],
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(fn ($request) => data_get($request->data(), 'callToAction') === ['actionType' => 'BOOK', 'url' => 'https://example.com/book']);
});

test('call omits the url even when one is stored', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => ['topic_type' => 'STANDARD', 'call_to_action' => ['action_type' => 'CALL', 'url' => null]],
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(fn ($request) => data_get($request->data(), 'callToAction') === ['actionType' => 'CALL']);
});

test('builds an event payload for EVENT topic type', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => [
            'topic_type' => 'EVENT',
            'event' => ['title' => 'Grand Opening', 'start_date' => '2026-09-01', 'end_date' => '2026-09-02'],
        ],
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        $event = data_get($request->data(), 'event');

        return $event['title'] === 'Grand Opening'
            && $event['schedule']['startDate'] === ['year' => 2026, 'month' => 9, 'day' => 1]
            && $event['schedule']['endDate'] === ['year' => 2026, 'month' => 9, 'day' => 2];
    });
});

test('builds both an event and an offer payload for OFFER topic type', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->postPlatform->update([
        'meta' => [
            'topic_type' => 'OFFER',
            'event' => ['title' => 'Summer Sale', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
            'offer' => ['coupon_code' => 'SUMMER20'],
        ],
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return data_get($request->data(), 'topicType') === 'OFFER'
            && data_get($request->data(), 'event') === [
                'title' => 'Summer Sale',
                'schedule' => [
                    'startDate' => ['year' => 2026, 'month' => 9, 'day' => 1],
                    'endDate' => ['year' => 2026, 'month' => 9, 'day' => 30],
                ],
            ]
            && data_get($request->data(), 'offer') === ['couponCode' => 'SUMMER20'];
    });
});

test('rejects video media for google business posts', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response(['name' => 'x'], 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    // Only a PHOTO mediaFormat is ever sent — video attachments are silently
    // excluded here because ContentType::GoogleBusinessPost::supportsVideo()
    // is false, so the editor's own validation already blocks scheduling one;
    // this assertion is the backend's defense-in-depth for that same rule.
    Http::assertSent(fn ($request) => ! isset($request['media']) || data_get($request->data(), 'media.0.mediaFormat') !== 'VIDEO');
});

test('throws a structured exception on API failure', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'summary too long'],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(GoogleBusinessPublishException::class, 'summary too long');
});

test('fetchLocations flattens accounts and locations across pages', function () {
    Http::fake([
        config('trypost.platforms.google_business.account_management_api').'/accounts*' => Http::response([
            'accounts' => [['name' => 'accounts/111']],
        ], 200),
        config('trypost.platforms.google_business.business_information_api').'/accounts/111/locations*' => Http::response([
            'locations' => [
                ['name' => 'locations/222', 'title' => 'Downtown Store', 'storefrontAddress' => ['addressLines' => ['123 Main St'], 'locality' => 'Springfield']],
            ],
        ], 200),
    ]);

    $locations = $this->publisher->fetchLocations('fake-access-token');

    expect($locations)->toHaveCount(1);
    expect($locations[0])->toMatchArray([
        'id' => 'accounts/111/locations/222',
        'account_name' => 'accounts/111',
        'location_name' => 'locations/222',
        'title' => 'Downtown Store',
        'address' => '123 Main St, Springfield',
    ]);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), config('trypost.platforms.google_business.account_management_api').'/accounts')
            && data_get($query, 'pageSize') === '20';
    });

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), config('trypost.platforms.google_business.business_information_api').'/accounts/111/locations')
            && data_get($query, 'readMask') === 'name,title,storefrontAddress,metadata';
    });
});

test('fetchLocations skips a location Google says cannot take local posts', function () {
    Http::fake([
        config('trypost.platforms.google_business.account_management_api').'/accounts*' => Http::response([
            'accounts' => [['name' => 'accounts/111']],
        ], 200),
        config('trypost.platforms.google_business.business_information_api').'/accounts/111/locations*' => Http::response([
            'locations' => [
                ['name' => 'locations/222', 'title' => 'Downtown Store', 'metadata' => ['canOperateLocalPost' => true]],
                ['name' => 'locations/333', 'title' => 'Warehouse', 'metadata' => ['canOperateLocalPost' => false]],
                ['name' => 'locations/444', 'title' => 'Airport Kiosk'],
            ],
        ], 200),
    ]);

    $titles = array_column($this->publisher->fetchLocations('fake-access-token'), 'title');

    // An absent flag stays offered — only an explicit refusal is one.
    expect($titles)->toBe(['Downtown Store', 'Airport Kiosk']);
});
