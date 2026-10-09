<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Rules\ContentTypeMatchesPostChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runMatchesPostChannelRule(Post $post, string $contentType): array
{
    $errors = [];

    (new ContentTypeMatchesPostChannel($post))->validate('content_type', $contentType, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('passes when content_type matches the post channel', function () {
    $post = Post::factory()->forAccount(SocialAccount::factory()->linkedin()->create())->create();

    expect(runMatchesPostChannelRule($post, ContentType::LinkedInPost->value))->toBe([]);
});

test('fails when content_type belongs to a different platform than the post channel', function () {
    $post = Post::factory()->forAccount(SocialAccount::factory()->linkedin()->create())->create();

    $errors = runMatchesPostChannelRule($post, ContentType::XPost->value);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('not compatible');
});

test('skips validation for a post without a channel', function () {
    expect(runMatchesPostChannelRule(Post::factory()->create(), ContentType::XPost->value))->toBe([]);
});

test('skips validation for an unknown content type', function () {
    $post = Post::factory()->forAccount(SocialAccount::factory()->linkedin()->create())->create();

    expect(runMatchesPostChannelRule($post, 'not_a_type'))->toBe([]);
});
