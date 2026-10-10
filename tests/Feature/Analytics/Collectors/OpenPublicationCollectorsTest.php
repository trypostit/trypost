<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\BlueskyPublicationCollector;
use App\Services\Analytics\Collectors\Publications\MastodonPublicationCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
});

test('bluesky pages repository records and hydrates public counts in batches', function () {
    $pds = 'https://pds.example';
    $appView = config('trypost.platforms.bluesky.public_appview');
    $uri = 'at://did:plc:alice/app.bsky.feed.post/post-1';
    Http::fake([
        "{$pds}/xrpc/com.atproto.repo.listRecords*" => Http::response([
            'records' => [[
                'uri' => $uri,
                'cid' => 'cid-1',
                'value' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => 'Hello from the repo',
                    'createdAt' => '2026-09-20T12:00:00Z',
                    'embed' => [
                        '$type' => 'app.bsky.embed.images',
                        'images' => [['image' => ['$type' => 'blob']]],
                    ],
                ],
            ]],
            'cursor' => 'repo-next',
        ]),
        "{$appView}/xrpc/app.bsky.feed.getPosts*" => Http::response([
            'posts' => [[
                'uri' => $uri,
                'likeCount' => 12,
                'repostCount' => 3,
                'replyCount' => 4,
                'quoteCount' => 2,
                'embed' => ['images' => [['thumb' => 'https://cdn.example/post.jpg']]],
            ]],
        ]),
    ]);
    $account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:alice',
        'username' => 'alice.bsky.social',
        'meta' => ['service' => $pds],
    ]);

    $page = app(BlueskyPublicationCollector::class)->page(
        $account,
        'repo-cursor',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->providerPostId)->toBe('post-1')
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Image)
        ->and($page->publications[0]->providerMetadata)->toMatchArray([
            'like_count' => 12,
            'repost_count' => 3,
            'reply_count' => 4,
            'quote_count' => 2,
        ])
        ->and($page->nextCursor)->toBe('repo-next');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/com.atproto.repo.listRecords')
        && $request['repo'] === 'did:plc:alice'
        && $request['collection'] === 'app.bsky.feed.post'
        && ! isset($request['reverse'])
        && $request['cursor'] === 'repo-cursor');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/app.bsky.feed.getPosts'));
});

test('bluesky reads the repository newest first and stops at the cutoff without losing newer records', function () {
    $pds = 'https://pds.example';
    $record = fn (string $id, string $createdAt): array => [
        'uri' => "at://did:plc:alice/app.bsky.feed.post/{$id}",
        'cid' => "cid-{$id}",
        'value' => ['$type' => 'app.bsky.feed.post', 'text' => $id, 'createdAt' => $createdAt],
    ];
    Http::fake([
        "{$pds}/xrpc/com.atproto.repo.listRecords*" => Http::response([
            'records' => [
                $record('newest', '2026-09-22T12:00:00Z'),
                $record('newer', '2026-09-21T12:00:00Z'),
                $record('old', '2025-01-01T12:00:00Z'),
            ],
            'cursor' => 'repo-next',
        ]),
        config('trypost.platforms.bluesky.public_appview').'/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => []]),
    ]);
    $account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:alice',
        'meta' => ['service' => $pds],
    ]);

    $page = app(BlueskyPublicationCollector::class)->page($account, null, CarbonImmutable::parse('2026-09-01', 'UTC'));

    expect(collect($page->publications)->pluck('providerPostId')->all())->toBe(['newest', 'newer'])
        ->and($page->nextCursor)->toBeNull()
        ->and($page->providerExhausted)->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => isset($request['reverse']));
});

test('bluesky respects the twenty five uri hydration limit', function () {
    $records = collect(range(1, 26))->map(fn (int $number): array => [
        'uri' => "at://did:plc:alice/app.bsky.feed.post/post-{$number}",
        'cid' => "cid-{$number}",
        'value' => [
            '$type' => 'app.bsky.feed.post',
            'text' => "Post {$number}",
            'createdAt' => '2026-09-20T12:00:00Z',
        ],
    ])->all();
    Http::fake(function (Request $request) use ($records) {
        if (str_contains($request->url(), '/com.atproto.repo.listRecords')) {
            return Http::response(['records' => $records]);
        }

        return Http::response(['posts' => collect($request['uris'])->map(fn (string $uri): array => [
            'uri' => $uri,
        ])->all()]);
    });
    $account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:alice',
        'meta' => ['service' => 'https://pds.example'],
    ]);

    $page = app(BlueskyPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    $hydrationRequests = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), '/app.bsky.feed.getPosts'),
    )->values();

    expect($page->publications)->toHaveCount(26)
        ->and($hydrationRequests)->toHaveCount(2)
        ->and(count($hydrationRequests[0][0]['uris']))->toBe(25)
        ->and(count($hydrationRequests[1][0]['uris']))->toBe(1);
});

