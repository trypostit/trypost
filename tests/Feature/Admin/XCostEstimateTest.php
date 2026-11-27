<?php

declare(strict_types=1);

use App\Actions\Admin\BuildXCostEstimate;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Admin\XPricing;
use Carbon\CarbonImmutable;

function xPostSentAt(SocialAccount $channel, CarbonImmutable $at, string $content, array $attributes = []): Post
{
    $post = Post::factory()->forAccount($channel)->published()->create(['content' => $content, ...$attributes]);
    Post::query()->whereKey($post->id)->toBase()->update(['published_at' => $at]);

    return $post;
}

test('counts the posts TryPost created on X per UTC day, with and without a link', function () {
    config()->set('trypost.platforms.x.defuse_links', false);
    $day = CarbonImmutable::now('UTC')->startOfDay()->subDay();
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);

    xPostSentAt($x, $day->addHours(2), '<p>Plain text</p>');
    xPostSentAt($x, $day->addHours(3), '<p>Read https://example.com/post</p>');
    xPostSentAt($x, $day->addHours(4), '<p>Bare host acme.com counts too</p>');
    xPostSentAt($x, $day->addHours(5), '<p>Node.js is not a link</p>');
    xPostSentAt(SocialAccount::factory()->create(['platform' => Platform::LinkedIn]), $day->addHours(6), '<p>Not X</p>');

    $estimate = collect(BuildXCostEstimate::execute([$day->toDateString()], [$day->toDateString() => 100]))->sole();

    expect($estimate)->toMatchArray([
        'date' => $day->toDateString(),
        'reads' => 100,
        'created' => 2,
        'created_with_link' => 2,
    ])
        ->and($estimate['cost_reads'])->toBe(round(100 * XPricing::POST_READ, 3))
        ->and($estimate['cost_created'])->toBe(round(2 * XPricing::POST_CREATE, 3))
        ->and($estimate['cost_created_with_link'])->toBe(round(2 * XPricing::POST_CREATE_WITH_URL, 3));
});

test('a defused link is billed as a plain post', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $day = CarbonImmutable::now('UTC')->startOfDay();
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);

    xPostSentAt($x, $day->addHour(), '<p>Read https://example.com/post</p>');

    expect(collect(BuildXCostEstimate::execute([$day->toDateString()], []))->sole())
        ->toMatchArray(['created' => 1, 'created_with_link' => 0, 'reads' => 0]);
});

test('every live reply of a thread is a post of its own', function () {
    config()->set('trypost.platforms.x.defuse_links', false);
    $day = CarbonImmutable::now('UTC')->startOfDay();
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);

    xPostSentAt($x, $day->addHour(), '<p>Root</p>', [
        'meta' => ['thread_replies' => [['text' => 'Second https://example.com'], ['text' => 'Third'], ['text' => 'Not sent']]],
        'thread_reply_ids' => ['2', '3'],
    ]);

    expect(collect(BuildXCostEstimate::execute([$day->toDateString()], []))->sole())
        ->toMatchArray(['created' => 2, 'created_with_link' => 1]);
});
