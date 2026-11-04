<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
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
        $comments = ThreadReplies::replyContentType($platform) === ContentType::LinkedInComment;

        return [$platform->value => [
            'reply_content_type' => ThreadReplies::replyContentType($platform)?->value,
            'max_replies' => ThreadReplies::maxReplies($platform),
            'empty_key' => $comments ? 'posts.form.thread.comment_empty' : 'posts.form.thread.reply_empty',
            'too_long_key' => $comments ? 'posts.form.thread.comment_too_long' : 'posts.form.thread.reply_too_long',
            'start_key' => $comments ? 'posts.form.thread.add_comment' : 'posts.form.thread.start',
        ]];
    })->all();

    expect($composer)->toBe($server);
});