test('mastodon pages account statuses with authenticated private history', function () {
    Http::fake(['*' => Http::response([
        [
            'id' => 'status-2',
            'created_at' => '2026-09-20T12:00:00Z',
            'content' => '<p>Hello Mastodon</p>',
            'url' => 'https://mastodon.example/@alice/status-2',
            'visibility' => 'private',
            'favourites_count' => 5,
            'reblogs_count' => 2,
            'replies_count' => 1,
            'media_attachments' => [[
                'type' => 'image',
                'preview_url' => 'https://cdn.example/status.jpg',
            ]],
        ],
    ], 200, [
        'Link' => '<https://mastodon.example/api/v1/accounts/42/statuses?limit=40&max_id=status-1>; rel="next"',
    ])]);
    $account = SocialAccount::factory()->mastodon()->create([
        'platform_user_id' => '42',
        'scopes' => ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'],
        'meta' => ['instance' => 'https://mastodon.example'],
    ]);

    $page = app(MastodonPublicationCollector::class)->page(
        $account,
        'status-3',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Image)
        ->and($page->publications[0]->excerpt)->toBe('Hello Mastodon')
        ->and($page->nextCursor)->toBe('status-1')
        ->and($page->partialReason)->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/accounts/42/statuses')
        && $request['limit'] === 40
        && $request['exclude_reblogs'] === true
        && $request['max_id'] === 'status-3'
        && $request->hasHeader('Authorization', 'Bearer '.$account->access_token));
});

test('mastodon imports public history as partial when an existing token lacks read statuses', function () {
    Http::fake(['*' => Http::response([])]);
    $account = SocialAccount::factory()->mastodon()->create([
        'platform_user_id' => '42',
        'scopes' => ['read:accounts', 'write:statuses', 'write:media'],
        'meta' => ['instance' => 'https://mastodon.example'],
    ]);

    $page = app(MastodonPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->providerExhausted)->toBeTrue()
        ->and($page->providerLimited)->toBeFalse()
        ->and($page->partialReason)->toBe('mastodon_reconnect_for_private_history');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('mastodon parent read scope authorizes complete private history', function () {
    Http::fake(['*' => Http::response([])]);
    $account = SocialAccount::factory()->mastodon()->create([
        'platform_user_id' => '42',
        'scopes' => ['read', 'write:statuses'],
        'meta' => ['instance' => 'https://mastodon.example'],
    ]);

    $page = app(MastodonPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->partialReason)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$account->access_token));
});

test('publication collector factory supports open networks', function (Platform $platform, string $collector) {
    $account = SocialAccount::factory()->create(['platform' => $platform]);

    expect(app(PublicationHistoryCollectorFactory::class)->for($account))->toBeInstanceOf($collector);
})->with([
    [Platform::Bluesky, BlueskyPublicationCollector::class],
    [Platform::Mastodon, MastodonPublicationCollector::class],
]);

test('bluesky tracks a post, never a reply, so a thread is its root', function () {
    $pds = 'https://pds.example';
    $record = fn (string $id, ?array $reply = null): array => [
        'uri' => "at://did:plc:alice/app.bsky.feed.post/{$id}",
        'cid' => "cid-{$id}",
        'value' => array_filter([
            '$type' => 'app.bsky.feed.post',
            'text' => $id,
            'createdAt' => '2026-09-22T12:00:00Z',
            'reply' => $reply,
        ]),
    ];
    $root = ['uri' => 'at://did:plc:alice/app.bsky.feed.post/thread-root', 'cid' => 'cid-thread-root'];
    Http::fake([
        "{$pds}/xrpc/com.atproto.repo.listRecords*" => Http::response([
            'records' => [$record('thread-reply', ['root' => $root, 'parent' => $root]), $record('thread-root')],
        ]),
        config('trypost.platforms.bluesky.public_appview').'/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => []]),
    ]);
    $account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:alice',
        'meta' => ['service' => $pds],
    ]);

    $page = app(BlueskyPublicationCollector::class)->page($account, null, CarbonImmutable::parse('2026-09-01', 'UTC'));

    expect(collect($page->publications)->pluck('providerPostId')->all())->toBe(['thread-root']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'getPosts')
        && str_contains(urldecode($request->url()), 'thread-reply'));
});

test('mastodon tracks a status, never a reply, so a thread is its root', function () {
    Http::fake(['*' => Http::response([
        ['id' => 'thread-reply', 'created_at' => '2026-09-20T12:01:00Z', 'content' => '<p>2/2</p>', 'in_reply_to_id' => 'thread-root', 'media_attachments' => []],
        ['id' => 'thread-root', 'created_at' => '2026-09-20T12:00:00Z', 'content' => '<p>1/2</p>', 'in_reply_to_id' => null, 'media_attachments' => []],
    ])]);
    $account = SocialAccount::factory()->mastodon()->create([
        'platform_user_id' => '42',
        'scopes' => ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'],
        'meta' => ['instance' => 'https://mastodon.example'],
    ]);

    $page = app(MastodonPublicationCollector::class)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC'));

    expect(collect($page->publications)->pluck('providerPostId')->all())->toBe(['thread-root']);
});
