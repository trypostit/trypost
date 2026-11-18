<?php

declare(strict_types=1);

use App\Enums\Analytics\PollVotingStatus;
use App\Models\AnalyticsPublication;
use App\Support\Social\MastodonPoll;
use App\Support\Social\Poll;
use App\Support\Social\ThreadsPoll;
use App\Support\Social\XPoll;

test('a mastodon poll found without a publish time takes the default length', function () {
    $publication = (new AnalyticsPublication)->forceFill([
        'provider_published_at' => null,
        'provider_metadata' => ['poll' => [
            'options' => [['label' => 'Tea', 'votes' => 1], ['label' => 'Coffee', 'votes' => 2]],
            'end_datetime' => now('UTC')->addDays(3)->toIso8601ZuluString(),
            'multiple' => true,
        ]],
    ]);

    expect(MastodonPoll::fromPublication($publication))->toBe([
        'options' => ['Tea', 'Coffee'],
        'duration_minutes' => MastodonPoll::DEFAULT_DURATION,
        'multiple' => true,
    ]);
});

test('a mastodon poll found ending further out than mastodon allows keeps the longest length', function () {
    $publishedAt = now('UTC')->subHour();

    $publication = (new AnalyticsPublication)->forceFill([
        'provider_published_at' => $publishedAt,
        'provider_metadata' => ['poll' => [
            'options' => [['label' => 'Tea', 'votes' => 0], ['label' => 'Coffee', 'votes' => 0]],
            'end_datetime' => $publishedAt->addDays(45)->toIso8601ZuluString(),
        ]],
    ]);

    expect(MastodonPoll::fromPublication($publication))->toMatchArray([
        'duration_minutes' => MastodonPoll::MAX_DURATION,
        'multiple' => false,
    ]);
});

test('a mastodon multiple choice poll keeps its voters and marks hidden totals', function () {
    $endsAt = now('UTC')->addDay()->toIso8601ZuluString();

    expect(MastodonPoll::result([
        'expired' => false,
        'expires_at' => $endsAt,
        'multiple' => true,
        'voters_count' => 7,
        'options' => [['title' => 'Tea', 'votes_count' => null], ['title' => 'Coffee', 'votes_count' => null]],
    ]))->toBe([
        'options' => [['label' => 'Tea', 'votes' => 0], ['label' => 'Coffee', 'votes' => 0]],
        'voting_status' => PollVotingStatus::Open->value,
        'end_datetime' => $endsAt,
        'duration_minutes' => null,
        'multiple' => true,
        'voters' => 7,
        'totals_hidden' => true,
    ]);
});

test('a mastodon single choice poll with visible totals has no voters count', function () {
    $result = MastodonPoll::result([
        'expired' => true,
        'multiple' => false,
        'voters_count' => null,
        'options' => [['title' => 'Tea', 'votes_count' => 3], ['title' => 'Coffee', 'votes_count' => 0]],
    ]);

    expect($result)->toMatchArray([
        'options' => [['label' => 'Tea', 'votes' => 3], ['label' => 'Coffee', 'votes' => 0]],
        'voting_status' => PollVotingStatus::Closed->value,
        'multiple' => false,
        'voters' => null,
        'totals_hidden' => false,
    ]);
});

test('a threads poll with four choices is sent as option a to d', function () {
    expect(ThreadsPoll::payload(['poll' => ['options' => ['Red', ' Green ', 'Blue', 'Gold']]]))->toBe([
        'option_a' => 'Red',
        'option_b' => 'Green',
        'option_c' => 'Blue',
        'option_d' => 'Gold',
    ]);
});

test('a threads poll result with four choices works the votes out of each share', function () {
    $endsAt = now('UTC')->addDay()->startOfSecond();

    expect(ThreadsPoll::result([
        'option_a' => 'Red',
        'option_b' => 'Green',
        'option_c' => 'Blue',
        'option_d' => 'Gold',
        'option_a_votes_percentage' => 0.1,
        'option_b_votes_percentage' => 0.2,
        'option_c_votes_percentage' => 0.3,
        'option_d_votes_percentage' => 0.4,
        'total_votes' => 20,
        'expiration_timestamp' => $endsAt->toIso8601String(),
    ]))->toBe([
        'options' => [
            ['label' => 'Red', 'votes' => 2],
            ['label' => 'Green', 'votes' => 4],
            ['label' => 'Blue', 'votes' => 6],
            ['label' => 'Gold', 'votes' => 8],
        ],
        'voting_status' => PollVotingStatus::Open->value,
        'end_datetime' => $endsAt->toIso8601ZuluString(),
        'duration_minutes' => null,
    ]);
});

