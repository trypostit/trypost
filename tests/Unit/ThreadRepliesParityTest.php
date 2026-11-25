<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Support\ThreadReplies;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('the composer threads every network the way the server does', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());
    $process = (new Process([$node, base_path('tests/fixtures/thread-replies-harness.ts'), resource_path('js'), ...$platforms]))->mustRun();

    $composer = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $server = collect(Platform::cases())->mapWithKeys(function (Platform $platform): array {
        $type = ThreadReplies::replyContentType($platform);
        $comments = $type?->isComment();
        $startKey = match ($type) {
            ContentType::InstagramComment => 'posts.form.thread.first_comment',
            ContentType::LinkedInComment => 'posts.form.thread.add_comment',
            default => 'posts.form.thread.start',
        };

        return [$platform->value => [
            'reply_content_type' => $type?->value,
            'reply_poll_content_type' => transform(ThreadReplies::replyPoll($platform), fn (string $poll): string => $poll::contentType()->value),
            'max_replies' => ThreadReplies::maxReplies($platform),
            'empty_key' => $comments ? 'posts.form.thread.comment_empty' : 'posts.form.thread.reply_empty',
            'too_long_key' => $comments ? 'posts.form.thread.comment_too_long' : 'posts.form.thread.reply_too_long',
            'start_key' => $startKey,
        ]];
    })->all();

    expect($composer['platforms'])->toBe($server)
        ->and($composer['standalone_types'])->toBe(collect(Platform::cases())->flatMap(fn (Platform $platform): array => config("trypost.platforms.{$platform->value}.replies.standalone_types", []))->all())
        ->and($composer['gated_features'])->toBe(array_column(GatedFeature::cases(), 'value'))
        ->and($composer['standalone_key'])->toBeNull()
        ->and($composer['replies_off_key'])->toBe('posts.form.thread.unsupported')
        ->and($composer['comments_off_key'])->toBe('posts.form.thread.comment_unsupported')
        ->and($composer['unsupported_story_keys'])->toBe(['posts.form.thread.instagram_story', 'posts.form.thread.unsupported'])
        ->and($composer['permission_keys'])->toBe(serverPermissionKeys());
});

/**
 * What the server refuses for each of the harness's cases: the first feature
 * (`GatedFeature::cases()` order) the post uses and the channel lacks, shown
 * with the harness's copy for it, the member's when the user cannot reconnect.
 *
 * @return array<string, string|null>
 */
function serverPermissionKeys(): array
{
    $thread = ['thread_replies' => [['text' => 'Second', 'media' => []]]];
    $copy = [
        GatedFeature::ThreadReplies->value => ['reconnect', 'ask an admin'],
        GatedFeature::PostInsights->value => ['reconnect', 'ask an admin'],
        GatedFeature::Publish->value => ['publish', 'publish member'],
        GatedFeature::ReelCover->value => ['reconnect', 'ask an admin'],
    ];
    $cases = [
        'allowed' => [$thread, [], true],
        'not_used' => [[], [GatedFeature::ThreadReplies], true],
        'publish_before_replies' => [$thread, [GatedFeature::ThreadReplies, GatedFeature::Publish], true],
        'analytics_only' => [$thread, [GatedFeature::PostInsights], true],
        'lacks_scope_admin' => [$thread, [GatedFeature::ThreadReplies], true],
        'lacks_scope_member' => [$thread, [GatedFeature::ThreadReplies], false],
        'reel_cover' => [[], [GatedFeature::ReelCover], true, ContentType::FacebookReel, true],
        'reel_without_cover' => [[], [GatedFeature::ReelCover], true, ContentType::FacebookReel, false],
        'feed_video_cover' => [[], [GatedFeature::ReelCover], true, ContentType::FacebookPost, true],
    ];

    return collect($cases)->map(function (array $case) use ($copy): ?string {
        [$meta, $missing, $canReconnect] = $case;
        $type = data_get($case, 3);
        $hasCoverImage = data_get($case, 4, false);
        $feature = collect(GatedFeature::cases())
            ->first(fn (GatedFeature $feature): bool => ! $feature->isOptionalRead() && $feature->isRequiredToPublish($meta, $type, $hasCoverImage) && collect($missing)->contains($feature));

        if (blank($feature)) {
            return null;
        }

        return data_get($copy, "{$feature->value}.".($canReconnect ? 0 : 1));
    })->all();
}