test('a threads poll result without a vote total reads no votes', function () {
    $result = ThreadsPoll::result([
        'option_a' => 'Red',
        'option_b' => 'Green',
        'option_a_votes_percentage' => 0.5,
        'option_b_votes_percentage' => 0.5,
        'expiration_timestamp' => now('UTC')->subDay()->toIso8601String(),
    ]);

    expect(data_get($result, 'options'))->toBe([['label' => 'Red', 'votes' => 0], ['label' => 'Green', 'votes' => 0]])
        ->and(data_get($result, 'voting_status'))->toBe(PollVotingStatus::Closed->value);
});

test('normalizing a poll keeps blank choices at their position while the network keeps only filled ones', function () {
    $poll = ['options' => ['Tea', '  ', null, ' Coffee '], 'duration_minutes' => '60', 'multiple' => 'true', 'hide_totals' => '0'];

    expect(Poll::normalize($poll))->toBe([
        'options' => ['Tea', '', '', 'Coffee'],
        'duration_minutes' => 60,
        'multiple' => true,
        'hide_totals' => false,
    ])
        ->and(MastodonPoll::forNetwork($poll))->toBe([
            'options' => ['Tea', 'Coffee'],
            'duration_minutes' => 60,
            'multiple' => true,
            'hide_totals' => false,
        ])
        ->and(XPoll::forNetwork($poll))->toBe(['options' => ['Tea', 'Coffee'], 'duration_minutes' => 60])
        ->and(ThreadsPoll::forNetwork($poll))->toBe(['options' => ['Tea', 'Coffee']]);
});

test('a poll the network returned reads as its result, and anything else as none', function (mixed $poll) {
    expect(MastodonPoll::resultOf($poll))->toBeNull()
        ->and(MastodonPoll::metadataFrom($poll))->toBe([]);
})->with([
    'no poll' => [null],
    'a string' => ['poll'],
    'a number' => [3],
]);

test('a poll the network returned reads as the same result its metadata keeps', function () {
    $poll = [
        'expired' => false,
        'expires_at' => now('UTC')->addDay()->toIso8601ZuluString(),
        'multiple' => false,
        'options' => [['title' => 'Tea', 'votes_count' => 2], ['title' => 'Coffee', 'votes_count' => 1]],
    ];

    expect(MastodonPoll::resultOf($poll))->toBe(MastodonPoll::result($poll))
        ->and(MastodonPoll::metadataFrom($poll))->toBe(['poll' => MastodonPoll::result($poll)]);
});

test('a mastodon poll result without an expired flag has no voting status', function () {
    $result = MastodonPoll::result([
        'expires_at' => now('UTC')->addDay()->toIso8601ZuluString(),
        'options' => [['title' => 'Tea', 'votes_count' => 1], ['title' => 'Coffee', 'votes_count' => 0]],
    ]);

    expect(data_get($result, 'voting_status'))->toBeNull();
});

test('a threads poll result without an end has no voting status and no end', function () {
    $result = ThreadsPoll::result([
        'option_a' => 'Red',
        'option_b' => 'Green',
        'option_a_votes_percentage' => 0.5,
        'option_b_votes_percentage' => 0.5,
        'total_votes' => 4,
    ]);

    expect(data_get($result, 'voting_status'))->toBeNull()
        ->and(data_get($result, 'end_datetime'))->toBeNull()
        ->and(data_get($result, 'options'))->toBe([['label' => 'Red', 'votes' => 2], ['label' => 'Green', 'votes' => 2]]);
});

test('a mastodon poll found ending sooner than mastodon allows keeps the shortest length', function () {
    $publishedAt = now('UTC')->subHour();

    $publication = (new AnalyticsPublication)->forceFill([
        'provider_published_at' => $publishedAt,
        'provider_metadata' => ['poll' => [
            'options' => [['label' => 'Tea', 'votes' => 0], ['label' => 'Coffee', 'votes' => 0]],
            'end_datetime' => $publishedAt->addMinutes(2)->toIso8601ZuluString(),
        ]],
    ]);

    expect(MastodonPoll::fromPublication($publication))->toMatchArray([
        'duration_minutes' => MastodonPoll::MIN_DURATION,
    ]);
});
